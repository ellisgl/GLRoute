<?php

declare(strict_types=1);

namespace GeekLab\GLRoute;

/**
 * Result of Router::match(): what a request would route to, without invoking anything.
 */
final readonly class RouteMatch
{
    /**
     * @param int                    $status  Router::FOUND, Router::NOT_FOUND or Router::METHOD_NOT_ALLOWED
     * @param mixed                  $handler the matched handler exactly as registered (null unless found)
     * @param array<string, ?string> $params  URL-decoded parameter values by name; an omitted optional
     *                                        parameter is null
     * @param list<string>           $allowed methods allowed for the path (only set on METHOD_NOT_ALLOWED)
     * @param string|null            $name    the matched route's name, if it has one
     */
    public function __construct(
        public int $status,
        public mixed $handler = null,
        public array $params = [],
        public array $allowed = [],
        public ?string $name = null,
    ) {
    }

    /** Whether a route matched (status is Router::FOUND). */
    public function found(): bool
    {
        return $this->status === Router::FOUND;
    }
}
