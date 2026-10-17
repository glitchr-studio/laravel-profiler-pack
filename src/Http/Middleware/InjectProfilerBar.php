<?php

namespace Glitchr\LaravelProfilerPack\Http\Middleware;

use Closure;
use Glitchr\LaravelProfilerPack\ProfilerBar;
use Glitchr\LaravelProfilerPack\ProfilerPackServiceProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedJsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Writes the bar into an HTML page, before its </body>. Nothing else is
 * touched: not JSON, not a redirect, not a download, not a streamed answer,
 * not an AJAX request, not Telescope's own pages.
 */
final class InjectProfilerBar
{
    public function __construct(
        private readonly Application $app,
        private readonly ProfilerBar $bar,
    ) {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        if (!$response instanceof Response || !$this->injectable($request, $response)) {
            return $response;
        }

        $content = (string) $response->getContent();
        $end = strripos($content, '</body>');
        if (false === $end) {
            return $response;
        }

        $response->setContent(substr($content, 0, $end).$this->bar->render($request, $response).substr($content, $end));
        if ($response->headers->has('Content-Length')) {
            $response->headers->set('Content-Length', (string) \strlen((string) $response->getContent()));
        }

        return $response;
    }

    private function injectable(Request $request, Response $response): bool
    {
        if (!ProfilerPackServiceProvider::active($this->app)) {
            return false;
        }
        if ($response instanceof StreamedResponse || $response instanceof StreamedJsonResponse || $response instanceof BinaryFileResponse) {
            return false;
        }
        if ($response->isRedirection() || $request->isXmlHttpRequest() || $request->isMethod('HEAD')) {
            return false;
        }
        if (str_contains(strtolower((string) $response->headers->get('Content-Disposition')), 'attachment')) {
            return false;
        }
        $type = strtolower((string) $response->headers->get('Content-Type', 'text/html'));
        if (!str_contains($type, 'text/html')) {
            return false;
        }
        if ($request->is(...$this->telescopePaths()) || $request->is(...array_values(array_filter((array) config('profiler-pack.except'))))) {
            return false;
        }

        $content = $response->getContent();

        return \is_string($content) && !str_contains($content, 'data-laravel-profiler-pack');
    }

    /** @return list<string> Telescope's own pages, as Request::is() reads them */
    private function telescopePaths(): array
    {
        $path = trim((string) config('telescope.path', 'telescope'), '/');
        $paths = ['telescope-api*', 'vendor/telescope*'];
        if ('' !== $path) {
            $paths[] = $path;
            $paths[] = $path.'/*';
        }

        return $paths;
    }
}
