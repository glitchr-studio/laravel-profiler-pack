/* The floating bar's gestures, ported from glitchr/symfony-profiler-pack.
   The handle is the bar's own button (.sf-toolbar-toggle-button): held and
   moved, it carries the toolbar; a plain click still opens and closes it.
   The left edge resizes. Place and width are kept in localStorage (keys of
   its own: laravel-profiler-pack/toolbar/...). Pointer events, so a finger and a pen do what the
   mouse does: the handle keeps the pointer while it moves (pointer capture)
   and is not a place to scroll the page from (touch-action, floating.css); a
   double tap stands for the double click phones do not reliably send. The
   bar is looked up again on each change of the page (a page that replaces
   its body keeps it working).

   Wherever it is put, kept or restored, the toolbar stays in the window: its
   handle and its right end never leave it (its body may slide off to the left,
   which is what sending it aside does), it stays inside what the browser shows
   of the page (window.visualViewport: not under a browser's own bottom bar),
   and above a bar the page keeps fixed along the bottom of the window.

   Nor does it cover what a visitor presses in a panel the page shows over
   itself: a cookie question floating at the bottom, a dialog, a chat button.
   Such a panel is a layer of the page fixed in the window, found under the
   toolbar's column (document.elementsFromPoint), or an open dialog wherever it
   is laid (dialog[open], role dialog or alertdialog, aria-modal, the banner of
   a cookie library, data-toolbar-avoid), holding a control. Where the toolbar
   would cover one, it is shown at the nearest height clear of the panel, up
   first (of a panel nearly as high as the window, a side rail or a drawer,
   clear of its controls); when no height is, clear of the controls; when none
   is either, where it was. The place asked for is left as it was: the panel closed, the
   toolbar goes back. A move does not jump: it is kept clear once let go.

   At the end of the page, where nothing scrolls out from under it any more, it
   covers no control of the page itself either (a link of the footer, a cookie
   circle there, its icon included), kept clear of each the same way - for a
   short climb only: rather than rise far up the window, it stays and covers
   them. */
