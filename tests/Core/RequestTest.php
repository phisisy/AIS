<?php
declare(strict_types=1);

use App\Core\HttpException;
use App\Core\Request;

test('request parses method, query, headers and object body', function (): void {
    $request = new Request('post', '/items?search=hello%20world', ['CONTENT-TYPE' => 'Application/JSON; charset=utf-8', 'X-CSRF-Token' => 'token'], '{"name":"Ada","roles":["student"],"meta":{}}');
    same('POST', $request->method);
    same('/items', $request->path);
    same(['search' => 'hello world'], $request->query);
    same('token', $request->header('x-csrf-token'));
    same(null, $request->header('missing'));
    same('Ada', $request->json()['name']);
    same(['student'], $request->json()['roles']);
    check($request->json()['meta'] instanceof stdClass);
});

test('request rejects non-JSON media types with 415', function (): void {
    foreach (['', 'text/plain', 'application/x-www-form-urlencoded', 'multipart/form-data; boundary=x', 'application/jsonp'] as $type) {
        $error = throws(HttpException::class, fn () => new Request('POST', '/', ['Content-Type' => $type], '{}'));
        same(415, $error->status);
    }
});

test('request rejects malformed JSON with 400 and non-object JSON with 422', function (): void {
    foreach (['{', '{"x":}', "{\"x\":\"\xff\"}"] as $body) {
        same(400, throws(HttpException::class, fn () => new Request('POST', '/', ['Content-Type' => 'application/json'], $body))->status);
    }
    foreach (['null', '[]', '"text"', '42', 'true'] as $body) {
        same(422, throws(HttpException::class, fn () => new Request('POST', '/', ['Content-Type' => 'application/json'], $body))->status);
    }
});

test('request enforces the exact 64 KiB boundary', function (): void {
    $body = '{"x":"' . str_repeat('a', Request::MAX_BODY_BYTES - 8) . '"}';
    same(Request::MAX_BODY_BYTES, strlen($body));
    same(Request::MAX_BODY_BYTES - 8, strlen((new Request('POST', '/', ['Content-Type' => 'application/json'], $body))->json()['x']));
    same(413, throws(HttpException::class, fn () => new Request('POST', '/', ['Content-Type' => 'application/json'], $body . ' '))->status);
});

test('request rejects unknown fields without echoing values', function (): void {
    $request = new Request('POST', '/', ['Content-Type' => 'application/json'], '{"name":"Ada","role":"secret"}');
    $error = throws(HttpException::class, fn () => $request->json(['name']));
    same(422, $error->status);
    same(['role' => 'Unknown field.'], $error->errors);
    same([], (new Request('GET', '/'))->json([]));
});

test('request rejects invalid paths', function (): void {
    foreach (['https://example.org/', "/bad\npath", '/bad#fragment'] as $path) {
        same(400, throws(HttpException::class, fn () => new Request('GET', $path))->status);
    }
});

test('request rejects consumed multipart input without a content length', function (): void {
    $server = $_SERVER;
    $post = $_POST;
    $files = $_FILES;
    try {
        foreach (['chunked', 'post', 'files'] as $case) {
            $_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/', 'CONTENT_TYPE' => 'multipart/form-data; boundary=test'];
            $_POST = $case === 'post' ? ['field' => 'value'] : [];
            $_FILES = $case === 'files' ? ['upload' => ['name' => 'test']] : [];
            if ($case === 'chunked') {
                $_SERVER['HTTP_TRANSFER_ENCODING'] = 'chunked';
            }
            same(415, throws(HttpException::class, fn () => Request::fromGlobals())->status);
        }
    } finally {
        $_SERVER = $server;
        $_POST = $post;
        $_FILES = $files;
    }
});
