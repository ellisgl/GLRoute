<?php

declare(strict_types=1);

namespace GeekLab\GLRoute;

use Closure;
use Countable;
use Generator;
use InvalidArgumentException;
use IteratorAggregate;
use LogicException;
use NoDiscard;
use Random\RandomException;
use ReflectionMethod;

// Every global function is imported so calls compile to direct (or, on PHP 8.4+, frameless)
// calls instead of a runtime "namespaced function first, then global" lookup.
use function array_combine;
use function array_key_last;
use function array_keys;
use function array_map;
use function array_pad;
use function array_pop;
use function array_push;
use function array_slice;
use function array_splice;
use function bin2hex;
use function class_exists;
use function count;
use function end;
use function explode;
use function file_put_contents;
use function function_exists;
use function get_debug_type;
use function http_build_query;
use function implode;
use function in_array;
use function is_array;
use function is_callable;
use function is_file;
use function is_object;
use function is_string;
use function method_exists;
use function opcache_invalidate;
use function preg_match;
use function preg_match_all;
use function preg_quote;
use function preg_replace_callback;
use function random_bytes;
use function rawurldecode;
use function rawurlencode;
use function rename;
use function rtrim;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strcspn;
use function strlen;
use function strpbrk;
use function strpos;
use function strrpos;
use function strtoupper;
use function substr;
use function trim;
use function usort;
use function var_export;

/**
 * A fast, low-memory HTTP router.
 *
 * Design:
 *  - Static routes live in a flat hash map: $static[METHOD][path] = routeId,
 *    so matching one is a single isset() with no explode().
 *  - Dynamic routes live in one segment trie per method, built from plain nested arrays.
 *    Walking it is one isset() per segment.
 *    Backtracking only happens at nodes that have both a static child and a dynamic child (or several dynamic kinds),
 *    so the common path never recurses.
 *  - Trie control keys all contain '/', which can never appear inside a URL segment produced by explode('/'),
 *    so they can never collide with a real segment name.
 *  - Handlers are stored once in a packed list and resolved lazily (and memoized) on first dispatch.
 *  - The whole route table is plain arrays, strings and ints, so it can be exported to a PHP file
 *    that OPcache keeps in shared memory: near-zero registration cost and request memory.
 *
 * Trie node layout (each node is an array):
 *   'segment' => child node      static child, keyed by the literal segment
 *   '/'       => route id        a route ends here (LEAF)
 *   '/:'      => child node      plain {param} child (PARAM)
 *   '/~'      => list of [regex, child node, ?group names]   constrained or mixed segments (REGEX)
 *   '/*'      => child node      catch-all child; its LEAF is the route (WILD)
 *   '/?'      => int bitmask     T_REGEX | T_WILD flags, present only when such children exist (DYN)
 *
 * @implements IteratorAggregate<int, array{id: int, method: string, pattern: string}>
 */
class Router implements Countable, IteratorAggregate
{
    /** Library version. Stored in exported caches; a cache from another version is rebuilt. */
    public const string VERSION = '1.0.1';

    /** RouteMatch::$status when a route matched. */
    public const int FOUND = 200;

    /** RouteMatch::$status when no route matches the path for any method. */
    public const int NOT_FOUND = 404;

    /** RouteMatch::$status when the path matches, but only for other methods. */
    public const int METHOD_NOT_ALLOWED = 405;

    /** Method key used for routes registered with any(); matched after method-specific routes. */
    public const string ANY = '*';

    // Trie control keys. They all contain '/', so they can never clash with a URL segment.

    /** Node key holding the id of the route that ends at this node. */
    private const string LEAF = '/';

    /** Node key holding the child for an unconstrained {param} segment. */
    private const string PARAM = '/:';

    /** Node key holding a list of [regex, child, ?groupNames] for constrained or mixed segments. */
    private const string REGEX = '/~';

    /** Node key holding the catch-all ({rest*}) child; only ever used for a pattern's last segment. */
    private const string WILD = '/*';

    /**
     * Node key holding a bitmask of T_REGEX / T_WILD, set only on nodes that have such children.
     * A plain {param} child is detected with isset(PARAM) instead, so the common case needs no
     * extra write at registration and the dispatch fast path can test for "param only" cheaply.
     */
    private const string DYN = '/?';

    /** Step / flag value: plain {param} segment. */
    private const int T_PARAM = 1;

    /** Step / flag value: regex-constrained or mixed literal+param segment. */
    private const int T_REGEX = 2;

    /** Step / flag value: catch-all segment. */
    private const int T_WILD = 4;

    /**
     * One parameter token: {name}, {name?}, {name*}, {name:regex} or {name?:regex}.
     * Groups: 1 = name, 2 = modifier (? or *), 3 = regex. The regex may contain one level of {},
     * so quantifiers such as \d{4} work.
     */
    private const string TOKEN = '~\{(\w+)([?*])?(?::((?:[^{}]++|\{[^{}]*})+))?}~';

    /** Shorthand constraints usable as {name:alias}. */
    private const array ALIASES = [
        'int' => '\d+',
        'num' => '-?\d+(?:\.\d+)?',
        'alpha' => '[A-Za-z]+',
        'alnum' => '[A-Za-z0-9]+',
        'slug' => '[A-Za-z0-9_-]+',
        'hex' => '[A-Fa-f0-9]+',
        'uuid' => '[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}',
    ];

    /** @var array<string, array<string, int>> method => normalized path => route id */
    private array $static = [];

    /** @var array<string, array> method => trie root node (see class docblock for the layout) */
    private array $tree = [];

    /** @var list<mixed> route id => handler exactly as registered */
    private array $handlers = [];

    /** @var array<int, string> route id => full pattern (dynamic routes only; shares the caller's string) */
    private array $patterns = [];

    /** @var array<int, list<string>> route id => parameter names, derived lazily from $patterns */
    private array $params = [];

    /** @var array<string, list<string>> interning table so identical name lists share one array */
    private array $nameLists = [];

    /** @var array<int, list<mixed>> route id => group + route middleware (sparse: only routes that have some) */
    private array $middleware = [];

    /** @var array<string, array{0: int, 1: string}> route name => [route id, full pattern] */
    private array $names = [];

    /** @var list<mixed> middleware run for every dispatched route, outermost first */
    private array $global = [];

    /** @var array<int, callable> route id => resolved callable (filled lazily on first dispatch) */
    private array $resolved = [];

    /** @var array<string, object|callable> class name => shared instance; "Class::method" => resolved callable */
    private array $instances = [];

    /** Prefix applied to routes registered inside group(); '' outside any group. */
    private string $prefix = '';

    /** @var list<mixed> middleware of the enclosing group(s), outermost first */
    private array $groupMiddleware = [];

