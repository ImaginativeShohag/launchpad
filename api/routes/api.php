<?php

use App\Http\Controllers\Api\V1\Admin\AuditLogController;
use App\Http\Controllers\Api\V1\Admin\MemberDirectoryController;
use App\Http\Controllers\Api\V1\Admin\SettingController;
use App\Http\Controllers\Api\V1\Admin\TopicController as AdminTopicController;
use App\Http\Controllers\Api\V1\Admin\UserRoleController;
use App\Http\Controllers\Api\V1\Admin\UserSuspensionController;
use App\Http\Controllers\Api\V1\Auth\CancelEmailChangeController;
use App\Http\Controllers\Api\V1\Auth\ConfirmEmailChangeController;
use App\Http\Controllers\Api\V1\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\V1\Auth\GoogleTokenController;
use App\Http\Controllers\Api\V1\Auth\IssueTokenController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\ResendVerificationController;
use App\Http\Controllers\Api\V1\Auth\ResetPasswordController;
use App\Http\Controllers\Api\V1\Auth\RevokeTokenController;
use App\Http\Controllers\Api\V1\Auth\VerifyEmailController;
use App\Http\Controllers\Api\V1\AvatarController;
use App\Http\Controllers\Api\V1\BroadcastAuthController;
use App\Http\Controllers\Api\V1\CommentController;
use App\Http\Controllers\Api\V1\CommentRepliesController;
use App\Http\Controllers\Api\V1\DayController;
use App\Http\Controllers\Api\V1\DeleteAccountController;
use App\Http\Controllers\Api\V1\EmailPreferenceController;
use App\Http\Controllers\Api\V1\ExportController;
use App\Http\Controllers\Api\V1\FeedController;
use App\Http\Controllers\Api\V1\FollowController;
use App\Http\Controllers\Api\V1\Founder\DashboardController;
use App\Http\Controllers\Api\V1\LeaderboardController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\MyFollowsController;
use App\Http\Controllers\Api\V1\MyProductController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ProductCommentController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ProductLogoController;
use App\Http\Controllers\Api\V1\ProductScreenshotController;
use App\Http\Controllers\Api\V1\ProductViewController;
use App\Http\Controllers\Api\V1\PublicConfigController;
use App\Http\Controllers\Api\V1\PublicProductController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\RequestEmailChangeController;
use App\Http\Controllers\Api\V1\ScheduleLaunchController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\Staff\ProductIntegrityController;
use App\Http\Controllers\Api\V1\Staff\ProductVotesController;
use App\Http\Controllers\Api\V1\Staff\ReportDetailController;
use App\Http\Controllers\Api\V1\Staff\ReportHistoryController;
use App\Http\Controllers\Api\V1\Staff\ReportQueueController;
use App\Http\Controllers\Api\V1\Staff\ResolveReportController;
use App\Http\Controllers\Api\V1\Staff\RestoreController;
use App\Http\Controllers\Api\V1\Staff\ReviewController;
use App\Http\Controllers\Api\V1\Staff\ReviewQueueController;
use App\Http\Controllers\Api\V1\Staff\ReviewRiskSignalController;
use App\Http\Controllers\Api\V1\Staff\RiskMemberController;
use App\Http\Controllers\Api\V1\Staff\RiskSignalController;
use App\Http\Controllers\Api\V1\Staff\StaffProductController;
use App\Http\Controllers\Api\V1\Staff\SuspendMemberController;
use App\Http\Controllers\Api\V1\Staff\TakeDownController;
use App\Http\Controllers\Api\V1\Staff\VoidMemberVotesController;
use App\Http\Controllers\Api\V1\Staff\VoidVoteController;
use App\Http\Controllers\Api\V1\SubmitProductController;
use App\Http\Controllers\Api\V1\TodayController;
use App\Http\Controllers\Api\V1\TopicController;
use App\Http\Controllers\Api\V1\TopicFollowController;
use App\Http\Controllers\Api\V1\UnsubscribeController;
use App\Http\Controllers\Api\V1\UpdatePasswordController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\UserProductController;
use App\Http\Controllers\Api\V1\VoteController;
use App\Http\Controllers\Api\V1\WithdrawProductController;
use Illuminate\Support\Facades\Route;

// The /api/v1 prefix is set in bootstrap/app.php. Google's browser flow (redirect + callback) is in
// routes/web.php because it needs the session. Broadcasting auth is registered below, not by
// withBroadcasting(): it needs its own validation, limiter and the `active` check.

