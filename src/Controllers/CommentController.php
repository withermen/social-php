<?php

namespace Social\Controllers;

use Social\Http\Request;
use Social\Http\Response;
use Social\Support\Validator;

final class CommentController extends Controller
{
    public function index(Request $request, string $videoId): Response
    {
        [$page, $perPage] = Validator::pagination($request->query, 20, 100);
        $parentId = $request->queryParam('parent_id');

        return $this->paginated($this->app->comments()->list(
            $videoId,
            $request->userId(),
            $page,
            $perPage,
            $parentId === null ? null : (string) $parentId
        ));
    }

    public function store(Request $request, string $videoId): Response
    {
        return Response::data($this->app->comments()->create($request->user(), $videoId, $request->all()), [], 201);
    }

    public function update(Request $request, string $id): Response
    {
        return Response::data($this->app->comments()->update($request->user(), $id, $request->all()));
    }

    public function destroy(Request $request, string $id): Response
    {
        $this->app->comments()->delete($request->user(), $id);

        return Response::noContent();
    }

    public function like(Request $request, string $id): Response
    {
        return Response::data($this->app->comments()->like($request->user(), $id));
    }

    public function unlike(Request $request, string $id): Response
    {
        return Response::data($this->app->comments()->unlike($request->user(), $id));
    }

    public function replies(Request $request, string $id): Response
    {
        $comment = $this->app->comments()->find($id);
        [$page, $perPage] = Validator::pagination($request->query, 20, 100);

        return $this->paginated($this->app->comments()->list(
            $comment['video_id'],
            $request->userId(),
            $page,
            $perPage,
            $id
        ));
    }
}
