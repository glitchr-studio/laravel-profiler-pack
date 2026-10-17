<?php

namespace Glitchr\LaravelProfilerPack;

use Composer\InstalledVersions;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Telescope\EntryType;
use Laravel\Telescope\EntryUpdate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\Watchers;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The bar of one answer: what the request itself tells (status, route, time,
 * memory) and what Telescope has recorded for it so far (its entries queue:
 * the entries it will store as this request's batch once the answer is sent),
 * each block a link into Telescope at the entry.
 */
final class ProfilerBar
{
    /** Telescope's pages, by entry type. */
    private const PAGES = [
        EntryType::REQUEST => 'requests',
        EntryType::QUERY => 'queries',
        EntryType::LOG => 'logs',
        EntryType::EXCEPTION => 'exceptions',
        EntryType::CACHE => 'cache',
        EntryType::MAIL => 'mail',
        EntryType::JOB => 'jobs',
        EntryType::VIEW => 'views',
        EntryType::EVENT => 'events',
        EntryType::MODEL => 'models',
        EntryType::CLIENT_REQUEST => 'client-requests',
        EntryType::NOTIFICATION => 'notifications',
        EntryType::REDIS => 'redis',
        EntryType::GATE => 'gates',
        EntryType::DUMP => 'dumps',
        EntryType::BATCH => 'batches',
    ];

    private const ERRORS = ['emergency', 'alert', 'critical', 'error'];

    public function __construct(
        private readonly Application $app,
        private readonly RequestEntry $requestEntry,
    ) {
    }

    /** The bar's markup, styles and scripts, to write before the page's </body>. */
    public function render(Request $request, Response $response): string
    {
        $blocks = $this->blocks($request, $response);

        $render = fn () => view('profiler-pack::toolbar', [
            'blocks' => $blocks,
            'token' => substr(md5(uniqid('', true)), 0, 6),
            'options' => $this->options(),
            'hideWhen' => config('profiler-pack.hide_when'),
            'styles' => $this->asset('css/toolbar.css').(config('profiler-pack.floating', true) ? "\n".$this->asset('css/floating.css') : ''),
            'script' => $this->asset('js/toolbar.js'),
            'floating' => $this->gestures() ? $this->asset('js/floating.js') : null,
            'nonce' => $this->nonce(),
            'icons' => fn (string $name) => $this->icon($name),
        ])->render();

        // Telescope records nothing of the bar itself (its view, say).
        return class_exists(Telescope::class) ? Telescope::withoutRecording($render) : $render();
    }

    /**
     * The blocks, left to right; each one a name, a status (green, yellow, red
     * or null), an icon, a value and a label, a link and its details (groups
     * of pieces: a label, a text, maybe a link and a status).
     *
     * @return list<array<string, mixed>>
     */
    public function blocks(Request $request, Response $response): array
    {
        $telescope = $this->telescope();
        $entries = $telescope['recording'] ? $this->entries() : [];
        $requestLink = null;
        if ($telescope['recording'] && $telescope['watches'](Watchers\RequestWatcher::class)) {
            $requestLink = $this->link(EntryType::REQUEST, $this->requestEntry->name());
        }
        $fallback = fn (string $type) => $requestLink ?? $this->link($type);

        $blocks = [
            $this->requestBlock($request, $response, $requestLink ?? $this->link(EntryType::REQUEST)),
            $this->timeBlock($request, $fallback(EntryType::REQUEST)),
            $this->memoryBlock($fallback(EntryType::REQUEST)),
        ];

        if ($telescope['recording']) {
            $blocks[] = $this->queriesBlock($entries[EntryType::QUERY] ?? [], $fallback(EntryType::QUERY), $telescope['watches'](Watchers\QueryWatcher::class));
            foreach ([
                EntryType::LOG => 'logsBlock',
                EntryType::EXCEPTION => 'exceptionsBlock',
                EntryType::CACHE => 'cacheBlock',
                EntryType::MAIL => 'mailBlock',
                EntryType::JOB => 'jobsBlock',
                EntryType::VIEW => 'viewsBlock',
                EntryType::EVENT => 'eventsBlock',
                EntryType::MODEL => 'modelsBlock',
                EntryType::CLIENT_REQUEST => 'clientRequestsBlock',
                EntryType::NOTIFICATION => 'otherBlock',
                EntryType::GATE => 'otherBlock',
                EntryType::REDIS => 'otherBlock',
                EntryType::DUMP => 'otherBlock',
            ] as $type => $method) {
                if (!empty($entries[$type])) {
                    $blocks[] = $this->{$method}($entries[$type], $fallback($type), $type);
                }
            }
        }

        $blocks[] = $this->telescopeBlock($telescope);

        return $blocks;
    }

    /**
     * Telescope's state for this request: recording or not and why, which
     * watchers are on, how its log watcher filters.
     *
     * @return array{installed: bool, enabled: bool, recording: bool, reason: ?string, watches: \Closure(string): bool, log_level: ?string, base: ?string}
     */
    private function telescope(): array
    {
        $installed = class_exists(Telescope::class);
        $enabled = $installed && (bool) config('telescope.enabled');
        $recording = $enabled && Telescope::isRecording();
        $reason = null;
        if (!$installed) {
            $reason = 'not installed';
        } elseif (!$enabled) {
            $reason = 'disabled (telescope.enabled)';
        } elseif (!$recording) {
            $paused = false;
            try {
                $paused = (bool) cache('telescope:pause-recording');
            } catch (Throwable) {
            }
            $reason = $paused ? 'paused' : 'not recording this request';
        }

        $level = null;
        foreach ((array) config('telescope.watchers', []) as $watcher => $options) {
            if (Watchers\LogWatcher::class === $watcher && \is_array($options)) {
                $level = $options['level'] ?? 'debug';
            }
        }

        return [
            'installed' => $installed,
            'enabled' => $enabled,
            'recording' => $recording,
            'reason' => $reason,
            'watches' => static fn (string $watcher) => $installed && Telescope::hasWatcher($watcher),
            'log_level' => $level,
            'base' => $enabled ? $this->telescopeBase() : null,
        ];
    }

    /**
     * Telescope's entries queued for this request, by type, each with its
     * updates (a sync job processed) applied.
     *
     * @return array<string, list<IncomingEntry>>
     */
    private function entries(): array
    {
        $changes = [];
        foreach (Telescope::$updatesQueue as $update) {
            if ($update instanceof EntryUpdate) {
                $changes[$update->uuid] = array_merge($changes[$update->uuid] ?? [], (array) $update->changes);
            }
        }

        $byType = [];
        foreach (Telescope::$entriesQueue as $entry) {
            if (!$entry instanceof IncomingEntry || null === $entry->type) {
                continue;
            }
            if (isset($changes[$entry->uuid])) {
                $entry = clone $entry;
                $entry->content = array_merge($entry->content, $changes[$entry->uuid]);
            }
            $byType[$entry->type][] = $entry;
        }

        return $byType;
    }

    private function requestBlock(Request $request, Response $response, ?string $link): array
    {
        $status = $response->getStatusCode();
        $route = $request->route();
        $name = $route?->getName();
        $uri = $route ? '/'.ltrim($route->uri(), '/') : '/'.ltrim($request->path(), '/');
        $action = $route ? $route->getActionName() : 'n/a';
        $method = $request->getMethod();

        $pieces = [
            ['HTTP status', $status.' '.(Response::$statusTexts[$status] ?? '')],
            ...('GET' !== $method ? [['Method', $method]] : []),
            ['Controller', $this->shortAction($action)],
            ['Route name', $name ?? 'n/a'],
            ['Route', $uri],
            ['Has session', $request->hasSession() && $request->session()->isStarted() ? 'yes' : 'no'],
        ];
        if (null !== $link) {
            $pieces[] = ['Telescope', 'This request', $link];
        }

        return [
            'name' => 'request',
            'status' => $status >= 400 ? 'red' : ($status >= 300 ? 'yellow' : 'green'),
            'badge' => (string) $status,
            'label' => ('GET' !== $method ? $method.' ' : '').'@',
            'value' => $name ?? $uri,
            'value_additional' => true,
            'link' => $link,
            'groups' => [$pieces],
        ];
    }

    private function timeBlock(Request $request, ?string $link): array
    {
        $start = \defined('LARAVEL_START') ? LARAVEL_START : (float) $request->server('REQUEST_TIME_FLOAT', microtime(true));
        $ms = max(0, (microtime(true) - $start) * 1000);

        return [
            'name' => 'time',
            'status' => $ms > 1000 ? 'yellow' : null,
            'icon' => 'time',
            'value' => number_format($ms, 0, '.', ''),
            'label' => 'ms',
            'link' => $link,
            'groups' => [[
                ['Total time', number_format($ms, 0, '.', '').' ms'],
                ['Measured', 'from the start of the request to the writing of this bar'],
            ]],
        ];
    }

    private function memoryBlock(?string $link): array
    {
        // Telescope's own measure of a request's memory.
        $peak = round(memory_get_peak_usage(true) / 1024 / 1024, 1);
        $limit = (string) \ini_get('memory_limit');
        $bytes = $this->bytes($limit);

        return [
            'name' => 'memory',
            'status' => $bytes > 0 && $peak * 1024 * 1024 > $bytes / 2 ? 'yellow' : null,
            'icon' => 'memory',
            'value' => $this->number($peak),
            'label' => 'MiB',
            'link' => $link,
            'groups' => [[
                ['Peak memory usage', $this->number($peak).' MiB'],
                ['PHP memory limit', '-1' === $limit ? 'Unlimited' : $limit],
            ]],
        ];
    }

    /** @param list<IncomingEntry> $queries */
    private function queriesBlock(array $queries, ?string $link, bool $watched): array
    {
        if (!$watched) {
            return [
                'name' => 'queries', 'status' => null, 'icon' => 'database', 'value' => 'n/a', 'label' => '', 'link' => $link,
                'groups' => [[['Queries', 'not recorded: Telescope\'s query watcher is off']]],
            ];
        }

        $time = 0.0;
        $slow = 0;
        $statements = [];
        $list = [];
        foreach ($queries as $query) {
            $time += (float) ($query->content['time'] ?? 0);
            $slow += !empty($query->content['slow']) ? 1 : 0;
            $statements[(string) ($query->content['sql'] ?? '')] = true;
            $list[] = [$this->number((float) ($query->content['time'] ?? 0), 2).' ms', Str::limit((string) ($query->content['sql'] ?? ''), 140), $this->link(EntryType::QUERY, $query->uuid), !empty($query->content['slow']) ? 'red' : null];
        }
        $count = \count($queries);

        return [
            'name' => 'queries',
            'status' => $slow > 0 ? 'red' : ($count > 50 ? 'yellow' : null),
            'icon' => 'database',
            'value' => (string) $count,
            'label' => 'in '.$this->number($time, 2).' ms',
            'link' => $link,
            'groups' => array_values(array_filter([
                [
                    ['Database queries', (string) $count],
                    ['Different statements', (string) \count($statements)],
                    ['Query time', $this->number($time, 2).' ms'],
                    ...($slow ? [['Slow queries', (string) $slow, null, 'red']] : []),
                ],
                $this->limited($list),
            ])),
        ];
    }

    /** @param list<IncomingEntry> $logs */
    private function logsBlock(array $logs, ?string $link): array
    {
        $levels = [];
        $list = [];
        foreach ($logs as $log) {
            $level = strtolower((string) ($log->content['level'] ?? 'info'));
            $levels[$level] = ($levels[$level] ?? 0) + 1;
            $list[] = [$level, Str::limit((string) ($log->content['message'] ?? ''), 140), $this->link(EntryType::LOG, $log->uuid), $this->levelStatus($level)];
        }
        $errors = array_sum(array_intersect_key($levels, array_flip(self::ERRORS)));
        $warnings = $levels['warning'] ?? 0;
        $level = $this->telescope()['log_level'];

        $counts = [];
        foreach ($levels as $name => $count) {
            $counts[] = [ucfirst($name), (string) $count, null, $this->levelStatus($name)];
        }
        if (null !== $level && 'debug' !== $level) {
            $counts[] = ['Recorded', 'from level '.$level.' up (telescope.watchers, LogWatcher)'];
        }

        return [
            'name' => 'logger',
            'status' => $errors ? 'red' : ($warnings ? 'yellow' : null),
            'icon' => 'logger',
            'value' => (string) \count($logs),
            'label' => '',
            'link' => $link,
            'groups' => array_values(array_filter([$counts, $this->limited($list)])),
        ];
    }

    /** @param list<IncomingEntry> $exceptions */
    private function exceptionsBlock(array $exceptions, ?string $link): array
    {
        $list = [];
        foreach ($exceptions as $exception) {
            $class = (string) ($exception->content['class'] ?? 'Exception');
            $list[] = [class_basename($class), Str::limit((string) ($exception->content['message'] ?? ''), 140), $this->link(EntryType::EXCEPTION, $exception->uuid), 'red'];
        }

        return [
            'name' => 'exception',
            'status' => 'red',
            'icon' => 'exception',
            'value' => (string) \count($exceptions),
            'label' => '',
            'link' => 1 === \count($exceptions) ? $this->link(EntryType::EXCEPTION, $exceptions[0]->uuid) : $link,
            'groups' => [$this->limited($list)],
        ];
    }

    /** @param list<IncomingEntry> $calls */
    private function cacheBlock(array $calls, ?string $link): array
    {
        $types = ['hit' => 0, 'missed' => 0, 'set' => 0, 'forget' => 0];
        $list = [];
        foreach ($calls as $call) {
            $type = (string) ($call->content['type'] ?? '');
            $types[$type] = ($types[$type] ?? 0) + 1;
            $list[] = [$type, Str::limit((string) ($call->content['key'] ?? ''), 140), $this->link(EntryType::CACHE, $call->uuid), 'missed' === $type ? 'yellow' : null];
        }
        $reads = $types['hit'] + $types['missed'];

        return [
            'name' => 'cache',
            'status' => null,
            'icon' => 'cache',
            'value' => (string) $types['hit'],
            'label' => '/ '.$reads.' hits',
            'link' => $link,
            'groups' => array_values(array_filter([
                [
                    ['Cache calls', (string) \count($calls)],
                    ['Hits', (string) $types['hit']],
                    ['Misses', (string) $types['missed'], null, $types['missed'] ? 'yellow' : null],
                    ['Writes', (string) $types['set']],
                    ['Deletes', (string) $types['forget']],
                    ...($reads ? [['Hit ratio', round(100 * $types['hit'] / $reads).' %']] : []),
                ],
                $this->limited($list),
            ])),
        ];
    }

    /** @param list<IncomingEntry> $mails */
    private function mailBlock(array $mails, ?string $link): array
    {
        $list = [];
        foreach ($mails as $mail) {
            $to = implode(', ', array_keys((array) ($mail->content['to'] ?? [])));
            $list[] = [Str::limit($to, 40) ?: 'no recipient', Str::limit((string) ($mail->content['subject'] ?? ''), 100), $this->link(EntryType::MAIL, $mail->uuid)];
        }

        return $this->countBlock('mailer', 'mailer', $mails, $link, 'Messages sent', $list);
    }

    /** @param list<IncomingEntry> $jobs */
    private function jobsBlock(array $jobs, ?string $link): array
    {
        $list = [];
        $failed = 0;
        foreach ($jobs as $job) {
            $status = (string) ($job->content['status'] ?? 'pending');
            $failed += 'failed' === $status ? 1 : 0;
            $where = trim(($job->content['connection'] ?? '').' / '.($job->content['queue'] ?? 'default'), ' /');
            $list[] = [$status, class_basename((string) ($job->content['name'] ?? 'Job')).($where ? ' ('.$where.')' : ''), $this->link(EntryType::JOB, $job->uuid), 'failed' === $status ? 'red' : ('processed' === $status ? 'green' : null)];
        }

        $block = $this->countBlock('jobs', 'jobs', $jobs, $link, 'Jobs dispatched', $list);
        $block['status'] = $failed ? 'red' : null;

        return $block;
    }

    /** @param list<IncomingEntry> $views */
    private function viewsBlock(array $views, ?string $link): array
    {
        $list = [];
        foreach ($views as $view) {
            $list[] = ['view', (string) ($view->content['name'] ?? ''), $this->link(EntryType::VIEW, $view->uuid)];
        }

        return $this->countBlock('views', 'views', $views, $link, 'Views rendered', $list);
    }

    /** @param list<IncomingEntry> $events */
    private function eventsBlock(array $events, ?string $link): array
    {
        $list = [];
        foreach ($events as $event) {
            $list[] = [\count((array) ($event->content['listeners'] ?? [])).' listeners', Str::limit((string) ($event->content['name'] ?? ''), 120), $this->link(EntryType::EVENT, $event->uuid)];
        }

        return $this->countBlock('events', 'event', $events, $link, 'Events dispatched', $list);
    }

    /** @param list<IncomingEntry> $models */
    private function modelsBlock(array $models, ?string $link): array
    {
        $list = [];
        foreach ($models as $model) {
            $list[] = [(string) ($model->content['action'] ?? ''), class_basename((string) ($model->content['model'] ?? '')), $this->link(EntryType::MODEL, $model->uuid)];
        }

        return $this->countBlock('models', 'models', $models, $link, 'Model actions', $list);
    }

    /** @param list<IncomingEntry> $requests */
    private function clientRequestsBlock(array $requests, ?string $link): array
    {
        $list = [];
        $errors = 0;
        foreach ($requests as $call) {
            $status = (int) ($call->content['response_status'] ?? 0);
            $errors += $status >= 400 ? 1 : 0;
            $list[] = [($call->content['method'] ?? 'GET').' '.($status ?: '-'), Str::limit((string) ($call->content['uri'] ?? ''), 120), $this->link(EntryType::CLIENT_REQUEST, $call->uuid), $status >= 400 ? 'red' : null];
        }

        $block = $this->countBlock('http-client', 'http-client', $requests, $link, 'HTTP requests sent', $list);
        $block['status'] = $errors ? 'red' : null;

        return $block;
    }

    /** @param list<IncomingEntry> $entries */
    private function otherBlock(array $entries, ?string $link, string $type): array
    {
        $titles = [EntryType::NOTIFICATION => 'Notifications', EntryType::GATE => 'Gate checks', EntryType::REDIS => 'Redis commands', EntryType::DUMP => 'Dumps'];
        $list = [];
        foreach ($entries as $entry) {
            $text = match ($type) {
                EntryType::NOTIFICATION => class_basename((string) ($entry->content['notification'] ?? '')).' via '.($entry->content['channel'] ?? ''),
                EntryType::GATE => ($entry->content['ability'] ?? '').': '.($entry->content['result'] ?? ''),
                EntryType::REDIS => (string) ($entry->content['command'] ?? ''),
                default => 'dump',
            };
            $list[] = [$type, Str::limit($text, 120), $this->link($type, EntryType::DUMP === $type ? null : $entry->uuid)];
        }

        return $this->countBlock($type, EntryType::DUMP === $type ? 'dump' : 'other', $entries, $link, $titles[$type] ?? ucfirst($type), $list);
    }

    private function countBlock(string $name, string $icon, array $entries, ?string $link, string $title, array $list): array
    {
        return [
            'name' => $name,
            'status' => null,
            'icon' => $icon,
            'value' => (string) \count($entries),
            'label' => '',
            'link' => $link,
            'groups' => array_values(array_filter([[[$title, (string) \count($entries)]], $this->limited($list)])),
        ];
    }

    /** @param array{installed: bool, enabled: bool, recording: bool, reason: ?string, base: ?string} $telescope */
    private function telescopeBlock(array $telescope): array
    {
        $version = $telescope['installed'] && class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('laravel/telescope')
            ? InstalledVersions::getPrettyVersion('laravel/telescope')
            : null;

        $pieces = [
            ['Telescope', $telescope['recording'] ? 'recording' : 'off: '.$telescope['reason'], null, $telescope['recording'] ? 'green' : 'yellow'],
            ...($version ? [['Telescope version', ltrim((string) $version, 'v')]] : []),
            ...($telescope['base'] ? [['Open', 'Telescope', $telescope['base']]] : []),
        ];

        return [
            'name' => 'telescope',
            'right' => true,
            'status' => $telescope['recording'] ? null : 'yellow',
            'icon' => 'telescope',
            'value' => $telescope['recording'] ? $this->app->version() : 'Telescope off',
            'label' => '',
            'link' => $telescope['base'],
            'groups' => [
                $pieces,
                [
                    ['Laravel version', $this->app->version()],
                    ['PHP version', \PHP_VERSION],
                    ['Environment', (string) $this->app->environment()],
                    ['Debug', config('app.debug') ? 'enabled' : 'disabled'],
                ],
            ],
        ];
    }

    /** At most max_items pieces, then how many more. */
    private function limited(array $list): array
    {
        $max = max(1, (int) config('profiler-pack.max_items', 10));
        if (\count($list) <= $max) {
            return $list;
        }
        $more = \count($list) - $max;

        return [...\array_slice($list, 0, $max), ['…', $more.' more in Telescope']];
    }

    /** Telescope's page of an entry, or of its type; null where Telescope serves no page. */
    private function link(string $type, ?string $uuid = null): ?string
    {
        $base = config('telescope.enabled') ? $this->telescopeBase() : null;
        if (null === $base || !isset(self::PAGES[$type])) {
            return $base;
        }

        return $base.'/'.self::PAGES[$type].(null !== $uuid ? '/'.$uuid : '');
    }

    private function telescopeBase(): string
    {
        $path = trim((string) config('telescope.path', 'telescope'), '/');
        $domain = config('telescope.domain');
        if ($domain) {
            return request()->getScheme().'://'.$domain.('' !== $path ? '/'.$path : '');
        }

        return rtrim(url($path), '/');
    }

    /** "App\Http\Controllers\PageController@show" as "PageController :: show". */
    private function shortAction(string $action): string
    {
        if (!str_contains($action, '@')) {
            return class_basename($action);
        }
        [$class, $method] = explode('@', $action, 2);

        return class_basename($class).' :: '.$method;
    }

    private function levelStatus(string $level): ?string
    {
        return \in_array($level, self::ERRORS, true) ? 'red' : ('warning' === $level ? 'yellow' : null);
    }

    private function number(float $number, int $decimals = 1): string
    {
        return number_format($number, $decimals, '.', '');
    }

    private function bytes(string $limit): int
    {
        if ('' === $limit || '-1' === $limit) {
            return -1;
        }
        $value = (int) $limit;

        return match (strtolower(substr($limit, -1))) {
            'g' => $value * 1024 ** 3,
            'm' => $value * 1024 ** 2,
            'k' => $value * 1024,
            default => $value,
        };
    }

    /** @return array<string, mixed> what the bar's scripts are told */
    private function options(): array
    {
        $floating = (bool) config('profiler-pack.floating', true);
        $position = (string) config('profiler-pack.position', 'bottom-right');

        return [
            'floating' => $floating,
            'draggable' => $floating && (bool) config('profiler-pack.draggable', true),
            'resizable' => $floating && (bool) config('profiler-pack.resizable', true),
            'minimizable' => $floating && (bool) config('profiler-pack.minimizable', true),
            'position' => \in_array($position, ['bottom-right', 'bottom-left', 'top-right', 'top-left'], true) ? $position : 'bottom-right',
        ];
    }

    private function gestures(): bool
    {
        $options = $this->options();

        return $options['draggable'] || $options['resizable'] || $options['minimizable'];
    }

    private function asset(string $file): string
    {
        return rtrim((string) file_get_contents(__DIR__.'/../resources/'.$file));
    }

    private function icon(string $name): string
    {
        $file = __DIR__.'/../resources/icons/'.$name.'.svg';

        return is_file($file) ? trim((string) file_get_contents($file)) : '';
    }

    /** The nonce of the page's Content-Security-Policy, when Vite was given one. */
    private function nonce(): ?string
    {
        try {
            if (class_exists(\Illuminate\Foundation\Vite::class) && $this->app->resolved(\Illuminate\Foundation\Vite::class)) {
                return $this->app->make(\Illuminate\Foundation\Vite::class)->cspNonce() ?: null;
            }
        } catch (Throwable) {
        }

        return null;
    }
}
