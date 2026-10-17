# How it works

## When it is on

`ProfilerPackServiceProvider` is discovered by Laravel (`extra.laravel.providers`). At boot it asks
`ProfilerPackServiceProvider::active()`:

- in production (`APP_ENV=production`): no, whatever the configuration says;
- `profiler-pack.enabled` set (`PROFILER_PACK_ENABLED`): as it says;
- left at `null`: when the application runs locally with debug on
  (`app()->environment('local') && config('app.debug')`).

Off, nothing is registered. On, the middleware `InjectProfilerBar` is put first in the `web` group,
through the HTTP kernel once the application's own groups are defined (`bootstrap/app.php`), and asks
`active()` again on every answer: an environment changed afterwards turns it off as well.

## Where it is written

The middleware is the first of the `web` group, so the last to see the answer on its way out, after
what the others do then (a session written, cookies queued). It writes the bar right before the
page's last `</body>`, and updates `Content-Length` when there is one. It leaves alone:

- an answer that is not `text/html` (JSON, a file, an image);
- a redirect, a download (`Content-Disposition: attachment`, `BinaryFileResponse`), a streamed answer
  (`StreamedResponse`, `StreamedJsonResponse`);
- an AJAX request (`X-Requested-With: XMLHttpRequest`) and a `HEAD` request;
- Telescope's own pages (`telescope.path`, `telescope-api*`, `vendor/telescope*`) and the paths of
  `profiler-pack.except`;
- a page without `</body>`, or one that carries the bar already.

## What each block reads

Telescope records a request's entries into a queue (`Telescope::$entriesQueue`) while it runs, and
stores the queue as one batch once the answer is sent (`$app->terminating`). The bar is written in
between: it reads that queue, which holds every entry of the batch recorded so far, and the updates
queued for them (`Telescope::$updatesQueue`: a sync job processed). Each entry keeps its uuid when it
is stored, so a link to `/telescope/{type}/{uuid}` opens it.

| Block | Reads | Links to |
| --- | --- | --- |
| Request | the response's status, `$request->route()` (name, URI, action), the session | the request's entry |
| Time | `LARAVEL_START` (or `REQUEST_TIME_FLOAT`) to now, as Telescope's request watcher measures it | the request's entry |
| Memory | `memory_get_peak_usage(true)` in MiB, as Telescope does; `memory_limit` | the request's entry |
| Queries | `query` entries: `time`, `sql`, `slow` | each query |
| Logs | `log` entries: `level`, `message` | each log entry |
| Exceptions | `exception` entries: `class`, `message` | each exception (the only one, from the block) |
| Cache | `cache` entries: `type` (hit, missed, set, forget), `key` | each call |
| Mail | `mail` entries: `to`, `subject` | each mail |
| Jobs | `job` entries: `name`, `connection`, `queue`, `status` (with its update) | each job |
| Views, events, models, HTTP client, notifications, gates, Redis, dumps | their entries | each entry |
| Telescope | `Telescope::isRecording()`, `telescope.enabled`, the pause flag; Laravel's and PHP's versions | `/telescope` |

What happens after the bar is written is in Telescope's batch but not on the bar: the queries of the
global middleware on the way out, the request's own entry, the models hydrated (Telescope records
those once the request is handled). The bar renders without recording: its own view is no entry.

### The request's entry, before Telescope records it

Telescope records the request's entry once the answer is out of the middleware, under a new uuid:
the bar cannot know it. So the bar chooses it. `RequestEntry::name()` gives an ordered uuid when the
bar is written, and a `Telescope::tag()` callback (they run for every entry before it is queued)
gives it to the next `request` entry. Entries are ordered by their sequence, not their uuid: nothing
else changes. When the request watcher is off, or Telescope does not record, there is no such entry:
the blocks link to Telescope's lists instead, or to nothing when Telescope is disabled.

### Telescope off

Not recording (disabled, paused with the pause button, a path it ignores or does not list in
`only_paths`, an Artisan command), Telescope has no entry for the request. The bar then shows what
the request itself tells (status, route, time, memory) and its Telescope block says it is off, and
why.

Telescope's filters (`Telescope::filter()`) run before an entry is queued: the bar shows what they
keep. A batch filter (`Telescope::filterBatch()`) runs once the answer is sent: a batch it drops is on
the bar, not in Telescope.

## The look, and the floating rules

The markup is that of Symfony's web debug toolbar: `.sf-toolbar` > `#sfwdt…` > `.sf-toolbarreset`,
`.sf-toolbar-block`, `.sf-toolbar-icon`, `.sf-toolbar-info`, `.sf-toolbar-toggle-button`. Its base
styles (`resources/css/toolbar.css`) are Symfony's, the blocks it has no use for left out; the
floating ones (`resources/css/floating.css`) and the gestures (`resources/js/floating.js`) are
glitchr/symfony-profiler-pack's, on the same classes, so the two bars look and move the same. What
the Symfony pack's [docs/how-it-works.md](https://github.com/glitchr-studio/symfony-profiler-pack/blob/1.x/docs/how-it-works.md)
says of its toolbar holds for this bar:

- its handle and its right end never leave the window; its body may slide off to the left, which is
  what sending it aside does;
- it stays inside what the browser shows of the page (`window.visualViewport`), above a bar the page
  keeps fixed along the bottom of the window (a `position: fixed` element touching the bottom, at
  least half the window wide, at most 120px high);
- it covers no control of a panel the page shows over itself (a fixed layer under its column, an open
  dialog, `[data-consent-banner]`, `[data-cookie-banner]`, `[data-toolbar-avoid]`): it is shown at the
  nearest height clear of it, upwards first, and goes back once the panel closes;
- at the end of the page it covers no control of the page either, within a climb of 160px; past that,
  within 160px, it takes the height that covers the fewest cookie openers, then leaves the page's last
  line clear (a footer's credits), then covers the fewest fixed or sticky controls, then the fewest
  controls, then the nearest;
- below 768px, or under a finger, it is one piece: the handle (48px under a finger) is the right end of
  the pill, which spans the window less its safe areas; a double tap sends it aside, and the click a
  phone sends after it reaches nothing.

What differs: the options come from `config/profiler-pack.php`, written as JSON on the root
(`data-laravel-profiler-pack`), and `position` names the corner it starts from (and comes back to
from aside) before it is moved.

## What is kept in the browser

Three entries of `localStorage`, per origin, apart from the Symfony pack's:
`laravel-profiler-pack/toolbar/displayState` (`opened` or `closed`),
`laravel-profiler-pack/toolbar/position` (`{"x": …, "y": …}`, an offset from the bottom right corner)
and `laravel-profiler-pack/toolbar/width`. Removing them puts the bar back.

## Cookie panels

Everything the bar shows is inside its root, `<div class="sf-toolbar" … data-cookie-ignore
data-consent-ignore>`, and `#sfwdt…` within it; what it writes beside it is a `<style>` and two
`<script>`s. A cookie panel that tells its own clicks from others by `.sf-toolbar`, `[id^="sfwdt"]`,
`[data-cookie-ignore]` or `[data-consent-ignore]` (omnibase/consent does) leaves the bar's clicks
alone. A test checks the markup for it.

## Nothing reaches the page being debugged

Every rule of the bar's styles starts with a class of the bar (`.sf-toolbar…`) or its `#sfwdt…`
element; a test reads the stylesheets for it. Lengths are counted on the bar's own type size. The
scripts are inline, use `/* */` comments only and end every statement (a test reads them for both),
and take the page's Content-Security-Policy nonce when Vite was given one (`Vite::useCspNonce()`).
