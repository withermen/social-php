<?php

namespace Social\Controllers;

use Social\Http\Request;
use Social\Http\Response;
use Social\Support\Validator;

final class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        [$page, $perPage] = Validator::pagination($request->query, 20, 100);
        $result = $this->app->notifications()->list($request->user(), $page, $perPage);

        return Response::data($result['items'], [
            'pagination' => $result['pagination'],
            'unread_count' => $result['unread_count'],
        ]);
    }

    public function unreadCount(Request $request): Response
    {
        return Response::data(['unread_count' => $this->app->notifications()->unreadCount($request->user())]);
    }

    public function read(Request $request, string $id): Response
    {
        $this->app->notifications()->markRead($request->user(), $id);

        return Response::noContent();
    }

    public function readAll(Request $request): Response
    {
        $this->app->notifications()->markAllRead($request->user());

        return Response::noContent();
    }
}
