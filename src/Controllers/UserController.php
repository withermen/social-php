<?php

namespace Social\Controllers;

use Social\Exceptions\ValidationException;
use Social\Http\Request;
use Social\Http\Response;
use Social\Support\Validator;

final class UserController extends Controller
{
    public function me(Request $request): Response
    {
        $user = $this->app->users()->requireById($request->user()['id']);

        return Response::data($this->app->auth()->toSelf($user));
    }

    public function updateMe(Request $request): Response
    {
        return Response::data($this->app->users()->updateProfile($request->user(), $request->all()));
    }

    public function deleteMe(Request $request): Response
    {
        $this->app->users()->deleteAccount($request->user());

        return Response::noContent();
    }

    public function avatar(Request $request): Response
    {
        $file = $request->file('avatar');

        if ($file === null) {
            throw new ValidationException(['avatar' => 'Avatar file is required.']);
        }

        return Response::data($this->app->users()->uploadAvatar($request->user(), $file));
    }

    public function removeAvatar(Request $request): Response
    {
        return Response::data($this->app->users()->removeAvatar($request->user()));
    }

    public function show(Request $request, string $username): Response
    {
        return Response::data($this->app->users()->profile($username, $request->userId()));
    }

    public function videos(Request $request, string $username): Response
    {
        [$page, $perPage] = Validator::pagination($request->query, (int) $this->app->config()->get('pagination.per_page', 20), (int) $this->app->config()->get('pagination.max_per_page', 100));

        return $this->paginated($this->app->videos()->listByUser($username, $request->userId(), $page, $perPage));
    }

    public function savedVideos(Request $request): Response
    {
        [$page, $perPage] = Validator::pagination($request->query, 20, 100);

        return $this->paginated($this->app->videos()->savedVideos($request->user(), $page, $perPage));
    }

    public function follow(Request $request, string $username): Response
    {
        return Response::data($this->app->users()->follow($request->user(), $username));
    }

    public function unfollow(Request $request, string $username): Response
    {
        return Response::data($this->app->users()->unfollow($request->user(), $username));
    }

    public function followers(Request $request, string $username): Response
    {
        [$page, $perPage] = Validator::pagination($request->query, 20, 100);

        return $this->paginated($this->app->users()->followers($username, $request->userId(), $page, $perPage));
    }

    public function following(Request $request, string $username): Response
    {
        [$page, $perPage] = Validator::pagination($request->query, 20, 100);

        return $this->paginated($this->app->users()->following($username, $request->userId(), $page, $perPage));
    }

    public function block(Request $request, string $username): Response
    {
        $this->app->users()->block($request->user(), $username);

        return Response::noContent();
    }

    public function unblock(Request $request, string $username): Response
    {
        $this->app->users()->unblock($request->user(), $username);

        return Response::noContent();
    }

    public function mute(Request $request, string $username): Response
    {
        $this->app->users()->mute($request->user(), $username);

        return Response::noContent();
    }

    public function unmute(Request $request, string $username): Response
    {
        $this->app->users()->unmute($request->user(), $username);

        return Response::noContent();
    }
}
