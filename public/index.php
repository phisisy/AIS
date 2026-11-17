<?php
declare(strict_types=1);

use App\Core\ErrorHandler;
use App\Core\Request;
use App\Core\Response;

ini_set('display_errors', '0');
require_once dirname(__DIR__) . '/app/autoload.php';

ErrorHandler::run(static function (): Response {
    $container = require dirname(__DIR__) . '/app/bootstrap.php';
    return $container['router']->dispatch(Request::fromGlobals());
})->send();
