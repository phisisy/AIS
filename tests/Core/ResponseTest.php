<?php
declare(strict_types=1);

use App\Core\ErrorHandler;
use App\Core\HttpException;
use App\Core\Response;

test('response emits success and error envelopes with security headers', function (): void {
    $success = Response::success(['id' => 1], 'Created', 201);
    same(201, $success->status);
    same(['success' => true, 'message' => 'Created', 'data' => ['id' => 1]], json_decode($success->body, true));
    same('application/json', $success->headers['Content-Type']);
    same('no-store', $success->headers['Cache-Control']);
    same('nosniff', $success->headers['X-Content-Type-Options']);
    check(json_decode(Response::success()->body)->data instanceof stdClass);
    $error = Response::error('Validation failed.', 422, ['name' => 'Required.']);
    same(['success' => false, 'message' => 'Validation failed.', 'errors' => ['name' => 'Required.']], json_decode($error->body, true));
    check(json_decode(Response::error('Not found.', 404)->body)->errors instanceof stdClass);
});

test('response refuses header injection and preserves mandatory headers', function (): void {
    throws(InvalidArgumentException::class, fn () => Response::error('No', 405, [], ['Allow' => "GET\r\nInjected: yes"]));
    $response = Response::error('No', 405, [], ['Allow' => 'GET', 'cache-control' => 'public']);
    same('GET', $response->headers['Allow']);
    same('no-store', $response->headers['Cache-Control']);
    check(!isset($response->headers['cache-control']));
});

test('error handler preserves safe HTTP errors and cleans accidental output', function (): void {
    ob_start();
    try {
        $response = ErrorHandler::run(static function (): Response {
            echo 'private debug data';
            throw new HttpException(405, 'Method not allowed.', [], ['Allow' => 'GET']);
        });
        same('', ob_get_contents());
        same(405, $response->status);
        same('GET', $response->headers['Allow']);
    } finally {
        ob_end_clean();
    }
});

test('error handler hides exceptions, warnings, encoding failures and server error details', function (): void {
    $directory = tempDirectory();
    $previous = ini_get('error_log');
    ini_set('error_log', $directory . '/errors.log');
    try {
        $actions = [
            static function (): Response { throw new RuntimeException('password=private SQL SELECT trace'); },
            static function (): Response { trigger_error('private warning', E_USER_WARNING); return Response::success(); },
            static fn (): Response => Response::success("\xff"),
            static function (): Response { throw new HttpException(503, 'private service detail', ['secret' => 'value']); },
            static function (): Response { throw new HttpException(422, "\xff"); },
            static function (): Response { throw new HttpException(422, 'Invalid', ['field' => "\xff"]); },
            static function (): Response { throw new HttpException(405, 'Invalid', [], ['Allow' => "GET\r\nprivate: value"]); },
        ];
        foreach ($actions as $action) {
            $response = ErrorHandler::run($action);
            same(500, $response->status);
            same('{"success":false,"message":"An internal error occurred.","errors":{}}', $response->body);
        }
        $log = file_get_contents($directory . '/errors.log');
        check(str_contains($log, 'RuntimeException'));
        check(str_contains($log, 'ErrorException'));
        check(str_contains($log, 'JsonException'));
        foreach (['private', 'password', 'SELECT', 'trace', 'value', __FILE__] as $secret) {
            check(!str_contains($log, $secret), 'Error log leaked exception details.');
        }
    } finally {
        ini_set('error_log', $previous);
        removeTestDirectory($directory);
    }
});
