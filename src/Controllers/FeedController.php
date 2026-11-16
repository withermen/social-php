<?php

namespace Social\Controllers;

use Social\Http\Request;
use Social\Http\Response;
use Social\Support\Validator;

final class FeedController extends Controller
{
    public function following(Request $request): Response
    {
        [$page, $perPage] = Validator::pagination($request->query, 20, 100);
        $result = $this->app->feeds()->following($request->user(), $page, $perPage, $this->format($request));

        return $this->paginated($result, ['algorithm' => $result['algorithm'], 'format' => $result['format']]);
    }

    public function forYou(Request $request): Response
    {
        [$page, $perPage] = Validator::pagination($request->query, 20, 100);
        $result = $this->app->feeds()->forYou($request->user(), $page, $perPage, $this->format($request));

        return $this->paginated($result, ['algorithm' => $result['algorithm']]);
    }

    public function explore(Request $request): Response
    {
        [$page, $perPage] = Validator::pagination($request->query, 20, 100);
        $result = $this->app->feeds()->explore($request->user(), $page, $perPage, $this->format($request));

        return $this->paginated($result, ['algorithm' => $result['algorithm']]);
    }

    public function shorts(Request $request): Response
    {
        [$page, $perPage] = Validator::pagination($request->query, 20, 100);
        $result = $this->app->feeds()->forYou($request->user(), $page, $perPage, 'short');

        return $this->paginated($result, ['algorithm' => $result['algorithm']]);
    }

    public function longform(Request $request): Response
    {
        [$page, $perPage] = Validator::pagination($request->query, 20, 100);
        $result = $this->app->feeds()->forYou($request->user(), $page, $perPage, 'long');

        return $this->paginated($result, ['algorithm' => $result['algorithm']]);
    }

    private function format(Request $request): ?string
    {
        $format = $request->queryParam('format');

        return $format === null ? null : (string) $format;
    }

    public function trends(Request $request): Response
    {
        $days = max(1, min(90, (int) ($request->queryParam('days') ?? 7)));
        $limit = max(1, min(50, (int) ($request->queryParam('limit') ?? 20)));

        return Response::data($this->app->feeds()->trends($days, $limit)['items'], ['window_days' => $days]);
    }

    public function hashtag(Request $request, string $tag): Response
    {
        [$page, $perPage] = Validator::pagination($request->query, 20, 100);
        $result = $this->app->feeds()->hashtag($tag, $request->user(), $page, $perPage);

        return $this->paginated($result, ['hashtag' => $result['hashtag']]);
    }
}
