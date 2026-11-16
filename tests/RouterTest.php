<?php

declare(strict_types=1);

namespace GeekLab\GLRoute\Tests;

use GeekLab\GLRoute\MethodNotAllowedException;
use GeekLab\GLRoute\NotFoundException;
use GeekLab\GLRoute\Router;
use \InvalidArgumentException;
use \LogicException;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixtures.php';

/**
 * Behavior tests for Router. Every test registers its own routes on a fresh router.
 * PHPUnit runs with failOnWarning, so any warning (e.g. from #[\NoDiscard]) fails the suite.
 */
final class RouterTest extends TestCase
{
    /** Static routes match exactly, tolerating an empty path, a trailing slash and a query string. */
    public function testStaticRoutes(): void
    {
        $r = new Router();
        $r->get('/', fn () => 'home');
        $r->get('/about/team', fn () => 'team');

        self::assertSame('home', $r->dispatch('GET', '/'));
        self::assertSame('home', $r->dispatch('GET', ''));
        self::assertSame('team', $r->dispatch('GET', '/about/team'));
        self::assertSame('team', $r->dispatch('GET', '/about/team/'));
        self::assertSame('team', $r->dispatch('GET', '/about/team?x=1'));
    }

    /** {name} and :name parameters are equivalent and are passed positionally; match() names them. */
    public function testBraceAndColonParams(): void
    {
        $r = new Router();
        $r->get('/posts/{id}', fn (string $id) => "post:$id");
        $r->get('/u/:name/posts/:post', fn ($name, $post) => "$name/$post");

        self::assertSame('post:42', $r->dispatch('GET', '/posts/42'));
        self::assertSame('bob/7', $r->dispatch('GET', '/u/bob/posts/7'));
        self::assertSame(['name' => 'bob', 'post' => '7'], $r->match('GET', '/u/bob/posts/7')->params);
    }

    /** Static segments win over parameters, and a dead-ended static branch backtracks to the parameter branch. */
    public function testStaticBeatsDynamicAndBacktracks(): void
    {
        $r = new Router();
        $r->get('/users/new', fn () => 'new-form');
        $r->get('/users/{id}', fn ($id) => "show:$id");
        $r->get('/users/{id}/edit', fn ($id) => "edit:$id");
        $r->get('/users/new/x/{a}', fn ($a) => "nx:$a");

        self::assertSame('new-form', $r->dispatch('GET', '/users/new'));
        self::assertSame('show:5', $r->dispatch('GET', '/users/5'));
        // "new" exists as a static child but /users/new/edit is only reachable via {id}: must backtrack.
        self::assertSame('edit:new', $r->dispatch('GET', '/users/new/edit'));
        self::assertSame('nx:1', $r->dispatch('GET', '/users/new/x/1'));
    }

    /** Values collected on a failed branch are discarded before trying the next one. */
    public function testBacktrackingRestoresParams(): void
    {
        $r = new Router();
        $r->get('/a/{x}/b/{y}/c', fn ($x, $y) => "1:$x,$y");
        $r->get('/a/{x}/{z}/d', fn ($x, $z) => "2:$x,$z");

        self::assertSame('1:p,q', $r->dispatch('GET', '/a/p/b/q/c'));
        self::assertSame('2:p,b', $r->dispatch('GET', '/a/p/b/d'));
        self::assertSame(['x' => 'p', 'z' => 'b'], $r->match('GET', '/a/p/b/d')->params);
    }

    /** Regex constraints (including quantifiers with braces) and aliases filter which routes match. */
    public function testRegexConstraintsAndAliases(): void
    {
        $r = new Router();
        $r->get('/item/{id:\d+}', fn ($id) => "num:$id");
        $r->get('/item/{slug}', fn ($slug) => "slug:$slug");
        $r->get('/year/{y:\d{4}}', fn ($y) => "y:$y");
        $r->get('/uuid/{u:uuid}', fn ($u) => "u:$u");

        self::assertSame('num:12', $r->dispatch('GET', '/item/12'));
        self::assertSame('slug:abc', $r->dispatch('GET', '/item/abc'));
        self::assertSame('y:2026', $r->dispatch('GET', '/year/2026'));
        self::assertSame(404, $r->match('GET', '/year/26')->status);
        self::assertSame('u:123e4567-e89b-12d3-a456-426614174000', $r->dispatch('GET', '/uuid/123e4567-e89b-12d3-a456-426614174000'));
    }

