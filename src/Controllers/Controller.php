<?php

namespace Social\Controllers;

use Social\App;
use Social\Http\Response;

abstract class Controller
{
    public function __construct(protected readonly App $app)
    {
    }

    protected function paginated(array $result, array $extra = []): Response
    {
        $meta = ['pagination' => $result['pagination'] ?? null];

        foreach ($extra as $key => $value) {
            $meta[$key] = $value;
        }

        return Response::data($result['items'] ?? [], $meta);
    }
}
