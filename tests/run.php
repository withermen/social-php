<?php

use Social\App;
use Social\Http\Request;
use Social\Http\Response;
use Social\Http\UploadedFile;

require __DIR__ . '/../autoload.php';

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'socialphp-test-' . bin2hex(random_bytes(4));
mkdir($root, 0775, true);
$config = require __DIR__ . '/../config/social.php';
$config['app']['debug'] = true;
$config['app']['url'] = 'http://api.test';
$config['app']['key'] = 'test-secret-key-with-enough-length';
$config['database']['database'] = $root . DIRECTORY_SEPARATOR . 'test.sqlite';
$config['storage']['root'] = $root . DIRECTORY_SEPARATOR . 'storage';
$config['mail']['log_path'] = $root . DIRECTORY_SEPARATOR . 'mail.log';
$config['auth']['expose_tokens'] = true;
$config['feed']['candidate_limit'] = 100;
$app = new App($config);
$tests = 0;

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function api(string $method, string $path, array $body = [], ?string $token = null, array $files = [], array $query = [], ?string $rawBody = null, array $headers = []): Response
{
    global $app;
    $multipart = $files !== [];
    $contentType = $multipart ? 'multipart/form-data; boundary=test' : ($rawBody === null ? 'application/json' : 'application/octet-stream');
    $payload = $multipart ? '' : ($rawBody ?? json_encode($body));
    $requestHeaders = array_merge(['content-type' => $contentType], $headers);

    if ($token !== null) {
        $requestHeaders['authorization'] = 'Bearer ' . $token;
    }

    return $app->handle(new Request($method, $path, $query, $requestHeaders, $payload, $files, ['REMOTE_ADDR' => '127.0.0.1'], $multipart ? $body : []));
}

function decoded(Response $response, int $status, string $label): array
{
    if ($response->status !== $status) {
        throw new RuntimeException($label . ' expected status ' . $status . ' got ' . $response->status . ' body: ' . substr($response->body, 0, 800));
    }

    $data = json_decode($response->body, true);

    if (!is_array($data)) {
        throw new RuntimeException($label . ' returned invalid JSON: ' . substr($response->body, 0, 300));
    }

    return $data;
}

function step(string $name, callable $callback): void
{
    global $tests;
    $callback();
    $tests++;
    echo '[ok] ' . $name . PHP_EOL;
}

function videoFixture(string $path): void
{
    $command = 'ffmpeg -y -f lavfi -i ' . escapeshellarg('testsrc=size=320x180:rate=24') . ' -t 2 -c:v libx264 -pix_fmt yuv420p ' . escapeshellarg($path) . ' 2>&1';
    exec($command, $output, $code);

    if ($code !== 0 || !is_file($path) || filesize($path) < 1000) {
        throw new RuntimeException('Could not generate test video.');
    }
}

