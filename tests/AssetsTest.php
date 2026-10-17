<?php

namespace Glitchr\LaravelProfilerPack\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the bar's styles and scripts promise, read in their source, as the
 * Symfony pack reads its own: the styles reach nothing of the page being
 * debugged, the scripts end every statement and use no line comment (they
 * stay safe to write on one line), and the floating rules are the Symfony
 * pack's: one piece on a phone, a fingertip's handle, inside the window,
 * clear of the page's panels and of its controls at its end.
 */
final class AssetsTest extends TestCase
{
    private const TEMPLATES = __DIR__.'/../resources';

    #[DataProvider('toolbarStylesheets')]
    public function testEveryRuleIsUnderAClassOfTheToolbar(string $file): void
    {
        $css = (string) file_get_contents(self::TEMPLATES.'/'.$file);
        $css = preg_replace(['~\{#.*?#\}~s', '~/\*.*?\*/~s'], '', $css);
        $css = preg_replace('~@media[^{]*\{~', '', $css);

        preg_match_all('~([^{}]+)\{[^{}]*\}~', $css, $rules);
        self::assertNotEmpty($rules[1]);

        foreach ($rules[1] as $selectors) {
            foreach (explode(',', $selectors) as $selector) {
                $selector = trim($selector);
                self::assertMatchesRegularExpression(
                    '~^(div)?(\.sf-(toolbar|minitoolbar|cancel-button)|\[id\^="sfwdt"\])~',
                    $selector,
                    \sprintf('"%s" in %s would style the page being debugged.', $selector, $file),
                );
            }
        }
    }

    public static function toolbarStylesheets(): iterable
    {
        yield ['css/toolbar.css'];
        yield ['css/floating.css'];
    }

    #[DataProvider('scripts')]
    public function testAScriptHasNoLineCommentAndEndsItsStatements(string $file): void
    {
        $js = (string) file_get_contents(self::TEMPLATES.'/'.$file);
        $js = preg_replace('~\{#.*?#\}~s', '', $js);

        self::assertStringNotContainsString('//', $js, 'On one line, a // comment swallows the rest of the script.');
        // Comments between slash-star marks run across lines: what is left is code.
        $js = preg_replace('~/\*.*?\*/~s', '', $js);

        $lines = array_values(array_filter(array_map('rtrim', explode("\n", $js)), static fn (string $line) => '' !== trim($line)));

        foreach ($lines as $number => $line) {
            // The last statement of a block needs no semicolon: a closing brace follows it.
            if (preg_match('~^\s*(/\*.*\*/|\}|\}\)|\{\{.*\}\})$~', $line) || str_starts_with(ltrim($lines[$number + 1] ?? '}'), '}')) {
                continue;
            }
            self::assertMatchesRegularExpression('~[;{},(\[:]$|\*/$|\|\|$|&&$|\+$~', $line, \sprintf('Line %d of %s does not end its statement: "%s".', $number + 1, $file, trim($line)));
        }
    }

    public static function scripts(): iterable
    {
        yield ['js/toolbar.js'];
        yield ['js/floating.js'];
    }

    /**
     * A finger sends no mouse event while it moves: the gestures are pointer
     * events, and the handle does not scroll the page under the finger.
     */
    public function testTheToolbarIsMovedByAFingerAsByTheMouse(): void
    {
        $js = (string) file_get_contents(self::TEMPLATES.'/js/floating.js');
        foreach (['pointerdown', 'pointermove', 'pointerup', 'pointercancel', 'setPointerCapture'] as $used) {
            self::assertStringContainsString($used, $js);
        }
        foreach (['mousedown', 'mousemove', 'mouseup'] as $unused) {
            self::assertStringNotContainsString("'".$unused."'", $js);
        }

        $css = (string) file_get_contents(self::TEMPLATES.'/css/floating.css');
        self::assertMatchesRegularExpression('~\.sf-toolbar-toggle-button\s*\{\s*touch-action:\s*none;~', $css);
        self::assertStringContainsString('@media (pointer: coarse)', $css);
    }

