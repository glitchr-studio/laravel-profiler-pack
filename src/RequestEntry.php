<?php

namespace Glitchr\LaravelProfilerPack;

use Illuminate\Support\Str;

/**
 * The uuid Telescope's entry for the current request will have. Telescope
 * records that entry once the answer is out of the middleware, under a uuid of
 * its own: the bar chooses one when it is written, links to it, and Telescope's
 * entry takes it (a tag callback, ProfilerPackServiceProvider::boot()).
 */
final class RequestEntry
{
    private ?string $uuid = null;

    /** A new uuid for the request being answered. */
    public function name(): string
    {
        return $this->uuid = (string) Str::orderedUuid();
    }

    /** The uuid chosen, once: the next request names its own. */
    public function take(): ?string
    {
        $uuid = $this->uuid;
        $this->uuid = null;

        return $uuid;
    }
}
