<?php

namespace Glitchr\LaravelProfilerPack\Tests;

use Glitchr\LaravelProfilerPack\Http\Middleware\InjectProfilerBar;
use Glitchr\LaravelProfilerPack\ProfilerPackServiceProvider;
use Illuminate\Contracts\Http\Kernel as HttpKernel;

/**
 * On by default where the application runs locally with debug on; off
 * elsewhere unless asked; never in production.
 */
final class ActivationTest extends TestCase
{
    public function testLocallyWithDebugItIsOn(): void
    {
        self::assertTrue(ProfilerPackServiceProvider::active($this->app));
        self::assertContains(InjectProfilerBar::class, $this->webGroup(), 'In the web group.');
        self::assertSame(InjectProfilerBar::class, $this->webGroup()[0], 'First of it: the last to see the answer.');
    }

    #[Environment('productionAskedFor')]
    public function testNeverInProductionEvenWhenAskedFor(): void
    {
        self::assertFalse(ProfilerPackServiceProvider::active($this->app));
        self::assertNotContains(InjectProfilerBar::class, $this->webGroup());
        self::assertSame(self::PAGE, $this->get('/page')->getContent());
    }

    protected function productionAskedFor($app): void
    {
        $app['env'] = 'production';
        $app['config']->set('profiler-pack.enabled', true);
    }

    public function testNeverInProductionEvenWithTheMiddlewareInPlace(): void
    {
        $this->app['env'] = 'production';
        $this->app['config']->set('profiler-pack.enabled', true);

        self::assertSame(self::PAGE, $this->get('/page')->getContent());
    }

    #[Environment('localWithoutDebug')]
    public function testLocallyWithoutDebugItIsOff(): void
    {
        self::assertSame(self::PAGE, $this->get('/page')->getContent());
    }

    protected function localWithoutDebug($app): void
    {
        $app['config']->set('app.debug', false);
    }

    #[Environment('testingEnvironment')]
    public function testElsewhereItIsOffByDefault(): void
    {
        self::assertSame(self::PAGE, $this->get('/page')->getContent());
    }

    protected function testingEnvironment($app): void
    {
        $app['env'] = 'testing';
    }

    #[Environment('disabled')]
    public function testItCanBeTurnedOff(): void
    {
        self::assertSame(self::PAGE, $this->get('/page')->getContent());
    }

    protected function disabled($app): void
    {
        $app['config']->set('profiler-pack.enabled', false);
    }

    /** The README's example: PROFILER_PACK_ENABLED=true on a staging server. */
    #[Environment('stagingAskedFor')]
    public function testElsewhereItCanBeAskedFor(): void
    {
        self::barOf((string) $this->get('/page')->getContent());
    }

    protected function stagingAskedFor($app): void
    {
        $app['env'] = 'staging';
        $app['config']->set('app.debug', false);
        $app['config']->set('profiler-pack.enabled', 'true');
    }

    public function testHideWhenIsOneSelector(): void
    {
        $this->app['config']->set('profiler-pack.hide_when', 'html { display: none } body');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('profiler-pack.hide_when is one CSS selector');

        $this->app->register(new ProfilerPackServiceProvider($this->app), true);
    }

    /** @return list<string> the web group, as the HTTP kernel hands it to the router */
    private function webGroup(): array
    {
        return $this->app->make(HttpKernel::class)->getMiddlewareGroups()['web'];
    }
}
