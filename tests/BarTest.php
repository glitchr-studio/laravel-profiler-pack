<?php

namespace Glitchr\LaravelProfilerPack\Tests;

use Illuminate\Support\Facades\DB;
use Laravel\Telescope\Telescope;

final class BarTest extends TestCase
{
    public function testTheBarIsWrittenIntoAPageBeforeItsBodyEnds(): void
    {
        $content = (string) $this->get('/page')->assertOk()->getContent();

        $bar = self::barOf($content);
        self::assertSame(1, substr_count($content, 'data-laravel-profiler-pack='), 'Once.');
        self::assertStringEndsWith('<!-- END of the Laravel profiler bar -->'."\n".'</body></html>', $content, 'Right before </body>.');
        self::assertLessThan(strpos($content, '<!-- START of the Laravel profiler bar'), strpos($content, '<!-- end of the page -->'));
        self::assertStringContainsString('laravel-profiler-pack/toolbar/position', $bar, 'The floating script.');
        self::assertStringContainsString('.sf-toolbarreset.inverted', $bar, 'The floating styles.');
        self::assertStringContainsString('<span class="sf-toolbar-status sf-toolbar-status-green">200</span>', $bar);
        self::assertStringContainsString('>page</span>', $bar, 'The route\'s name.');
    }

    public function testTheRootIsOneACookiePanelSetsAside(): void
    {
        $bar = self::barOf((string) $this->get('/page')->getContent());

        self::assertSame(1, preg_match('~<div class="sf-toolbar sf-toolbar-opened" role="toolbar" aria-label="Laravel profiler bar" data-laravel-profiler-pack="[^"]*" data-cookie-ignore data-consent-ignore>\s*<div id="sfwdt[0-9a-f]+">~', $bar), 'omnibase/consent\'s selectors: .sf-toolbar, [id^="sfwdt"], [data-cookie-ignore], [data-consent-ignore].');

        // Everything shown is inside the root: beside it, a <style> and <script>s only.
        $outside = preg_replace(['~<div class="sf-toolbar .*</div>\s*(?=<script)~s', '~<style[^>]*>.*?</style>~s', '~<script[^>]*>.*?</script>~s', '~<!--.*?-->~s'], '', $bar);
        self::assertSame('', trim($outside));
    }

    public function testTheBlocksReadTelescopesEntriesAndLinkToThem(): void
    {
        $content = (string) $this->get('/articles')->assertOk()->getContent();
        $bar = self::barOf($content);

        $entries = DB::table('telescope_entries')->orderBy('sequence')->get(['uuid', 'batch_id', 'type', 'content']);
        $request = $entries->firstWhere('type', 'request');
        self::assertNotNull($request, 'Telescope stored the request.');
        $batch = $entries->where('batch_id', $request->batch_id);
        $queries = $batch->where('type', 'query');
        $logs = $batch->where('type', 'log');
        $exceptions = $batch->where('type', 'exception');
        $cache = $batch->where('type', 'cache');

        // The request block opens Telescope's entry of this very request.
        self::assertStringContainsString('href="http://localhost/telescope/requests/'.$request->uuid.'"', $bar);

        // Two queries, two logs, an exception, three cache calls: each one in its block, a link to its entry.
        self::assertCount(2, $queries);
        self::assertMatchesRegularExpression('~sf-toolbar-block-queries.*?<span class="sf-toolbar-value">2</span>\s*<span class="sf-toolbar-label">in [0-9.]+ ms</span>~s', $bar);
        foreach ($queries as $query) {
            self::assertStringContainsString('href="http://localhost/telescope/queries/'.$query->uuid.'"', $bar);
        }
        self::assertStringContainsString('select 1 as one', $bar);

        self::assertCount(2, $logs);
        self::assertMatchesRegularExpression('~sf-toolbar-block-logger sf-toolbar-status-yellow.*?<span class="sf-toolbar-value">2</span>~s', $bar, 'A warning: yellow.');
        foreach ($logs as $log) {
            self::assertStringContainsString('href="http://localhost/telescope/logs/'.$log->uuid.'"', $bar);
        }
        self::assertStringContainsString('Articles listed', $bar);

        self::assertCount(1, $exceptions);
        self::assertMatchesRegularExpression('~sf-toolbar-block-exception sf-toolbar-status-red.*?href="http://localhost/telescope/exceptions/'.$exceptions->first()->uuid.'"~s', $bar);
        self::assertStringContainsString('An article went missing', $bar);

        self::assertCount(3, $cache);
        self::assertMatchesRegularExpression('~sf-toolbar-block-cache.*?<span class="sf-toolbar-value">1</span>\s*<span class="sf-toolbar-label">/ 2 hits</span>~s', $bar, 'One hit of two reads.');
        foreach ($cache as $call) {
            self::assertStringContainsString('href="http://localhost/telescope/cache/'.$call->uuid.'"', $bar);
        }

        // The bar's own view is not one of the request's.
        self::assertFalse($batch->contains(fn ($entry) => str_contains((string) $entry->content, 'profiler-pack::toolbar')));

        self::assertMatchesRegularExpression('~<b><span class="sf-toolbar-status sf-toolbar-status-green">Telescope</span></b>\s*<span>recording</span>~', $bar);
    }

