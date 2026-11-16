<?php

namespace Social\Controllers;

use Social\Exceptions\ValidationException;
use Social\Http\Request;
use Social\Http\Response;
use Social\Support\Str;
use Social\Support\Validator;

final class AdController extends Controller
{
    public function serve(Request $request): Response
    {
        $placement = (string) ($request->queryParam('placement') ?? 'feed');
        $videoId = $request->queryParam('video_id');
        $ipHash = Str::ipHash($request->ip($this->app->trustProxy()), (string) $this->app->config()->get('app.key'));
        $result = $this->app->ads()->serve($request->user(), $placement, $videoId === null ? null : (string) $videoId, $ipHash);

        return Response::data($result['ad'], ['reason' => $result['reason'], 'placement' => $placement]);
    }

    public function click(Request $request, string $id): Response
    {
        $ipHash = Str::ipHash($request->ip($this->app->trustProxy()), (string) $this->app->config()->get('app.key'));

        return Response::data($this->app->ads()->click($request->user(), $id, $ipHash));
    }

    public function index(Request $request): Response
    {
        [$page, $perPage] = Validator::pagination($request->query, 20, 100);
        $status = $request->queryParam('status');

        return $this->paginated($this->app->ads()->campaigns($request->user(), $page, $perPage, $status === null ? null : (string) $status));
    }

    public function store(Request $request): Response
    {
        return Response::data($this->app->ads()->createCampaign($request->user(), $request->all()), [], 201);
    }

    public function show(Request $request, string $id): Response
    {
        return Response::data($this->app->ads()->campaignDetail($request->user(), $id));
    }

    public function update(Request $request, string $id): Response
    {
        return Response::data($this->app->ads()->updateCampaign($request->user(), $id, $request->all()));
    }

    public function creative(Request $request, string $id): Response
    {
        $image = $request->file('image');

        return Response::data($this->app->ads()->createCreative($request->user(), $id, $request->all(), $image), [], 201);
    }

    public function stats(Request $request, string $id): Response
    {
        return Response::data($this->app->ads()->stats($request->user(), $id));
    }

    public function adminStatus(Request $request, string $id): Response
    {
        $status = (string) ($request->input('status') ?? '');

        if ($status === '') {
            throw new ValidationException(['status' => 'Status is required.']);
        }

        return Response::data($this->app->ads()->setStatus($request->user(), $id, $status));
    }
}
