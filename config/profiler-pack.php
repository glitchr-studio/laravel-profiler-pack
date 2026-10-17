<?php

return [

    /*
     * The bar is shown when this is true, and not when it is false. Left at
     * null, it is shown when the application runs locally with debug on
     * (APP_ENV=local, APP_DEBUG=true). It is never shown in production,
     * whatever this says: a package installed there by mistake shows nothing.
     */
    'enabled' => env('PROFILER_PACK_ENABLED'),

    /*
     * Where the bar is first shown, before it is moved (the browser keeps the
     * place it is moved to): bottom-right, bottom-left, top-right, top-left.
     * In the upper half of the window its details open downwards.
     */
    'position' => env('PROFILER_PACK_POSITION', 'bottom-right'),

    /* The bar floats over the page; false lays it across the bottom of the window as a strip. */
    'floating' => true,

    /* The floating bar moves by its handle; its place is remembered by the browser. */
    'draggable' => true,

    /* The floating bar is made narrower or wider by its left edge; its width is remembered. */
    'resizable' => true,

    /* A double click on the left edge folds the bar, another unfolds it; one on the handle sends it aside. */
    'minimizable' => true,

    /*
     * A CSS selector: while <html> or <body> matches it, the bar is hidden
     * (a page transition, say: 'html.is-loading'). One selector, no "{", "}",
     * "<", ">" or ";".
     */
    'hide_when' => null,

    /* Paths the bar is never written into, as Request::is() reads them ('admin/*'). */
    'except' => [],

    /* How many entries of each kind a block's details list (queries, logs, cache keys...). */
    'max_items' => 10,

];