    public function testWithTelescopeOffTheBarShowsWhatTheRequestTells(): void
    {
        Telescope::stopRecording();

        $bar = self::barOf((string) $this->get('/articles')->assertOk()->getContent());

        self::assertStringContainsString('sf-toolbar-block-request', $bar);
        self::assertStringContainsString('sf-toolbar-block-time', $bar);
        self::assertStringContainsString('sf-toolbar-block-memory', $bar);
        self::assertStringNotContainsString('sf-toolbar-block-queries', $bar);
        self::assertStringNotContainsString('sf-toolbar-block-logger', $bar);
        self::assertStringContainsString('<span class="sf-toolbar-value">Telescope off</span>', $bar);
        self::assertStringContainsString('sf-toolbar-block sf-toolbar-block-telescope sf-toolbar-status-yellow sf-toolbar-block-right', $bar);
        self::assertStringContainsString('off: not recording this request', $bar);
        self::assertStringNotContainsString('/telescope/requests/', $bar, 'No entry to open.');
        self::assertSame(0, DB::table('telescope_entries')->where('type', 'request')->count());
    }

    #[Environment('telescopeDisabled')]
    public function testWithTelescopeDisabledTheBarSaysSo(): void
    {
        $bar = self::barOf((string) $this->get('/page')->assertOk()->getContent());

        self::assertStringContainsString('off: disabled (telescope.enabled)', $bar);
        self::assertStringNotContainsString('href="http://localhost/telescope', $bar, 'Telescope serves no page.');
    }

    protected function telescopeDisabled($app): void
    {
        $app['config']->set('telescope.enabled', false);
    }

    public function testJsonIsLeftAlone(): void
    {
        $response = $this->get('/json')->assertOk();

        self::assertSame('{"ok":true}', $response->getContent());
    }

    public function testAJsonAnswerToAnHtmlPageIsLeftAloneToo(): void
    {
        self::assertStringNotContainsString('data-laravel-profiler-pack', (string) $this->getJson('/json')->getContent());
    }

    public function testARedirectIsLeftAlone(): void
    {
        $this->get('/redirect')->assertRedirect('/page');

        self::assertStringNotContainsString('data-laravel-profiler-pack', (string) $this->get('/redirect')->getContent());
    }

    public function testADownloadIsLeftAlone(): void
    {
        self::assertSame(self::PAGE, $this->get('/download')->assertOk()->getContent());
        self::assertStringNotContainsString('data-laravel-profiler-pack', (string) $this->get('/file')->assertOk()->streamedContent());
    }

    public function testAStreamedAnswerIsLeftAlone(): void
    {
        self::assertSame(self::PAGE, $this->get('/stream')->assertOk()->streamedContent());
    }

    public function testAnAjaxRequestIsLeftAlone(): void
    {
        self::assertSame(self::PAGE, $this->get('/page', ['X-Requested-With' => 'XMLHttpRequest'])->getContent());
    }

    public function testAPageWithoutABodyIsLeftAlone(): void
    {
        self::assertSame('<p>A fragment</p>', $this->get('/no-body')->getContent());
    }

    public function testTelescopesOwnPagesAreLeftAlone(): void
    {
        $content = (string) $this->get('/telescope/requests')->getContent();

        self::assertNotSame('', $content);
        self::assertStringNotContainsString('data-laravel-profiler-pack', $content);
    }

    #[Environment('exceptAdmin')]
    public function testPathsSetAsideAreLeftAlone(): void
    {
        self::assertSame(self::PAGE, $this->get('/admin/page')->getContent());
        self::barOf((string) $this->get('/page')->getContent());
    }

    protected function exceptAdmin($app): void
    {
        $app['config']->set('profiler-pack.except', ['admin/*']);
    }

    public function testOptionsReachTheScripts(): void
    {
        $bar = self::barOf((string) $this->get('/page')->getContent());

        self::assertSame(1, preg_match('~data-laravel-profiler-pack="([^"]*)"~', $bar, $matches));
        self::assertSame(['floating' => true, 'draggable' => true, 'resizable' => true, 'minimizable' => true, 'position' => 'bottom-right'], json_decode(html_entity_decode($matches[1]), true));
    }

    #[Environment('noGestures')]
    public function testWithoutGesturesThereIsNoFloatingScript(): void
    {
        $bar = self::barOf((string) $this->get('/page')->getContent());

        self::assertStringNotContainsString('__laravelProfilerPackFloating', $bar);
        self::assertStringContainsString('.sf-toolbarreset.inverted', $bar, 'It still floats.');
        self::assertStringContainsString('&quot;position&quot;:&quot;top-left&quot;', $bar);
    }

    protected function noGestures($app): void
    {
        $app['config']->set('profiler-pack.draggable', false);
        $app['config']->set('profiler-pack.resizable', false);
        $app['config']->set('profiler-pack.minimizable', false);
        $app['config']->set('profiler-pack.position', 'top-left');
    }

    #[Environment('strip')]
    public function testNotFloatingTheBarIsAStrip(): void
    {
        $bar = self::barOf((string) $this->get('/page')->getContent());

        self::assertStringNotContainsString('.sf-toolbarreset.inverted', $bar);
        self::assertStringNotContainsString('__laravelProfilerPackFloating', $bar);
        self::assertStringContainsString('laravel-profiler-pack/toolbar/displayState', $bar, 'It still opens and closes.');
    }

    protected function strip($app): void
    {
        $app['config']->set('profiler-pack.floating', false);
    }

    #[Environment('hiddenWhileLoading')]
    public function testTheBarHidesWhileThePageSaysSo(): void
    {
        self::assertStringContainsString('html.is-loading .sf-toolbar { display: none !important; }', self::barOf((string) $this->get('/page')->getContent()));
    }

    protected function hiddenWhileLoading($app): void
    {
        $app['config']->set('profiler-pack.hide_when', 'html.is-loading');
    }
}
