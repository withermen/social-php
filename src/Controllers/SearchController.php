<?php

namespace Social\Controllers;

use Social\Http\Request;
use Social\Http\Response;
use Social\Support\Validator;

final class SearchController extends Controller
{
    public function search(Request $request): Response
    {
        [$page, $perPage] = Validator::pagination($request->query, 20, 100);
        $query = (string) ($request->queryParam('q') ?? '');
        $type = (string) ($request->queryParam('type') ?? 'all');
        $result = $this->app->search()->search($query, $type, $request->user(), $page, $perPage);

        return Response::data($result);
    }
}
