<?php

use Social\App;
use Social\Controllers\AdController;
use Social\Controllers\AdminController;
use Social\Controllers\AuthController;
use Social\Controllers\CommentController;
use Social\Controllers\FeedController;
use Social\Controllers\LicenseController;
use Social\Controllers\MessageController;
use Social\Controllers\NotificationController;
use Social\Controllers\PremiumController;
use Social\Controllers\RecommendationController;
use Social\Controllers\ReportController;
use Social\Controllers\SearchController;
use Social\Controllers\SystemController;
use Social\Controllers\UploadController;
use Social\Controllers\UserController;
use Social\Controllers\VideoController;
use Social\Http\Request;
use Social\Http\Router;

return function (App $app, Router $router): void {
    $system = new SystemController($app);
    $auth = new AuthController($app);
    $users = new UserController($app);
    $videos = new VideoController($app);
    $uploads = new UploadController($app);
    $comments = new CommentController($app);
    $feeds = new FeedController($app);
    $search = new SearchController($app);
    $notifications = new NotificationController($app);
    $messages = new MessageController($app);
    $reports = new ReportController($app);
    $admin = new AdminController($app);
    $recommendations = new RecommendationController($app);
    $licenses = new LicenseController($app);
    $premium = new PremiumController($app);
    $ads = new AdController($app);

    $router->get('/', fn (Request $request): \Social\Http\Response => $system->index());
    $router->get('/api/v1', fn (Request $request): \Social\Http\Response => $system->index());
    $router->get('/api/v1/health', fn (Request $request): \Social\Http\Response => $system->health());
    $router->get('/api/v1/platform', fn (Request $request): \Social\Http\Response => $system->platform());
    $router->get('/media/{path:.+}', fn (Request $request, array $params): \Social\Http\Response => $system->media($request, $params['path']));

    $router->post('/api/v1/auth/register', fn (Request $request): \Social\Http\Response => $auth->register($request));
    $router->post('/api/v1/auth/login', fn (Request $request): \Social\Http\Response => $auth->login($request));
    $router->post('/api/v1/auth/refresh', fn (Request $request): \Social\Http\Response => $auth->refresh($request));
    $router->post('/api/v1/auth/logout', fn (Request $request): \Social\Http\Response => $auth->logout($request), ['auth' => 'optional']);
    $router->post('/api/v1/auth/verify-email', fn (Request $request): \Social\Http\Response => $auth->verifyEmail($request));
    $router->post('/api/v1/auth/resend-verification', fn (Request $request): \Social\Http\Response => $auth->resendVerification($request));
    $router->post('/api/v1/auth/forgot-password', fn (Request $request): \Social\Http\Response => $auth->forgotPassword($request));
    $router->post('/api/v1/auth/reset-password', fn (Request $request): \Social\Http\Response => $auth->resetPassword($request));

    $router->get('/api/v1/me', fn (Request $request): \Social\Http\Response => $users->me($request), ['auth' => true]);
    $router->patch('/api/v1/me', fn (Request $request): \Social\Http\Response => $users->updateMe($request), ['auth' => true]);
    $router->delete('/api/v1/me', fn (Request $request): \Social\Http\Response => $users->deleteMe($request), ['auth' => true]);
    $router->post('/api/v1/me/avatar', fn (Request $request): \Social\Http\Response => $users->avatar($request), ['auth' => true]);
    $router->delete('/api/v1/me/avatar', fn (Request $request): \Social\Http\Response => $users->removeAvatar($request), ['auth' => true]);
    $router->post('/api/v1/me/password', fn (Request $request): \Social\Http\Response => $auth->changePassword($request), ['auth' => true]);
    $router->get('/api/v1/me/saved-videos', fn (Request $request): \Social\Http\Response => $users->savedVideos($request), ['auth' => true]);

    $router->get('/api/v1/users/{username}', fn (Request $request, array $params): \Social\Http\Response => $users->show($request, $params['username']), ['auth' => 'optional']);
    $router->get('/api/v1/users/{username}/videos', fn (Request $request, array $params): \Social\Http\Response => $users->videos($request, $params['username']), ['auth' => 'optional']);
    $router->get('/api/v1/users/{username}/followers', fn (Request $request, array $params): \Social\Http\Response => $users->followers($request, $params['username']), ['auth' => 'optional']);
    $router->get('/api/v1/users/{username}/following', fn (Request $request, array $params): \Social\Http\Response => $users->following($request, $params['username']), ['auth' => 'optional']);
    $router->post('/api/v1/users/{username}/follow', fn (Request $request, array $params): \Social\Http\Response => $users->follow($request, $params['username']), ['auth' => true]);
    $router->delete('/api/v1/users/{username}/follow', fn (Request $request, array $params): \Social\Http\Response => $users->unfollow($request, $params['username']), ['auth' => true]);
    $router->post('/api/v1/users/{username}/block', fn (Request $request, array $params): \Social\Http\Response => $users->block($request, $params['username']), ['auth' => true]);
    $router->delete('/api/v1/users/{username}/block', fn (Request $request, array $params): \Social\Http\Response => $users->unblock($request, $params['username']), ['auth' => true]);
    $router->post('/api/v1/users/{username}/mute', fn (Request $request, array $params): \Social\Http\Response => $users->mute($request, $params['username']), ['auth' => true]);
    $router->delete('/api/v1/users/{username}/mute', fn (Request $request, array $params): \Social\Http\Response => $users->unmute($request, $params['username']), ['auth' => true]);

    $router->post('/api/v1/videos', fn (Request $request): \Social\Http\Response => $videos->store($request), ['auth' => true]);
    $router->get('/api/v1/videos/{id}', fn (Request $request, array $params): \Social\Http\Response => $videos->show($request, $params['id']), ['auth' => 'optional']);
    $router->patch('/api/v1/videos/{id}', fn (Request $request, array $params): \Social\Http\Response => $videos->update($request, $params['id']), ['auth' => true]);
    $router->delete('/api/v1/videos/{id}', fn (Request $request, array $params): \Social\Http\Response => $videos->destroy($request, $params['id']), ['auth' => true]);
    $router->get('/api/v1/videos/{id}/stream', fn (Request $request, array $params): \Social\Http\Response => $videos->stream($request, $params['id']), ['auth' => 'optional']);
    $router->get('/api/v1/videos/{id}/analysis', fn (Request $request, array $params): \Social\Http\Response => $videos->analysis($request, $params['id']), ['auth' => 'optional']);
    $router->post('/api/v1/videos/{id}/analyze', fn (Request $request, array $params): \Social\Http\Response => $videos->analyze($request, $params['id']), ['auth' => true]);
    $router->post('/api/v1/videos/{id}/watch', fn (Request $request, array $params): \Social\Http\Response => $videos->watch($request, $params['id']), ['auth' => 'optional']);
    $router->post('/api/v1/videos/{id}/not-interested', fn (Request $request, array $params): \Social\Http\Response => $recommendations->notInterested($request, $params['id']), ['auth' => true]);
    $router->delete('/api/v1/videos/{id}/not-interested', fn (Request $request, array $params): \Social\Http\Response => $recommendations->clearNotInterested($request, $params['id']), ['auth' => true]);
    $router->post('/api/v1/videos/{id}/view', fn (Request $request, array $params): \Social\Http\Response => $videos->view($request, $params['id']), ['auth' => 'optional']);
    $router->post('/api/v1/videos/{id}/like', fn (Request $request, array $params): \Social\Http\Response => $videos->like($request, $params['id']), ['auth' => true]);
    $router->delete('/api/v1/videos/{id}/like', fn (Request $request, array $params): \Social\Http\Response => $videos->unlike($request, $params['id']), ['auth' => true]);
    $router->post('/api/v1/videos/{id}/save', fn (Request $request, array $params): \Social\Http\Response => $videos->save($request, $params['id']), ['auth' => true]);
    $router->delete('/api/v1/videos/{id}/save', fn (Request $request, array $params): \Social\Http\Response => $videos->unsave($request, $params['id']), ['auth' => true]);
    $router->post('/api/v1/videos/{id}/share', fn (Request $request, array $params): \Social\Http\Response => $videos->share($request, $params['id']), ['auth' => 'optional']);
    $router->get('/api/v1/videos/{id}/likes', fn (Request $request, array $params): \Social\Http\Response => $videos->likers($request, $params['id']), ['auth' => 'optional']);

    $router->get('/api/v1/videos/{id}/comments', fn (Request $request, array $params): \Social\Http\Response => $comments->index($request, $params['id']), ['auth' => 'optional']);
    $router->post('/api/v1/videos/{id}/comments', fn (Request $request, array $params): \Social\Http\Response => $comments->store($request, $params['id']), ['auth' => true]);
    $router->patch('/api/v1/comments/{id}', fn (Request $request, array $params): \Social\Http\Response => $comments->update($request, $params['id']), ['auth' => true]);
    $router->delete('/api/v1/comments/{id}', fn (Request $request, array $params): \Social\Http\Response => $comments->destroy($request, $params['id']), ['auth' => true]);
    $router->post('/api/v1/comments/{id}/like', fn (Request $request, array $params): \Social\Http\Response => $comments->like($request, $params['id']), ['auth' => true]);
    $router->delete('/api/v1/comments/{id}/like', fn (Request $request, array $params): \Social\Http\Response => $comments->unlike($request, $params['id']), ['auth' => true]);
    $router->get('/api/v1/comments/{id}/replies', fn (Request $request, array $params): \Social\Http\Response => $comments->replies($request, $params['id']), ['auth' => 'optional']);

    $router->post('/api/v1/uploads', fn (Request $request): \Social\Http\Response => $uploads->create($request), ['auth' => true]);
    $router->get('/api/v1/uploads/{id}', fn (Request $request, array $params): \Social\Http\Response => $uploads->status($request, $params['id']), ['auth' => true]);
    $router->put('/api/v1/uploads/{id}/chunk', fn (Request $request, array $params): \Social\Http\Response => $uploads->chunk($request, $params['id']), ['auth' => true]);
    $router->post('/api/v1/uploads/{id}/complete', fn (Request $request, array $params): \Social\Http\Response => $uploads->complete($request, $params['id']), ['auth' => true]);
    $router->delete('/api/v1/uploads/{id}', fn (Request $request, array $params): \Social\Http\Response => $uploads->destroy($request, $params['id']), ['auth' => true]);

    $router->get('/api/v1/feed/following', fn (Request $request): \Social\Http\Response => $feeds->following($request), ['auth' => true]);
    $router->get('/api/v1/feed/foryou', fn (Request $request): \Social\Http\Response => $feeds->forYou($request), ['auth' => 'optional']);
    $router->get('/api/v1/feed/shorts', fn (Request $request): \Social\Http\Response => $feeds->shorts($request), ['auth' => 'optional']);
    $router->get('/api/v1/feed/long', fn (Request $request): \Social\Http\Response => $feeds->longform($request), ['auth' => 'optional']);
    $router->get('/api/v1/explore', fn (Request $request): \Social\Http\Response => $feeds->explore($request), ['auth' => 'optional']);
    $router->get('/api/v1/recommendations/interests', fn (Request $request): \Social\Http\Response => $recommendations->interests($request), ['auth' => true]);
    $router->put('/api/v1/recommendations/interests', fn (Request $request): \Social\Http\Response => $recommendations->updateInterests($request), ['auth' => true]);
    $router->delete('/api/v1/recommendations/interests', fn (Request $request): \Social\Http\Response => $recommendations->clearInterests($request), ['auth' => true]);
    $router->get('/api/v1/trends', fn (Request $request): \Social\Http\Response => $feeds->trends($request), ['auth' => 'optional']);
    $router->get('/api/v1/hashtags/{tag}', fn (Request $request, array $params): \Social\Http\Response => $feeds->hashtag($request, $params['tag']), ['auth' => 'optional']);
    $router->get('/api/v1/search', fn (Request $request): \Social\Http\Response => $search->search($request), ['auth' => 'optional']);

    $router->get('/api/v1/notifications', fn (Request $request): \Social\Http\Response => $notifications->index($request), ['auth' => true]);
    $router->get('/api/v1/notifications/unread-count', fn (Request $request): \Social\Http\Response => $notifications->unreadCount($request), ['auth' => true]);
    $router->post('/api/v1/notifications/read-all', fn (Request $request): \Social\Http\Response => $notifications->readAll($request), ['auth' => true]);
    $router->post('/api/v1/notifications/{id}/read', fn (Request $request, array $params): \Social\Http\Response => $notifications->read($request, $params['id']), ['auth' => true]);

    $router->get('/api/v1/conversations', fn (Request $request): \Social\Http\Response => $messages->index($request), ['auth' => true]);
    $router->post('/api/v1/conversations', fn (Request $request): \Social\Http\Response => $messages->store($request), ['auth' => true]);
    $router->get('/api/v1/conversations/{id}', fn (Request $request, array $params): \Social\Http\Response => $messages->show($request, $params['id']), ['auth' => true]);
    $router->get('/api/v1/conversations/{id}/messages', fn (Request $request, array $params): \Social\Http\Response => $messages->messages($request, $params['id']), ['auth' => true]);
    $router->post('/api/v1/conversations/{id}/messages', fn (Request $request, array $params): \Social\Http\Response => $messages->send($request, $params['id']), ['auth' => true]);
    $router->post('/api/v1/conversations/{id}/read', fn (Request $request, array $params): \Social\Http\Response => $messages->read($request, $params['id']), ['auth' => true]);

    $router->get('/api/v1/license/plans', fn (Request $request): \Social\Http\Response => $licenses->plans());
    $router->get('/api/v1/license/status', fn (Request $request): \Social\Http\Response => $licenses->status(), ['auth' => true, 'role' => ['admin']]);
    $router->post('/api/v1/license/activate', fn (Request $request): \Social\Http\Response => $licenses->activate($request));
    $router->post('/api/v1/license/checkout', fn (Request $request): \Social\Http\Response => $licenses->checkout($request));
    $router->post('/api/v1/license/webhook', fn (Request $request): \Social\Http\Response => $licenses->webhook($request));

    $router->get('/api/v1/premium/plans', fn (Request $request): \Social\Http\Response => $premium->plans());
    $router->get('/api/v1/premium/status', fn (Request $request): \Social\Http\Response => $premium->status($request), ['auth' => true]);
    $router->get('/api/v1/me/subscription', fn (Request $request): \Social\Http\Response => $premium->subscription($request), ['auth' => true]);
    $router->post('/api/v1/premium/subscribe', fn (Request $request): \Social\Http\Response => $premium->subscribe($request), ['auth' => true]);
    $router->post('/api/v1/premium/cancel', fn (Request $request): \Social\Http\Response => $premium->cancel($request), ['auth' => true]);
    $router->post('/api/v1/premium/webhook', fn (Request $request): \Social\Http\Response => $premium->webhook($request));

    $router->get('/api/v1/ads/serve', fn (Request $request): \Social\Http\Response => $ads->serve($request), ['auth' => 'optional']);
    $router->post('/api/v1/ads/{id}/click', fn (Request $request, array $params): \Social\Http\Response => $ads->click($request, $params['id']), ['auth' => 'optional']);
    $router->get('/api/v1/ads/campaigns', fn (Request $request): \Social\Http\Response => $ads->index($request), ['auth' => true]);
    $router->post('/api/v1/ads/campaigns', fn (Request $request): \Social\Http\Response => $ads->store($request), ['auth' => true]);
    $router->get('/api/v1/ads/campaigns/{id}', fn (Request $request, array $params): \Social\Http\Response => $ads->show($request, $params['id']), ['auth' => true]);
    $router->patch('/api/v1/ads/campaigns/{id}', fn (Request $request, array $params): \Social\Http\Response => $ads->update($request, $params['id']), ['auth' => true]);
    $router->post('/api/v1/ads/campaigns/{id}/creatives', fn (Request $request, array $params): \Social\Http\Response => $ads->creative($request, $params['id']), ['auth' => true]);
    $router->get('/api/v1/ads/campaigns/{id}/stats', fn (Request $request, array $params): \Social\Http\Response => $ads->stats($request, $params['id']), ['auth' => true]);

    $router->post('/api/v1/reports', fn (Request $request): \Social\Http\Response => $reports->store($request), ['auth' => true]);

    $router->get('/api/v1/admin/stats', fn (Request $request): \Social\Http\Response => $admin->stats(), ['auth' => true, 'role' => ['admin', 'moderator']]);
    $router->get('/api/v1/admin/users', fn (Request $request): \Social\Http\Response => $admin->users($request), ['auth' => true, 'role' => ['admin']]);
    $router->post('/api/v1/admin/users/{id}/ban', fn (Request $request, array $params): \Social\Http\Response => $admin->ban($request, $params['id']), ['auth' => true, 'role' => ['admin']]);
    $router->post('/api/v1/admin/users/{id}/unban', fn (Request $request, array $params): \Social\Http\Response => $admin->unban($params['id']), ['auth' => true, 'role' => ['admin']]);
    $router->post('/api/v1/admin/users/{id}/verify', fn (Request $request, array $params): \Social\Http\Response => $admin->verify($request, $params['id']), ['auth' => true, 'role' => ['admin']]);
    $router->post('/api/v1/admin/users/{id}/role', fn (Request $request, array $params): \Social\Http\Response => $admin->role($request, $params['id']), ['auth' => true, 'role' => ['admin']]);
    $router->get('/api/v1/admin/reports', fn (Request $request): \Social\Http\Response => $admin->reports($request), ['auth' => true, 'role' => ['admin', 'moderator']]);
    $router->post('/api/v1/admin/reports/{id}/resolve', fn (Request $request, array $params): \Social\Http\Response => $admin->resolveReport($request, $params['id']), ['auth' => true, 'role' => ['admin', 'moderator']]);
    $router->post('/api/v1/admin/videos/{id}/takedown', fn (Request $request, array $params): \Social\Http\Response => $admin->takedown($params['id']), ['auth' => true, 'role' => ['admin', 'moderator']]);
    $router->post('/api/v1/admin/videos/{id}/restore', fn (Request $request, array $params): \Social\Http\Response => $admin->restore($params['id']), ['auth' => true, 'role' => ['admin', 'moderator']]);
    $router->get('/api/v1/admin/licenses', fn (Request $request): \Social\Http\Response => $licenses->index($request), ['auth' => true, 'role' => ['admin']]);
    $router->post('/api/v1/admin/licenses', fn (Request $request): \Social\Http\Response => $licenses->issue($request), ['auth' => true, 'role' => ['admin']]);
    $router->post('/api/v1/admin/licenses/{id}/revoke', fn (Request $request, array $params): \Social\Http\Response => $licenses->revoke($params['id']), ['auth' => true, 'role' => ['admin']]);
    $router->post('/api/v1/admin/subscriptions', fn (Request $request): \Social\Http\Response => $premium->grant($request), ['auth' => true, 'role' => ['admin']]);
    $router->post('/api/v1/admin/ads/{id}/status', fn (Request $request, array $params): \Social\Http\Response => $ads->adminStatus($request, $params['id']), ['auth' => true, 'role' => ['admin', 'moderator']]);
};