    /**
     * On a narrow window or under a finger the toolbar is one piece: the
     * handle is the pill's right end (no box of its own beside it, which a
     * phone's width pushed apart and out of the window), the pill spans the
     * window and keeps clear of the bottom a phone keeps for itself.
     */
    public function testOnAPhoneTheHandleIsTheEndOfThePill(): void
    {
        $css = (string) file_get_contents(self::TEMPLATES.'/css/floating.css');
        $css = preg_replace(['~\{#.*?#\}~s', '~/\*.*?\*/~s'], '', $css);

        self::assertSame(1, preg_match('~@media \(max-width: 767px\), \(pointer: coarse\) \{(.*?)\n\}~s', $css, $narrow), 'The narrow and touch layout has its block.');
        $rules = self::rulesOf($narrow[1]);

        $handle = $rules['.sf-toolbar:not(.sf-toolbar-closed) .sf-toolbarreset .sf-toolbar-toggle-button'] ?? null;
        self::assertNotNull($handle, 'The open handle is laid out for a phone.');
        self::assertSame('absolute', $handle['position']);
        self::assertSame('0', $handle['right'], 'At the right end of the toolbar...');
        self::assertSame('0', $handle['margin'], '...not pushed beside it by a negative margin.');

        $pill = $rules['.sf-toolbar .sf-toolbarreset'];
        self::assertSame('0 calc(var(--profiler-pack-handle) + var(--profiler-pack-grip)) 0 auto', $pill['margin'], 'The pill ends where the handle starts: its blocks scroll under nothing.');
        self::assertSame('0.25em 0 0.25em 1em', $pill['padding']);
        self::assertStringStartsWith('calc(100% ', $pill['max-width'], 'A width kept from a wider window does not overflow.');
        self::assertSame('none', $pill['transition'], 'Opened or closed at once: the handle stays under the finger.');

        $bar = $rules['.sf-toolbar'];
        self::assertStringContainsString('env(safe-area-inset-bottom', $bar['margin']);
        self::assertStringContainsString('env(safe-area-inset-left', $bar['left']);
        self::assertStringContainsString('env(safe-area-inset-right', $bar['right']);

        $closed = $rules['.sf-toolbar.sf-toolbar-closed .sf-toolbarreset .sf-toolbar-toggle-button'];
        self::assertSame('relative', $closed['position'], 'Closed, the handle alone is the toolbar.');
        self::assertSame('0', $closed['margin']);
    }

    /**
     * Wherever the toolbar is put, kept or restored, its handle stays in the
     * window, inside what the browser shows of the page and above a bar the
     * page keeps along its bottom; the place asked for is kept apart from the
     * place shown, so the toolbar comes back down when that bar goes.
     */
    public function testTheToolbarIsKeptInsideTheWindow(): void
    {
        $js = (string) file_get_contents(self::TEMPLATES.'/js/floating.js');

        foreach (['function bounds()', 'function floor()', 'function pageBottomBar()', 'function settle()', 'window.visualViewport', 'document.elementsFromPoint', "'transitionend'"] as $used) {
            self::assertStringContainsString($used, $js);
        }
        self::assertStringContainsString('var minX = MARGIN - box.handleLeft;', $js, 'Sent aside, the handle stays in view.');
        self::assertStringContainsString('var maxX = Math.max(minX, window.innerWidth - MARGIN - box.right);', $js, 'Its right end never leaves the window.');
        self::assertStringContainsString('var maxY = Math.max(minY, floor() - box.bottom);', $js);
        self::assertStringNotContainsString('Math.min(2 * PADDING', $js, 'The old bound let the handle out on the right.');
    }

