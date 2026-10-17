<?php

namespace Glitchr\LaravelProfilerPack\Tests;

/** The method that sets a test's environment up, after the one every test has (TestCase::defineEnvironment()). */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class Environment
{
    public function __construct(public readonly string $method)
    {
    }
}
