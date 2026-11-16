<?php

declare(strict_types=1);

namespace GeekLab\GLRoute\Tests;

// Handler and middleware classes used by RouterTest.

/** Controller used to test class-based handlers and instance sharing. */
final class UserController
{
    /** How many instances have been created; tests reset it to 0. */
    public static int $constructed = 0;

    /** Counts instances, so tests can check that the router shares one. */
    public function __construct()
    {
        self::$constructed++;
    }

    /** Instance-method handler: returns "user:<id>". */
    public function show(string $id): string
    {
        return "user:$id";
    }

    /** Static-method handler: returns "users". */
    public static function list(): string
    {
        return 'users';
    }
}

/** Invokable controller, registered by class name alone. */
final class Invokable
{
    /** Returns "inv:<slug>"; the default covers the optional-parameter short route. */
    public function __invoke(string $slug = 'none'): string
    {
        return "inv:$slug";
    }
}

/** Middleware used as a [Class, 'method'] callable. */
final class Tag
{
    /** Wraps the rest of the chain's output in square brackets. */
    public static function mw(callable $next, array $params): string
    {
        return '[' . $next() . ']';
    }
}