    /**
     * Under a finger the handle is a fingertip's target: at least 44px each way
     * (Apple, WCAG; Material asks 48), open as closed.
     */
    public function testUnderAFingerTheHandleIsAFingertipsTarget(): void
    {
        $css = (string) file_get_contents(self::TEMPLATES.'/css/floating.css');
        $css = preg_replace(['~\{#.*?#\}~s', '~/\*.*?\*/~s'], '', $css);

        self::assertSame(2, preg_match_all('~@media \(pointer: coarse\) \{(.*?)\n\}~s', $css, $blocks));
        $coarse = self::rulesOf($blocks[1][1]);

        self::assertGreaterThanOrEqual(44, (int) $coarse['.sf-toolbar']['--profiler-pack-handle'], 'Wide enough.');
        self::assertSame('calc(48px - 0.5em)', $coarse['.sf-toolbar:not(.sf-toolbar-closed) .sf-toolbarreset']['min-height'], 'High enough: the handle spans the pill, 48px with its padding.');

        self::assertSame(1, preg_match('~@media \(max-width: 767px\), \(pointer: coarse\) \{(.*?)\n\}~s', $css, $narrow));
        $rules = self::rulesOf($narrow[1]);
        self::assertSame('0.25em 0 0.25em 1em', $rules['.sf-toolbar .sf-toolbarreset']['padding'], 'The 0.5em taken off the 48px.');
        self::assertSame('content-box', $rules['.sf-toolbar .sf-toolbarreset']['box-sizing']);
        self::assertSame('0', $rules['.sf-toolbar:not(.sf-toolbar-closed) .sf-toolbarreset .sf-toolbar-toggle-button']['top']);
        self::assertSame('0', $rules['.sf-toolbar:not(.sf-toolbar-closed) .sf-toolbarreset .sf-toolbar-toggle-button']['bottom']);

        self::assertSame(1, preg_match('~@media \(pointer: coarse\) \{(.*?)\n\}~s', $css, $first));
        $closed = self::rulesOf($first[1])['.sf-toolbar.sf-toolbar-closed .sf-toolbarreset .sf-toolbar-toggle-button'];
        self::assertGreaterThanOrEqual(44, (int) $closed['height']);
        self::assertGreaterThanOrEqual(44, (int) $closed['width']);
    }

    /**
     * A double tap (or click) on the handle sends the toolbar aside, to the
     * left edge with its handle in view, from wherever it is; once it is
     * there, the next one brings it back to its corner.
     */
    public function testADoubleTapSendsTheToolbarAsideThenBack(): void
    {
        $js = (string) file_get_contents(self::TEMPLATES.'/js/floating.js');

        self::assertStringContainsString('var left = box ? MARGIN - box.handleLeft : -(window.innerWidth - 5 * PADDING);', $js, 'Aside: the handle at the left edge.');
        self::assertStringContainsString('var aside = applied.x <= left + ASIDE;', $js, 'Back only from there, not from half way.');
        self::assertStringContainsString('var placed = place(aside ? start.x : left, start.y);', $js, 'Back to the corner it starts from.');
        self::assertStringNotContainsString('left / 2', $js, 'A toolbar dragged half way left was taken for aside and sent back.');
        self::assertSame(2, substr_count($js, 'foldAside(event.target.closest(\'.sf-toolbar-toggle-button\'));'), 'From a double tap and from a double click.');

        /* The click a phone sends after the second tap lands where the handle was, on the page under it by then. */
        self::assertSame(1, preg_match("~window\\.addEventListener\\('click', function \\(event\\) \\{\\s*if \\(clickAfterFold && event\\.isTrusted && Date\\.now\\(\\) - clickAfterFold < \\d+\\) \\{\\s*clickAfterFold = 0;\\s*event\\.stopImmediatePropagation\\(\\);\\s*event\\.preventDefault\\(\\);~", $js), 'It reaches nothing, caught on the window before any listener of the page.');
        self::assertStringContainsString("tappedFold = now;\n                    clickAfterFold = now;", $js);
    }

    /**
     * The toolbar covers no control of a panel the page shows over itself (a
     * cookie question floating at the bottom, a dialog): a layer fixed in the
     * window found under the toolbar's column, or an open dialog wherever it
     * is, holding a control. Shown at the nearest height clear of it, the
     * place asked for kept, it goes back once the panel is closed; a move is
     * kept clear once let go, not while it is made.
     */
    public function testTheToolbarKeepsClearOfThePagesPanels(): void
    {
        $js = (string) file_get_contents(self::TEMPLATES.'/js/floating.js');

        foreach (['function panels(left, right)', 'function nearestClear(', 'function clearOfPanels(', 'document.querySelectorAll(PANELS)', "'fixed' === window.getComputedStyle(el).position"] as $used) {
            self::assertStringContainsString($used, $js);
        }
        self::assertSame(1, preg_match("~var PANELS = '([^']+)';~", $js, $panels));
        foreach (['dialog[open]', '[role="dialog"]', '[role="alertdialog"]', '[aria-modal="true"]', '[data-consent-banner]', '[data-cookie-banner]', '[data-toolbar-avoid]'] as $selector) {
            self::assertStringContainsString($selector, $panels[1]);
        }
        self::assertStringContainsString('[tabindex]:not([tabindex="-1"])', $js, 'A panel focusable as a whole is not a control.');
        self::assertStringContainsString('(rect.width >= width * PAGE && rect.height >= height * PAGE)', $js, 'A page laid out in a fixed layer is not a panel.');
        self::assertStringContainsString('if (list[i].bottom - list[i].top < window.innerHeight * TALL) {', $js, 'A side rail is kept clear of by its controls, not sent over the top.');

        /* Shown clear of the panels, kept where it was asked. */
        self::assertStringContainsString("var shown = moving ? clamped : clampPosition(nx, ny, true);\n        bar.style.transform = 'translate(' + shown.x + 'px, ' + shown.y + 'px)';\n        applied = shown;\n\n        return clamped;", $js);
        self::assertStringContainsString('place(drag.fromX + dx, drag.fromY + dy, true);', $js, 'Not while it is being moved.');

        /* A panel shown or hidden, sliding in, scrolled into view. */
        self::assertStringContainsString("attributes: true, attributeFilter: ['hidden', 'class', 'open', 'aria-hidden', 'aria-expanded']", $js);
        self::assertStringContainsString("document.addEventListener('animationend', settle);", $js);
        self::assertStringContainsString("window.addEventListener('scroll', settle, { passive: true });", $js);
    }

