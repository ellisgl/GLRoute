<?php

declare(strict_types=1);

namespace GeekLab\GLRoute;

use RuntimeException;

/**
 * Thrown by Router::dispatch() when no route matches the path for any method and no
 * notFound() handler is set. The exception code is 404.
 */
class NotFoundException extends RuntimeException
{
    /**
     * @param string $method the request method
     * @param string $path   the normalized request path
     */
    public function __construct(public readonly string $method, public readonly string $path)
    {
        parent::__construct("No route for $method $path", 404);
    }
}
