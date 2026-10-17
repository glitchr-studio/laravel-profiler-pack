<?php

namespace Glitchr\LaravelProfilerPack\Tests;

use Glitchr\LaravelProfilerPack\ProfilerPackServiceProvider;
use Illuminate\Http\Response;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeServiceProvider;
use Laravel\Telescope\Watchers\LogWatcher;
use Orchestra\Testbench\TestCase as Testbench;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * An application as the README describes one: Telescope installed, its
 * entries in the default connection, running locally with debug on; a few
 * pages of each kind the bar is or is not written into.
 */
abstract class TestCase extends Testbench
{
    protected const PAGE = '<!doctype html><html><head><title>Page</title></head><body><main>A page</main><!-- end of the page --></body></html>';

    protected function getPackageProviders($app): array
    {
        return [TelescopeServiceProvider::class, ProfilerPackServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['env'] = 'local';
        $app['config']->set('app.debug', true);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('telescope.enabled', true);
        $app['config']->set('telescope.storage.database.connection', 'testing');
        $app['config']->set('telescope.watchers.'.LogWatcher::class.'.level', 'debug');

        // A test's own environment, on top of this one: Testbench runs its
        // #[DefineEnvironment] before defineEnvironment(), which would undo it.
        foreach ((new \ReflectionMethod($this, $this->name()))->getAttributes(Environment::class) as $attribute) {
            $this->{$attribute->newInstance()->method}($app);
        }
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(\dirname(__DIR__).'/vendor/laravel/telescope/database/migrations');
    }

    /** @param Router $router */
    protected function defineRoutes($router): void
    {
        $router->middleware('web')->group(static function (Router $router) {
            $router->get('/page', static fn () => new Response(self::PAGE))->name('page');

            // A page whose request runs queries, logs, uses the cache and reports an exception.
            $router->get('/articles', static function () {
                DB::select('select 1 as one');
                DB::table('telescope_monitoring')->where('tag', 'articles')->count();
                Log::info('Articles listed');
                Log::warning('An article without a title');
                Cache::get('articles:missing');
                Cache::put('articles:count', 3, 60);
                Cache::get('articles:count');
                report(new \RuntimeException('An article went missing'));

                return new Response(self::PAGE);
            })->name('articles');

            $router->get('/json', static fn () => response()->json(['ok' => true]));
            $router->get('/redirect', static fn () => redirect('/page'));
            $router->get('/download', static fn () => response(self::PAGE, 200, ['Content-Type' => 'text/html', 'Content-Disposition' => 'attachment; filename="page.html"']));
            $router->get('/file', static fn () => response()->download(__FILE__));
            $router->get('/stream', static fn () => new StreamedResponse(static function () {
                echo self::PAGE;
            }, 200, ['Content-Type' => 'text/html']));
            $router->get('/no-body', static fn () => new Response('<p>A fragment</p>'));
            $router->get('/admin/page', static fn () => new Response(self::PAGE));
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        // In the application a request starts with an empty queue; here the
        // migrations have just been recorded into it.
        Telescope::flushEntries();
        Telescope::$updatesQueue = [];
    }

    /** The bar written into a page, from its start to its end. */
    protected static function barOf(string $content): string
    {
        self::assertSame(1, preg_match('~<!-- START of the Laravel profiler bar.*<!-- END of the Laravel profiler bar -->~s', $content, $matches), 'The page carries the bar.');

        return $matches[0];
    }
}