    /** @return array<string, array<string, string>> each selector of a block of rules, with its declarations */
    private static function rulesOf(string $css): array
    {
        preg_match_all('~([^{}]+)\{([^{}]*)\}~', $css, $matches, \PREG_SET_ORDER);
        $rules = [];
        foreach ($matches as [, $selectors, $body]) {
            $declarations = [];
            foreach (array_filter(array_map('trim', explode(';', $body))) as $declaration) {
                [$property, $value] = array_map('trim', explode(':', $declaration, 2));
                $declarations[$property] = $value;
            }
            foreach (explode(',', $selectors) as $selector) {
                $rules[trim($selector)] = $declarations + ($rules[trim($selector)] ?? []);
            }
        }

        return $rules;
    }

    /**
     * At the end of the page nothing scrolls out from under the toolbar any
     * more: a control of the page itself there (glitchr's footer cookie circle,
     * on a phone) was under it, and a tap on it opened the profiler.
     */
    public function testAtTheEndOfThePageTheToolbarCoversNoControlOfThePage(): void
    {
        $js = (string) file_get_contents(self::TEMPLATES.'/js/floating.js');

        self::assertStringContainsString('function atPageEnd()', $js);
        self::assertMatchesRegularExpression('~if \(atPageEnd\(\)\) \{\s+var own = document\.querySelectorAll\(CONTROLS\);~', $js);
        // A control measured with what it holds (latoucheoriginale's footer icons spill out of their links' line).
        self::assertStringContainsString('var rect = drawnRect(own[k]);', $js);
        // A short climb only (glitchr's intro: the toolbar rose to the top of the window to clear a column of links).
        self::assertSame(1, preg_match('~var CLIMB = (\d+);~', $js, $climb));
        self::assertLessThanOrEqual(200, (int) $climb[1]);
        self::assertStringContainsString('if (ny - y > CLIMB) {', $js);
        // Past it, within CLIMB, the page's last line is left clear first (a footer's credits: wineseller's lay under it), then as few controls as can be.
        self::assertStringContainsString('y = fewestCovered(own, panelsOnly, panelsOnly.length ? solve(panelsOnly) : ny);', $js);
        self::assertStringContainsString('var low = Math.max(minY, y - CLIMB);', $js);
        // Cookie openers first (genealogist's circle opened the profiler), then the last line, then the count.
        // A fixed control is covered after an ordinary link (nakaya-gourmet's "Haut de page").
        self::assertStringContainsString('var rank = [consent, lastLine, fixed, count, -ty];', $js);
        // A control fixed or stuck in the window is kept clear of (latoucheoriginale's bottom bar, nakaya's "back to top") but is no part of the last line (youtube's bottom navigation); one under another layer is out of reach (glitchr's footer under its intro).
        self::assertStringContainsString("pinned: pinned(own[k]), consent: !!own[k].closest(CONSENT)", $js);
        self::assertStringContainsString('lastLine = !own[r].pinned && own[r].bottom > last - LINE ? 1 : lastLine;', $js);
        self::assertStringContainsString('if (rect && across(rect) && !hidden(own[k], rect)) {', $js);
        self::assertStringContainsString("var CONSENT = '[data-consent-open], [data-cookie-open], [data-consent], [data-cookie]';", $js);
    }
}
