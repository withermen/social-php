<?php

namespace Social\Auth;

use Social\App;
use Social\Exceptions\ApiException;
use Social\Exceptions\ValidationException;
use Social\Http\Request;
use Social\Support\Str;
use Social\Support\Validator;

final class AuthService
{
    public function __construct(private readonly App $app)
    {
    }

    public function register(array $data, Request $request): array
    {
        $ip = $request->ip($this->app->trustProxy());
        $limit = $this->app->rateLimiter()->attempt(
            'register:' . $ip,
            (int) $this->app->config()->get('auth.max_register_attempts', 20),
            (int) $this->app->config()->get('auth.register_window', 3600)
        );

        if (!$limit['allowed']) {
            throw ApiException::tooManyAttempts('Too many registration attempts. Try again later.', $limit['retry_after']);
        }

        $clean = Validator::validate($data, [
            'username' => 'required|string:3,24|username',
            'email' => 'required|string:3,190|email',
            'password' => 'required|password:8',
            'display_name' => 'nullable|string:1,100',
        ]);
        $username = Str::normalizeUsername($clean['username']);
        $email = mb_strtolower($clean['email'], 'UTF-8');
        $existing = $this->app->db()->fetch('SELECT username, email FROM users WHERE username = ? OR email = ? LIMIT 1', [$username, $email]);

        if ($existing !== null) {
            $fields = [];

            if ($existing['username'] === $username) {
                $fields['username'] = 'This username is already taken.';
            }

            if ($existing['email'] === $email) {
                $fields['email'] = 'This email is already registered.';
            }

            throw new ValidationException($fields);
        }

        $id = Str::id();
        $now = Str::now();
        $displayName = trim((string) ($clean['display_name'] ?? '')) ?: $username;
        $this->app->db()->insert('users', [
            'id' => $id,
            'username' => $username,
            'email' => $email,
            'password_hash' => password_hash($clean['password'], PASSWORD_DEFAULT),
            'display_name' => $displayName,
            'role' => 'user',
            'status' => 'active',
            'verified' => 0,
            'follower_count' => 0,
            'following_count' => 0,
            'video_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $user = $this->userById($id);
        $verification = $this->createVerification($user, 'email', (int) $this->app->config()->get('auth.verification_ttl', 86400));
        $this->sendVerificationMail($user, $verification);
        $payload = $this->issueTokens($user, $request);

        if ((bool) $this->app->config()->get('auth.expose_tokens', false)) {
            $payload['verification_token'] = $verification;
        }

        return $payload;
    }

    public function login(string $identifier, string $password, Request $request): array
    {
        $identifier = trim($identifier);
        $ip = $request->ip($this->app->trustProxy());
        $bucket = 'login:' . $ip . ':' . substr(hash('sha256', mb_strtolower($identifier, 'UTF-8')), 0, 24);
        $limit = $this->app->rateLimiter()->attempt(
            $bucket,
            (int) $this->app->config()->get('auth.max_login_attempts', 10),
            (int) $this->app->config()->get('auth.login_window', 300)
        );

        if (!$limit['allowed']) {
            throw ApiException::tooManyAttempts('Too many login attempts. Try again later.', $limit['retry_after']);
        }

        $user = $this->app->db()->fetch(
            'SELECT * FROM users WHERE email = ? OR username = ? LIMIT 1',
            [mb_strtolower($identifier, 'UTF-8'), Str::normalizeUsername($identifier)]
        );

        if ($user === null || !password_verify($password, $user['password_hash'])) {
            throw ApiException::unauthorized('The provided credentials are incorrect.');
        }

        if ($user['status'] !== 'active') {
            throw ApiException::forbidden('This account is not available.');
        }

        $this->app->rateLimiter()->clear($bucket);
        $this->app->db()->update('users', ['last_seen_at' => Str::now(), 'updated_at' => Str::now()], 'id = ?', [$user['id']]);
        $user['last_seen_at'] = Str::now();

        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $this->app->db()->update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [$user['id']]);
        }

        return $this->issueTokens($user, $request);
    }

    public function refresh(string $refreshToken, Request $request): array
    {
        $row = $this->app->db()->fetch(
            'SELECT * FROM refresh_tokens WHERE token_hash = ? LIMIT 1',
            [Str::hash($refreshToken)]
        );

        if ($row === null || $row['revoked_at'] !== null || Str::timestamp($row['expires_at']) <= time()) {
            throw ApiException::unauthorized('Refresh token is invalid or expired.');
        }

        $user = $this->userById($row['user_id']);

        if ($user['status'] !== 'active') {
            throw ApiException::forbidden('This account is not available.');
        }

        $this->app->db()->update('refresh_tokens', ['revoked_at' => Str::now()], 'id = ?', [$row['id']]);

        return $this->issueTokens($user, $request);
    }

