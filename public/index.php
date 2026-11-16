<?php

use Social\App;
use Social\Http\Request;
use Social\Http\Response;
use Social\Support\Config;

require __DIR__ . '/../autoload.php';

try {
    $app = new App(Config::load(__DIR__ . '/../config/social.php'));
    $request = Request::capture();
    $app->handle($request)->send($request->method === 'HEAD');
} catch (\Throwable $exception) {
    $debug = filter_var(getenv('APP_DEBUG') === false ? '1' : getenv('APP_DEBUG'), FILTER_VALIDATE_BOOL);
    $response = Response::error('server_error', 'Application could not start.', 500, $debug ? [
        'exception' => $exception::class,
        'message' => $exception->getMessage(),
    ] : []);
    $response->header('Content-Type', 'application/json; charset=utf-8')->send();
}
