<?php
declare(strict_types=1);

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

test('router matches multiple positive integer parameters', function (): void {
    $router = new Router();
    $router->add('GET', '/sections/{sectionId}/students/{id}', static function (Request $request, array $params): Response {
        same('GET', $request->method);
        same(['sectionId' => 12, 'id' => 34], $params);
        return Response::success($params);
    });
    same(200, $router->dispatch(new Request('GET', '/sections/12/students/34'))->status);
});

test('router rejects invalid or overflowing integer parameters with 404', function (): void {
    $router = new Router();
    $router->add('GET', '/items/{id}', static fn (): Response => Response::success());
    foreach (['0', '-1', '+1', '01', '1.0', '1e2', 'abc', '1/2', '%31', (string) PHP_INT_MAX . '0'] as $id) {
        same(404, throws(HttpException::class, fn () => $router->dispatch(new Request('GET', '/items/' . $id)))->status);
    }
    same(200, $router->dispatch(new Request('GET', '/items/' . PHP_INT_MAX))->status);
});

test('router literal paths win independent of registration order', function (): void {
    $router = new Router();
    $router->add('GET', '/items/{id}', static fn (): Response => Response::success('parameter'));
    $router->add('GET', '/items/7', static fn (): Response => Response::success('literal'));
    $router->add('GET', '/items/me', static fn (): Response => Response::success('me'));
    same('literal', json_decode($router->dispatch(new Request('GET', '/items/7'))->body, true)['data']);
    same('me', json_decode($router->dispatch(new Request('GET', '/items/me'))->body, true)['data']);
});

test('router distinguishes 404 and 405 and includes all allowed methods', function (): void {
    $router = new Router();
    $router->add('GET', '/', static fn (): Response => Response::success());
    $router->add('POST', '/', static fn (): Response => Response::success());
    same(404, throws(HttpException::class, fn () => $router->dispatch(new Request('GET', '/missing')))->status);
    $error = throws(HttpException::class, fn () => $router->dispatch(new Request('DELETE', '/')));
    same(405, $error->status);
    same(['Allow' => 'GET, POST'], $error->headers);
});

test('router does not fall through a literal path to another method on a parameter route', function (): void {
    $router = new Router();
    $router->add('POST', '/items/{id}', static fn (): Response => Response::success('wrong'));
    $router->add('GET', '/items/7', static fn (): Response => Response::success());
    $error = throws(HttpException::class, fn () => $router->dispatch(new Request('POST', '/items/7')));
    same(405, $error->status);
    same('GET', $error->headers['Allow']);
});

test('router rejects ambiguous duplicate definitions and malformed placeholders', function (): void {
    $router = new Router();
    $handler = static fn (): Response => Response::success();
    $router->add('GET', '/', $handler);
    throws(InvalidArgumentException::class, fn () => $router->add('GET', '/', $handler));
    throws(InvalidArgumentException::class, fn () => $router->add('GET', '/{id}/{id}', $handler));
    throws(InvalidArgumentException::class, fn () => $router->add('GET', '/{bad', $handler));
});