(function () {
    'use strict';

    if (window.__laravelProfilerPackFloating) {
        return;
    }
    window.__laravelProfilerPackFloating = true;

    /* The options are the bar's own (config/profiler-pack.php), written on its root element. */
    var options = {};
    try {
        options = JSON.parse(document.querySelector('[data-laravel-profiler-pack]').getAttribute('data-laravel-profiler-pack')) || {};
    } catch (e) {
    }
    var DRAGGABLE = true === options.draggable;
    var RESIZABLE = true === options.resizable;
    var MINIMIZABLE = true === options.minimizable;
    var POSITION = String(options.position || 'bottom-right');

    var POSITION_KEY = 'laravel-profiler-pack/toolbar/position';
    var WIDTH_KEY = 'laravel-profiler-pack/toolbar/width';
    var THRESHOLD = 5;
    var PADDING = 16;
    var EDGE = 15;
    var TOUCH_EDGE = 28;
    var DOUBLE_TAP = 350;   /* ms between the two taps */
    var MIN_WIDTH = 200;
    var FOLDED_WIDTH = 65;

    var NAV_HEIGHT = 120;   /* px: a bar fixed along the bottom of the window, not a panel */
    var MARGIN = 4;
    var ASIDE = 24;         /* px: a handle this close to the left edge is aside (an open and a closed handle are not as wide) */

    var CONTROLS = 'a[href], button, input, select, textarea, summary, [role="button"], [role="link"], [role="checkbox"], [role="switch"], [contenteditable="true"], [tabindex]:not([tabindex="-1"])';
    var PANELS = 'dialog[open], [role="dialog"], [role="alertdialog"], [aria-modal="true"], [data-consent-banner], [data-cookie-banner], [data-toolbar-avoid]';
    var CONSENT = '[data-consent-open], [data-cookie-open], [data-consent], [data-cookie]';   /* what opens the cookie panel: a tap on it must never reach the toolbar */
    var GRID = 32;          /* px between the points a panel is looked for under */
    var PAGE = 0.9;         /* a fixed layer this much of the window each way is the page laid out in it, not a panel */
    var TALL = 0.75;        /* a panel this much of the window high (a side rail, a drawer) is kept clear of by its controls only */
    var CLIMB = 160;        /* px: the most the toolbar climbs to clear the page's own controls at its end; past that it climbs no more, leaving the page's last line clear */
    var LINE = 24;          /* px: controls ending this close to the lowest one are the page's last line */

    var bar = null;
    var content = null;
    var applied = { x: 0, y: 0 };
    var settling = false;
    var fullWidth = 0;
    var x = 0;
    var y = 0;
    var drag = null;
    var resize = null;
    var swallowClick = false;
    var openBeforeClicks = true;
    var lastDown = 0;
    var lastTap = null;
    var tappedFold = 0;
    var clickAfterFold = 0;

    function read(key) {
        try {
            return window.localStorage.getItem(key);
        } catch (e) {
            return null;
        }
    }

    function write(key, value) {
        try {
            if (null === value) {
                window.localStorage.removeItem(key);
            } else {
                window.localStorage.setItem(key, value);
            }
        } catch (e) {
        }
    }

    function isOpen() {
        return bar && !bar.classList.contains('sf-toolbar-closed') && content && content.offsetWidth > 0;
    }

    /* Widths are the ones written in the style: the content's, without its padding and borders. */
    function chrome() {
        var style = window.getComputedStyle(content);
        if ('border-box' === style.boxSizing) {
            return 0;
        }

        return (parseFloat(style.paddingLeft) || 0) + (parseFloat(style.paddingRight) || 0) + (parseFloat(style.borderLeftWidth) || 0) + (parseFloat(style.borderRightWidth) || 0);
    }

    function width() {
        return content.offsetWidth - chrome();
    }

    function handle() {
        return bar ? bar.querySelector('.sf-toolbar-toggle-button') : null;
    }

    /* The height of a bar the page keeps fixed along the bottom of the window (a navigation, a player): wide, low, touching the bottom. */
    function pageBottomBar() {
        var lift = 0;
        var height = window.innerHeight;
        var seen = [];
        if (!document.elementsFromPoint) {
            return 0;
        }
        for (var i = 1; i <= 3; i++) {
            var stack = document.elementsFromPoint(window.innerWidth * i / 4, height - 2);
            for (var j = 0; j < stack.length; j++) {
                for (var el = stack[j]; el && el !== document.body && el !== document.documentElement; el = el.parentElement) {
                    if (-1 !== seen.indexOf(el) || (bar && bar.contains(el))) {
                        break;
                    }
                    seen.push(el);
                    if ('fixed' !== window.getComputedStyle(el).position) {
                        continue;
                    }
                    var rect = el.getBoundingClientRect();
                    if (rect.bottom >= height - MARGIN && rect.width >= window.innerWidth / 2 && rect.height > 0 && rect.height <= Math.min(NAV_HEIGHT, height / 4)) {
                        lift = Math.max(lift, height - rect.top);
                    }
                    break;
                }
            }
        }

        return lift;
    }

    /* The lowest line the toolbar may reach: the bottom of what the browser shows, above the page's own bottom bar. */
    function floor() {
        var bottom = window.innerHeight;
        var viewport = window.visualViewport;
        if (viewport && Math.abs(viewport.scale - 1) < 0.01) {
            bottom = Math.min(bottom, viewport.offsetTop + viewport.height);
        }

        return Math.min(bottom, window.innerHeight - pageBottomBar()) - MARGIN;
    }

    /* The toolbar where it would be without any move: its pill and its handle, the transform taken off. */
    function bounds() {
        var toggle = handle();
        if (!bar || !content || !toggle) {
            return null;
        }
        var pill = content.getBoundingClientRect();
        var knob = toggle.getBoundingClientRect();
        if (!knob.width || !knob.height) {
            return null;
        }
        var visible = pill.width && pill.height;

        return {
            left: (visible ? Math.min(pill.left, knob.left) : knob.left) - applied.x,
            handleLeft: knob.left - applied.x,
            right: (visible ? Math.max(pill.right, knob.right) : knob.right) - applied.x,
            top: (visible ? Math.min(pill.top, knob.top) : knob.top) - applied.y,
            bottom: (visible ? Math.max(pill.bottom, knob.bottom) : knob.bottom) - applied.y
        };
    }

    function visibleRect(el) {
        var rect = el.getBoundingClientRect();
        if (!rect.width || !rect.height || 'hidden' === window.getComputedStyle(el).visibility) {
            return null;
        }

        return rect;
    }

    /* What a control shows: its own box and what it holds, an icon spilling out of a link's line included (a footer's icons). */
    function drawnRect(el) {
        var rect = visibleRect(el);
        if (!rect) {
            return null;
        }
        var top = rect.top;
        var bottom = rect.bottom;
        var left = rect.left;
        var right = rect.right;
        var inside = el.querySelectorAll('*');
        for (var i = 0; i < inside.length && i < 12; i++) {
            var child = visibleRect(inside[i]);
            if (child) {
                top = Math.min(top, child.top);
                bottom = Math.max(bottom, child.bottom);
                left = Math.min(left, child.left);
                right = Math.max(right, child.right);
            }
        }

        return { top: top, bottom: bottom, left: left, right: right, width: right - left, height: bottom - top };
    }

    /* The panels of the page across the toolbar's column, from left to right: each one's top and bottom, and its controls'. */
    function panels(left, right) {
        var found = [];
        var list = [];
        var seen = [];
        var width = window.innerWidth;
        var height = window.innerHeight;

        function across(rect) {
            return rect.right > left && rect.left < right && rect.bottom > 0 && rect.top < height;
        }

        function take(el) {
            if (-1 !== found.indexOf(el) || !bar || bar.contains(el) || el.contains(bar)) {
                return;
            }
            found.push(el);
            var rect = visibleRect(el);
            if (!rect || !across(rect) || (rect.width >= width * PAGE && rect.height >= height * PAGE)) {
                return;
            }
            var controls = [];
            var inside = el.querySelectorAll(CONTROLS);
            for (var i = 0; i < inside.length; i++) {
                var control = visibleRect(inside[i]);
                if (control && across(control) && control.bottom > rect.top && control.top < rect.bottom) {
                    controls.push({ top: Math.max(control.top, rect.top), bottom: Math.min(control.bottom, rect.bottom) });
                }
            }
            if (controls.length) {
                list.push({ top: rect.top, bottom: rect.bottom, controls: controls });
            }
        }

        var marked = document.querySelectorAll(PANELS);
        for (var m = 0; m < marked.length; m++) {
            take(marked[m]);
        }
        if (document.elementsFromPoint) {
            for (var py = GRID / 2; py < height; py += GRID) {
                for (var c = 0; c <= 4; c++) {
                    var stack = document.elementsFromPoint(left + 2 + (right - left - 4) * c / 4, py);
                    for (var j = 0; j < stack.length; j++) {
                        for (var el = stack[j]; el && el !== document.body && el !== document.documentElement; el = el.parentElement) {
                            if (-1 !== seen.indexOf(el) || bar.contains(el)) {
                                break;
                            }
                            seen.push(el);
                            if ('fixed' === window.getComputedStyle(el).position) {
                                take(el);
                                break;
                            }
                        }
                    }
                }
            }
        }

        /* At the end of the page nothing scrolls out from under the toolbar any more: a control of the page itself (a link of the footer, its cookie circle) is kept clear of too, each one a panel of its own. Before the end, the page's controls pass under it as it scrolls, and it stays where it is. */
        if (atPageEnd()) {
            var own = document.querySelectorAll(CONTROLS);
            for (var k = 0; k < own.length; k++) {
                if (bar.contains(own[k])) {
                    continue;
                }
                var rect = drawnRect(own[k]);
                /* Under another layer (glitchr's intro over its footer) a control is out of reach: nothing to keep clear of. */
                if (rect && across(rect) && !hidden(own[k], rect)) {
                    /* A control of a layer fixed or stuck in the window (a bottom bar, "back to top") is kept clear of, but is no part of the page's last line. */
                    list.push({ top: rect.top, bottom: rect.bottom, controls: [{ top: rect.top, bottom: rect.bottom }], page: true, pinned: pinned(own[k]), consent: !!own[k].closest(CONSENT) });
                }
            }
        }

        return list;
    }

    /* Another layer lies over the middle of the control (the toolbar itself does not count). */
    function hidden(el, rect) {
        var x = Math.min(Math.max((rect.left + rect.right) / 2, 0), window.innerWidth - 1);
        var y = Math.min(Math.max((rect.top + rect.bottom) / 2, 0), window.innerHeight - 1);
        var hit = document.elementFromPoint ? document.elementFromPoint(x, y) : null;

        return !!hit && hit !== el && !el.contains(hit) && !hit.contains(el) && !bar.contains(hit);
    }

    /* Inside a layer fixed or stuck in the window. */
    function pinned(el) {
        for (var node = el; node && node !== document.body && node !== document.documentElement; node = node.parentElement) {
            var position = window.getComputedStyle(node).position;
            if ('fixed' === position || 'sticky' === position) {
                return true;
            }
        }

        return false;
    }

    /* The page shows its end: scrolled to the bottom, or no taller than the window. */
    function atPageEnd() {
        var root = document.scrollingElement || document.documentElement;

        return window.scrollY + window.innerHeight >= root.scrollHeight - 2;
    }

    /* The nearest height, between minY and maxY, at which the toolbar (top to bottom, moved by y) meets none of the rectangles; null when there is none. */
    function nearestClear(rects, top, bottom, y, minY, maxY) {
        var tries = [y];
        for (var i = 0; i < rects.length; i++) {
            tries.push(rects[i].top - MARGIN - bottom, rects[i].bottom + MARGIN - top);
        }
        var best = null;
        for (var t = 0; t < tries.length; t++) {
            var ty = tries[t];
            if (ty < minY || ty > maxY) {
                continue;
            }
            var clear = true;
            for (var r = 0; r < rects.length && clear; r++) {
                clear = !(top + ty < rects[r].bottom && bottom + ty > rects[r].top);
            }
            if (clear && (null === best || Math.abs(ty - y) < Math.abs(best - y) || (Math.abs(ty - y) === Math.abs(best - y) && ty < best))) {
                best = ty;
            }
        }

        return best;
    }

    /* Where the toolbar, at (nx, ny), covers no control of a panel of the page: clear of the panels, else of their controls, else where it was. */
    function clearOfPanels(box, nx, ny, minY, maxY) {
        var left = Math.max(0, box.left + nx);
        var right = Math.min(window.innerWidth, box.right + nx);
        if (right <= left) {
            return ny;
        }
        var found = panels(left, right);
        if (!found.length) {
            return ny;
        }

        function solve(list) {
            var whole = [];
            var controls = [];
            for (var i = 0; i < list.length; i++) {
                if (list[i].bottom - list[i].top < window.innerHeight * TALL) {
                    whole.push(list[i]);
                } else {
                    whole = whole.concat(list[i].controls);
                }
                controls = controls.concat(list[i].controls);
            }
            var y = nearestClear(whole, box.top, box.bottom, ny, minY, maxY);
            if (null === y) {
                y = nearestClear(controls, box.top, box.bottom, ny, minY, maxY);
            }

            return null === y ? ny : y;
        }

        /* The page's own controls (at its end) are worth a short climb only: past CLIMB it climbs no more than CLIMB, to a height covering no cookie opener, leaving the page's last line clear (a footer's credits) and covering as few of them as it can, kept clear of the panels. */
        var y = solve(found);
        if (ny - y > CLIMB) {
            var panelsOnly = [];
            var own = [];
            for (var p = 0; p < found.length; p++) {
                (found[p].page ? own : panelsOnly).push(found[p]);
            }
            if (own.length) {
                y = fewestCovered(own, panelsOnly, panelsOnly.length ? solve(panelsOnly) : ny);
            }
        }

        return y;

        /* Within CLIMB of y, the height covering the fewest cookie openers (a tap there must open the panel, not the profiler), then leaving the page's last line clear (a footer's credits), then covering the fewest fixed controls (the only way to an action while the page scrolls: "back to top"), then the fewest controls, then the nearer. */
        function fewestCovered(own, panelsOnly, y) {
            var low = Math.max(minY, y - CLIMB);
            var keep = [];
            for (var k = 0; k < panelsOnly.length; k++) {
                keep = keep.concat(panelsOnly[k].bottom - panelsOnly[k].top < window.innerHeight * TALL ? [panelsOnly[k]] : panelsOnly[k].controls);
            }
            var last = -Infinity;
            for (var l = 0; l < own.length; l++) {
                if (!own[l].pinned) {
                    last = Math.max(last, own[l].bottom);
                }
            }
            var tries = [y];
            for (var i = 0; i < own.length; i++) {
                tries.push(own[i].top - MARGIN - box.bottom, own[i].bottom + MARGIN - box.top);
            }
            var best = null;
            for (var t = 0; t < tries.length; t++) {
                var ty = tries[t];
                if (ty < low || ty > y || ty > maxY || null === nearestClear(keep, box.top, box.bottom, ty, ty, ty)) {
                    continue;
                }
                var count = 0;
                var lastLine = 0;
                var consent = 0;
                var fixed = 0;
                for (var r = 0; r < own.length; r++) {
                    if (box.top + ty < own[r].bottom && box.bottom + ty > own[r].top) {
                        count++;
                        lastLine = !own[r].pinned && own[r].bottom > last - LINE ? 1 : lastLine;
                        consent += own[r].consent ? 1 : 0;
                        fixed += own[r].pinned ? 1 : 0;
                    }
                }
                var rank = [consent, lastLine, fixed, count, -ty];
                if (null === best || before(rank, best.rank)) {
                    best = { y: ty, rank: rank };
                }
            }

            return null === best ? y : best.y;

            /* Whether one rank comes before another, its first differing term the smaller. */
            function before(a, b) {
                for (var i = 0; i < a.length; i++) {
                    if (a[i] !== b[i]) {
                        return a[i] < b[i];
                    }
                }

                return false;
            }
        }
    }

    function clampPosition(nx, ny, clear) {
        var box = bounds();
        if (!box) {
            return { x: nx, y: ny };
        }
        var minX = MARGIN - box.handleLeft;
        var maxX = Math.max(minX, window.innerWidth - MARGIN - box.right);
        var minY = MARGIN - box.top;
        var maxY = Math.max(minY, floor() - box.bottom);
        var cx = Math.min(maxX, Math.max(minX, nx));
        var cy = Math.min(maxY, Math.max(minY, ny));

        return {
            x: cx,
            y: clear ? clearOfPanels(box, cx, cy, minY, maxY) : cy
        };
    }

    function clampWidth(width) {
        return Math.min(Math.max(fullWidth, MIN_WIDTH), Math.max(FOLDED_WIDTH, width));
    }

    /* Shown at (nx, ny), in the window; kept clear of the page's panels but while it is being moved. Returns the place in the window, the one to keep. */
    function place(nx, ny, moving) {
        var clamped = clampPosition(nx, ny, false);
        var shown = moving ? clamped : clampPosition(nx, ny, true);
        bar.style.transform = 'translate(' + shown.x + 'px, ' + shown.y + 'px)';
        applied = shown;

        return clamped;
    }

    /* Put back inside the window after it, the page or the toolbar changed; the place kept is left as it was. */
    function settle() {
        if (settling) {
            return;
        }
        settling = true;
        window.requestAnimationFrame(function () {
            settling = false;
            if (!bar || drag) {
                return;
            }
            place(x, y);
            invert();
        });
    }

    function invert() {
        if (!content) {
            return;
        }
        var rect = content.getBoundingClientRect();
        content.classList.toggle('inverted', rect.height > 0 && rect.top + rect.height / 2 < window.innerHeight / 2);
    }

    /* Where the bar is shown before it is moved: the corner the configuration names (bottom right unless told otherwise). */
    function home() {
        var box = bounds();
        var top = -1 !== POSITION.indexOf('top');
        var left = -1 !== POSITION.indexOf('left');

        return {
            x: left && box ? MARGIN - box.left : 0,
            y: top && box ? MARGIN - box.top : 0
        };
    }

    function restore() {
        var saved = parseInt(read(WIDTH_KEY), 10);
        if ((RESIZABLE || MINIMIZABLE) && saved > 0) {
            content.style.width = clampWidth(saved) + 'px';
        }

        var position = null;
        try {
            position = JSON.parse(read(POSITION_KEY));
        } catch (e) {
        }
        /* The place asked for is kept as it was asked; where it is shown may be nearer (a smaller window, a bar of the page). */
        var start = home();
        x = DRAGGABLE && position ? Number(position.x) || 0 : start.x;
        y = DRAGGABLE && position ? Number(position.y) || 0 : start.y;
        place(x, y);

        invert();
    }

    function acquire() {
        var currentBar = document.querySelector('[data-laravel-profiler-pack].sf-toolbar');
        var currentContent = currentBar ? currentBar.querySelector('.sf-toolbarreset') : null;
        if (!currentBar || !currentContent) {
            return;
        }
        if (currentBar === bar && currentContent === content) {
            return;
        }

        bar = currentBar;
        content = currentContent;
        applied = { x: 0, y: 0 };
        bar.style.transform = '';

        var written = content.style.width;
        content.style.width = '';
        fullWidth = content.offsetWidth ? width() : fullWidth;
        content.style.width = written;

        restore();
    }

    function onLeftEdge(event) {
        if (!isOpen()) {
            return false;
        }
        var rect = content.getBoundingClientRect();
        var edge = 'touch' === event.pointerType ? TOUCH_EDGE : EDGE;

        return event.clientX >= rect.left && event.clientX <= rect.left + edge && event.clientY >= rect.top && event.clientY <= rect.bottom;
    }

    function onHandle(event) {
        return bar && event.target && event.target.closest && event.target.closest('.sf-toolbar-toggle-button') && bar.contains(event.target);
    }

    document.addEventListener('pointerdown', function (event) {
        if (0 !== event.button || !bar) {
            return;
        }

        if (DRAGGABLE && onHandle(event)) {
            /* The state before the clicks of a double click (or the taps of a double tap), each of which opens or closes the toolbar. */
            var now = Date.now();
            if (now - lastDown > DOUBLE_TAP) {
                openBeforeClicks = !bar.classList.contains('sf-toolbar-closed');
            }
            lastDown = now;

            drag = { startX: event.clientX, startY: event.clientY, fromX: applied.x, fromY: applied.y, moved: false, pointer: event.pointerType };
            swallowClick = false;
            if (event.target.setPointerCapture) {
                try {
                    event.target.setPointerCapture(event.pointerId);
                } catch (e) {
                }
            }
            if ('mouse' === event.pointerType) {
                event.preventDefault();
            }

            return;
        }

        if (RESIZABLE && onLeftEdge(event)) {
            resize = { right: content.getBoundingClientRect().right };
            if (event.target.setPointerCapture) {
                try {
                    event.target.setPointerCapture(event.pointerId);
                } catch (e) {
                }
            }
            event.preventDefault();
            event.stopPropagation();
        }
    });

    document.addEventListener('pointermove', function (event) {
        if (drag) {
            var dx = event.clientX - drag.startX;
            var dy = event.clientY - drag.startY;
            if (!drag.moved && Math.abs(dx) <= THRESHOLD && Math.abs(dy) <= THRESHOLD) {
                return;
            }
            drag.moved = true;
            place(drag.fromX + dx, drag.fromY + dy, true);
            invert();

            return;
        }

        if (resize) {
            content.style.width = Math.max(Math.min(MIN_WIDTH, fullWidth), clampWidth(resize.right - event.clientX - chrome())) + 'px';
        }
    });

    function release(event) {
        if (drag) {
            if (drag.moved) {
                var placed = place(drag.fromX + event.clientX - drag.startX, drag.fromY + event.clientY - drag.startY);
                x = placed.x;
                y = placed.y;
                write(POSITION_KEY, JSON.stringify({ x: x, y: y }));
                swallowClick = true;
                invert();
                lastTap = null;
            } else if (MINIMIZABLE && 'mouse' !== drag.pointer && 'pointerup' === event.type) {
                /* A double tap on the handle: what a double click does. */
                var now = Date.now();
                if (lastTap && now - lastTap.time <= DOUBLE_TAP && Math.abs(event.clientX - lastTap.x) < 30 && Math.abs(event.clientY - lastTap.y) < 30) {
                    lastTap = null;
                    tappedFold = now;
                    clickAfterFold = now;
                    foldAside(event.target.closest('.sf-toolbar-toggle-button'));
                } else {
                    lastTap = { time: now, x: event.clientX, y: event.clientY };
                }
            }
            drag = null;
        }

        if (resize) {
            write(WIDTH_KEY, String(Math.round(width())));
            resize = null;
        }
    }

    document.addEventListener('pointerup', release);
    document.addEventListener('pointercancel', release);

    /* The click a phone sends after the second tap of a double tap lands where the handle was: the toolbar has moved away by then, so it would reach the page under it (a link, a cookie panel). It reaches nothing; the toolbar's state is set by foldAside. Caught on the window, before any listener of the page. */
    window.addEventListener('click', function (event) {
        if (clickAfterFold && event.isTrusted && Date.now() - clickAfterFold < 700) {
            clickAfterFold = 0;
            event.stopImmediatePropagation();
            event.preventDefault();
        }
    }, true);

    /* A move that ends on the handle is not a click on it: the toolbar stays open or closed as it was. */
    document.addEventListener('click', function (event) {
        if (!onHandle(event)) {
            swallowClick = false;

            return;
        }
        if (swallowClick) {
            swallowClick = false;
            event.stopImmediatePropagation();
            event.preventDefault();
        }
    }, true);

    /* Sent to the left edge, its handle staying in view; back to its corner when it is there already. Open or closed as it was before the clicks. */
    function foldAside(toggle) {
        var box = bounds();
        var left = box ? MARGIN - box.handleLeft : -(window.innerWidth - 5 * PADDING);
        var aside = applied.x <= left + ASIDE;
        var start = home();
        var placed = place(aside ? start.x : left, start.y);
        x = placed.x;
        y = start.y;
        write(POSITION_KEY, JSON.stringify({ x: x, y: y }));

        window.setTimeout(function () {
            if (toggle && openBeforeClicks === bar.classList.contains('sf-toolbar-closed')) {
                toggle.click();
            }
            settle();
        }, 0);
    }

    if (MINIMIZABLE) {
        document.addEventListener('dblclick', function (event) {
            if (!bar) {
                return;
            }

            if (DRAGGABLE && onHandle(event)) {
                /* Already done by the double tap this double click comes from. */
                if (Date.now() - tappedFold < 600) {
                    return;
                }
                foldAside(event.target.closest('.sf-toolbar-toggle-button'));

                return;
            }

            if (onLeftEdge(event)) {
                var folded = width() <= FOLDED_WIDTH + 1;
                content.style.width = (folded ? Math.max(fullWidth, MIN_WIDTH) : FOLDED_WIDTH) + 'px';
                write(WIDTH_KEY, folded ? null : String(FOLDED_WIDTH));
                resize = null;
                event.preventDefault();
                event.stopPropagation();
            }
        });
    }

    window.addEventListener('resize', settle);
    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', settle);
    }

    /* Opened, closed, filled: the toolbar changes size, measured once its transitions are over; a bar or a panel of the page comes and goes, shown or hidden, slides in, scrolls into view. */
    document.addEventListener('click', function (event) {
        if (onHandle(event)) {
            settle();
        }
    });
    document.addEventListener('transitionend', settle);
    document.addEventListener('animationend', settle);
    window.addEventListener('scroll', settle, { passive: true });

    acquire();
    new MutationObserver(function (records) {
        for (var i = 0; i < records.length; i++) {
            if ('attributes' !== records[i].type || !bar || !bar.contains(records[i].target)) {
                acquire();
                settle();

                return;
            }
        }
    }).observe(document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['hidden', 'class', 'open', 'aria-hidden', 'aria-expanded'] });
})();
