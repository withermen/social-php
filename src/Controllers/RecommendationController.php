<?php

namespace Social\Controllers;

use Social\Exceptions\ValidationException;
use Social\Http\Request;
use Social\Http\Response;

final class RecommendationController extends Controller
{
    public function interests(Request $request): Response
    {
        $items = $this->app->analysis()->interests($request->user()['id'], 50);

        return Response::data($items, [
            'personalized' => $items !== [],
            'topics' => array_column($items, 'topic'),
        ]);
    }

    public function updateInterests(Request $request): Response
    {
        $topics = $request->input('topics');

        if (is_string($topics)) {
            $topics = explode(',', $topics);
        }

        if (!is_array($topics)) {
            throw new ValidationException(['topics' => 'Topics must be an array or a comma separated string.']);
        }

        if (count($topics) > 20) {
            throw new ValidationException(['topics' => 'You can select at most 20 topics.']);
        }

        $items = $this->app->analysis()->setExplicit($request->user()['id'], $topics);

        return Response::data($items, [
            'personalized' => $items !== [],
            'topics' => array_column($items, 'topic'),
        ]);
    }

    public function clearInterests(Request $request): Response
    {
        $this->app->analysis()->clearExplicit($request->user()['id']);

        return Response::noContent();
    }

    public function notInterested(Request $request, string $id): Response
    {
        return Response::data($this->app->analysis()->feedback($request->user(), $id, 'not_interested'));
    }

    public function clearNotInterested(Request $request, string $id): Response
    {
        $this->app->analysis()->clearFeedback($request->user(), $id);

        return Response::noContent();
    }
}
