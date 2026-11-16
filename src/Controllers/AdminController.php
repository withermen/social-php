<?php

namespace Social\Controllers;

use Social\Http\Request;
use Social\Http\Response;
use Social\Support\Validator;

final class AdminController extends Controller
{
    public function stats(): Response
    {
        return Response::data($this->app->admin()->stats());
    }

    public function users(Request $request): Response
    {
        [$page, $perPage] = Validator::pagination($request->query, 20, 100);
        $query = (string) ($request->queryParam('q') ?? '');
        $status = (string) ($request->queryParam('status') ?? 'all');

        return $this->paginated($this->app->admin()->users($query, $status, $page, $perPage));
    }

    public function ban(Request $request, string $id): Response
    {
        return Response::data($this->app->admin()->ban($request->user(), $id));
    }

    public function unban(string $id): Response
    {
        return Response::data($this->app->admin()->unban($id));
    }

    public function verify(Request $request, string $id): Response
    {
        $verified = filter_var($request->input('verified', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;

        return Response::data($this->app->admin()->verify($id, $verified));
    }

    public function role(Request $request, string $id): Response
    {
        return Response::data($this->app->admin()->setRole($request->user(), $id, (string) $request->input('role', 'user')));
    }

    public function reports(Request $request): Response
    {
        [$page, $perPage] = Validator::pagination($request->query, 20, 100);
        $status = (string) ($request->queryParam('status') ?? 'open');

        return $this->paginated($this->app->reports()->list($status, $page, $perPage));
    }

    public function resolveReport(Request $request, string $id): Response
    {
        $status = (string) $request->input('status', 'resolved');
        $note = $request->input('note');

        return Response::data($this->app->reports()->resolve($request->user(), $id, $status, $note === null ? null : (string) $note));
    }

    public function takedown(string $id): Response
    {
        return Response::data($this->app->admin()->takedown($id));
    }

    public function restore(string $id): Response
    {
        return Response::data($this->app->admin()->restore($id));
    }
}
