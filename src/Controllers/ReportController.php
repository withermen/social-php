<?php

namespace Social\Controllers;

use Social\Http\Request;
use Social\Http\Response;

final class ReportController extends Controller
{
    public function store(Request $request): Response
    {
        return Response::data($this->app->reports()->create($request->user(), $request->all()), [], 201);
    }
}