    /** Segments that mix literal text and parameters, e.g. {name}.{ext} or v{version:int}. */
    public function testMixedSegments(): void
    {
        $r = new Router();
        $r->get('/files/{name}.{ext}', fn ($name, $ext) => "$name|$ext");
        $r->get('/api/v{version:int}/ping', fn ($v) => "v$v");

        self::assertSame('report|tar.gz', $r->dispatch('GET', '/files/report.tar.gz'));
        self::assertSame('v2', $r->dispatch('GET', '/api/v2/ping'));
        self::assertSame(['name' => 'a', 'ext' => 'pdf'], $r->match('GET', '/files/a.pdf')->params);
    }

    /** Optional last parameters fall back to the handler default; catch-alls take the rest of the path, or nothing. */
    public function testOptionalAndWildcard(): void
    {
        $r = new Router();
        $r->get('/blog/{page?}', fn ($page = '1') => "page:$page");
        $r->get('/cat/{c}/{page?:int}', fn ($c, $page = '1') => "$c:$page");
        $r->get('/static/{path*}', fn ($path) => "path:$path");
        $r->get('/dl/*', fn ($rest) => "dl:$rest");

        self::assertSame('page:1', $r->dispatch('GET', '/blog'));
        self::assertSame('page:3', $r->dispatch('GET', '/blog/3'));
        self::assertSame('x:1', $r->dispatch('GET', '/cat/x'));
        self::assertSame('x:4', $r->dispatch('GET', '/cat/x/4'));
        self::assertSame(['c' => 'x', 'page' => null], $r->match('GET', '/cat/x')->params);
        self::assertSame('path:css/app/main.css', $r->dispatch('GET', '/static/css/app/main.css'));
        self::assertSame('path:', $r->dispatch('GET', '/static'));
        self::assertSame('dl:a/b', $r->dispatch('GET', '/dl/a/b'));
    }

