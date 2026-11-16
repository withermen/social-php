<?php

namespace Social\Controllers;

use Social\Http\Request;
use Social\Http\Response;

final class UploadController extends Controller
{
    public function create(Request $request): Response
    {
        return Response::data($this->app->uploads()->create($request->user(), $request->all()), [], 201);
    }

    public function status(Request $request, string $id): Response
    {
        return Response::data($this->app->uploads()->status($request->user(), $id));
    }

    public function chunk(Request $request, string $id): Response
    {
        $offset = $request->queryParam('offset');

        if ($offset === null) {
            $range = $request->header('content-range');

            if ($range !== null && preg_match('/bytes\s+(\d+)-\d+\/(?:\d+|\*)/i', $range, $matches)) {
                $offset = (int) $matches[1];
            }
        }

        if ($offset === null) {
            $offset = $this->app->uploads()->status($request->user(), $id)['received'];
        }

        return Response::data($this->app->uploads()->writeChunk($request->user(), $id, $request->body, (int) $offset));
    }

    public function complete(Request $request, string $id): Response
    {
        return Response::data($this->app->uploads()->complete($request->user(), $id, $request->all()), [], 201);
    }

    public function destroy(Request $request, string $id): Response
    {
        $this->app->uploads()->abort($request->user(), $id);

        return Response::noContent();
    }
}
