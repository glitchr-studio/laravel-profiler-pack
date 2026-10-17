<?php

namespace Glitchr\LaravelProfilerPack;

use Glitchr\LaravelProfilerPack\Http\Middleware\InjectProfilerBar;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Laravel\Telescope\EntryType;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;

/**
 * A floating debug bar for Laravel, read from Telescope. Discovered by Laravel
 * (extra.laravel.providers): installed, it is on wherever the application runs
 * locally with debug on, and never in production.
 */
final class ProfilerPackServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/profiler-pack.php', 'profiler-pack');

        $this->app->singleton(RequestEntry::class);
        $this->app->singleton(ProfilerBar::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/profiler-pack.php' => $this->app->configPath('profiler-pack.php'),
            ], 'profiler-pack-config');
        }

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'profiler-pack');

        if (!self::active($this->app)) {
            return;
        }

        self::checkHideWhen($this->app['config']->get('profiler-pack.hide_when'));

        // First of the web group: the last to see the answer on its way out,
        // after what the others do then (a session written, cookies queued).
        // Set on the HTTP kernel, which hands its groups to the router, once
        // the application's own groups are there (bootstrap/app.php).
        $inject = static function (object $kernel): void {
            if ($kernel instanceof HttpKernel && \array_key_exists('web', $kernel->getMiddlewareGroups())) {
                $kernel->prependMiddlewareToGroup('web', InjectProfilerBar::class);
            }
        };
        if ($this->app->resolved(HttpKernelContract::class)) {
            $inject($this->app->make(HttpKernelContract::class));
        } else {
            $this->app->afterResolving(HttpKernelContract::class, $inject);
        }

        // The request's entry is recorded by Telescope after the answer has
        // left the middleware: the bar names it beforehand, so that its link
        // opens that entry (RequestEntry).
        $requestEntry = $this->app->make(RequestEntry::class);
        Telescope::tag(static function (IncomingEntry $entry) use ($requestEntry): array {
            if (EntryType::REQUEST === $entry->type && null !== $uuid = $requestEntry->take()) {
                $entry->uuid = $uuid;
            }

            return [];
        });
        $this->app->terminating(static fn () => $requestEntry->take());
    }

    /**
     * Whether the bar is on: never in production; elsewhere as the
     * configuration says, or, left at null, locally with debug on.
     */
    public static function active(Application $app): bool
    {
        if ($app->environment('production')) {
            return false;
        }

        $enabled = $app['config']->get('profiler-pack.enabled');
        if (null === $enabled || '' === $enabled) {
            return $app->environment('local') && (bool) $app['config']->get('app.debug');
        }

        return filter_var($enabled, \FILTER_VALIDATE_BOOL);
    }

    private static function checkHideWhen(mixed $selector): void
    {
        if (null === $selector || '' === $selector) {
            return;
        }
        if (!\is_string($selector) || 1 === preg_match('/[{}<>;]/', $selector)) {
            throw new InvalidArgumentException(\sprintf('profiler-pack.hide_when is one CSS selector: no "{", "}", "<", ">" or ";" in it. Got %s.', var_export($selector, true)));
        }
    }
}
