# GLRoute

A PHP router with no dependencies. It is one class plus three small value classes.

- **Static routes** are stored in a flat hash map, so a match costs one `isset()` and no `explode()`.
- **Dynamic routes** are stored in a segment trie per HTTP method. A match costs one `isset()` per segment.
- **Compiled cache:** under OPcache the route table lives in shared memory. Registration becomes nearly free and uses almost no request memory.
- **Features:** named params, regex constraints and aliases, optional and catch-all params, mixed segments (`{name}.{ext}`), groups, middleware, named routes with URL generation, 404/405 with an `Allow` list, HEAD→GET fallback, any-method routes, controller resolution with a container hook, method override, and base path support.

Requires PHP 8.3+, `match()`, `url()`, `allowedMethods()`, `hasRoute()`, `routes()` and `export()` are marked `#[\NoDiscard]`, so calling one and ignoring the result raises a warning. 

Based on:
- [Sharkk Router](https://github.com/Valgorithms/sharkk-router)
- [Jaunt](https://github.com/davenusbaum/jaunt)
- [Bramus Router](https://github.com/bramus/router)

## Example
```php
use GeekLab\GLRoute\Router;

$r = new Router();

$r->get('/', fn () => 'home');
$r->get('/users/{id:int}', [UserController::class, 'show'], name: 'user.show');
$r->post('/users', 'UserController@store');
$r->get('/files/{path*}', fn (string $path) => "file: $path");

$r->group(
    '/admin',
    function (Router $r) {
        $r->get('/stats', [AdminController::class, 'stats']);
    },
    middleware: [AuthMiddleware::class]
);

echo $r->run();                               // reads $_SERVER
echo $r->dispatch('GET', '/users/42');        // or dispatch explicitly
echo $r->url('user.show', ['id' => 42]);      // "/users/42"
```

## Route patterns

| Pattern | Matches | Handler receives |
|---|---|---|
| `/about` | `/about` (and `/about/`) | nothing |
| `/posts/{id}` or `/posts/:id` | one non-empty segment | `$id` |
| `/posts/{id:\d+}` | a segment matching the regex | `$id` |
| `/posts/{id:int}` | aliases: `int` `num` `alpha` `alnum` `slug` `hex` `uuid` | `$id` |
| `/blog/{page?}` | `/blog` and `/blog/2` (last segment only) | `$page`, or the handler's default value |
| `/assets/{path*}` or `/assets/*` | everything after the prefix, including `/` (last segment only) | `$path` |
| `/files/{name}.{ext}` | mixed literal and param segments | `$name`, `$ext` |

Parameters are passed to the handler **positionally**, in pattern order.

**Precedence.** At each segment the router tries a static match first, then regex-constrained params (in registration order), then a plain `{param}`, then a catch-all. It backtracks if needed, so `/users/new` (static) and `/users/{id}/edit` both work for `/users/new/edit`.

## Handlers

Any of these work, and controllers are created lazily on first dispatch:

```php
fn ($id) => ...                    // closure
[UserController::class, 'show']    // instance method: one shared instance per class
UserController::class . '@show'    // same
'UserController::list'             // static method
InvokableController::class         // __invoke
'my_function'                      // function name
```

Plug in your DI container with `$r->setResolver(fn (string $class) => $container->get($class));`.

## Methods, 404 and 405

```php
$r->add('GET|POST', '/form', $h);   // or ['GET', 'POST']
$r->any('/ping', $h);               // any method (method-specific routes win)
// put() patch() delete() options() also exist. HEAD automatically falls back to GET.

$r->notFound(fn (string $method, string $path) => ...);
$r->methodNotAllowed(fn (array $allowed, string $method, string $path) => ...);
```

Without these handlers, `dispatch()` throws `NotFoundException` or `MethodNotAllowedException`. The latter has `->allowed` and `->allowHeader()`.

`match()` finds a route without calling it:

```php
$m = $r->match('GET', '/users/42');
$m->status;   // 200 | 404 | 405
$m->handler;  // as registered
$m->params;   // ['id' => '42']
$m->allowed;  // on 405: ['GET', 'HEAD', ...]
$m->name;     // 'user.show'
```

## Groups and middleware

Middleware signature: `fn (callable $next, array $params): mixed`. It can be any handler form listed above.

```php
$r->use(function (callable $next, array $params) {                 // global: every route
    $t = hrtime(true);
    $out = $next();
    header('Server-Timing: app;dur=' . (hrtime(true) - $t) / 1e6);
    return $out;
});
$r->group('/api', function (Router $r) {
    $r->get('/me', $h, middleware: [RateLimit::class]);            // per route
}, middleware: [[Auth::class, 'handle']]);                         // per group (nests)
```

Routes with no middleware skip the pipeline entirely.

## URL generation

```php
$r->get('/posts/{id:int}/{slug}', $h, name: 'post');
$r->url('post', ['id' => 5, 'slug' => 'hello world'], ['ref' => 'rss']);
// "/posts/5/hello%20world?ref=rss"
```

## Compiled cache (recommended for production)

```php
$router = (new Router())->cache(__DIR__ . '/var/routes.php', function (Router $r) {
    require __DIR__ . '/routes.php';
}, enabled: getenv('APP_ENV') === 'prod');
```

On the first request the router writes the compiled table as a plain PHP array. On later requests it `require`s that file, so OPcache serves the array from shared memory: no trie building and no per-request copy. Handlers and route middleware **must not be closures** when caching; use `[Class::class, 'method']` or `'Class@method'`. Global middleware, the resolver and the 404/405 handlers are not cached, so set them after `cache()`. Delete the file to rebuild it.

`routes()` lists everything registered (`id`, `method`, `pattern`) for debugging or a `route:list` command. The router is also `Countable` and iterable:

```php
count($r);                       // number of entries routes() returns (one per method)
foreach ($r as $route) {
    echo "{$route['method']} {$route['pattern']}\n";
}
```

## Development

```bash
composer install && vendor/bin/phpunit
cd benchmark && composer install && php run.php               # optional: --data=<php-router-benchmark>/src/TestData
php -d opcache.enable_cli=1 cache.php
```

## License

BSD-3-Clause
