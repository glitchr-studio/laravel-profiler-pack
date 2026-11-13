# Changelog

## Unreleased

## [1.0.0] - 2026-11-13

The first tagged release: `composer require --dev glitchr/laravel-profiler-pack` installs it in a project with
Composer's default minimum-stability. PHP 8.2, Laravel 12 or 13, Laravel Telescope 5.

### Added

- A debug bar read from Laravel Telescope at the bottom of every page, on by default where the application runs
  locally with debug on: the request's status and route, its time and memory, its queries, logs, exceptions, cache
  calls, mail, jobs, views, events, models, HTTP client calls, notifications, gates, Redis and dumps, each block a
  link to its entry in `/telescope`; Telescope's own state (recording, paused, a path it does not record).
- The bar floats as glitchr/symfony-profiler-pack's toolbar does: moved by its handle, resized by its left edge,
  folded, sent aside, its place kept by the browser, inside the window and clear of the page's panels; one piece on
  a phone.
- Discovered by Laravel (`extra.laravel.providers`).

### Changed

- CI: actions pinned by commit, the workflow callable; `release.yml` publishes a tag after the CI passes on it.
