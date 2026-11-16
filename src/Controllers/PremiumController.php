<?php

namespace Social\Controllers;

use Social\Exceptions\ValidationException;
use Social\Http\Request;
use Social\Http\Response;

final class PremiumController extends Controller
{
    public function plans(): Response
    {
        return Response::data($this->app->premium()->plans());
    }

    public function status(Request $request): Response
    {
        return Response::data($this->app->premium()->summary($request->user()['id']));
    }

    public function subscription(Request $request): Response
    {
        $subscription = $this->app->premium()->activeSubscription($request->user()['id']);

        return Response::data([
            'premium' => $this->app->premium()->summary($request->user()['id']),
            'subscription' => $subscription === null ? null : [
                'id' => $subscription['id'],
                'plan_code' => $subscription['plan_code'],
                'plan_name' => $subscription['plan_name'],
                'status' => $subscription['status'],
                'provider' => $subscription['provider'],
                'started_at' => $subscription['started_at'],
                'current_period_end' => $subscription['current_period_end'],
                'cancel_at_period_end' => (bool) $subscription['cancel_at_period_end'],
            ],
        ]);
    }

    public function subscribe(Request $request): Response
    {
        $plan = trim((string) ($request->input('plan') ?? $request->input('plan_code') ?? ''));

        if ($plan === '') {
            throw new ValidationException(['plan' => 'Plan is required.']);
        }

        return Response::data($this->app->premium()->subscribe($request->user(), $plan), [], 201);
    }

    public function cancel(Request $request): Response
    {
        return Response::data($this->app->premium()->cancel($request->user()));
    }

    public function webhook(Request $request): Response
    {
        return Response::data($this->app->premium()->handleWebhook(
            $request->body,
            $request->header('x-payment-signature') ?? $request->header('stripe-signature')
        ));
    }

    public function grant(Request $request): Response
    {
        $userId = trim((string) ($request->input('user_id') ?? ''));
        $plan = trim((string) ($request->input('plan') ?? ''));

        if ($userId === '') {
            throw new ValidationException(['user_id' => 'User id is required.']);
        }

        if ($plan === '') {
            throw new ValidationException(['plan' => 'Plan is required.']);
        }

        $days = $request->input('days');

        return Response::data($this->app->premium()->adminGrant(
            $request->user(),
            $userId,
            $plan,
            $days === null ? null : (int) $days
        ), [], 201);
    }
}
