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
$config['feed']['exploration_rate'] = 0.34;
$config['feed']['max_author_total'] = 6;
$config['platform']['short_max_duration'] = 3;
$config['platform']['long_min_duration'] = 3;
$config['ads']['frequency_per_hour'] = 5;
$config['ads']['min_budget_cents'] = 10;
$config['payments']['webhook_secret'] = 'test-payment-secret';
$config['licensing']['webhook_secret'] = 'test-license-secret';
$app = new App($config);
$tests = 0;

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function apiOn(App $target, string $method, string $path, array $body = [], ?string $token = null, array $files = [], array $query = [], ?string $rawBody = null, array $headers = []): Response
{
    $multipart = $files !== [];
    $contentType = $multipart ? 'multipart/form-data; boundary=test' : ($rawBody === null ? 'application/json' : 'application/octet-stream');
    $payload = $multipart ? '' : ($rawBody ?? json_encode($body));
    $requestHeaders = array_merge(['content-type' => $contentType], $headers);

    if ($token !== null) {
        $requestHeaders['authorization'] = 'Bearer ' . $token;
    }

    return $target->handle(new Request($method, $path, $query, $requestHeaders, $payload, $files, ['REMOTE_ADDR' => '127.0.0.1'], $multipart ? $body : []));
}

function api(string $method, string $path, array $body = [], ?string $token = null, array $files = [], array $query = [], ?string $rawBody = null, array $headers = []): Response
{
    global $app;

    return apiOn($app, $method, $path, $body, $token, $files, $query, $rawBody, $headers);
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

function videoFixture(string $path, int $seconds = 2): void
{
    $command = 'ffmpeg -y -f lavfi -i ' . escapeshellarg('testsrc=size=320x180:rate=24') . ' -t ' . $seconds . ' -c:v libx264 -pix_fmt yuv420p ' . escapeshellarg($path) . ' 2>&1';
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
        expect($data['data']['version'] === '1.1.0', 'version missing');
    });

    step('platform modes and capabilities', function (): void {
        $data = decoded(api('GET', '/api/v1/platform'), 200, 'platform');
        expect($data['data']['mode'] === 'hybrid', 'platform mode should be hybrid');
        expect(in_array('short', $data['data']['accepts'], true) && in_array('long', $data['data']['accepts'], true), 'hybrid should accept both formats');
        expect($data['data']['features']['content_analysis'] === true, 'content analysis should be enabled');
        expect($data['data']['short_max_duration'] === 3, 'short duration limit mismatch');
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
    $longVideoId = '';
    $adCampaignId = '';
    $adCreativeId = '';
    $licenseKey = '';
    $licenseId = '';
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

    step('video content analysis', function () use (&$bobToken, &$aliceToken, &$videoId): void {
        $analysis = decoded(api('GET', '/api/v1/videos/' . $videoId . '/analysis'), 200, 'analysis');
        expect($analysis['data']['status'] === 'complete', 'analysis should be complete');
        expect($analysis['data']['format'] === 'short', 'analysis format should be short');
        expect(in_array('short_form', array_keys($analysis['data']['topics']), true), 'short_form topic missing');
        expect(in_array('backend', array_keys($analysis['data']['topics']), true), 'text topic missing');
        expect($analysis['data']['features']['frame_count'] >= 1, 'analysis should sample frames');
        expect(is_array($analysis['data']['visual']['palette']), 'palette missing');
        expect($analysis['data']['visual']['perceptual_hash'] !== null, 'perceptual hash missing');
        $replay = decoded(api('POST', '/api/v1/videos/' . $videoId . '/analyze', [], $bobToken), 200, 'force analysis');
        expect($replay['data']['status'] === 'complete', 'forced analysis failed');
        expect(api('POST', '/api/v1/videos/' . $videoId . '/analyze', [], $aliceToken)->status === 403, 'non owner should not analyze');
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

    step('content based recommendations and formats', function () use (&$bobToken, &$aliceToken, &$videoId, &$longVideoId, $root): void {
        videoFixture($root . '/long.mp4', 4);
        $long = decoded(api('POST', '/api/v1/videos', [
            'title' => 'Long form php tutorial',
            'description' => 'Deep dive into backend engineering',
            'visibility' => 'public',
            'tags' => ['php', 'tutorial'],
        ], $bobToken, [
            'video' => UploadedFile::fromPath($root . '/long.mp4', 'long.mp4', 'video/mp4'),
        ]), 201, 'upload long video');
        expect($long['data']['format'] === 'long', 'long video format mismatch');
        expect(in_array('long_form', $long['data']['topics'], true), 'long form topic missing');
        $longVideoId = $long['data']['id'];

        $interests = decoded(api('PUT', '/api/v1/recommendations/interests', [
            'topics' => ['php', 'backend', 'tutorial', 'programming'],
        ], $aliceToken), 200, 'set interests');
        expect(in_array('php', array_column($interests['data'], 'topic'), true), 'explicit interest missing');

        $profile = decoded(api('GET', '/api/v1/recommendations/interests', [], $aliceToken), 200, 'interest profile');
        expect($profile['meta']['personalized'] === true, 'interest profile should be personalized');
        expect(in_array('php', $profile['meta']['topics'], true), 'interest topic missing');

        $shorts = decoded(api('GET', '/api/v1/feed/shorts', [], $aliceToken), 200, 'shorts feed');
        expect($shorts['meta']['algorithm']['format'] === 'short', 'shorts feed format mismatch');
        expect(in_array($videoId, array_column($shorts['data'], 'id'), true), 'short video missing from shorts feed');
        expect(!in_array($longVideoId, array_column($shorts['data'], 'id'), true), 'long video should not appear in shorts feed');

        $longFeed = decoded(api('GET', '/api/v1/feed/foryou', [], $aliceToken, [], ['format' => 'long']), 200, 'long feed');
        expect($longFeed['data'][0]['id'] === $longVideoId, 'long feed should contain long video');
        expect($longFeed['data'][0]['recommendation']['interest_match'] > 0, 'interest match should be positive');

        $forYou = decoded(api('GET', '/api/v1/feed/foryou', [], $aliceToken, [], ['format' => 'short']), 200, 'personalized feed');
        expect($forYou['meta']['algorithm']['personalized'] === true, 'feed should be personalized');
        expect($forYou['meta']['algorithm']['interests'] > 0, 'feed should use interests');
        expect($forYou['data'][0]['recommendation']['matched_topics'] !== [], 'matched topics missing');
        expect($forYou['data'][0]['recommendation']['score'] > 0, 'recommendation score missing');

        $watch = decoded(api('POST', '/api/v1/videos/' . $videoId . '/watch', [
            'position' => 2,
            'duration' => 2,
        ], $aliceToken), 200, 'watch event');
        expect($watch['data']['completed'] === true, 'watch should be completed');
        expect(abs((float) $watch['data']['watch_ratio'] - 1.0) < 0.001, 'watch ratio mismatch');

        $hidden = decoded(api('POST', '/api/v1/videos/' . $longVideoId . '/not-interested', [], $aliceToken), 200, 'not interested');
        expect($hidden['data']['hidden'] === true, 'feedback should hide video');
        $hiddenFeed = decoded(api('GET', '/api/v1/feed/foryou', [], $aliceToken, [], ['format' => 'long']), 200, 'hidden feed');
        expect(in_array('not_interested', $hiddenFeed['data'][0]['recommendation']['reasons'], true), 'negative feedback signal missing');
        expect(api('DELETE', '/api/v1/videos/' . $longVideoId . '/not-interested', [], $aliceToken)->status === 204, 'clear feedback failed');
    });

    step('attention retention ranking', function () use (&$bobToken, &$aliceToken, $root): void {
        $hook = decoded(api('POST', '/api/v1/videos', [
            'title' => 'PHP async patterns that hook viewers',
            'description' => 'Fast backend programming tutorial with practical examples',
            'visibility' => 'public',
            'tags' => ['php', 'backend', 'programming', 'tutorial'],
        ], $bobToken, [
            'video' => UploadedFile::fromPath($root . '/clip.mp4', 'hook.mp4', 'video/mp4'),
        ]), 201, 'upload hook video');
        $skip = decoded(api('POST', '/api/v1/videos', [
            'title' => 'Quiet garden tour',
            'description' => 'Slow gardening walkthrough',
            'visibility' => 'public',
            'tags' => ['gardening', 'nature'],
        ], $bobToken, [
            'video' => UploadedFile::fromPath($root . '/clip.mp4', 'skip.mp4', 'video/mp4'),
        ]), 201, 'upload skip video');
        $fresh = decoded(api('POST', '/api/v1/videos', [
            'title' => 'New php programming trick',
            'description' => 'Fresh backend tip',
            'visibility' => 'public',
            'tags' => ['php', 'backend'],
        ], $bobToken, [
            'video' => UploadedFile::fromPath($root . '/clip.mp4', 'fresh.mp4', 'video/mp4'),
        ]), 201, 'upload fresh video');
        $session = 'session-attention-1';
        $completion = decoded(api('POST', '/api/v1/videos/' . $hook['data']['id'] . '/watch', [
            'position' => 2,
            'duration' => 2,
            'event' => 'complete',
            'session_id' => $session,
        ], $aliceToken), 200, 'complete hook video');
        expect($completion['data']['completed'] === true, 'hook watch should complete');
        expect($completion['data']['event'] === 'complete', 'hook event mismatch');
        expect($completion['data']['session_id'] === $session, 'watch session missing');
        $skipped = decoded(api('POST', '/api/v1/videos/' . $skip['data']['id'] . '/watch', [
            'position' => 0,
            'duration' => 2,
            'event' => 'skip',
            'session_id' => $session,
        ], $aliceToken), 200, 'skip boring video');
        expect($skipped['data']['skipped'] === true, 'skip should be detected');
        expect($skipped['data']['event'] === 'skip', 'skip event mismatch');
        $replay = decoded(api('POST', '/api/v1/videos/' . $hook['data']['id'] . '/watch', [
            'position' => 1,
            'duration' => 2,
            'event' => 'replay',
            'replayed' => true,
            'session_id' => $session,
        ], $aliceToken), 200, 'replay hook video');
        expect($replay['data']['replayed'] === true, 'replay should be detected');
        api('POST', '/api/v1/videos/' . $hook['data']['id'] . '/like', [], $aliceToken);

        $feed = decoded(api('GET', '/api/v1/feed/shorts', [], $aliceToken, [], ['session_id' => $session, 'per_page' => 20]), 200, 'attention feed');
        expect($feed['meta']['session_id'] === $session, 'feed session mismatch');
        expect($feed['meta']['algorithm']['session']['id'] === $session, 'algorithm session missing');
        expect($feed['meta']['algorithm']['session']['skips'] >= 1, 'session skip signal missing');
        expect(in_array('expected_watch_time', $feed['meta']['algorithm']['signals'], true), 'watch signal missing');
        expect(in_array('skip_probability', $feed['meta']['algorithm']['signals'], true), 'skip signal missing');
        $positions = array_flip(array_column($feed['data'], 'id'));
        expect(isset($positions[$hook['data']['id']]), 'hook video missing from feed');
        expect(isset($positions[$skip['data']['id']]), 'skip video missing from feed');
        expect($positions[$hook['data']['id']] < $positions[$skip['data']['id']], 'completed video should outrank skipped video');
        $hookItem = $feed['data'][$positions[$hook['data']['id']]]['recommendation'];
        $skipItem = $feed['data'][$positions[$skip['data']['id']]]['recommendation'];
        expect($hookItem['predicted_completion'] > $skipItem['predicted_completion'], 'completion prediction should favor hook');
        expect($hookItem['predicted_skip'] < $skipItem['predicted_skip'], 'skip prediction should favor hook');
        expect($hookItem['expected_watch_seconds'] >= $skipItem['expected_watch_seconds'], 'expected watch time should favor hook');
        expect($hookItem['matched_topics'] !== [], 'hook topic match missing');
        $exploratory = array_filter($feed['data'], fn (array $item): bool => ($item['recommendation']['exploration'] ?? 0) > 0.5);
        expect($exploratory !== [], 'exploration signal missing');
        $sessionSlots = array_filter($feed['data'], fn (array $item): bool => in_array('exploration_slot', $item['recommendation']['reasons'] ?? [], true));
        expect($sessionSlots !== [], 'exploration slot missing');

        $pages = 'session-attention-pages';
        $pageOne = decoded(api('GET', '/api/v1/feed/shorts', [], $aliceToken, [], ['session_id' => $pages, 'per_page' => 2]), 200, 'session page one');
        $pageTwo = decoded(api('GET', '/api/v1/feed/shorts', [], $aliceToken, [], ['session_id' => $pages, 'per_page' => 2, 'page' => 2]), 200, 'session page two');
        $overlap = array_intersect(array_column($pageOne['data'], 'id'), array_column($pageTwo['data'], 'id'));
        expect($overlap === [], 'session pages should not repeat videos');
        expect($pageTwo['meta']['session_id'] === $pages, 'pagination session mismatch');
        expect($pageTwo['meta']['algorithm']['session']['impressions'] > 0, 'session impressions missing');
        expect(isset($positions[$fresh['data']['id']]), 'fresh video should enter candidate pool');
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

    step('commercial license lifecycle', function () use (&$adminToken, &$licenseKey, &$licenseId): void {
        $plans = decoded(api('GET', '/api/v1/license/plans'), 200, 'license plans');
        expect(count($plans['data']) === 2, 'license plans missing');
        expect($plans['data'][0]['price_cents'] > 0, 'license price missing');
        expect($plans['meta']['enforcement'] === 'off', 'license enforcement should be off in tests');

        $issued = decoded(api('POST', '/api/v1/admin/licenses', [
            'plan' => 'commercial',
            'email' => 'buyer@example.com',
            'holder_name' => 'Buyer Inc',
        ], $adminToken), 201, 'issue license');
        $licenseKey = $issued['data']['key'];
        $licenseId = $issued['data']['license']['id'];
        expect(str_starts_with($licenseKey, 'SOC-COMM-'), 'license key format mismatch');
        expect($issued['data']['license']['status'] === 'active', 'license should be active');

        for ($i = 1; $i <= 3; $i++) {
            $activation = decoded(api('POST', '/api/v1/license/activate', [
                'license_key' => $licenseKey,
                'installation_id' => 'install-' . $i,
                'domain' => 'site-' . $i . '.example.com',
            ]), 200, 'activate license ' . $i);
            expect($activation['data']['activated'] === true, 'activation failed');
        }

        expect(api('POST', '/api/v1/license/activate', [
            'license_key' => $licenseKey,
            'installation_id' => 'install-4',
        ])->status === 403, 'activation limit should be enforced');

        $status = decoded(api('GET', '/api/v1/license/status', [], $adminToken), 200, 'license status');
        expect($status['data']['required'] === false, 'license should not be required in tests');

        $revoked = decoded(api('POST', '/api/v1/admin/licenses/' . $licenseId . '/revoke', [], $adminToken), 200, 'revoke license');
        expect($revoked['data']['status'] === 'revoked', 'license should be revoked');
        expect(api('POST', '/api/v1/license/activate', [
            'license_key' => $licenseKey,
            'installation_id' => 'install-5',
        ])->status === 403, 'revoked license should not activate');

        $licenses = decoded(api('GET', '/api/v1/admin/licenses', [], $adminToken), 200, 'license list');
        expect(count($licenses['data']) >= 1, 'license list empty');

        $checkout = decoded(api('POST', '/api/v1/license/checkout', [
            'plan' => 'enterprise',
            'email' => 'company@example.com',
            'holder_name' => 'Company LLC',
        ]), 201, 'license checkout');
        expect($checkout['data']['status'] === 'pending', 'license order should be pending');
        expect($checkout['data']['amount_cents'] > 0, 'license order amount missing');

        $webhookBody = json_encode([
            'id' => 'evt_license_' . $checkout['data']['order_id'],
            'type' => 'license.paid',
            'data' => ['order_id' => $checkout['data']['order_id']],
        ]);
        $signature = 'sha256=' . hash_hmac('sha256', $webhookBody, 'test-license-secret');
        $paid = decoded(api('POST', '/api/v1/license/webhook', [], null, [], [], $webhookBody, [
            'x-license-signature' => $signature,
        ]), 200, 'license webhook');
        expect($paid['data']['processed'] === true, 'license webhook not processed');
        expect($paid['data']['license']['plan'] === 'enterprise', 'license webhook plan mismatch');
        expect(!empty($paid['data']['key']), 'license webhook key missing');
        $licenseKey = $paid['data']['key'];

        $duplicate = decoded(api('POST', '/api/v1/license/webhook', [], null, [], [], $webhookBody, [
            'x-license-signature' => $signature,
        ]), 200, 'duplicate license webhook');
        expect($duplicate['data']['duplicate'] === true, 'duplicate webhook should be idempotent');
    });

    step('license enforcement middleware', function () use (&$licenseKey): void {
        global $config;
        $restricted = $config;
        $restricted['licensing']['enabled'] = true;
        $restricted['licensing']['enforcement'] = 'enforce';
        $restricted['licensing']['key'] = '';
        $restrictedApp = new App($restricted);
        $blocked = decoded(apiOn($restrictedApp, 'GET', '/api/v1/platform'), 402, 'unlicensed platform');
        expect($blocked['error']['code'] === 'license_required', 'license error code mismatch');

        $licensed = $config;
        $licensed['licensing']['enabled'] = true;
        $licensed['licensing']['enforcement'] = 'enforce';
        $licensed['licensing']['key'] = $licenseKey;
        $licensedApp = new App($licensed);
        $allowed = apiOn($licensedApp, 'GET', '/api/v1/platform');
        expect($allowed->status === 200, 'licensed platform should respond');
        expect($allowed->getHeader('X-License-Status') === 'licensed', 'license status header mismatch');
    });

    step('advertising lifecycle and targeting', function () use (&$aliceToken, &$bobToken, &$adCampaignId, &$adCreativeId): void {
        $campaign = decoded(api('POST', '/api/v1/ads/campaigns', [
            'name' => 'PHP course promo',
            'placement' => 'feed',
            'objective' => 'impressions',
            'budget_cents' => 1000,
            'bid_cents' => 1000,
            'targeting' => ['topics' => ['php'], 'formats' => ['short']],
        ], $aliceToken), 201, 'create ad campaign');
        expect($campaign['data']['status'] === 'active', 'campaign should be active');
        $adCampaignId = $campaign['data']['id'];

        $creative = decoded(api('POST', '/api/v1/ads/campaigns/' . $adCampaignId . '/creatives', [
            'title' => 'Learn PHP today',
            'body' => 'Backend engineering course',
            'cta_label' => 'Start learning',
            'click_url' => 'https://example.com/php-course',
            'image_url' => 'https://example.com/ad.jpg',
        ], $aliceToken), 201, 'create ad creative');
        $adCreativeId = $creative['data']['id'];
        expect($creative['data']['sponsored'] ?? true, 'creative payload invalid');

        $served = decoded(api('GET', '/api/v1/ads/serve', [], $aliceToken, [], ['placement' => 'feed']), 200, 'serve ad');
        expect($served['data']['id'] === $adCreativeId, 'served creative mismatch');
        expect($served['data']['sponsored'] === true, 'ad should be sponsored');
        expect($served['meta']['reason'] === null, 'ad should fill');
        expect(isset($served['data']['impression_id']), 'impression id missing');

        for ($i = 0; $i < 4; $i++) {
            api('GET', '/api/v1/ads/serve', [], $aliceToken, [], ['placement' => 'feed']);
        }

        $capped = decoded(api('GET', '/api/v1/ads/serve', [], $aliceToken, [], ['placement' => 'feed']), 200, 'frequency capped ad');
        expect($capped['data'] === null, 'frequency cap should block ad');
        expect($capped['meta']['reason'] === 'frequency_cap', 'frequency cap reason missing');

        $guest = decoded(api('GET', '/api/v1/ads/serve', [], null, [], ['placement' => 'feed']), 200, 'guest ad');
        expect($guest['data'] === null, 'targeted ad should not serve without context');

        $click = decoded(api('POST', '/api/v1/ads/' . $adCreativeId . '/click', [], $aliceToken), 200, 'ad click');
        expect($click['data']['clicked'] === true, 'ad click failed');
        expect($click['data']['click_url'] === 'https://example.com/php-course', 'click url mismatch');

        $stats = decoded(api('GET', '/api/v1/ads/campaigns/' . $adCampaignId . '/stats', [], $aliceToken), 200, 'ad stats');
        expect($stats['data']['impressions'] === 5, 'impression count mismatch');
        expect($stats['data']['clicks'] === 1, 'click count mismatch');
        expect($stats['data']['ctr'] > 0, 'ctr should be positive');
        expect($stats['data']['spent_cents'] > 0, 'ad spend missing');

        $clickCampaign = decoded(api('POST', '/api/v1/ads/campaigns', [
            'name' => 'Click budget campaign',
            'placement' => 'explore',
            'objective' => 'clicks',
            'budget_cents' => 100,
            'bid_cents' => 50,
        ], $aliceToken), 201, 'create click campaign');
        $clickCreative = decoded(api('POST', '/api/v1/ads/campaigns/' . $clickCampaign['data']['id'] . '/creatives', [
            'title' => 'Click me',
            'click_url' => 'https://example.com/click',
        ], $aliceToken), 201, 'create click creative');
        api('POST', '/api/v1/ads/' . $clickCreative['data']['id'] . '/click', [], $aliceToken);
        api('POST', '/api/v1/ads/' . $clickCreative['data']['id'] . '/click', [], $aliceToken);
        $clickStats = decoded(api('GET', '/api/v1/ads/campaigns/' . $clickCampaign['data']['id'] . '/stats', [], $aliceToken), 200, 'click stats');
        expect($clickStats['data']['spent_cents'] === 100, 'click spend mismatch');
        expect($clickStats['data']['status'] === 'completed', 'budget exhausted campaign should complete');

        $adminCampaigns = decoded(api('GET', '/api/v1/ads/campaigns', [], $aliceToken), 200, 'campaign list');
        expect(count($adminCampaigns['data']) === 2, 'campaign list mismatch');

        api('POST', '/api/v1/premium/subscribe', ['plan' => 'plus'], $bobToken);
        $adFree = decoded(api('GET', '/api/v1/ads/serve', [], $bobToken, [], ['placement' => 'feed']), 200, 'premium ad free');
        expect($adFree['data'] === null, 'premium user should not receive ads');
        expect($adFree['meta']['reason'] === 'premium_ad_free', 'premium ad free reason missing');
        api('POST', '/api/v1/premium/cancel', [], $bobToken);
    });

    step('premium subscription lifecycle', function () use (&$aliceToken, &$aliceId, &$adminToken): void {
        $plans = decoded(api('GET', '/api/v1/premium/plans'), 200, 'premium plans');
        expect(count($plans['data']) === 2, 'premium plans missing');
        expect($plans['data'][0]['price_cents'] > 0, 'premium price missing');

        $before = decoded(api('GET', '/api/v1/me/subscription', [], $aliceToken), 200, 'subscription before');
        expect($before['data']['premium']['is_premium'] === false, 'alice should not be premium');

        $subscribed = decoded(api('POST', '/api/v1/premium/subscribe', ['plan' => 'plus'], $aliceToken), 201, 'subscribe plus');
        expect($subscribed['data']['premium']['is_premium'] === true, 'subscription should activate');
        expect($subscribed['data']['premium']['entitlements']['ad_free'] === true, 'ad_free entitlement missing');
        expect($subscribed['data']['premium']['entitlements']['max_duration'] === 1800, 'premium duration limit mismatch');
        expect($subscribed['data']['subscription']['plan_code'] === 'plus', 'subscription plan mismatch');

        $status = decoded(api('GET', '/api/v1/premium/status', [], $aliceToken), 200, 'premium status');
        expect($status['data']['is_premium'] === true, 'premium status mismatch');

        $canceled = decoded(api('POST', '/api/v1/premium/cancel', [], $aliceToken), 200, 'cancel premium');
        expect($canceled['data']['premium']['is_premium'] === false, 'premium should be canceled');
        expect($canceled['data']['subscription']['cancel_at_period_end'] === true, 'cancel flag missing');

        $webhookBody = json_encode([
            'id' => 'evt_sub_1',
            'type' => 'subscription.activated',
            'data' => [
                'user_id' => $aliceId,
                'plan_code' => 'pro',
                'provider_ref' => 'sub_test_1',
            ],
        ]);
        $signature = 'sha256=' . hash_hmac('sha256', $webhookBody, 'test-payment-secret');
        $activated = decoded(api('POST', '/api/v1/premium/webhook', [], null, [], [], $webhookBody, [
            'x-payment-signature' => $signature,
        ]), 200, 'premium webhook');
        expect($activated['data']['processed'] === true, 'premium webhook not processed');
        expect($activated['data']['premium']['plan'] === 'pro', 'premium webhook plan mismatch');
        expect(api('POST', '/api/v1/premium/webhook', [], null, [], [], $webhookBody, [
            'x-payment-signature' => 'sha256=bad',
        ])->status === 403, 'invalid webhook signature should fail');

        $granted = decoded(api('POST', '/api/v1/admin/subscriptions', [
            'user_id' => $aliceId,
            'plan' => 'plus',
            'days' => 30,
        ], $adminToken), 201, 'admin grant');
        expect($granted['data']['premium']['plan'] === 'plus', 'admin grant plan mismatch');

        $stats = decoded(api('GET', '/api/v1/admin/stats', [], $adminToken), 200, 'admin stats extended');
        expect(isset($stats['data']['ads']['impressions']), 'ad stats missing');
        expect(isset($stats['data']['premium']['active_subscriptions']), 'premium stats missing');
        expect(isset($stats['data']['licensing']['total']), 'license stats missing');
        expect($stats['data']['analysis']['analyzed'] >= 2, 'analysis stats missing');
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
