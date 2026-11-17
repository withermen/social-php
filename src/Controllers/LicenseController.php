<?php

namespace Social\Controllers;

use Social\Exceptions\ValidationException;
use Social\Http\Request;
use Social\Http\Response;
use Social\Support\Validator;

final class LicenseController extends Controller
{
    public function plans(): Response
    {
        return Response::data($this->app->licenses()->plans(), [
            'enforcement' => $this->app->licenses()->enforcement(),
            'required' => $this->app->licenses()->status()['required'],
        ]);
    }

    public function status(): Response
    {
        return Response::data($this->app->licenses()->status());
    }

    public function activate(Request $request): Response
    {
        $key = (string) ($request->input('license_key') ?? $request->input('key') ?? '');
        $installation = (string) ($request->input('installation_id') ?? '');

        if ($key === '') {
            throw new ValidationException(['license_key' => 'License key is required.']);
        }

        if ($installation === '') {
            throw new ValidationException(['installation_id' => 'Installation id is required.']);
        }

        if ($this->app->remoteLicense()->remoteRequired()) {
            return Response::data($this->app->remoteLicense()->activate($key));
        }

        $ipHash = \Social\Support\Str::ipHash($request->ip($this->app->trustProxy()), (string) $this->app->config()->get('app.key'));

        return Response::data($this->app->licenses()->activate($key, $installation, $request->input('domain'), $ipHash));
    }

    public function checkout(Request $request): Response
    {
        $plan = trim((string) ($request->input('plan') ?? ''));
        $email = trim((string) ($request->input('email') ?? ''));

        if ($plan === '') {
            throw new ValidationException(['plan' => 'Plan is required.']);
        }

        if ($email === '') {
            throw new ValidationException(['email' => 'Email is required.']);
        }

        return Response::data($this->app->licenses()->checkout($plan, $email, $request->input('holder_name')), [], 201);
    }

    public function webhook(Request $request): Response
    {
        $result = $this->app->licenses()->handleWebhook(
            $request->body,
            $request->header('x-license-signature') ?? $request->header('x-payment-signature')
        );

        return Response::data($result);
    }

    public function issue(Request $request): Response
    {
        $plan = trim((string) ($request->input('plan') ?? ''));
        $email = trim((string) ($request->input('email') ?? ''));

        if ($plan === '') {
            throw new ValidationException(['plan' => 'Plan is required.']);
        }

        if ($email === '') {
            throw new ValidationException(['email' => 'Email is required.']);
        }

        $duration = $request->input('duration_days');
        $activations = $request->input('max_activations');

        return Response::data($this->app->licenses()->issue(
            $plan,
            $email,
            $request->input('holder_name'),
            $duration === null ? null : (int) $duration,
            $activations === null ? null : (int) $activations
        ), [], 201);
    }

    public function index(Request $request): Response
    {
        [$page, $perPage] = Validator::pagination($request->query, 20, 100);

        return $this->paginated($this->app->licenses()->list($page, $perPage));
    }

    public function revoke(string $id): Response
    {
        return Response::data($this->app->licenses()->revoke($id));
    }
}