    public function logout(?string $refreshToken): void
    {
        if ($refreshToken === null || $refreshToken === '') {
            return;
        }

        $this->app->db()->update(
            'refresh_tokens',
            ['revoked_at' => Str::now()],
            'token_hash = ? AND revoked_at IS NULL',
            [Str::hash($refreshToken)]
        );
    }

    public function verifyEmail(string $token): array
    {
        $record = $this->findVerification($token, 'email');

        if ($record === null) {
            throw ApiException::badRequest('Verification token is invalid or expired.');
        }

        $now = Str::now();
        $this->app->db()->update('verification_tokens', ['used_at' => $now], 'id = ?', [$record['id']]);
        $this->app->db()->update('users', ['email_verified_at' => $now, 'updated_at' => $now], 'id = ?', [$record['user_id']]);

        return ['verified' => true];
    }

    public function resendVerification(string $email, ?Request $request = null): array
    {
        if ($request !== null) {
            $limit = $this->app->rateLimiter()->attempt('verify-mail:' . $request->ip($this->app->trustProxy()), 5, 300);

            if (!$limit['allowed']) {
                throw ApiException::tooManyAttempts('Too many verification requests. Try again later.', $limit['retry_after']);
            }
        }

        $user = $this->app->db()->fetch('SELECT * FROM users WHERE email = ? LIMIT 1', [mb_strtolower(trim($email), 'UTF-8')]);
        $result = ['sent' => true];

        if ($user !== null && $user['status'] === 'active' && $user['email_verified_at'] === null) {
            $token = $this->createVerification($user, 'email', (int) $this->app->config()->get('auth.verification_ttl', 86400));
            $this->sendVerificationMail($user, $token);

            if ((bool) $this->app->config()->get('auth.expose_tokens', false)) {
                $result['verification_token'] = $token;
            }
        }

        return $result;
    }

    public function forgotPassword(string $email, ?Request $request = null): array
    {
        if ($request !== null) {
            $limit = $this->app->rateLimiter()->attempt('forgot:' . $request->ip($this->app->trustProxy()), 5, 300);

            if (!$limit['allowed']) {
                throw ApiException::tooManyAttempts('Too many password reset requests. Try again later.', $limit['retry_after']);
            }
        }

        $user = $this->app->db()->fetch('SELECT * FROM users WHERE email = ? LIMIT 1', [mb_strtolower(trim($email), 'UTF-8')]);
        $result = ['sent' => true];

        if ($user !== null && $user['status'] === 'active') {
            $token = $this->createVerification($user, 'password', (int) $this->app->config()->get('auth.reset_ttl', 3600));
            $this->sendPasswordMail($user, $token);

            if ((bool) $this->app->config()->get('auth.expose_tokens', false)) {
                $result['reset_token'] = $token;
            }
        }

        return $result;
    }

    public function resetPassword(string $token, string $password): array
    {
        $clean = Validator::validate(['password' => $password], ['password' => 'required|password:8']);
        $record = $this->findVerification($token, 'password');

        if ($record === null) {
            throw ApiException::badRequest('Reset token is invalid or expired.');
        }

        $now = Str::now();
        $this->app->db()->transaction(function () use ($record, $clean, $now): void {
            $this->app->db()->update('users', [
                'password_hash' => password_hash($clean['password'], PASSWORD_DEFAULT),
                'updated_at' => $now,
            ], 'id = ?', [$record['user_id']]);
            $this->app->db()->update('verification_tokens', ['used_at' => $now], 'id = ?', [$record['id']]);
            $this->app->db()->update('refresh_tokens', ['revoked_at' => $now], 'user_id = ? AND revoked_at IS NULL', [$record['user_id']]);
        });

        return ['reset' => true];
    }

