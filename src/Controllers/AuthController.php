<?php

namespace Social\Controllers;

use Social\Exceptions\ValidationException;
use Social\Http\Request;
use Social\Http\Response;
use Social\Support\Validator;

final class AuthController extends Controller
{
    public function register(Request $request): Response
    {
        return Response::data($this->app->auth()->register($request->all(), $request), [], 201);
    }

    public function login(Request $request): Response
    {
        $data = $request->all();
        $identifier = (string) ($data['identifier'] ?? $data['email'] ?? $data['username'] ?? '');
        $password = (string) ($data['password'] ?? '');
        $errors = [];

        if (trim($identifier) === '') {
            $errors['identifier'] = 'Email or username is required.';
        }

        if ($password === '') {
            $errors['password'] = 'Password is required.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return Response::data($this->app->auth()->login($identifier, $password, $request));
    }

    public function refresh(Request $request): Response
    {
        $clean = Validator::validate($request->all(), ['refresh_token' => 'required|string:10,255']);

        return Response::data($this->app->auth()->refresh($clean['refresh_token'], $request));
    }

    public function logout(Request $request): Response
    {
        $this->app->auth()->logout((string) ($request->input('refresh_token') ?? ''));

        return Response::noContent();
    }

    public function verifyEmail(Request $request): Response
    {
        $clean = Validator::validate($request->all(), ['token' => 'required|string:10,255']);

        return Response::data($this->app->auth()->verifyEmail($clean['token']));
    }

    public function resendVerification(Request $request): Response
    {
        $clean = Validator::validate($request->all(), ['email' => 'required|string:3,190|email']);

        return Response::data($this->app->auth()->resendVerification($clean['email'], $request));
    }

    public function forgotPassword(Request $request): Response
    {
        $clean = Validator::validate($request->all(), ['email' => 'required|string:3,190|email']);

        return Response::data($this->app->auth()->forgotPassword($clean['email'], $request));
    }

    public function resetPassword(Request $request): Response
    {
        $clean = Validator::validate($request->all(), [
            'token' => 'required|string:10,255',
            'password' => 'required|password:8',
        ]);

        return Response::data($this->app->auth()->resetPassword($clean['token'], $clean['password']));
    }

    public function changePassword(Request $request): Response
    {
        $clean = Validator::validate($request->all(), [
            'current_password' => 'required|string:1,255',
            'password' => 'required|password:8',
        ]);
        $this->app->auth()->changePassword($request->user(), $clean['current_password'], $clean['password']);

        return Response::data(['changed' => true]);
    }
}
