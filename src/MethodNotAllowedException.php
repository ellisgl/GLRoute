<?php

declare(strict_types=1);

namespace GeekLab\GLRoute;

use RuntimeException;

/**
 * Thrown by Router::dispatch() when the path matches routes for other methods only and
 * no methodNotAllowed() handler is set.
 * The exception code is 405. HTTP requires a 405 response to carry an Allow header; use allowHeader() for its value.
 */
class MethodNotAllowedException extends RuntimeException
{
    /**
     * @param string       $method  the request method
     * @param string       $path    the normalized request path
     * @param list<string> $allowed methods that do have a route for the path (includes HEAD when GET does)
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $allowed
    ) {
        parent::__construct(
            "Method $method not allowed for $path (allowed: " . implode(', ', $allowed) . ')',
            405
        );
    }

    /**
     * Value for the HTTP "Allow" response header, e.g. "GET, POST, HEAD".
     *
     * @return string
     */
    public function allowHeader(): string
    {
        return implode(', ', $this->allowed);
    }
}