    /** Creates controller instances when set; otherwise `new $class()` is used. */
    private ?Closure $resolver = null;

    /** Handler called by dispatch() when nothing matches; null means throw NotFoundException. */
    private mixed $notFound = null;

    /** Handler called by dispatch() on a 405; null means throw MethodNotAllowedException. */
    private mixed $methodNotAllowed = null;

    /** Prefix that run() strips from REQUEST_URI; '' when the app lives at the web root. */
    private string $basePath = '';

    // ---------------------------------------------------------------------
    // Registration
    // ---------------------------------------------------------------------
    /**
     * @param string $path
     * @return string
     */
    private static function normalizePath(string $path): string
    {
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }

        $len = strlen($path);
        if ($len > 1 && $path[$len - 1] === '/') {
            $path = rtrim($path, '/') ?: '/';
        }
        return $path;
    }

    /**
     * Register a GET route. HEAD requests fall back to GET routes automatically.
     *
     * @param string        $pattern    route pattern, e.g. '/users/{id:int}'
     * @param mixed         $handler    closure, callable, [Class::class, 'method'], 'Class@method',
     *                                  'Class::method', invokable class name or function name
     * @param string | null $name       route name for url() and RouteMatch::$name
     * @param list<mixed>   $middleware middleware for this route only, outermost first
     *
     * @return $this
     */
    public function get(string $pattern, mixed $handler, ?string $name = null, array $middleware = []): static
    {
        return $this->register(['GET'], $pattern, $handler, $name, $middleware);
    }

    /**
     * Register a POST route.
     *
     * @param string        $pattern    route pattern, e.g. '/users/{id:int}'
     * @param mixed         $handler    closure, callable, [Class::class, 'method'], 'Class@method',
     *                                  'Class::method', invokable class name or function name
     * @param string | null $name       route name for url() and RouteMatch::$name
     * @param list<mixed>   $middleware middleware for this route only, outermost first
     *
     * @return $this
     */
    public function post(string $pattern, mixed $handler, ?string $name = null, array $middleware = []): static
    {
        return $this->register(['POST'], $pattern, $handler, $name, $middleware);
    }

    /**
     * Register a PUT route.
     *
     * @param string        $pattern    route pattern, e.g. '/users/{id:int}'
     * @param mixed         $handler    closure, callable, [Class::class, 'method'], 'Class@method',
     *                                  'Class::method', invokable class name or function name
     * @param string | null $name       route name for url() and RouteMatch::$name
     * @param list<mixed>   $middleware middleware for this route only, outermost first
     *
     * @return $this
     */
    public function put(string $pattern, mixed $handler, ?string $name = null, array $middleware = []): static
    {
        return $this->register(['PUT'], $pattern, $handler, $name, $middleware);
    }

    /**
     * Register a PATCH route.
     *
     * @param string        $pattern    route pattern, e.g. '/users/{id:int}'
     * @param mixed         $handler    closure, callable, [Class::class, 'method'], 'Class@method',
     *                                  'Class::method', invokable class name or function name
     * @param string | null $name       route name for url() and RouteMatch::$name
     * @param list<mixed>   $middleware middleware for this route only, outermost first
     *
     * @return $this
     */
    public function patch(string $pattern, mixed $handler, ?string $name = null, array $middleware = []): static
    {
        return $this->register(['PATCH'], $pattern, $handler, $name, $middleware);
    }

    /**
     * Register a DELETE route.
     *
     * @param string $pattern route pattern, e.g. '/users/{id:int}'
     * @param mixed $handler closure, callable, [Class::class, 'method'], 'Class@method',
     * 'Class::method', invokable class name or function name
     * @param string | null $name route name for url() and RouteMatch::$name
     * @param list<mixed> $middleware middleware for this route only, outermost first
     *
     * @return $this
     */
    public function delete(string $pattern, mixed $handler, ?string $name = null, array $middleware = []): static
    {
        return $this->register(['DELETE'], $pattern, $handler, $name, $middleware);
    }

    /**
     * Register an OPTIONS route.
     *
     * @param string $pattern           route pattern, e.g. '/users/{id:int}'
     * @param mixed $handler            closure, callable, [Class::class, 'method'], 'Class@method',
     *                                  'Class::method', invokable class name or function name
     * @param string | null $name       route name for url() and RouteMatch::$name
     * @param list<mixed>   $middleware middleware for this route only, outermost first
     *
     * @return $this
     */
    public function options(string $pattern, mixed $handler, ?string $name = null, array $middleware = []): static
    {
        return $this->register(['OPTIONS'], $pattern, $handler, $name, $middleware);
    }

    /**
     * Register a route for any HTTP method. Method-specific routes for the same path take precedence.
     *
     * @param string        $pattern    route pattern, e.g. '/users/{id:int}'
     * @param mixed         $handler    closure, callable, [Class::class, 'method'], 'Class@method',
     *                                  'Class::method', invokable class name or function name
     * @param string | null $name       route name for url() and RouteMatch::$name
     * @param list<mixed>   $middleware middleware for this route only, outermost first
     *
     * @return $this
     */
    public function any(string $pattern, mixed $handler, ?string $name = null, array $middleware = []): static
    {
        return $this->register([self::ANY], $pattern, $handler, $name, $middleware);
    }

    /**
     * Register a route for one or more methods. Method names are case-insensitive.
     *
     * @param string | list<string> $methods    'GET', 'GET|POST', ['GET', 'POST'] or '*' for any method
     * @param string                $pattern    route pattern, e.g. '/users/{id:int}'
     * @param mixed                 $handler    closure, callable, [Class::class, 'method'], 'Class@method',
     *                                          'Class::method', invokable class name or function name
     * @param string | null         $name       route name for url() and RouteMatch::$name
     * @param list<mixed>           $middleware middleware for this route only, outermost first
     *
     * @return $this
     */
    public function add(
        string|array $methods,
        string $pattern,
        mixed $handler,
        ?string $name = null,
        array $middleware = []
    ): static {
        if (is_string($methods)) {
            $methods = explode('|', $methods);
        }

        return $this->register(array_map(strtoupper(...), $methods), $pattern, $handler, $name, $middleware);
    }

    /**
     * Alias of add().
     *
     * @param string | list<string> $methods    'GET', 'GET|POST', ['GET', 'POST'] or '*' for any method
     * @param string                $pattern    route pattern, e.g. '/users/{id:int}'
     * @param mixed                 $handler    closure, callable, [Class::class, 'method'], 'Class@method',
     *                                          'Class::method', invokable class name or function name
     * @param string | null         $name       route name for url() and RouteMatch::$name
     * @param list<mixed>           $middleware middleware for this route only, outermost first
     *
     * @return $this
     */
    public function map(
        string | array $methods,
        string $pattern,
        mixed $handler,
        ?string $name = null,
        array $middleware = []
    ): static {
        return $this->add($methods, $pattern, $handler, $name, $middleware);
    }

    /**
     * Register routes under a common prefix and/or middleware. Groups nest: prefixes are joined and
     * middleware lists are concatenated, outermost first.
     * The previous state is restored afterward, even if $routes throws.
     *
     * @param string                 $prefix     path prefix, e.g. '/admin' ('' for middleware only)
     * @param callable(static): void $routes     receives this router and registers the group's routes
     * @param list<mixed>            $middleware middleware applied to every route in the group
     */
    public function group(string $prefix, callable $routes, array $middleware = []): static
    {
        $prevPrefix = $this->prefix;
        $prevMiddleware = $this->groupMiddleware;

        $prefix = trim($prefix, '/');
        if ($prefix !== '') {
            $this->prefix .= '/' . $prefix;
        }
        if ($middleware) {
            $this->groupMiddleware = [...$prevMiddleware, ...$middleware];
        }

        try {
            $routes($this);
        } finally {
            $this->prefix = $prevPrefix;
            $this->groupMiddleware = $prevMiddleware;
        }

        return $this;
    }

    /**
     * Add middleware that runs for every dispatched route, outside any group or route middleware.
     * Middleware signature: fn(callable $next, array $params): mixed, where $next() runs the rest of
     * the chain and $params holds the named route parameters.
     *
     * Global middleware is not part of the route cache; register it after cache().
     *
     * @param mixed ...$middleware
     *
     * @return $this
     */
    public function use(mixed ...$middleware): static
    {
        array_push($this->global, ...$middleware);

        return $this;
    }

    /**
     * Shared implementation of all registration methods:
     *   normalize the pattern,
     *   store the handler,
     *   then insert into the static map or the trie.
     *
     * @param list<string>  $methods    upper-cased method names, or [self::ANY]
     * @param string        $pattern
     * @param mixed         $handler
     * @param string | null $name
     * @param list<mixed>   $middleware route-level middleware
     *
     * @return $this
     */
    private function register(array $methods, string $pattern, mixed $handler, ?string $name, array $middleware): static
    {
        // Normalize to a leading '/' and no trailing '/' (except the root), matching normalize().
        $pattern = $this->prefix . $pattern;
        $pattern = self::normalizePath($pattern);

        // Route ids are positions in the packed $handlers list.
        $id = count($this->handlers);
        $this->handlers[] = $handler;

        if ($middleware || $this->groupMiddleware) {
            $this->middleware[$id] = [...$this->groupMiddleware, ...$middleware];
        }
        if ($name !== null) {
            $this->names[$name] = [$id, $pattern];
        }

        // Fast path: a pattern with none of the parameter markers is a plain hash-map entry.
        if (strpbrk($pattern, '{:*') === false) {
            foreach ($methods as $m) {
                $this->static[$m][$pattern] = $id;
            }

            return $this;
        }

        $this->insertDynamic($methods, $pattern, $id);

        return $this;
    }

    /**
     * Insert a pattern that contains a parameter marker into the trie
     * (or into the static map, if it turns out to have no real parameters).
     *
     * @param list<string> $methods
     * @param string       $pattern
     * @param int          $id
     *
     * @return void
     *
     * @throws InvalidArgumentException when an optional or catch-all parameter is not in the last segment
     */
    private function insertDynamic(array $methods, string $pattern, int $id): void
    {
        $segments = explode('/', $pattern);

        // Single pass for the common case: one method, only static / {name} / :name segments.
        // Writes the trie while parsing. Any other segment form bails out to the general parser
        // below (which re-walks the same prefix, so a partial walk leaves nothing inconsistent).
        if (!isset($methods[1])) {
            $node = &$this->tree[$methods[0]];
            $dynamic = false;
            $simple = true;
            $first = true;
            foreach ($segments as $s) {
                // Skip the empty segment before the leading '/'.
                if ($first) {
                    $first = false;
                    continue;
                }

                // Static segment, unless it hides a mixed parameter such as "v{version}".
                if ($s === '' || ($s[0] !== '{' && $s[0] !== ':' && $s !== '*')) {
                    if (str_contains($s, '{')) {
                        $simple = false;
                        break;
                    }

                    $node = &$node[$s];
                    continue;
                }

                // ":name", or "{name}" with no modifier, regex or nested braces.
                if ($s[0] === ':') {
                    $dynamic = true;
                } elseif ($s[-1] === '}' && strcspn($s, ':?*{}', 1) === strlen($s) - 2) {
                    $dynamic = true;
                } else {
                    $simple = false;
                    break;
                }

                $node = &$node[self::PARAM];
            }

            if ($simple && $dynamic) {
                $node[self::LEAF] = $id;
                $this->patterns[$id] = $pattern;

                return;
            }

            // Bailing out can leave empty (null) placeholder nodes. They are harmless: isset() is
            // false on null, so matching ignores them, and collect()/countLeaves() skip non-arrays.
            unset($node);
        }

        $last = count($segments) - 1;
        // Step encoding: string = static segment, T_PARAM, T_WILD, or [T_REGEX, regex, ?groups].
        $steps = [];
        $names = [];
        $optional = false;

        for ($i = 1; $i <= $last; ++$i) {
            $s = $segments[$i];

            // Fast paths (no regex, no allocation) for the overwhelmingly common segment forms.
            if ($s === '' || ($s[0] !== '{' && $s[0] !== ':' && $s !== '*')) {
                if (!str_contains($s, '{')) {
                    $steps[] = $s;
                    continue;
                }
            } elseif ($s[0] === ':') {
                $steps[] = self::T_PARAM;
                $names[] = substr($s, 1);
                continue;
            } elseif ($s[-1] === '}' && strcspn($s, ':?*{}', 1) === strlen($s) - 2) {
                $steps[] = self::T_PARAM;
                $names[] = substr($s, 1, -1);
                continue;
            }

            [$type, $key, $segNames, $opt, $groups] = $this->parseSegment($s) + [4 => null];
            if ($opt && $i !== $last) {
                throw new InvalidArgumentException("Optional parameters are only allowed in the last segment: $pattern");
            }

            if ($type === self::T_WILD && $i !== $last) {
                throw new InvalidArgumentException("Wildcard parameters are only allowed in the last segment: $pattern");
            }

            $optional = $opt;
            array_push($names, ...$segNames);
            $steps[] = match ($type) {
                null => $key,
                self::T_REGEX => [self::T_REGEX, $key, $groups],
                default => $type,
            };
        }

        if (!$names) {
            // Contained ':' or '*' but not at a segment start: it is actually a static route.
            foreach ($methods as $m) {
                $this->static[$m][$pattern] = $id;
            }

            return;
        }

        // Parameter names are derived from the pattern lazily (see names()); dispatch() never needs them.
        $this->patterns[$id] = $pattern;

        foreach ($methods as $m) {
            $this->insertSteps($this->tree[$m], $steps, $id);
        }

        // "/posts/{page?}" also matches "/posts" (the handler receives its default value).
        if ($optional) {
            array_pop($steps);
            if (count($names) === 1) {
                // The optional parameter was the only one: the short form is a static route.
                $short = substr($pattern, 0, strrpos($pattern, '/')) ?: '/';
                foreach ($methods as $m) {
                    $this->static[$m][$short] = $id;
                }
            } else {
                foreach ($methods as $m) {
                    $this->insertSteps($this->tree[$m], $steps, $id);
                }
            }
        }
    }

    /**
     * Walk (creating as needed) the trie path described by $steps and store $id at its end.
     * Identical regex steps at the same node share one child.
     *
     * @param array | null $node  trie root for one method, by reference (null for a new method)
     * @param list<mixed>  $steps steps produced by insertDynamic()
     */
    private function insertSteps(?array &$node, array $steps, int $id): void
    {
        foreach ($steps as $st) {
            if (is_string($st)) {
                $node = &$node[$st];
            } elseif ($st === self::T_PARAM) {
                $node = &$node[self::PARAM];
            } elseif ($st === self::T_WILD) {
                $node[self::DYN] = ($node[self::DYN] ?? 0) | self::T_WILD;
                $node = &$node[self::WILD];
            } else {
                $node[self::DYN] = ($node[self::DYN] ?? 0) | self::T_REGEX;
                // Reuse the child of an identical regex; otherwise append a new entry, which keeps
                // constrained params in registration order for matching.
                $idx = null;
                foreach ($node[self::REGEX] ?? [] as $j => $entry) {
                    if ($entry[0] === $st[1]) {
                        $idx = $j;
                        break;
                    }
                }

                if ($idx === null) {
                    $node[self::REGEX][] = [$st[1], [], $st[2]];
                    $idx = array_key_last($node[self::REGEX]);
                }

                $node = &$node[self::REGEX][$idx][1];
            }
        }

        $node[self::LEAF] = $id;
    }

    /**
     * Parse one pattern segment that is not handled by the inline fast paths.
     *
     * @return array{0: ?int, 1: ?string, 2: list<string>, 3: bool, 4?: list<string>}
     *         [type (null = static, or T_*), static segment or compiled regex, parameter names,
     *         whether the parameter is optional, regex group names (mixed segments only)]
     */
    private function parseSegment(string $s): array
    {
        if ($s === '*') {
            return [self::T_WILD, null, ['*'], false];
        }

        if ($s !== '' && $s[0] === ':') {
            return [self::T_PARAM, null, [substr($s, 1)], false];
        }

        if (!str_contains($s, '{')) {
            return [null, $s, [], false];
        }

        preg_match_all(self::TOKEN, $s, $tokens, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if (!$tokens) {
            // Braces that don't form a parameter token: treat the segment literally.
            return [null, $s, [], false];
        }

        // Whole segment is a single parameter.
        if (count($tokens) === 1 && $tokens[0][0][0] === $s) {
            $name  = $tokens[0][1][0];
            $mod   = $tokens[0][2][0] ?? '';
            if ($mod === '*') {
                return [self::T_WILD, null, [$name], false];
            }

            $regex = $tokens[0][3][0] ?? '';
            if ($regex === '') {
                return [self::T_PARAM, null, [$name], $mod === '?'];
            }

            return [self::T_REGEX, $this->compile($regex), [$name], $mod === '?'];
        }

        // Mixed segment, e.g. "{name}.{ext}" or "v{version:int}": one regex with a named group per
        // parameter (p0, p1, ...). Named groups keep values correct even if a user regex has its own
        // capturing groups. Unconstrained parts use the lazy ".+?" so "a.tar.gz" splits as a|tar.gz.
        $re = '';
        $pos = 0;
        $names = [];
        $groups = [];
        foreach ($tokens as $n => $t) {
            $re      .= preg_quote(substr($s, $pos, $t[0][1] - $pos), '~');
            $inner    = $t[3][0] ?? '';
            $inner    = $inner === '' ? '.+?' : (self::ALIASES[$inner] ?? str_replace('~', '\~', $inner));
            $re      .= "(?<p$n>$inner)";
            $names[]  = $t[1][0];
            $groups[] = "p$n";
            $pos      = $t[0][1] + strlen($t[0][0]);
        }

        $re .= preg_quote(substr($s, $pos), '~');

        return [self::T_REGEX, '~^' . $re . '$~D', $names, false, $groups];
    }

    /**
     * Compile a {name:regex} constraint (or alias) into an anchored regex that matches a whole segment.
     * The D modifier stops '$' from matching before a trailing newline.
     *
     * @param string $regex
     *
     * @return string
     */
    private function compile(string $regex): string
    {
        $regex = self::ALIASES[$regex] ?? str_replace('~', '\~', $regex);

        return '~^(?:' . $regex . ')$~D';
    }

    // ---------------------------------------------------------------------
    // Matching
    // ---------------------------------------------------------------------

    /**
     * Find a route and invoke its handler (through any middleware). Parameter values are passed to
     * the handler positionally, in pattern order, and are URL-decoded.
     *
     * @param string $method upper-case HTTP method
     * @param string $path   request path; a query string and trailing slash are tolerated
     *
     * @return mixed whatever the handler (or a fallback handler) returns
     *
     * @throws NotFoundException         when nothing matches and no notFound() handler is set
     * @throws MethodNotAllowedException when only other methods match and no methodNotAllowed() handler is set
     */
    public function dispatch(string $method, string $path): mixed
    {
        $v = [];
        if (isset($this->static[$method][$path])) {
            // Hot path 1: exact static hit. One hash lookup, no allocation.
            $id = $this->static[$method][$path];
        } else {
            $id = -1;
            // Hot path 2: greedy inline trie walk for already-clean paths. At each node take the
            // static child if there is one, else a plain {param} if that is the node's only dynamic
            // kind. Greedy static-first is exactly the first branch the full DFS would try, so any
            // leaf reached here IS the correct match; if it dead-ends, the slow path below does the
            // full backtracking search (regex constraints, catch-alls, ambiguous branches).
            if (
                isset($this->tree[$method]) &&
                isset($path[1]) && $path[0] === '/' && !str_contains($path, '?')
            ) {
                $node = $this->tree[$method];
                $first = true;
                foreach (explode('/', $path) as $s) {
                    // Skip the empty segment before the leading '/'.
                    if ($first) {
                        $first = false;
                        continue;
                    }

                    if (isset($node[$s])) {
                        $node = $node[$s];
                        continue;
                    }

                    if (isset($node[self::PARAM]) && !isset($node[self::DYN]) && $s !== '') {
                        $v[] = $s;
                        $node = $node[self::PARAM];
                        continue;
                    }

                    $node = null;
                    break;
                }

                $id = $node[self::LEAF] ?? -1;
                if ($id >= 0 && $v && str_contains($path, '%')) {
                    $v = array_map(rawurldecode(...), $v);
                }
            }

            if ($id < 0) {
                // Slow path: normalize (query string, slashes), HEAD -> GET, any-method routes.
                $v = [];
                $id = $this->locate($method, $path, $v);
                if ($id < 0) {
                    return $this->fail($method, $path);
                }
            }
        }

        $handler = $this->resolved[$id] ?? $this->resolveRoute($id);

        // Routes without middleware skip the pipeline (and its closures) entirely.
        if ($this->global || isset($this->middleware[$id])) {
            return $this->pipeline($id, $handler, $v);
        }

        return $handler(...$v);
    }

    /**
     * Find a route without invoking it.
     *
     * @param string $method upper-case HTTP method
     * @param string $path   request path; a query string and trailing slash are tolerated
     *
     * @return RouteMatch status FOUND with handler, named params and route name; METHOD_NOT_ALLOWED
     *                    with the allowed methods; or NOT_FOUND
     */
    #[NoDiscard]
    public function match(string $method, string $path): RouteMatch
    {
        $values = [];
        $id = $this->locate($method, $path, $values);

        if ($id >= 0) {
            return new RouteMatch(
                self::FOUND,
                $this->handlers[$id],
                $this->named($id, $values),
                [],
                $this->nameOf($id)
            );
        }

        $allowed = $this->allowedMethods($path);

        return new RouteMatch(
            $allowed ? self::METHOD_NOT_ALLOWED : self::NOT_FOUND,
            null,
            [],
            $allowed
        );
    }

    /**
     * Dispatch the current request. Reads $_SERVER unless $method/$uri are given, strips the query
     * string and setBasePath(), and lets a POST become PUT, PATCH or DELETE via the
     * X-HTTP-Method-Override header or a _method form field.
     *
     * @param string | null $method HTTP method; defaults to $_SERVER['REQUEST_METHOD']
     * @param string | null $uri    request URI; defaults to $_SERVER['REQUEST_URI']
     *
     * @return mixed whatever the handler returns
     *
     * @throws NotFoundException | MethodNotAllowedException see dispatch()
     */
    public function run(?string $method = null, ?string $uri = null): mixed
    {
        $method ??= $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri    ??= $_SERVER['REQUEST_URI'] ?? '/';

        // Only the methods HTML forms can't send are accepted as overrides.
        if ($method === 'POST') {
            $override = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? $_POST['_method'] ?? null;
            if (
                is_string($override) &&
                in_array($override = strtoupper($override), ['PUT', 'PATCH', 'DELETE'], true)
            ) {
                $method = $override;
            }
        }

        $q = strpos($uri, '?');
        if ($q !== false) {
            $uri = substr($uri, 0, $q);
        }

        if ($this->basePath !== '' && str_starts_with($uri, $this->basePath)) {
            $uri = substr($uri, strlen($this->basePath)) ?: '/';
        }

        return $this->dispatch($method, $uri);
    }

    /**
     * List the methods that have a route matching $path, for 405 responses and the Allow header.
     * HEAD is included whenever GET is. Any-method routes are not listed.
     *
     * @return list<string>
     */
    #[NoDiscard]
    public function allowedMethods(string $path): array
    {
        $path = self::normalize($path);
        $allowed = [];
        foreach (array_keys($this->static + $this->tree) as $m) {
            $m = (string) $m;
            if ($m === self::ANY) {
                continue;
            }

            $values = [];
            if ($this->locateExact($m, $path, $values) >= 0) {
                $allowed[] = $m;
            }
        }

        if (in_array('GET', $allowed, true) && !in_array('HEAD', $allowed, true)) {
            $allowed[] = 'HEAD';
        }

        return $allowed;
    }

    /**
     * Resolve method + path to a route id. Falls back HEAD -> GET, then to any-method routes, and
     * URL-decodes the parameter values.
     *
     * @param string       $path   normalized in place, so callers can reuse the clean path
     * @param list<string> $values receives positional parameter values
     *
     * @return int route id, or -1 if nothing matches
     */
    private function locate(string $method, string &$path, array &$values): int
    {
        $path = self::normalize($path);

        $id = $this->locateExact($method, $path, $values);
        if ($id < 0 && $method === 'HEAD') {
            $id = $this->locateExact('GET', $path, $values);
        }
        if ($id < 0 && $method !== self::ANY && (isset($this->static[self::ANY]) || isset($this->tree[self::ANY]))) {
            $id = $this->locateExact(self::ANY, $path, $values);
        }
        if ($id >= 0 && $values && str_contains($path, '%')) {
            $values = array_map(rawurldecode(...), $values);
        }

        return $id;
    }

    /**
     * Look up a normalized path for exactly one method key (no fallbacks): the static map first,
     * then the full backtracking walk of that method's trie.
     *
     * @param list<string> $values receives positional parameter values (raw, not decoded)
     *
     * @return int route id, or -1 if nothing matches
     */
    private function locateExact(string $method, string $path, array &$values): int
    {
        if (isset($this->static[$method][$path])) {
            return $this->static[$method][$path];
        }

        if (!isset($this->tree[$method])) {
            return -1;
        }

        $values = [];
        $segments = explode('/', $path);

        return self::walk($this->tree[$method], $segments, 1, count($segments), $values);
    }

    /**
     * General trie walk with backtracking. Precedence at each node: static child, constrained
     * params (in registration order), plain param, catch-all. Recursion only happens at nodes that
     * have a matching static child AND a dynamic alternative, or more than one dynamic kind.
     *
     * @param array        $node trie node to start from
     * @param list<string> $segs path segments ($segs[0] is the empty string before the leading '/')
     * @param int          $i    index of the next segment to match
     * @param int          $n    number of segments
     * @param list<string> $v    parameter values collected so far; on failure, values pushed by
     *                           this call may remain and the caller must truncate
     *
     * @return int route id, or -1 if nothing matches below $node
     */
    private static function walk(array $node, array $segs, int $i, int $n, array &$v): int
    {
        for (; $i < $n; ++$i) {
            $s = $segs[$i];
            // Bitmask of this node's dynamic child kinds.
            $d = ($node[self::DYN] ?? 0) | (isset($node[self::PARAM]) ? self::T_PARAM : 0);

            if (isset($node[$s])) {
                if ($d === 0) {
                    // No alternative to fall back to: iterate instead of recursing.
                    $node = $node[$s];
                    continue;
                }

                $k = count($v);
                if (($id = self::walk($node[$s], $segs, $i + 1, $n, $v)) >= 0) {
                    return $id;
                }

                // The static branch dead-ended: drop its values and try the dynamic children.
                if (count($v) !== $k) {
                    array_splice($v, $k);
                }
            } elseif ($d === 0) {
                return -1;
            }

            if ($d & self::T_REGEX) {
                foreach ($node[self::REGEX] as [$re, $child, $groups]) {
                    if (preg_match($re, $s, $m)) {
                        $k = count($v);
                        if ($groups === null) {
                            // Single constrained parameter: the whole segment is the value.
                            $v[] = $s;
                        } else {
                            // Mixed segment: one value per named group.
                            foreach ($groups as $g) {
                                $v[] = $m[$g];
                            }
                        }

                        if (($id = self::walk($child, $segs, $i + 1, $n, $v)) >= 0) {
                            return $id;
                        }

                        array_splice($v, $k);
                    }
                }
            }

            // A plain parameter never matches an empty segment ("/a//b").
            if (($d & self::T_PARAM) && $s !== '') {
                if (!($d & self::T_WILD)) {
                    // Nothing left to fall back to at this level: iterate instead of recursing.
                    $v[] = $s;
                    $node = $node[self::PARAM];
                    continue;
                }

                $k = count($v);
                $v[] = $s;
                if (($id = self::walk($node[self::PARAM], $segs, $i + 1, $n, $v)) >= 0) {
                    return $id;
                }

                array_splice($v, $k);
            }

            // Catch-all: swallow this and every remaining segment.
            if (isset($node[self::WILD][self::LEAF])) {
                $v[] = implode('/', array_slice($segs, $i));

                return $node[self::WILD][self::LEAF];
            }

            return -1;
        }

        if (isset($node[self::LEAF])) {
            return $node[self::LEAF];
        }

        // A catch-all also matches nothing at all: "/static/{path*}" matches "/static" with path ''.
        if (isset($node[self::WILD][self::LEAF])) {
            $v[] = '';

            return $node[self::WILD][self::LEAF];
        }

        return -1;
    }

    /**
     * Canonical form of a request path: query string removed, a leading '/' and no trailing '/'
     * (except for the root). Patterns are normalized the same way in register().
     */
    private static function normalize(string $path): string
    {
        $q = strpos($path, '?');
        if ($q !== false) {
            $path = substr($path, 0, $q);
        }

        return self::normalizePath($path);
    }

    // ---------------------------------------------------------------------
    // Invocation
    // ---------------------------------------------------------------------
    /** Resolve the handler of route $id to a callable and memoize it in $resolved. */
    private function resolveRoute(int $id): callable
    {
        return $this->resolved[$id] = $this->toCallable($this->handlers[$id]);
    }

    /**
     * Turn a handler or middleware definition into a callable. Supported forms:
     *   Closure / any callable, [Class::class, 'method'], 'Class@method', 'Class::method',
     *   invokable class name, function name.
     * Controller instances are created once per class (through the resolver if one is set), and
     * each Class::method pair is resolved (including the reflection check) only once.
     *
     * @throws LogicException when the handler names a missing method or is not callable
     */
    private function toCallable(mixed $handler): callable
    {
        if ($handler instanceof Closure) {
            return $handler;
        }

        if (is_string($handler)) {
            if (str_contains($handler, '@')) {
                $handler = explode('@', $handler, 2);
            } elseif (str_contains($handler, '::')) {
                $handler = explode('::', $handler, 2);
            } elseif (class_exists($handler)) {
                return $this->instance($handler);
            } elseif (is_callable($handler)) {
                return $handler;
            }
        }

        if (is_array($handler) && is_string($handler[0] ?? null) && is_string($handler[1] ?? null)) {
            [$class, $method] = $handler;
            // Many routes usually share a few controller methods: resolve each Class::method once.
            $key = "$class::$method";
            if (isset($this->instances[$key])) {
                return $this->instances[$key];
            }

            if (!method_exists($class, $method)) {
                throw new LogicException("Route handler $class::$method does not exist");
            }

            // Static methods are called on the class; instance methods on a shared instance.
            return $this->instances[$key] = (new ReflectionMethod($class, $method))->isStatic()
                ? [$class, $method]
                : [$this->instance($class), $method];
        }

        if (is_callable($handler)) {
            return $handler;
        }

        throw new LogicException('Route handler is not callable: ' . get_debug_type($handler));
    }

    /** Shared instance of $class, created on first use through the resolver or `new $class()`. */
    private function instance(string $class): object
    {
        return $this->instances[$class] ??= $this->resolver !== null ? ($this->resolver)($class) : new $class();
    }

    /**
     * Run a route through global, group and route middleware, outermost first, ending with the handler.
     * Each middleware receives a $next closure and the named parameters.
     *
     * @param int           $id
     * @param callable     $handler
     * @param list<string> $values positional parameter values for the handler
     *
     * @return mixed
     */
    private function pipeline(int $id, callable $handler, array $values): mixed
    {
        $stack = isset($this->middleware[$id]) ? [...$this->global, ...$this->middleware[$id]] : $this->global;
        $params = $this->named($id, $values);

        // Build the onion from the inside out: the innermost $next calls the handler.
        $next = static fn () => $handler(...$values);
        for ($i = count($stack) - 1; $i >= 0; --$i) {
            $mw = $this->toCallable($stack[$i]);
            $inner = $next;
            $next = static fn () => $mw($inner, $params);
        }

        return $next();
    }

    /**
     * Handle a dispatch that found no route: call the configured fallback handler, or throw.
     *
     * @throws MethodNotAllowedException when other methods match and no methodNotAllowed() handler is set
     * @throws NotFoundException         when nothing matches and no notFound() handler is set
     */
    private function fail(string $method, string $path): mixed
    {
        $allowed = $this->allowedMethods($path);

        if ($allowed) {
            if ($this->methodNotAllowed !== null) {
                return $this->toCallable($this->methodNotAllowed)($allowed, $method, $path);
            }
            throw new MethodNotAllowedException($method, $path, $allowed);
        }

        if ($this->notFound !== null) {
            return $this->toCallable($this->notFound)($method, $path);
        }
        throw new NotFoundException($method, $path);
    }

    /**
     * Parameter names of a route, in pattern order. Parsed from its pattern on first use, then cached;
     * identical lists are interned so they share one array.
     *
     * @return list<string> empty for static routes
     */
    private function names(int $id): array
    {
        if (isset($this->params[$id])) {
            return $this->params[$id];
        }
        if (!isset($this->patterns[$id])) {
            return [];
        }

        $names = [];
        foreach (explode('/', $this->patterns[$id]) as $s) {
            if ($s === '*') {
                $names[] = '*';
            } elseif ($s !== '' && $s[0] === ':') {
                $names[] = substr($s, 1);
            } elseif (str_contains($s, '{') && preg_match_all(self::TOKEN, $s, $m)) {
                array_push($names, ...$m[1]);
            }
        }

        return $this->params[$id] = $this->nameLists[implode('/', $names)] ??= $names;
    }

    /**
     * Pair positional values with the route's parameter names. Missing trailing values (the short
     * form of an optional parameter) become null.
     *
     * @param list<string> $values
     *
     * @return array<string, ?string>
     */
    private function named(int $id, array $values): array
    {
        $names = $this->names($id);
        if (!$names) {
            return [];
        }
        if (count($values) !== count($names)) {
            $values = array_pad(array_slice($values, 0, count($names)), count($names), null);
        }

        return array_combine($names, $values);
    }

    /** Name of route $id, or null if it has none. Only used by match(), never by dispatch(). */
    private function nameOf(int $id): ?string
    {
        foreach ($this->names as $name => [$routeId]) {
            if ($routeId === $id) {
                return $name;
            }
        }

        return null;
    }

    // ---------------------------------------------------------------------
    // Configuration
    // ---------------------------------------------------------------------
    /**
     * Set the handler dispatch() calls when nothing matches, with ($method, $path).
     * Any handler form is accepted. Not part of the route cache.
     */
    public function notFound(mixed $handler): static
    {
        $this->notFound = $handler;

        return $this;
    }

    /**
     * Set the handler dispatch() calls when the path exists for other methods only, with
     * ($allowedMethods, $method, $path). Any handler form is accepted. Not part of the route cache.
     */
    public function methodNotAllowed(mixed $handler): static
    {
        $this->methodNotAllowed = $handler;

        return $this;
    }

    /**
     * Set how controller classes are instantiated, e.g. fn(string $class) => $container->get($class).
     * Pass null to go back to `new $class()`. Clears already-resolved handlers and instances.
     * Not part of the route cache.
     *
     * @param (callable(class-string): object)|null $resolver
     *
     * @return $this
     */
    public function setResolver(?callable $resolver): static
    {
        $this->resolver = $resolver === null ? null : $resolver(...);
        $this->instances = [];
        $this->resolved = [];

        return $this;
    }

    /**
     * Set the prefix run() strips from REQUEST_URI, for apps served from a subdirectory (e.g. '/app').
     *
     * @param string $basePath
     *
     * @return $this
     */
    public function setBasePath(string $basePath): static
    {
        $this->basePath = rtrim($basePath, '/');

        return $this;
    }

    // ---------------------------------------------------------------------
    // URL generation
    // ---------------------------------------------------------------------
    /**
     * Build a URL for a named route. Values are rawurlencode()d; catch-all values keep their '/'.
     * Optional parameters and catch-alls may be omitted.
     *
     * @param string                $name   route name given at registration
     * @param array<string, scalar> $params parameter values by name
     * @param array<string, mixed>  $query  appended as a query string with http_build_query()
     *
     * @throws InvalidArgumentException for an unknown route name or a missing required parameter
     */
    #[NoDiscard]
    public function url(string $name, array $params = [], array $query = []): string
    {
        $pattern = $this->names[$name][1] ?? throw new InvalidArgumentException("Unknown route name: $name");

        $segments = explode('/', $pattern);
        $out = [];
        foreach ($segments as $i => $s) {
            // Skip the empty segment before the leading '/'.
            if ($i === 0) {
                continue;
            }
            // ":name" or bare "*" segments.
            if ($s === '*' || ($s !== '' && $s[0] === ':')) {
                $key = $s === '*' ? '*' : substr($s, 1);
                if (!isset($params[$key])) {
                    throw new InvalidArgumentException("Missing parameter \"$key\" for route $name");
                }
                $out[] = $s === '*' ? (string) $params[$key] : rawurlencode((string) $params[$key]);
                continue;
            }
            if (!str_contains($s, '{')) {
                $out[] = $s;
                continue;
            }

            // Brace tokens, possibly mixed with literal text in the same segment.
            $skip = false;
            $out[] = preg_replace_callback(
                self::TOKEN,
                static function (array $m) use ($params, $name, &$skip): string {
                    $key = $m[1];
                    $mod = $m[2] ?? '';
                    if (!isset($params[$key]) || $params[$key] === '') {
                        if ($mod === '?' || $mod === '*') {
                            $skip = true;

                            return '';
                        }
                        throw new InvalidArgumentException("Missing parameter \"$key\" for route $name");
                    }

                    return $mod === '*'
                        ? implode('/', array_map(rawurlencode(...), explode('/', (string) $params[$key])))
                        : rawurlencode((string) $params[$key]);
                },
                $s,
            );
            // An omitted optional/catch-all parameter drops its whole segment, not just the token.
            if ($skip && end($out) === '') {
                array_pop($out);
            }
        }

        $url = '/' . implode('/', $out);
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }

    /** Whether a route with this name has been registered. */
    #[NoDiscard]
    public function hasRoute(string $name): bool
    {
        return isset($this->names[$name]);
    }

    // ---------------------------------------------------------------------
    // Introspection & caching
    // ---------------------------------------------------------------------

    /**
     * Number of route entries, i.e. the number of items routes() returns: one per method, plus one
     * for the short form of an optional-parameter route. Counted directly, without building the list.
     */
    #[NoDiscard]
    public function count(): int
    {
        $n = 0;
        foreach ($this->static as $paths) {
            $n += count($paths);
        }
        foreach ($this->tree as $root) {
            $n += self::countLeaves($root);
        }

        return $n;
    }

    /**
     * Number of LEAF entries in a trie node and everything below it.
     *
     * @param array $node
     *
     * @return int
     */
    private static function countLeaves(array $node): int
    {
        $n = isset($node[self::LEAF]) ? 1 : 0;
        foreach ($node as $key => $child) {
            if ($key === self::REGEX) {
                // A list of [regex, child, groups] entries, not a node.
                foreach ($child as $entry) {
                    $n += self::countLeaves($entry[1]);
                }
            } elseif (is_array($child)) {
                // Skips LEAF ids, the DYN flag and null placeholders.
                $n += self::countLeaves($child);
            }
        }

        return $n;
    }

    /**
     * Iterate over routes() entries: foreach ($router as $route) { $route['method'], $route['pattern'] }.
     *
     * @return Generator<int, array{id: int, method: string, pattern: string}>
     */
    #[NoDiscard]
    public function getIterator(): Generator
    {
        yield from $this->routes();
    }

    /**
     * List all registered routes, ordered by registration (route id), then method. Patterns are
     * reconstructed from the tables, so nothing extra is stored for this. Alias constraints are
     * shown expanded (e.g. {id:\d+} for {id:int}).
     *
     * @return list<array{id: int, method: string, pattern: string}>
     */
    #[NoDiscard]
    public function routes(): array
    {
        $list = [];
        foreach ($this->static as $method => $paths) {
            foreach ($paths as $path => $id) {
                $list[] = ['id' => $id, 'method' => $method, 'pattern' => $path];
            }
        }
        foreach ($this->tree as $method => $root) {
            $this->collect($root, [], $method, $list);
        }
        usort($list, static fn ($a, $b) => [$a['id'], $a['method']] <=> [$b['id'], $b['method']]);

        return $list;
    }

    /**
     * Depth-first collection of every route below a trie node.
     *
     * @param array $node
     * @param list<mixed>  $parts path so far: static segments, "\0p" (param), "\0w" (catch-all) or
     *                            ["\0r", regex, ?groups] (constrained/mixed)
     * @param list<array{id: int, method: string, pattern: string}> $list receives the entries
     */
    /**
     * @param array       $node
     * @param list<mixed> $parts path so far: static segments, "\0p" (param), "\0w" (catch-all) or
     *                           ["\0r", regex, ?groups] (constrained/mixed)
     * @param string      $method
     * @param list<array{id: int, method: string, pattern: string}> $list receives the entries
     *
     * @return void
     */
    private function collect(array $node, array $parts, string $method, array &$list): void
    {
        foreach ($node as $key => $child) {
            // Numeric segments such as "2024" become int keys; compare as strings.
            $key = (string) $key;
            if ($key === self::LEAF) {
                $list[] = ['id' => $child, 'method' => $method, 'pattern' => $this->rebuild($child, $parts)];
            } elseif (!is_array($child)) {
                continue; // DYN flag, or an empty placeholder left by an aborted fast insert
            } elseif ($key === self::PARAM) {
                $this->collect($child, [...$parts, "\0p"], $method, $list);
            } elseif ($key === self::WILD) {
                $this->collect($child, [...$parts, "\0w"], $method, $list);
            } elseif ($key === self::REGEX) {
                foreach ($child as [$re, $sub, $groups]) {
                    $this->collect($sub, [...$parts, ["\0r", $re, $groups]], $method, $list);
                }
            } elseif ($key !== self::DYN) {
                $this->collect($child, [...$parts, $key], $method, $list);
            }
        }
    }

    /**
     * Turn collected trie parts back into a pattern string, using the route's parameter names.
     *
     * @param int         $id
     * @param list<mixed> $parts path so far: static segments, "\0p" (param), "\0w" (catch-all) or
     *                           ["\0r", regex, ?groups] (constrained/mixed)
     *
     * @return string
     */
    private function rebuild(int $id, array $parts): string
    {
        $names = $this->names($id);
        $n = 0;
        $out = '';
        foreach ($parts as $p) {
            if ($p === "\0p") {
                $out .= '/{' . ($names[$n++] ?? '?') . '}';
            } elseif ($p === "\0w") {
                $out .= '/{' . ($names[$n++] ?? '*') . '*}';
            } elseif (is_array($p)) {
                if ($p[2] === null) {
                    // Strip the "~^(?:" prefix and ")$~D" suffix added by compile().
                    $out .= '/{' . ($names[$n++] ?? '?') . ':' . substr($p[1], 5, -4) . '}';
                } else {
                    // Mixed segment: show the compiled regex, and skip past its parameter names.
                    $out .= '/' . $p[1];
                    $n += count($p[2]);
                }
            } else {
                $out .= '/' . $p;
            }
        }

        return $out === '' ? '/' : $out;
    }

    /**
     * Export the compiled route table as plain arrays, for import() or a cache file.
     * Global middleware, the resolver and the 404/405 handlers are not included.
     *
     * @return array<string, mixed>
     *
     * @throws LogicException if any handler or route middleware is a Closure or object, which
     *                        var_export() cannot reproduce
     */
    #[NoDiscard]
    public function export(): array
    {
        $check = static function (mixed $h, string $what): void {
            if ($h instanceof Closure || is_object($h) || (is_array($h) && is_object($h[0] ?? null))) {
                throw new LogicException("Cannot cache routes: $what is a Closure/object. Use [Class::class, 'method'] or 'Class@method'.");
            }
        };

        foreach ($this->handlers as $id => $h) {
            $check($h, "handler #$id");
        }

        foreach ($this->middleware as $list) {
            foreach ($list as $mw) {
                $check($mw, 'route middleware');
            }
        }

        // Short keys keep the cache file small.
        return [
            'v' => self::VERSION,
            's' => $this->static,
            't' => $this->tree,
            'h' => $this->handlers,
            'p' => $this->patterns,
            'm' => $this->middleware,
            'n' => $this->names,
        ];
    }

    /**
     * Replace the route table with one produced by export(). Global middleware, the resolver and
     * the 404/405 handlers are kept as they are on this router.
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidArgumentException if $data was exported by a different GLRoute version
     */
    public function import(array $data): static
    {
        if (($data['v'] ?? null) !== self::VERSION) {
            throw new InvalidArgumentException('Route cache was built by a different GLRoute version');
        }

        $this->static     = $data['s'];
        $this->tree       = $data['t'];
        $this->handlers   = $data['h'];
        $this->patterns   = $data['p'];
        $this->params     = [];
        $this->middleware = $data['m'];
        $this->names      = $data['n'];
        $this->resolved   = [];

        return $this;
    }

    /**
     * Load routes from a PHP cache file, or run $define and write the cache. With OPcache enabled the
     * loaded table lives in shared memory, so registration costs nothing and uses no request memory.
     * A cache from another GLRoute version is ignored and rebuilt. Delete the file to rebuild it.
     *
     * @param string                 $file    path of the cache file (its directory must be writable)
     * @param callable(static): void $define  registers the routes; only called when the cache is missing
     * @param bool                   $enabled false to always run $define and skip the file entirely
     *
     * @throws LogicException | RandomException if routes must be written but contain closures (see export())
     */
    public function cache(string $file, callable $define, bool $enabled = true): static
    {
        if ($enabled && is_file($file)) {
            $data = require $file;
            if (is_array($data) && ($data['v'] ?? null) === self::VERSION) {
                return $this->import($data);
            }
        }

        $define($this);

        if ($enabled) {
            // Write to a unique temp file and rename(): concurrent requests never see a partial file.
            $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
            file_put_contents($tmp, '<?php return ' . var_export($this->export(), true) . ';' . PHP_EOL, LOCK_EX);
            rename($tmp, $file);
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($file, true);
            }
        }

        return $this;
    }
}