Route::prefix('auth')->group(function () {
    // Guests
    Route::post('register', RegisterController::class)->middleware('throttle:register');
    Route::post('login', LoginController::class)->middleware('throttle:login');
    Route::post('token', IssueTokenController::class)->middleware('throttle:login');
    Route::post('google/token', GoogleTokenController::class)->middleware('throttle:google');
    Route::post('password/forgot', ForgotPasswordController::class)->middleware('throttle:password-forgot');
    Route::post('password/reset', ResetPasswordController::class)->middleware('throttle:password-forgot');

    // Signed links from emails (the web app forwards the link's query string verbatim)
    Route::post('email/change/confirm', ConfirmEmailChangeController::class)
        ->middleware('signed:relative')->name('auth.email.change.confirm');
    Route::post('email/change/cancel', CancelEmailChangeController::class)
        ->middleware('signed:relative')->name('auth.email.change.cancel');

    // Signed in
    Route::post('logout', LogoutController::class)->middleware('auth:sanctum');
    Route::delete('token', RevokeTokenController::class)->middleware('auth:sanctum');
    Route::post('email/verify', VerifyEmailController::class)
        ->middleware(['auth:sanctum', 'active', 'signed:relative'])
        ->name('auth.email.verify');
    Route::post('email/resend', ResendVerificationController::class)
        ->middleware(['auth:sanctum', 'active', 'throttle:verification-resend']);
});

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    // No `verified.api`: the channel only carries the member's own data, and unverified members need it for prompts.
    Route::post('broadcasting/auth', BroadcastAuthController::class)->middleware('throttle:broadcast-auth');

    Route::get('me', [MeController::class, 'show']);
    Route::patch('me', [MeController::class, 'update'])->middleware('verified.api');
    Route::delete('me', DeleteAccountController::class)->middleware(['recent.login', 'throttle:account-delete']);
    Route::post('me/avatar', [AvatarController::class, 'store'])->middleware(['verified.api', 'throttle:avatar']);
    Route::delete('me/avatar', [AvatarController::class, 'destroy'])->middleware(['verified.api', 'throttle:avatar']);
    Route::put('me/password', UpdatePasswordController::class)->middleware('throttle:password-change');
    Route::post('me/email', RequestEmailChangeController::class)
        ->middleware(['verified.api', 'recent.login', 'throttle:email-change']);

    // The bell. No `verified.api`: an unverified member still gets notified in-site and must be able to read and clear it.
    // The two reads carry only the group's 60/min; the write has its own limiter.
    Route::get('me/notifications', [NotificationController::class, 'index']);
    Route::get('me/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('me/notifications/read', [NotificationController::class, 'markRead'])->middleware('throttle:notifications-read');

    // Mail preferences. Same guards as the bell: a member can switch mail off before verifying. A missing row means on.
    Route::get('me/email-preferences', [EmailPreferenceController::class, 'show']);
    Route::patch('me/email-preferences', [EmailPreferenceController::class, 'update'])->middleware('throttle:email-prefs');
});

// One-click unsubscribe from a mail (public, authorised by the relative signature, which has no expiry). The throttle runs
// before the signature check so forged links are limited too; the path is excluded from CSRF in bootstrap/app.php.
Route::post('email/unsubscribe', UnsubscribeController::class)
    ->middleware(['throttle:unsubscribe', 'signed:relative'])->name('email.unsubscribe');

Route::get('users/{user}', [UserController::class, 'show']);
Route::get('users/{user}/products', [UserProductController::class, 'index']);
Route::get('products/{slug}', [PublicProductController::class, 'show']);
// The view beacon: public (a signed-in caller is recognised only to skip the maker and staff), always 204 for a visible
// product. It carries only its own limiter, and its path is excluded from CSRF in bootstrap/app.php.
Route::post('products/{product}/view', ProductViewController::class)->middleware('throttle:view');
// Public reads; a signed-in caller (token or SPA cookie) is recognised for can_edit/can_delete, but nothing requires it.
Route::get('products/{slug}/comments', [ProductCommentController::class, 'index']);
Route::get('comments/{comment}/replies', CommentRepliesController::class);
Route::get('topics', [TopicController::class, 'index']);
// Public and identical for everyone: the three form limits, with a short shared cache (not no-store).
Route::get('config', PublicConfigController::class);
// Public; a signed-in caller (token or SPA cookie) is recognised for has_voted, but nothing requires it.
Route::get('today', TodayController::class);
Route::get('days/{date}', DayController::class);
Route::get('leaderboards', LeaderboardController::class);
// Public; the most expensive read, so it has its own limiter below the group's. Optional sign-in only for has_voted.
Route::get('search', SearchController::class)->middleware('throttle:search');

