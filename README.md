# Laravel Profiler Pack

A debug bar for Laravel, read from [Laravel Telescope](https://laravel.com/docs/telescope): at the
bottom of every page, the status of the request, its route, its time and memory, its queries, logs,
exceptions, cache calls, mail and jobs, each block a link into `/telescope` at the entry. It floats
over the page as [glitchr/symfony-profiler-pack](https://github.com/glitchr-studio/symfony-profiler-pack)
floats Symfony's web debug toolbar, and looks and moves the same.

Laravel ships no such bar. Telescope records everything, on pages of its own; this pack puts what it
recorded for the page in front of you on the page itself.

## What it shows

- **Request.** The status code, `@` the route's name (or its URI); in its details the controller, the
  route, the session. A click opens Telescope's entry of this very request.
- **Time and memory.** From the start of the request to the bar, and the peak memory, as Telescope
  measures them.
- **Queries.** How many, in how long, how many different statements, the slow ones in red; each one
  a link to its entry.
- **Logs, exceptions.** Counted by level, a warning yellow, an error or an exception red; each
  message a link to its entry.
- **Cache.** Hits out of reads (`3 / 4 hits`), misses, writes, deletes; each key a link.
- **Mail, jobs, views, events, models, HTTP client calls, notifications, gates, Redis, dumps**: a block
  each when the request has any.
- **Telescope.** Recording or off, and why (disabled, paused, a path it does not record). Off, the bar
  still shows what the request itself tells: its status, route, time and memory.

## The bar

- **It floats.** A rounded bar over the bottom of the page, in place of a strip across it.
- **Move it.** Hold the handle at its right end and drag, with the mouse or a finger. Dragged into
  the upper half of the window, it opens its details downwards.
- **Resize it.** Pull its left edge. Double-click that edge to fold it to its first block, again to
  unfold.
- **Send it aside.** Double-click the handle (double-tap it on a phone): the bar slides off to the
  left, its handle staying in view; do it again to bring it back.
- **It remembers.** Open or closed, place and width are kept by the browser, from one page to the
  next.
- **It stays in the window**, inside what the browser shows of the page, above a bar the page keeps
  fixed along the bottom of the window.
- **It covers no button of the page's panels** (a cookie question, a dialog, a chat button), and at
  the end of the page none of its links either, within a short climb: it is shown just above them.
- **On a phone, one piece.** Below 768px, or under a finger, the handle is the right end of the pill,
  48px wide; the pill spans the window and its blocks scroll sideways inside it.

A plain click on the handle opens and closes the bar.

## Install

```bash
composer require --dev glitchr/laravel-profiler-pack
```

Telescope comes with it. If it is new to the application, install it and create its table:

```bash
php artisan telescope:install
php artisan migrate
```

That is all. Laravel discovers the pack, and the bar is on wherever the application runs locally
with debug on (`APP_ENV=local`, `APP_DEBUG=true`).

Telescope keeps its entries in the default database connection (`DB_CONNECTION`). An application
whose default connection is not the one to write them in (a database it only reads) names another:

```dotenv
TELESCOPE_DB_CONNECTION=sqlite
```

with, in `config/telescope.php`, `'connection' => env('TELESCOPE_DB_CONNECTION', env('DB_CONNECTION', 'mysql'))`.

Telescope's log watcher records from the level `error` up, as it is published. For every log entry
on the bar, set its level to `debug`, in `config/telescope.php`:

```php
Watchers\LogWatcher::class => [
    'enabled' => env('TELESCOPE_LOG_WATCHER', true),
    'level' => 'debug',
],
```

Requires PHP 8.2, Laravel 12 or 13 (Laravel 11 is past its security fixes: Composer refuses it), Telescope 5, and a browser that knows the CSS `:has()`
selector (Chrome 105, Safari 15.4, Firefox 121).

## Never in production

The bar is never written in production (`APP_ENV=production`), whatever the configuration says: a
pack installed there by mistake, with `--dev` forgotten, shows nothing. Elsewhere (a staging server,
say) it is shown when asked for:

```dotenv
PROFILER_PACK_ENABLED=true
```

and `PROFILER_PACK_ENABLED=false` turns it off locally.

## Configuration

To change more, publish the configuration:

```bash
php artisan vendor:publish --tag=profiler-pack-config
```

```php
// config/profiler-pack.php
return [
    'enabled' => env('PROFILER_PACK_ENABLED'),   // null: locally with debug on; never in production
    'position' => 'bottom-right',                // where it starts: bottom-right, bottom-left, top-right, top-left
    'floating' => true,                          // false lays it across the bottom of the window, as a strip
    'draggable' => true,                         // moved by its handle
    'resizable' => true,                         // resized by its left edge
    'minimizable' => true,                       // the two double clicks
    'hide_when' => null,                         // a CSS selector: while <html> or <body> matches it, the bar is hidden
    'except' => [],                              // paths it is never written into: ['admin/*']
    'max_items' => 10,                           // entries listed in a block's details
];
```

`draggable`, `resizable` and `minimizable` belong to the floating bar: with `floating: false` they do
nothing. `hide_when` is for pages that animate their own changes: `'html.is-loading'` hides the bar
while a transition runs.

## Where it is written, and where not

Into HTML pages answered by a route of the `web` group, right before `</body>`. Not into JSON, a
redirect, a download, a streamed answer, an AJAX request, a page without `</body>`, Telescope's own
pages, or a path of `except`.

## Cookie panels

Everything the bar shows is inside its root element, which carries the classes and attributes a
cookie panel already sets aside: `.sf-toolbar`, an `#sfwdt…` element, `data-cookie-ignore` and
`data-consent-ignore`. A panel that closes on a click elsewhere (omnibase/consent's) takes a click
on the bar for none of its own.

## How it works

The details (what each block reads, how the request's entry is linked before Telescope records it,
the floating rules) are in [docs/how-it-works.md](docs/how-it-works.md).

## Tests

```bash
composer install
vendor/bin/phpunit
```

## Licence

MIT. The bar's markup and base styles are those of Symfony's web debug toolbar (MIT, Fabien
Potencier), its icons are [Tabler Icons](https://tabler.io/icons) (MIT), its floating design was
first written for [omnibase](https://github.com/glitchr-studio/omnibase).