    public function changePassword(array $user, string $currentPassword, string $newPassword): void
    {
        if (!password_verify($currentPassword, $user['password_hash'])) {
            throw new ValidationException(['current_password' => 'Current password is incorrect.']);
        }

        $clean = Validator::validate(['password' => $newPassword], ['password' => 'required|password:8']);
        $now = Str::now();
        $this->app->db()->transaction(function () use ($user, $clean, $now): void {
            $this->app->db()->update('users', [
                'password_hash' => password_hash($clean['password'], PASSWORD_DEFAULT),
                'updated_at' => $now,
            ], 'id = ?', [$user['id']]);
            $this->app->db()->update('refresh_tokens', ['revoked_at' => $now], 'user_id = ? AND revoked_at IS NULL', [$user['id']]);
        });
    }

    public function userById(string $id): array
    {
        $user = $this->app->db()->fetch('SELECT * FROM users WHERE id = ? LIMIT 1', [$id]);

        if ($user === null) {
            throw ApiException::unauthorized('Account no longer exists.');
        }

        return $user;
    }

    public function toSelf(array $user): array
    {
        return [
            'id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'display_name' => $user['display_name'],
            'bio' => $user['bio'],
            'website' => $user['website'],
            'location' => $user['location'],
            'avatar_url' => $user['avatar_path'] ? $this->app->assetUrl($user['avatar_path']) : null,
            'role' => $user['role'],
            'status' => $user['status'],
            'verified' => (bool) $user['verified'],
            'email_verified' => $user['email_verified_at'] !== null,
            'follower_count' => (int) $user['follower_count'],
            'following_count' => (int) $user['following_count'],
            'video_count' => (int) $user['video_count'],
            'created_at' => $user['created_at'],
        ];
    }

    public function revokeRefreshTokens(string $userId): void
    {
        $this->app->db()->update('refresh_tokens', ['revoked_at' => Str::now()], 'user_id = ? AND revoked_at IS NULL', [$userId]);
    }

    private function issueTokens(array $user, Request $request): array
    {
        $accessTtl = (int) $this->app->config()->get('auth.access_ttl', 3600);
        $refreshTtl = (int) $this->app->config()->get('auth.refresh_ttl', 2592000);
        $refresh = Str::token(48);
        $this->app->db()->insert('refresh_tokens', [
            'id' => Str::id(),
            'user_id' => $user['id'],
            'token_hash' => Str::hash($refresh),
            'expires_at' => Str::now($refreshTtl),
            'user_agent' => $request->userAgent(),
            'ip' => $request->ip($this->app->trustProxy()),
            'created_at' => Str::now(),
        ]);

        return [
            'user' => $this->toSelf($user),
            'token_type' => 'Bearer',
            'access_token' => $this->app->jwt()->issue($user['id'], ['role' => $user['role']]),
            'refresh_token' => $refresh,
            'expires_in' => $accessTtl,
        ];
    }

    private function createVerification(array $user, string $purpose, int $ttl): string
    {
        $token = Str::token(32);
        $this->app->db()->delete(
            'verification_tokens',
            'user_id = ? AND purpose = ? AND used_at IS NULL',
            [$user['id'], $purpose]
        );
        $this->app->db()->insert('verification_tokens', [
            'id' => Str::id(),
            'user_id' => $user['id'],
            'purpose' => $purpose,
            'token_hash' => Str::hash($token),
            'expires_at' => Str::now($ttl),
            'created_at' => Str::now(),
        ]);

        return $token;
    }

    private function findVerification(string $token, string $purpose): ?array
    {
        $record = $this->app->db()->fetch(
            'SELECT * FROM verification_tokens WHERE token_hash = ? AND purpose = ? AND used_at IS NULL LIMIT 1',
            [Str::hash($token), $purpose]
        );

        if ($record === null || Str::timestamp($record['expires_at']) <= time()) {
            return null;
        }

        return $record;
    }

    private function sendVerificationMail(array $user, string $token): void
    {
        $link = $this->app->url('/verify-email?token=' . rawurlencode($token));
        $body = 'Hi ' . $user['display_name'] . ',' . PHP_EOL . PHP_EOL
            . 'Confirm your email address:' . PHP_EOL . $link . PHP_EOL . PHP_EOL
            . 'Verification code: ' . $token;
        $this->app->mailer()->send($user['email'], 'Verify your email', $body);
    }

    private function sendPasswordMail(array $user, string $token): void
    {
        $link = $this->app->url('/reset-password?token=' . rawurlencode($token));
        $body = 'Hi ' . $user['display_name'] . ',' . PHP_EOL . PHP_EOL
            . 'Reset your password:' . PHP_EOL . $link . PHP_EOL . PHP_EOL
            . 'Reset code: ' . $token;
        $this->app->mailer()->send($user['email'], 'Reset your password', $body);
    }
}