try {
    step('health endpoint', function (): void {
        $data = decoded(api('GET', '/api/v1/health'), 200, 'health');
        expect($data['data']['status'] === 'ok', 'health should be ok');
        expect($data['data']['database'] === true, 'database should be connected');
    });

    step('api index', function (): void {
        $data = decoded(api('GET', '/'), 200, 'index');
        expect($data['data']['version'] === '1.0.0', 'version missing');
    });

    $aliceToken = '';
    $bobToken = '';
    $aliceRefresh = '';
    $aliceId = '';
    $bobId = '';

    step('register alice and bob', function () use (&$aliceToken, &$bobToken, &$aliceRefresh, &$aliceId, &$bobId): void {
        $alice = decoded(api('POST', '/api/v1/auth/register', [
            'username' => 'alice',
            'email' => 'alice@example.com',
            'password' => 'password123',
            'display_name' => 'Alice',
        ]), 201, 'register alice');
        expect($alice['data']['access_token'] !== '', 'alice token missing');
        expect($alice['data']['user']['email'] === 'alice@example.com', 'alice email missing');
        $aliceToken = $alice['data']['access_token'];
        $aliceRefresh = $alice['data']['refresh_token'];
        $aliceId = $alice['data']['user']['id'];

        $bob = decoded(api('POST', '/api/v1/auth/register', [
            'username' => 'bob',
            'email' => 'bob@example.com',
            'password' => 'password123',
            'display_name' => 'Bob',
        ]), 201, 'register bob');
        $bobToken = $bob['data']['access_token'];
        $bobId = $bob['data']['user']['id'];
    });

    step('duplicate and invalid registration', function (): void {
        $duplicate = decoded(api('POST', '/api/v1/auth/register', [
            'username' => 'alice',
            'email' => 'other@example.com',
            'password' => 'password123',
        ]), 422, 'duplicate register');
        expect(isset($duplicate['error']['details']['fields']['username']), 'duplicate username field error missing');

        $invalid = decoded(api('POST', '/api/v1/auth/register', [
            'username' => 'x',
            'email' => 'not-an-email',
            'password' => '123',
        ]), 422, 'invalid register');
        expect(count($invalid['error']['details']['fields']) >= 2, 'expected multiple validation errors');
    });

    step('login and profile', function () use (&$aliceToken, &$aliceRefresh): void {
        $wrong = api('POST', '/api/v1/auth/login', ['identifier' => 'alice@example.com', 'password' => 'wrong-password']);
        expect($wrong->status === 401, 'wrong password should fail');

        $login = decoded(api('POST', '/api/v1/auth/login', ['identifier' => 'alice', 'password' => 'password123']), 200, 'login');
        expect($login['data']['token_type'] === 'Bearer', 'token type should be bearer');
        $aliceToken = $login['data']['access_token'];
        $aliceRefresh = $login['data']['refresh_token'];

        $me = decoded(api('GET', '/api/v1/me', [], $aliceToken), 200, 'me');
        expect($me['data']['username'] === 'alice', 'me username mismatch');
    });

    step('profile update and avatar', function () use (&$aliceToken, $root): void {
        $updated = decoded(api('PATCH', '/api/v1/me', [
            'display_name' => 'Alice A.',
            'bio' => 'Video creator',
            'website' => 'https://example.com',
            'location' => 'Istanbul',
        ], $aliceToken), 200, 'update profile');
        expect($updated['data']['display_name'] === 'Alice A.', 'display name not updated');

        $image = imagecreatetruecolor(180, 180);
        $color = imagecolorallocate($image, 220, 90, 110);
        imagefilledrectangle($image, 0, 0, 180, 180, $color);
        $avatarPath = $root . '/avatar.png';
        imagepng($image, $avatarPath);
        imagedestroy($image);

        $avatar = decoded(api('POST', '/api/v1/me/avatar', [], $aliceToken, [
            'avatar' => UploadedFile::fromPath($avatarPath, 'avatar.png', 'image/png'),
        ]), 200, 'avatar');
        expect($avatar['data']['avatar_url'] !== null, 'avatar url missing');
    });

    step('follow and notifications', function () use (&$aliceToken, &$bobToken): void {
        $follow = decoded(api('POST', '/api/v1/users/bob/follow', [], $aliceToken), 200, 'follow');
        expect($follow['data']['is_following'] === true, 'follow flag false');
        expect($follow['data']['follower_count'] === 1, 'follower count should be 1');

        $following = decoded(api('GET', '/api/v1/users/alice/following', [], null), 200, 'following list');
        expect(count($following['data']) === 1, 'following list should contain bob');

        $notifications = decoded(api('GET', '/api/v1/notifications', [], $bobToken), 200, 'bob notifications');
        expect($notifications['data'][0]['type'] === 'follow', 'follow notification missing');
    });

    $videoId = '';
    $secondVideoId = '';
    $commentId = '';
    $replyId = '';
    $conversationId = '';

    step('upload video', function () use (&$bobToken, &$videoId, $root): void {
        videoFixture($root . '/clip.mp4');
        $image = imagecreatetruecolor(640, 360);
        $color = imagecolorallocate($image, 40, 90, 160);
        imagefilledrectangle($image, 0, 0, 640, 360, $color);
        imagejpeg($image, $root . '/thumb.jpg', 85);
        imagedestroy($image);

        $video = decoded(api('POST', '/api/v1/videos', [
            'title' => 'First clip #demo',
            'description' => 'A test video by @alice',
            'visibility' => 'public',
            'tags' => ['php', 'backend'],
        ], $bobToken, [
            'video' => UploadedFile::fromPath($root . '/clip.mp4', 'clip.mp4', 'video/mp4'),
            'thumbnail' => UploadedFile::fromPath($root . '/thumb.jpg', 'thumb.jpg', 'image/jpeg'),
        ]), 201, 'upload video');
        expect($video['data']['duration'] === 2, 'duration should be 2');
        expect($video['data']['author']['username'] === 'bob', 'author mismatch');
        expect(in_array('demo', $video['data']['tags'], true), 'hashtag demo missing');
        expect(in_array('backend', $video['data']['tags'], true), 'tag backend missing');
        expect($video['data']['thumbnail_url'] !== null, 'thumbnail missing');
        $videoId = $video['data']['id'];
    });

    step('stream with range', function () use (&$videoId): void {
        $response = api('GET', '/api/v1/videos/' . $videoId . '/stream', [], null, [], [], null, ['range' => 'bytes=0-99']);
        expect($response->status === 206, 'range stream should return 206');
        expect($response->getHeader('Content-Range') !== null, 'content range missing');
        expect($response->getHeader('Content-Length') === '100', 'range length should be 100');
        expect($response->filePath !== null, 'stream file path missing');
    });

    step('views are deduplicated', function () use (&$videoId): void {
        $first = decoded(api('POST', '/api/v1/videos/' . $videoId . '/view'), 200, 'first view');
        expect($first['data']['counted'] === true, 'first view should count');
        $second = decoded(api('POST', '/api/v1/videos/' . $videoId . '/view'), 200, 'second view');
        expect($second['data']['counted'] === false, 'second view should be deduplicated');
        expect($second['data']['view_count'] === 1, 'view count should be 1');
    });

    step('likes saves shares', function () use (&$aliceToken, &$bobToken, &$videoId): void {
        $like = decoded(api('POST', '/api/v1/videos/' . $videoId . '/like', [], $aliceToken), 200, 'like');
        expect($like['data']['liked'] === true && $like['data']['like_count'] === 1, 'like failed');
        $save = decoded(api('POST', '/api/v1/videos/' . $videoId . '/save', [], $aliceToken), 200, 'save');
        expect($save['data']['saved'] === true && $save['data']['save_count'] === 1, 'save failed');
        $share = decoded(api('POST', '/api/v1/videos/' . $videoId . '/share', ['platform' => 'copy'], null), 200, 'share');
        expect($share['data']['share_count'] === 1, 'share failed');
        $likers = decoded(api('GET', '/api/v1/videos/' . $videoId . '/likes'), 200, 'likers');
        expect($likers['data'][0]['username'] === 'alice', 'liker list mismatch');

        $saved = decoded(api('GET', '/api/v1/me/saved-videos', [], $aliceToken), 200, 'saved videos');
        expect($saved['data'][0]['id'] === $videoId, 'saved video missing');
    });

    step('comments and replies', function () use (&$aliceToken, &$bobToken, &$videoId, &$commentId, &$replyId): void {
        $comment = decoded(api('POST', '/api/v1/videos/' . $videoId . '/comments', ['body' => 'Great video @bob'], $aliceToken), 201, 'comment');
        $commentId = $comment['data']['id'];
        expect($comment['data']['author']['username'] === 'alice', 'comment author mismatch');

        $reply = decoded(api('POST', '/api/v1/videos/' . $videoId . '/comments', ['body' => 'Thanks!', 'parent_id' => $commentId], $bobToken), 201, 'reply');
        $replyId = $reply['data']['id'];
        expect($reply['data']['parent_id'] === $commentId, 'reply parent mismatch');

        $list = decoded(api('GET', '/api/v1/videos/' . $videoId . '/comments'), 200, 'comments list');
        expect(count($list['data']) === 1, 'top level comment count should be 1');
        expect(count($list['data'][0]['replies']) === 1, 'reply missing from list');

        $like = decoded(api('POST', '/api/v1/comments/' . $commentId . '/like', [], $bobToken), 200, 'comment like');
        expect($like['data']['liked'] === true, 'comment like failed');

        $replies = decoded(api('GET', '/api/v1/comments/' . $commentId . '/replies'), 200, 'replies');
        expect($replies['data'][0]['id'] === $replyId, 'reply endpoint mismatch');
    });

    step('feeds and discovery', function () use (&$aliceToken, &$videoId): void {
        $following = decoded(api('GET', '/api/v1/feed/following', [], $aliceToken), 200, 'following feed');
        expect($following['data'][0]['id'] === $videoId, 'following feed missing video');
        expect($following['meta']['algorithm'] === 'following', 'following algorithm meta missing');

        $forYou = decoded(api('GET', '/api/v1/feed/foryou'), 200, 'foryou feed');
        expect($forYou['data'][0]['id'] === $videoId, 'foryou feed missing video');
        expect($forYou['meta']['algorithm']['name'] === 'foryou', 'foryou algorithm missing');

        $explore = decoded(api('GET', '/api/v1/explore'), 200, 'explore');
        expect($explore['data'][0]['id'] === $videoId, 'explore missing video');

        $trends = decoded(api('GET', '/api/v1/trends'), 200, 'trends');
        expect($trends['data'][0]['tag'] === 'demo' || in_array('demo', array_column($trends['data'], 'tag'), true), 'demo trend missing');

        $hashtag = decoded(api('GET', '/api/v1/hashtags/demo'), 200, 'hashtag');
        expect($hashtag['data'][0]['id'] === $videoId, 'hashtag feed missing video');

        $searchVideos = decoded(api('GET', '/api/v1/search', [], null, [], ['q' => 'First', 'type' => 'videos']), 200, 'search videos');
        expect($searchVideos['data']['videos'][0]['id'] === $videoId, 'video search mismatch');

        $searchUsers = decoded(api('GET', '/api/v1/search', [], null, [], ['q' => 'ali', 'type' => 'users']), 200, 'search users');
        expect($searchUsers['data']['users'][0]['username'] === 'alice', 'user search mismatch');

        $searchTags = decoded(api('GET', '/api/v1/search', [], null, [], ['q' => 'dem', 'type' => 'hashtags']), 200, 'search hashtags');
        expect($searchTags['data']['hashtags'][0]['tag'] === 'demo', 'hashtag search mismatch');
    });

    step('notifications lifecycle', function () use (&$bobToken, &$aliceToken): void {
        $count = decoded(api('GET', '/api/v1/notifications/unread-count', [], $bobToken), 200, 'unread count');
        expect($count['data']['unread_count'] > 0, 'unread count should be positive');
        $list = decoded(api('GET', '/api/v1/notifications', [], $bobToken), 200, 'notification list');
        $types = array_column($list['data'], 'type');
        expect(in_array('like', $types, true), 'like notification missing');
        expect(in_array('comment', $types, true), 'comment notification missing');

        $read = api('POST', '/api/v1/notifications/read-all', [], $bobToken);
        expect($read->status === 204, 'read all should be 204');
        $after = decoded(api('GET', '/api/v1/notifications/unread-count', [], $bobToken), 200, 'unread after');
        expect($after['data']['unread_count'] === 0, 'unread should be zero');

        $aliceNotifications = decoded(api('GET', '/api/v1/notifications', [], $aliceToken), 200, 'alice notifications');
        expect(in_array('reply', array_column($aliceNotifications['data'], 'type'), true), 'reply notification missing');
    });

    step('messaging', function () use (&$aliceToken, &$bobToken, &$conversationId): void {
        $conversation = decoded(api('POST', '/api/v1/conversations', ['username' => 'bob'], $aliceToken), 201, 'start conversation');
        $conversationId = $conversation['data']['id'];
        expect($conversation['data']['participant']['username'] === 'bob', 'conversation participant mismatch');

        $message = decoded(api('POST', '/api/v1/conversations/' . $conversationId . '/messages', ['body' => 'Hello Bob'], $aliceToken), 201, 'send message');
        expect($message['data']['is_mine'] === true, 'message should be mine');

        $bobList = decoded(api('GET', '/api/v1/conversations', [], $bobToken), 200, 'bob conversations');
        expect($bobList['data'][0]['unread_count'] === 1, 'bob unread should be 1');

        $bobView = decoded(api('GET', '/api/v1/conversations/' . $conversationId, [], $bobToken), 200, 'bob conversation');
        expect(count($bobView['data']['messages']) === 1, 'bob should see one message');

        $read = api('POST', '/api/v1/conversations/' . $conversationId . '/read', [], $bobToken);
        expect($read->status === 204, 'mark read should be 204');

        $empty = decoded(api('POST', '/api/v1/conversations/' . $conversationId . '/messages', ['body' => ''], $bobToken), 422, 'empty message');
        expect(isset($empty['error']['details']['fields']['body']), 'empty message validation missing');
    });

    step('resumable upload', function () use (&$bobToken, &$secondVideoId, $root, &$app): void {
        $bytes = file_get_contents($root . '/clip.mp4');
        $size = strlen($bytes);
        $session = decoded(api('POST', '/api/v1/uploads', [
            'filename' => 'chunked.mp4',
            'size' => $size,
            'mime' => 'video/mp4',
            'title' => 'Chunked upload #upload',
            'visibility' => 'public',
        ], $bobToken), 201, 'create upload');
        $uploadId = $session['data']['id'];
        expect($session['data']['chunk_size'] > 0, 'chunk size missing');

        $split = intdiv($size, 2);
        $first = decoded(api('PUT', '/api/v1/uploads/' . $uploadId . '/chunk', [], $bobToken, [], ['offset' => 0], substr($bytes, 0, $split)), 200, 'chunk one');
        expect($first['data']['received'] === $split, 'first chunk length mismatch');
        $second = decoded(api('PUT', '/api/v1/uploads/' . $uploadId . '/chunk', [], $bobToken, [], ['offset' => $split], substr($bytes, $split)), 200, 'chunk two');
        expect($second['data']['received'] === $size, 'second chunk length mismatch');

        $video = decoded(api('POST', '/api/v1/uploads/' . $uploadId . '/complete', ['tags' => ['chunked']], $bobToken), 201, 'complete upload');
        expect($video['data']['title'] === 'Chunked upload #upload', 'chunked title mismatch');
        expect(in_array('chunked', $video['data']['tags'], true), 'chunked tag missing');
        $secondVideoId = $video['data']['id'];
    });

    step('visibility and updates', function () use (&$bobToken, &$secondVideoId): void {
        $private = decoded(api('PATCH', '/api/v1/videos/' . $secondVideoId, ['visibility' => 'private', 'title' => 'Private clip'], $bobToken), 200, 'make private');
        expect($private['data']['visibility'] === 'private', 'visibility not updated');
        $guest = api('GET', '/api/v1/videos/' . $secondVideoId);
        expect($guest->status === 404, 'private video should be hidden from guests');
        $owner = decoded(api('GET', '/api/v1/videos/' . $secondVideoId, [], $bobToken), 200, 'owner private video');
        expect($owner['data']['is_owner'] === true, 'owner flag missing');
        api('PATCH', '/api/v1/videos/' . $secondVideoId, ['visibility' => 'public'], $bobToken);
    });

    $adminToken = '';

    step('admin moderation', function () use (&$adminToken, &$aliceToken, &$bobToken, &$videoId, &$secondVideoId, &$aliceId): void {
        $admin = decoded(api('POST', '/api/v1/auth/register', [
            'username' => 'admin',
            'email' => 'admin@example.com',
            'password' => 'password123',
            'display_name' => 'Admin',
        ]), 201, 'register admin');
        $adminToken = $admin['data']['access_token'];
        global $app;
        $app->db()->update('users', ['role' => 'admin', 'email_verified_at' => '2026-01-01 00:00:00'], 'username = ?', ['admin']);

        $stats = decoded(api('GET', '/api/v1/admin/stats', [], $adminToken), 200, 'admin stats');
        expect($stats['data']['users']['total'] >= 3, 'admin stats user count mismatch');

        $report = decoded(api('POST', '/api/v1/reports', [
            'target_type' => 'video',
            'target_id' => $videoId,
            'reason' => 'spam',
            'details' => 'Test report',
        ], $aliceToken), 201, 'create report');
        expect($report['data']['status'] === 'open', 'report should be open');

        $reports = decoded(api('GET', '/api/v1/admin/reports', [], $adminToken), 200, 'admin reports');
        expect($reports['data'][0]['id'] === $report['data']['id'], 'report missing in admin list');

        $resolved = decoded(api('POST', '/api/v1/admin/reports/' . $report['data']['id'] . '/resolve', ['status' => 'resolved', 'note' => 'Handled'], $adminToken), 200, 'resolve report');
        expect($resolved['data']['status'] === 'resolved', 'report not resolved');

        $takedown = decoded(api('POST', '/api/v1/admin/videos/' . $secondVideoId . '/takedown', [], $adminToken), 200, 'takedown');
        expect($takedown['data']['status'] === 'removed', 'takedown failed');
        expect(api('GET', '/api/v1/videos/' . $secondVideoId)->status === 404, 'removed video should be hidden');
        $restore = decoded(api('POST', '/api/v1/admin/videos/' . $secondVideoId . '/restore', [], $adminToken), 200, 'restore');
        expect($restore['data']['status'] === 'published', 'restore failed');

        $verified = decoded(api('POST', '/api/v1/admin/users/' . $aliceId . '/verify', ['verified' => true], $adminToken), 200, 'verify user');
        expect($verified['data']['verified'] === true, 'user not verified');

        $troll = decoded(api('POST', '/api/v1/auth/register', [
            'username' => 'troll',
            'email' => 'troll@example.com',
            'password' => 'password123',
        ]), 201, 'register troll');
        $trollId = $troll['data']['user']['id'];
        $banned = decoded(api('POST', '/api/v1/admin/users/' . $trollId . '/ban', [], $adminToken), 200, 'ban troll');
        expect($banned['data']['status'] === 'banned', 'troll not banned');
        expect(api('POST', '/api/v1/auth/login', ['identifier' => 'troll', 'password' => 'password123'])->status === 403, 'banned user should not login');
        $unbanned = decoded(api('POST', '/api/v1/admin/users/' . $trollId . '/unban', [], $adminToken), 200, 'unban troll');
        expect($unbanned['data']['status'] === 'active', 'troll not unbanned');

        $users = decoded(api('GET', '/api/v1/admin/users', [], $adminToken, [], ['q' => 'troll']), 200, 'admin user search');
        expect($users['data'][0]['username'] === 'troll', 'admin user search mismatch');
    });

    step('password reset flow', function () use (&$aliceId, &$aliceToken): void {
        $forgot = decoded(api('POST', '/api/v1/auth/forgot-password', ['email' => 'alice@example.com']), 200, 'forgot password');
        expect(!empty($forgot['data']['reset_token']), 'reset token missing');
        $reset = decoded(api('POST', '/api/v1/auth/reset-password', [
            'token' => $forgot['data']['reset_token'],
            'password' => 'new-password-456',
        ]), 200, 'reset password');
        expect($reset['data']['reset'] === true, 'password not reset');
        expect(api('POST', '/api/v1/auth/login', ['identifier' => 'alice', 'password' => 'password123'])->status === 401, 'old password should fail');
        decoded(api('POST', '/api/v1/auth/login', ['identifier' => 'alice', 'password' => 'new-password-456']), 200, 'login with new password');
    });

    step('refresh rotation and logout', function () use (&$aliceRefresh): void {
        $login = decoded(api('POST', '/api/v1/auth/login', ['identifier' => 'alice', 'password' => 'new-password-456']), 200, 'login before refresh');
        $aliceRefresh = $login['data']['refresh_token'];
        $refreshed = decoded(api('POST', '/api/v1/auth/refresh', ['refresh_token' => $aliceRefresh]), 200, 'refresh');
        expect($refreshed['data']['access_token'] !== '', 'new access token missing');
        $old = api('POST', '/api/v1/auth/refresh', ['refresh_token' => $aliceRefresh]);
        expect($old->status === 401, 'rotated refresh token should be invalid');
        $logout = api('POST', '/api/v1/auth/logout', ['refresh_token' => $refreshed['data']['refresh_token']]);
        expect($logout->status === 204, 'logout should be 204');
        $after = api('POST', '/api/v1/auth/refresh', ['refresh_token' => $refreshed['data']['refresh_token']]);
        expect($after->status === 401, 'logged out refresh token should be invalid');
    });

    step('authorization boundaries', function () use (&$aliceToken, &$bobToken, &$videoId, &$aliceId): void {
        expect(api('GET', '/api/v1/me')->status === 401, 'me without token should be 401');
        expect(api('GET', '/api/v1/admin/stats', [], $aliceToken)->status === 403, 'normal user should not access admin');
        expect(api('PATCH', '/api/v1/videos/' . $videoId, ['title' => 'Hijack'], $aliceToken)->status === 403, 'non owner should not edit video');
        expect(api('POST', '/api/v1/videos/not-found/like', [], $aliceToken)->status === 404, 'unknown video should be 404');
        expect(api('GET', '/api/v1/does-not-exist')->status === 404, 'unknown route should be 404');
        expect(api('DELETE', '/api/v1/auth/login')->status === 405, 'wrong method should be 405');
        expect(api('POST', '/api/v1/auth/login', [], null, [], [], '{bad json', ['content-type' => 'application/json'])->status === 400, 'invalid json should be 400');
    });

    step('delete comment and video', function () use (&$aliceToken, &$bobToken, &$replyId, &$videoId): void {
        expect(api('DELETE', '/api/v1/comments/' . $replyId, [], $bobToken)->status === 204, 'delete reply failed');
        expect(api('DELETE', '/api/v1/videos/' . $videoId, [], $bobToken)->status === 204, 'delete video failed');
        expect(api('GET', '/api/v1/videos/' . $videoId)->status === 404, 'deleted video should be hidden');
    });

    echo PHP_EOL . 'All ' . $tests . ' test groups passed.' . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, PHP_EOL . '[fail] ' . $exception->getMessage() . PHP_EOL);
    fwrite(STDERR, $exception->getFile() . ':' . $exception->getLine() . PHP_EOL);
    fwrite(STDERR, 'Test directory: ' . $root . PHP_EOL);
    exit(1);
}
