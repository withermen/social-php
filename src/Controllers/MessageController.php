<?php

namespace Social\Controllers;

use Social\Http\Request;
use Social\Http\Response;
use Social\Support\Validator;

final class MessageController extends Controller
{
    public function index(Request $request): Response
    {
        $limit = (int) ($request->queryParam('limit') ?? 50);
        $result = $this->app->messages()->list($request->user(), $limit);

        return Response::data($result['items'], ['count' => count($result['items'])]);
    }

    public function store(Request $request): Response
    {
        return Response::data($this->app->messages()->start($request->user(), $request->all()), [], 201);
    }

    public function show(Request $request, string $id): Response
    {
        return Response::data($this->app->messages()->show($request->user(), $id));
    }

    public function messages(Request $request, string $id): Response
    {
        [$page, $perPage] = Validator::pagination($request->query, 30, 100);

        return $this->paginated($this->app->messages()->messages($request->user(), $id, $page, $perPage));
    }

    public function send(Request $request, string $id): Response
    {
        return Response::data($this->app->messages()->send($request->user(), $id, $request->all()), [], 201);
    }

    public function read(Request $request, string $id): Response
    {
        $this->app->messages()->markRead($request->user(), $id);

        return Response::noContent();
    }
}
