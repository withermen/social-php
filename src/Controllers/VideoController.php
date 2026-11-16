<?php

namespace Social\Controllers;

use Social\Exceptions\ValidationException;
use Social\Http\Request;
use Social\Http\Response;
use Social\Support\Str;

final class VideoController extends Controller
{
    public function store(Request $request): Response
    {
        $file = $request->file('video');

        if ($file === null) {
            throw new ValidationException(['video' => 'Video file is required.']);
        }

        if (!$file->isValid()) {
            throw new ValidationException(['video' => 'Upload failed or file is empty.']);
        }

        $video = $this->app->videos()->publish(
            $request->user(),
            $file->path,
            $file->originalName,
            $file->mime,
            $file->size,
            $request->all(),
            $request->file('thumbnail')
        );

        return Response::data($video, [], 201);
    }

    public function show(Request $request, string $id): Response
    {
        return Response::data($this->app->videos()->get($id, $request->userId()));
    }

    public function update(Request $request, string $id): Response
    {
        return Response::data($this->app->videos()->update($request->user(), $id, $request->all()));
    }

    public function analysis(Request $request, string $id): Response
    {
        return Response::data($this->app->videos()->analysis($id, $request->userId()));
    }

    public function analyze(Request $request, string $id): Response
    {
        return Response::data($this->app->videos()->analyze($request->user(), $id));
    }

    public function watch(Request $request, string $id): Response
    {
        return Response::data($this->app->videos()->watch(
            $request->user(),
            $id,
            (int) ($request->input('position') ?? $request->input('position_seconds') ?? 0),
            (int) ($request->input('duration') ?? $request->input('duration_seconds') ?? 0),
            $request->input('session_id') === null ? null : (string) $request->input('session_id'),
            $request->input('event') === null ? null : (string) $request->input('event'),
            (bool) ($request->input('replayed') ?? false)
        ));
    }

    public function destroy(Request $request, string $id): Response
    {
        $this->app->videos()->delete($request->user(), $id);

        return Response::noContent();
    }

    public function stream(Request $request, string $id): Response
    {
        $row = $this->app->videos()->streamRow($id, $request->userId());

        return Response::file(
            $this->app->storage()->path($row['storage_path']),
            $row['mime'],
            $request->header('range'),
            ['Cache-Control' => 'private, max-age=0, must-revalidate']
        );
    }

    public function view(Request $request, string $id): Response
    {
        $ipHash = Str::ipHash($request->ip($this->app->trustProxy()), (string) $this->app->config()->get('app.key'));

        return Response::data($this->app->videos()->recordView($id, $request->user(), $ipHash));
    }

    public function like(Request $request, string $id): Response
    {
        return Response::data($this->app->videos()->like($request->user(), $id));
    }

    public function unlike(Request $request, string $id): Response
    {
        return Response::data($this->app->videos()->unlike($request->user(), $id));
    }

    public function save(Request $request, string $id): Response
    {
        return Response::data($this->app->videos()->save($request->user(), $id));
    }

    public function unsave(Request $request, string $id): Response
    {
        return Response::data($this->app->videos()->unsave($request->user(), $id));
    }

    public function share(Request $request, string $id): Response
    {
        return Response::data($this->app->videos()->share($request->user(), $id, $request->input('platform')));
    }

    public function likers(Request $request, string $id): Response
    {
        [$page, $perPage] = \Social\Support\Validator::pagination($request->query, 20, 100);

        return $this->paginated($this->app->videos()->likers($id, $request->userId(), $page, $perPage));
    }
}