// Founder routes: signed in, active, email confirmed.
Route::middleware(['auth:sanctum', 'active', 'verified.api'])->group(function () {
    Route::get('me/products', [MyProductController::class, 'index']);
    Route::get('me/products/{product}', [MyProductController::class, 'show']);
    Route::post('products', [ProductController::class, 'store'])->middleware('throttle:product-write');
    Route::patch('products/{product}', [ProductController::class, 'update'])->middleware('throttle:product-write');
    Route::delete('products/{product}', [ProductController::class, 'destroy'])->middleware('throttle:product-write');

    Route::post('products/{product}/logo', [ProductLogoController::class, 'store'])->middleware('throttle:product-media');
    Route::delete('products/{product}/logo', [ProductLogoController::class, 'destroy'])->middleware('throttle:product-write');
    Route::post('products/{product}/screenshots', [ProductScreenshotController::class, 'store'])->middleware('throttle:product-media');
    Route::put('products/{product}/screenshots/order', [ProductScreenshotController::class, 'reorder'])->middleware('throttle:product-write');
    Route::delete('products/{product}/screenshots/{screenshot}', [ProductScreenshotController::class, 'destroy'])->middleware('throttle:product-write');

    Route::post('products/{product}/submit', SubmitProductController::class)->middleware('throttle:product-submit');
    Route::post('products/{product}/withdraw', WithdrawProductController::class)->middleware('throttle:product-submit');
    Route::put('products/{product}/launch', ScheduleLaunchController::class)->middleware('throttle:product-write');

    Route::put('products/{product}/vote', [VoteController::class, 'store'])->middleware('throttle:vote');
    Route::delete('products/{product}/vote', [VoteController::class, 'destroy'])->middleware('throttle:vote');

    // Plain-string ids: the actions look them up under the product lock, so every hidden case is one 404.
    Route::post('products/{product}/comments', [CommentController::class, 'store'])->middleware('throttle:comment-create');
    Route::patch('comments/{comment}', [CommentController::class, 'update'])->middleware('throttle:comment-modify');
    Route::delete('comments/{comment}', [CommentController::class, 'destroy'])->middleware('throttle:comment-modify');

    // Reports (plain-string ids; every hidden target is one 404).
    Route::post('comments/{comment}/report', [ReportController::class, 'comment'])->middleware('throttle:report');
    Route::post('products/{product}/report', [ReportController::class, 'product'])->middleware('throttle:report');

    // Follows. `{user}` is the handle binding (a deleted account is a 404; `active` and `verified.api` run before it);
    // `{topic}` is a plain slug string the action resolves. Lists are private: only `me/follows/*` exists, no follower lists.
    Route::put('users/{user}/follow', [FollowController::class, 'store'])->middleware('throttle:follow');
    Route::delete('users/{user}/follow', [FollowController::class, 'destroy'])->middleware('throttle:follow');
    Route::put('topics/{topic}/follow', [TopicFollowController::class, 'store'])->middleware('throttle:follow');
    Route::delete('topics/{topic}/follow', [TopicFollowController::class, 'destroy'])->middleware('throttle:follow');
    Route::get('me/follows/users', [MyFollowsController::class, 'users']);
    Route::get('me/follows/topics', [MyFollowsController::class, 'topics']);
    Route::get('me/feed', FeedController::class);

    // The dashboard reads (the maker's own numbers; the `staff/` mirror is below). Plain-string ids: the request authorises
    // the caller before it looks the product up or validates a parameter, so a stranger gets the one 404.
    Route::prefix('me/products/{product}/dashboard')->middleware('throttle:dashboard')->group(function () {
        Route::get('/', [DashboardController::class, 'summary']);
        Route::get('series', [DashboardController::class, 'series']);
        Route::get('rank', [DashboardController::class, 'rank']);
        Route::get('referrers', [DashboardController::class, 'referrers']);
    });

    // The maker's exports (plain-string ids; the actions authorise before any lookup, so every hidden case is one 404).
    // There is no staff route: staff cannot export.
    Route::post('me/products/{product}/exports', [ExportController::class, 'store'])->middleware('throttle:export-start');
    Route::get('me/products/{product}/exports', [ExportController::class, 'index'])->middleware('throttle:export-poll');
    Route::get('me/products/{product}/exports/{export}', [ExportController::class, 'show'])->middleware('throttle:export-poll');
    Route::get('me/products/{product}/exports/{export}/download', [ExportController::class, 'download'])->middleware('throttle:export-download');
});

