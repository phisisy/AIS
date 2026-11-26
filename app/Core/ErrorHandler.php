<?php
declare(strict_types=1);

namespace App\Core;

use ErrorException;
use Throwable;

final class ErrorHandler
{
    public static function run(callable $action): Response
    {
        $level = ob_get_level();
        ob_start();
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            return $action();
        } catch (Throwable $exception) {
            if ($exception instanceof HttpException && $exception->status < 500) {
                try {
                    return Response::error($exception->getMessage(), $exception->status, $exception->errors, $exception->headers);
                } catch (Throwable $conversionFailure) {
                    $exception = $conversionFailure;
                }
            }
            // Anonymous class names can contain source paths; never log those.
            $class = get_class($exception);
            error_log(preg_match('/\A[A-Za-z0-9_\\\\]+\z/', $class) ? $class : 'Throwable');
            return Response::error('An internal error occurred.', 500);
        } finally {
            restore_error_handler();
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }
}
