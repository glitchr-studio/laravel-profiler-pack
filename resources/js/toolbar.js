/* The bar's own behaviour, as Symfony's toolbar script gives it to its
   toolbar: the handle opens and closes the bar, the state kept in
   localStorage (laravel-profiler-pack/toolbar/displayState), and a block's
   details are laid inside the window when they open. Comments between
   slash-star marks only, and every statement ended, as in the pack's other
   scripts. */
(function () {
    'use strict';

    var STATE_KEY = 'laravel-profiler-pack/toolbar/displayState';

    var roots = document.querySelectorAll('[data-laravel-profiler-pack]');
    var root = roots.length ? roots[roots.length - 1] : null;
    if (!root || root.__laravelProfilerPackBar) {
        return;
    }
    root.__laravelProfilerPackBar = true;

    function read() {
        try {
            return window.localStorage.getItem(STATE_KEY);
        } catch (e) {
            return null;
        }
    }

    function write(value) {
        try {
            window.localStorage.setItem(STATE_KEY, value);
        } catch (e) {
        }
    }

    var toggle = root.querySelector('.sf-toolbar-toggle-button');

    function show(opened) {
        root.classList.toggle('sf-toolbar-opened', opened);
        root.classList.toggle('sf-toolbar-closed', !opened);
        if (toggle) {
            toggle.setAttribute('aria-expanded', opened ? 'true' : 'false');
        }
    }

    show('closed' !== read());

    if (toggle) {
        toggle.addEventListener('click', function (event) {
            event.preventDefault();
            var opened = 'closed' === read();
            write(opened ? 'opened' : 'closed');
            show(opened);
        });
    }

    /* A block's details inside the page's width: from the block's left, or its right, or the page's left edge. */
    function positionInfo() {
        var info = this.querySelector('.sf-toolbar-info');
        if (!info) {
            return;
        }
        var pageWidth = document.body.clientWidth;
        var elementWidth = info.offsetWidth;
        var leftValue = (elementWidth + this.offsetLeft) - pageWidth;
        var rightValue = (elementWidth + (pageWidth - this.offsetLeft)) - pageWidth;

        info.style.right = '';
        info.style.left = '';

        if (elementWidth > pageWidth) {
            info.style.left = 0;
        } else if (leftValue > 0 && rightValue > 0) {
            info.style.right = (rightValue * -1) + 'px';
        } else if (leftValue < 0) {
            info.style.left = 0;
        } else {
            info.style.right = '0px';
        }
    }

    var blocks = root.querySelectorAll('.sf-toolbar-block');
    for (var i = 0; i < blocks.length; i++) {
        blocks[i].addEventListener('mouseover', positionInfo);
        blocks[i].addEventListener('focusin', positionInfo);
    }

    /* Escape leaves a block whose details the keyboard opened. */
    root.addEventListener('keydown', function (event) {
        if ('Escape' === event.key && document.activeElement && root.contains(document.activeElement)) {
            document.activeElement.blur();
        }
    });
})();