// Staff routes (reviewers and admins; each request authorises the role before it looks anything up).
Route::prefix('staff')->middleware(['auth:sanctum', 'active', 'verified.api'])->group(function () {
    Route::get('review-queue', ReviewQueueController::class);
    Route::get('products', [StaffProductController::class, 'index']);
    Route::get('products/{product}', [StaffProductController::class, 'show']);
    // Read-only mirror of the maker's dashboard for support: role gate (403), then lookup (404), then the policy (a draft is 404).
    Route::prefix('products/{product}/dashboard')->middleware('throttle:dashboard')->group(function () {
        Route::get('/', [DashboardController::class, 'summary']);
        Route::get('series', [DashboardController::class, 'series']);
        Route::get('rank', [DashboardController::class, 'rank']);
        Route::get('referrers', [DashboardController::class, 'referrers']);
    });
    // Anti-cheat reads (reviewers and admins). Plain-string ids; the request judges the role (403) before anything is looked up
    // (404) or validated (422). Reads ride the global `api` limiter; the writes (review, void, suspend) use `admin-write`.
    Route::get('risk-signals', [RiskSignalController::class, 'index']);
    Route::get('risk-signals/{signal}', [RiskSignalController::class, 'show']);
    Route::post('risk-signals/{signal}/review', ReviewRiskSignalController::class)->middleware('throttle:admin-write');
    Route::get('risk/members', [RiskMemberController::class, 'index']);
    Route::get('risk/members/{member}', [RiskMemberController::class, 'show']);
    Route::post('risk/members/{member}/void-votes', VoidMemberVotesController::class)->middleware('throttle:admin-write');
    Route::post('risk/members/{member}/suspend', SuspendMemberController::class)->middleware('throttle:admin-write');
    Route::post('votes/{vote}/void', VoidVoteController::class)->middleware('throttle:admin-write');
    Route::get('products/{product}/vote-integrity', ProductIntegrityController::class);
    Route::get('products/{product}/votes', ProductVotesController::class);
    Route::post('products/{product}/review', ReviewController::class)->middleware('throttle:admin-write');
    Route::post('products/{product}/take-down', TakeDownController::class)->middleware('throttle:admin-write');
    Route::post('products/{product}/restore', RestoreController::class)->middleware('throttle:admin-write');

    // Reports: one row per reported target. `{type}` and `{id}` are plain strings; the request authorises the role first.
    Route::get('reports', ReportQueueController::class);
    Route::get('reports/history', ReportHistoryController::class);
    Route::get('reports/{type}/{id}', ReportDetailController::class);
    Route::post('reports/{type}/{id}/resolve', ResolveReportController::class)->middleware('throttle:admin-write');
});

Route::prefix('admin')->middleware(['auth:sanctum', 'active', 'verified.api'])->group(function () {
    // The member directory (reads ride the group limiter). Role gate first; no member lookup.
    Route::get('users', MemberDirectoryController::class);
    Route::patch('users/{user}/role', UserRoleController::class)->middleware('throttle:admin-write');
    // The handle stays a plain string: the request authorises the admin role before the member is looked up.
    Route::post('users/{user}/suspend', [UserSuspensionController::class, 'suspend'])->middleware('throttle:admin-write');
    Route::post('users/{user}/restore', [UserSuspensionController::class, 'restore'])->middleware('throttle:admin-write');
    Route::get('audit-logs', AuditLogController::class);
    Route::get('settings', [SettingController::class, 'index']);
    Route::patch('settings/{key}', [SettingController::class, 'update'])->middleware('throttle:admin-write');
    Route::post('topics', [AdminTopicController::class, 'store'])->middleware('throttle:admin-write');
    Route::patch('topics/{topic}', [AdminTopicController::class, 'update'])->middleware('throttle:admin-write');
    Route::delete('topics/{topic}', [AdminTopicController::class, 'destroy'])->middleware('throttle:admin-write');
});