    /** An optional parameter outside the last segment is rejected at registration. */
    public function testOptionalNotLastThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Router())->get('/a/{b?}/c', fn () => 1);
    }

    /** Multi-method and any-method routes, HEAD falling back to GET, and 405 with the allowed methods. */
    public function testMethodsHeadAnyAnd405(): void
    {
        $r = new Router();
        $r->get('/thing/{id}', fn ($id) => "get:$id");
        $r->post('/thing/{id}', fn ($id) => "post:$id");
        $r->add('PUT|PATCH', '/thing/{id}', fn ($id) => "write:$id");
        $r->any('/ping', fn () => 'pong');

        self::assertSame('post:1', $r->dispatch('POST', '/thing/1'));
        self::assertSame('write:1', $r->dispatch('PATCH', '/thing/1'));
        self::assertSame('get:1', $r->dispatch('HEAD', '/thing/1'));
        self::assertSame('pong', $r->dispatch('DELETE', '/ping'));

        $m = $r->match('DELETE', '/thing/1');
        self::assertSame(405, $m->status);
        self::assertSame(['GET', 'POST', 'PUT', 'PATCH', 'HEAD'], $m->allowed);

        try {
            $r->dispatch('DELETE', '/thing/1');
            self::fail('expected 405');
        } catch (MethodNotAllowedException $e) {
            self::assertStringContainsString('POST', $e->allowHeader());
        }
    }

    /** A static route for another method doesn't hide this method's dynamic route. */
    public function testMethodSpecificMatchBeatsOtherMethodsStaticRoute(): void
    {
        $r = new Router();
        $r->post('/users/new', fn () => 'create');
        $r->get('/users/{id}', fn ($id) => "show:$id");

        self::assertSame('show:new', $r->dispatch('GET', '/users/new'));
        self::assertSame('create', $r->dispatch('POST', '/users/new'));
    }

    /** delete() registers a DELETE-only route: other methods get a 405 listing DELETE, and it can be named. */
    public function testDeleteRoute(): void
    {
        $r = new Router();
        $r->get('/posts/{id}', fn ($id) => "show:$id");
        $r->delete('/posts/{id:\d+}', fn ($id) => "deleted:$id", 'posts.delete');
        $r->delete('/session', fn () => 'logout');

        self::assertSame('deleted:42', $r->dispatch('DELETE', '/posts/42'));
        self::assertSame('show:42', $r->dispatch('GET', '/posts/42'));
        self::assertSame('logout', $r->dispatch('DELETE', '/session'));

        $m = $r->match('DELETE', '/posts/42');
        self::assertSame(200, $m->status);
        self::assertSame('posts.delete', $m->name);
        self::assertSame(['id' => '42'], $m->params);
        self::assertSame('/posts/42', $r->url('posts.delete', ['id' => 42]));

        // The \d+ constraint applies to DELETE only; a non-numeric id doesn't match it.
        self::assertSame(405, $r->match('DELETE', '/posts/abc')->status);

        $m = $r->match('POST', '/session');
        self::assertSame(405, $m->status);
        self::assertSame(['DELETE'], $m->allowed);
    }

    /** Unmatched paths give a 404 match and make dispatch() throw NotFoundException. */
    public function testNotFound(): void
    {
        $r = new Router();
        $r->get('/a', fn () => 'a');
        self::assertSame(404, $r->match('GET', '/b')->status);

        $this->expectException(NotFoundException::class);
        $r->dispatch('GET', '/b');
    }

    /** notFound() and methodNotAllowed() handlers replace the exceptions. */
    public function testFallbackHandlers(): void
    {
        $r = new Router();
        $r->get('/a', fn () => 'a');
        $r->notFound(fn ($m, $p) => "404 $m $p");
        $r->methodNotAllowed(fn (array $allowed) => '405 ' . implode(',', $allowed));

        self::assertSame('404 GET /zzz', $r->dispatch('GET', '/zzz'));
        self::assertSame('405 GET,HEAD', $r->dispatch('POST', '/a'));
    }

    /** Nested group prefixes and middleware, route middleware and global middleware run in onion order. */
    public function testGroupsAndMiddleware(): void
    {
        $r = new Router();
        $r->use(fn (callable $next) => 'G(' . $next() . ')');
        $r->group('/admin', function (Router $r) {
            $r->get('/', fn () => 'dash');
            $r->group('users', function (Router $r) {
                $r->get('/{id}', fn ($id) => "u$id", middleware: [fn ($next, $p) => 'R' . $p['id'] . '(' . $next() . ')']);
            });
        }, middleware: [fn ($next) => 'A(' . $next() . ')', [Tag::class, 'mw']]);
        $r->get('/plain', fn () => 'plain');

        self::assertSame('G(A([dash]))', $r->dispatch('GET', '/admin'));
        self::assertSame('G(A([R9(u9)]))', $r->dispatch('GET', '/admin/users/9'));
        self::assertSame('G(plain)', $r->dispatch('GET', '/plain'));
    }

    /** All controller handler forms work, and controller instances are shared. */
    public function testControllerHandlers(): void
    {
        UserController::$constructed = 0;
        $r = new Router();
        $r->get('/u/{id}', [UserController::class, 'show']);
        $r->get('/v/{id}', UserController::class . '@show');
        $r->get('/list', UserController::class . '::list');
        $r->get('/inv/{slug?}', Invokable::class);

        self::assertSame('user:1', $r->dispatch('GET', '/u/1'));
        self::assertSame('user:2', $r->dispatch('GET', '/u/2'));
        self::assertSame('user:3', $r->dispatch('GET', '/v/3'));
        self::assertSame('users', $r->dispatch('GET', '/list'));
        self::assertSame('inv:x', $r->dispatch('GET', '/inv/x'));
        self::assertSame('inv:none', $r->dispatch('GET', '/inv'));
        self::assertSame(1, UserController::$constructed, 'controller instances are shared');
    }

    /** setResolver() decides how controller classes are instantiated. */
    public function testResolver(): void
    {
        $r = new Router();
        $r->setResolver(function (string $class) {
            // Ignores $class and returns a stand-in to prove the resolver is used.
            return new class {
                public function show(string $id): string
                {
                    return "resolved:$id";
                }
            };
        });
        $r->get('/u/{id}', [UserController::class, 'show']);
        self::assertSame('resolved:1', $r->dispatch('GET', '/u/1'));
    }

    /** url() fills parameters, encodes values, drops omitted optional segments and appends the query. */
    public function testUrlGeneration(): void
    {
        $r = new Router();
        $r->get('/posts/{id:int}/{slug}', fn () => 1, name: 'post');
        $r->get('/blog/{page?}', fn () => 1, name: 'blog');
        $r->get('/u/:name', fn () => 1, name: 'user');
        $r->get('/f/{path*}', fn () => 1, name: 'file');
        $r->get('/files/{name}.{ext}', fn () => 1, name: 'dl');
        $r->group('/admin', fn (Router $r) => $r->get('/stats', fn () => 1, name: 'admin.stats'));

        self::assertSame('/posts/5/hello%20world', $r->url('post', ['id' => 5, 'slug' => 'hello world']));
        self::assertSame('/blog', $r->url('blog'));
        self::assertSame('/blog/2?sort=asc', $r->url('blog', ['page' => 2], ['sort' => 'asc']));
        self::assertSame('/u/bob', $r->url('user', ['name' => 'bob']));
        self::assertSame('/f/a/b%20c.txt', $r->url('file', ['path' => 'a/b c.txt']));
        self::assertSame('/files/x.pdf', $r->url('dl', ['name' => 'x', 'ext' => 'pdf']));
        self::assertSame('/admin/stats', $r->url('admin.stats'));
        self::assertTrue($r->hasRoute('post'));
        self::assertSame('post', $r->match('GET', '/posts/5/x')->name);

        $this->expectException(InvalidArgumentException::class);
        $unreachable = $r->url('post', ['id' => 5]); // throws: missing {slug}
    }

    /** Parameter values are URL-decoded before reaching the handler. */
    public function testUrlDecodingOfParams(): void
    {
        $r = new Router();
        $r->get('/s/{q}', fn ($q) => $q);
        self::assertSame('hello world', $r->dispatch('GET', '/s/hello%20world'));
    }

    /** Numeric segments (which PHP stores as int array keys) match like any other segment. */
    public function testNumericSegments(): void
    {
        $r = new Router();
        $r->get('/2024/{m}', fn ($m) => "a$m");
        $r->get('/{y}/01', fn ($y) => "b$y");

        self::assertSame('a01', $r->dispatch('GET', '/2024/01'));
        self::assertSame('b2023', $r->dispatch('GET', '/2023/01'));
    }

    /** routes() reconstructs every pattern in registration order. */
    public function testRoutesListing(): void
    {
        $r = new Router();
        $r->get('/a', fn () => 1);
        $r->post('/b/{id:\d+}/{x}', fn () => 1);
        $r->get('/c/{p*}', fn () => 1);

        $patterns = array_map(fn ($x) => $x['method'] . ' ' . $x['pattern'], $r->routes());
        self::assertSame(['GET /a', 'POST /b/{id:\d+}/{x}', 'GET /c/{p*}'], $patterns);
    }

    /** run() reads $_SERVER, strips the base path and query string, and honours the method override header. */
    public function testRun(): void
    {
        $r = new Router();
        $r->setBasePath('/app');
        $r->put('/x/{id}', fn ($id) => "put:$id");

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/app/x/3?foo=bar';
        $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] = 'PUT';
        self::assertSame('put:3', $r->run());
        unset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']);
    }

    /** A cached router behaves identically to the one that wrote the cache, and skips the definitions. */
    public function testCacheRoundTrip(): void
    {
        $file = sys_get_temp_dir() . '/glroute-test-' . bin2hex(random_bytes(4)) . '.php';
        $define = function (Router $r): void {
            $r->get('/u/{id:int}', [UserController::class, 'show'], name: 'user');
            $r->get('/list', UserController::class . '::list');
            $r->group('/g', fn (Router $r) => $r->get('/{id}', UserController::class . '@show'), [[Tag::class, 'mw']]);
        };

        try {
            $a = (new Router())->cache($file, $define);
            self::assertFileExists($file);

            $calls = 0;
            $b = (new Router())->cache($file, function () use (&$calls) { $calls++; });
            self::assertSame(0, $calls, 'definitions are skipped when the cache exists');
            foreach ([$a, $b] as $r) {
                self::assertSame('user:7', $r->dispatch('GET', '/u/7'));
                self::assertSame('users', $r->dispatch('GET', '/list'));
                self::assertSame('[user:z]', $r->dispatch('GET', '/g/z'));
                self::assertSame('/u/7', $r->url('user', ['id' => 7]));
                self::assertSame(404, $r->match('GET', '/u/x')->status);
            }
        } finally {
            @unlink($file);
        }
    }

    /** At the same node a constrained parameter wins over a plain one, whatever the registration order. */
    public function testRegexBeatsPlainParamAtSameNode(): void
    {
        $r = new Router();
        $r->get('/p/{slug}', fn ($s) => "slug:$s");
        $r->get('/p/{id:int}', fn ($id) => "id:$id");   // registered later, still wins for digits

        self::assertSame('id:7', $r->dispatch('GET', '/p/7'));
        self::assertSame('slug:x', $r->dispatch('GET', '/p/x'));
    }

    /** When dispatch()'s greedy fast path dead-ends, the full walk still finds the catch-all. */
    public function testGreedyDeadEndFallsBackToWildcard(): void
    {
        $r = new Router();
        $r->get('/docs/api/{v}', fn ($v) => "api:$v");
        $r->get('/docs/{path*}', fn ($p) => "doc:$p");

        self::assertSame('api:1', $r->dispatch('GET', '/docs/api/1'));
        self::assertSame('doc:api/1/x', $r->dispatch('GET', '/docs/api/1/x'));
        self::assertSame('doc:api', $r->dispatch('GET', '/docs/api'));
    }

    /** A parameter never matches an empty segment such as the one in "/a//b". */
    public function testParamNeverMatchesEmptySegment(): void
    {
        $r = new Router();
        $r->get('/a/{x}/b', fn ($x) => $x);
        self::assertSame(404, $r->match('GET', '/a//b')->status);
    }

    /** A ':' that doesn't start a segment is literal, even after an aborted fast insert. */
    public function testColonInsideStaticSegmentStaysStatic(): void
    {
        $r = new Router();
        $r->get('/x/a:b', fn () => 'colon');
        $r->get('/x/{y}', fn ($y) => "y:$y");

        self::assertSame('colon', $r->dispatch('GET', '/x/a:b'));
        self::assertSame('y:q', $r->dispatch('GET', '/x/q'));
        self::assertCount(2, $r->routes());
    }

    /** A dynamic route registered for several methods (case-insensitive) matches each of them. */
    public function testMultiMethodDynamicRoute(): void
    {
        $r = new Router();
        $r->add(['get', 'post'], '/m/{id}', fn ($id) => "m:$id");
        self::assertSame('m:1', $r->dispatch('POST', '/m/1'));
        self::assertSame('m:2', $r->dispatch('GET', '/m/2'));
    }

    /** count() agrees with iteration for every route kind, and iteration yields routes(). */
    public function testCountableAndIterable(): void
    {
        self::assertCount(0, new Router());

        $r = new Router();
        $r->get('/', fn () => 1);
        $r->add('GET|POST', '/form', fn () => 1);           // 2 entries
        $r->get('/u/{id:int}', fn () => 1);
        $r->get('/f/{name}.{ext}', fn () => 1);
        $r->get('/s/{path*}', fn () => 1);
        $r->get('/blog/{page?}', fn () => 1);               // 2 entries: /blog/{page} and /blog
        $r->get('/c/{cat}/{page?}', fn () => 1);            // 2 entries
        $r->any('/ping', fn () => 1);

        $iterated = [];
        foreach ($r as $route) {
            $iterated[] = $route['method'] . ' ' . $route['pattern'];
        }

        self::assertCount(11, $r);
        self::assertSame(count($r), count($iterated));
        self::assertSame($r->routes(), iterator_to_array($r));
        self::assertContains('POST /form', $iterated);
        self::assertContains('GET /blog', $iterated);
        self::assertContains('* /ping', $iterated);
    }

    /** An imported route table has the same count and entries as the exported one. */
    public function testCountSurvivesCacheRoundTrip(): void
    {
        $r = new Router();
        $r->get('/a/{id:int}', [UserController::class, 'show']);
        $r->post('/b/{x}/{y?}', [UserController::class, 'show']);
        $r->get('/c', [UserController::class, 'show']);

        $loaded = (new Router())->import($r->export());
        self::assertCount(count($r), $loaded);
        self::assertSame($r->routes(), $loaded->routes());
    }

    /** On PHP 8.5+, ignoring the result of a #[\NoDiscard] query method raises a warning. */
    public function testIgnoredQueryResultsWarnOnPhp85(): void
    {
        if (\PHP_VERSION_ID < 80500) {
            self::markTestSkipped('#[\NoDiscard] is enforced from PHP 8.5; older versions ignore the attribute.');
        }

        $r = new Router();
        $r->get('/a/{id}', fn ($id) => $id, name: 'a');

        $warnings = [];
        set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
            $warnings[] = $msg;
            return true;
        }, \E_WARNING | \E_USER_WARNING);
        try {
            // These results are discarded on purpose: the test checks that each one raises a warning.
            /** @noinspection PhpExpressionResultUnusedInspection */
            $r->match('GET', '/a/1');
            /** @noinspection PhpExpressionResultUnusedInspection */
            $r->url('a', ['id' => 1]);
            /** @noinspection PhpExpressionResultUnusedInspection */
            $r->allowedMethods('/a/1');
            /** @noinspection PhpExpressionResultUnusedInspection */
            $r->hasRoute('a');
            /** @noinspection PhpExpressionResultUnusedInspection */
            $r->routes();
            $kept = $r->match('GET', '/a/1');   // used: no warning
        } finally {
            restore_error_handler();
        }

        self::assertCount(5, $warnings);
        self::assertStringContainsString('Router::match()', $warnings[0]);
        self::assertSame(200, $kept->status);
    }

    /** export() refuses closure handlers, which var_export() can't reproduce. */
    public function testExportRejectsClosures(): void
    {
        $r = new Router();
        $r->get('/', fn () => 1);
        $this->expectException(LogicException::class);
        $unreachable = $r->export(); // throws: closure handler
    }
}
