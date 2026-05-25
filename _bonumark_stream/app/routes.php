<?php
require_once __DIR__ . '/profiles.php';
require_once __DIR__ . '/registration.php';
require_once __DIR__ . '/password-recovery.php';
require_once __DIR__ . '/comments.php';
require_once __DIR__ . '/renderer.php';
require_once __DIR__ . '/pages.php';
require_once __DIR__ . '/sitemap.php';

function mp_public_safe_exception_notice(Throwable $e, string $fallback = 'The request could not be completed. Try again or contact the site admin.'): string
{
    $message = trim((string)$e->getMessage());
    if ($message === '') {
        return $fallback;
    }

    $unsafePatterns = [
        '/SQLSTATE\[/i',
        '/PDO/i',
        '/database/i',
        '/stack trace/i',
        '/\/[_A-Za-z0-9.-]+\//',
        '/[A-Za-z]:\\\\/',
        '/config\.php/i',
        '/installed\.lock/i',
        '/_bonumark_stream/i',
    ];

    foreach ($unsafePatterns as $pattern) {
        if (preg_match($pattern, $message) === 1) {
            error_log('Bonumark Stream public route error: ' . $message);
            return $fallback;
        }
    }

    return $message;
}

function mp_handle_account_route(): void
{
    if (!mp_is_installed()) {
        mp_redirect(mp_url_path('install.php'));
    }

    $returnTo = mp_stream_safe_return_url((string)($_GET['return_to'] ?? $_POST['return_to'] ?? mp_url_path()));
    $notice = '';
    $noticeType = 'info';

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && (string)($_GET['action'] ?? '') === 'verify') {
        try {
            $verifiedUser = mp_registration_verify_token((string)($_GET['token'] ?? ''));
            if ((string)($verifiedUser['status'] ?? '') === 'active') {
                $notice = 'Email verified. You can sign in now.';
            } else {
                $notice = 'Email verified. Your account is waiting for admin approval.';
            }
            $noticeType = 'success';
        } catch (Throwable $e) {
            $notice = mp_public_safe_exception_notice($e);
            $noticeType = 'error';
        }
    }

    $accountAction = (string)($_GET['action'] ?? '');
    $resetToken = (string)($_GET['token'] ?? $_POST['token'] ?? '');
    $resetTokenValid = false;

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && $accountAction === 'reset') {
        $resetTokenValid = mp_password_recovery_token_is_valid($resetToken);
        if (!$resetTokenValid) {
            $notice = 'Password reset link is invalid or expired.';
            $noticeType = 'error';
        }
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && $accountAction === 'logout' && mp_is_logged_in()) {
        $token = (string)($_GET['csrf_token'] ?? '');
        if ($token !== '' && hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
            mp_logout();
            mp_redirect(mp_url_path('account.php'));
        }
        $notice = 'Invalid sign-out link. Open your account page and try again.';
        $noticeType = 'error';
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        try {
            mp_verify_csrf();
            $action = (string)($_POST['action'] ?? '');
            if ($action === 'login') {
                $loginUsername = (string)($_POST['username'] ?? '');
                $candidate = function_exists('mp_find_user_by_username_any') ? mp_find_user_by_username_any($loginUsername) : null;
                if (is_array($candidate) && (string)($candidate['status'] ?? '') === 'pending') {
                    if (trim((string)($candidate['email_verified_at'] ?? '')) !== '') {
                        throw new RuntimeException('That account is waiting for admin approval.');
                    }
                    throw new RuntimeException('That account is pending. Check your email and verify the account before signing in.');
                }
                if (mp_attempt_login($loginUsername, (string)($_POST['password'] ?? ''))) {
                    mp_redirect($returnTo !== '' ? $returnTo : mp_url_path('account.php'));
                }
                throw new RuntimeException('Login failed. Check the username and password.');
            }
            if ($action === 'register') {
                $result = mp_registration_create_public_account(
                    (string)($_POST['username'] ?? ''),
                    (string)($_POST['display_name'] ?? ''),
                    (string)($_POST['email'] ?? ''),
                    (string)($_POST['password'] ?? ''),
                    (string)($_POST['confirm_password'] ?? ''),
                    (string)($_POST['company_url'] ?? ''),
                    (string)($_POST['invite_code'] ?? '')
                );
                $user = is_array($result['user'] ?? null) ? $result['user'] : [];
                if (!empty($result['requires_verification'])) {
                    $notice = 'Account created. Check your email to verify the account before signing in.';
                    if (!empty($result['requires_approval'])) {
                        $notice .= ' Admin approval will also be required.';
                    }
                    if ((string)($result['mail_error'] ?? '') !== '') {
                        $notice .= ' The verification email could not be sent. Contact the site admin if the message does not arrive.';
                        error_log('Bonumark Stream verification mail error: ' . (string)$result['mail_error']);
                        $noticeType = 'warning';
                    } else {
                        $noticeType = 'success';
                    }
                } elseif (!empty($result['requires_approval'])) {
                    $notice = 'Account created. It is waiting for admin approval.';
                    $noticeType = 'success';
                } elseif (mp_attempt_login((string)($user['username'] ?? ''), (string)($_POST['password'] ?? ''))) {
                    mp_redirect(mp_url_path('account.php'));
                } else {
                    $notice = 'Account created. You can sign in now.';
                    $noticeType = 'success';
                }
            }
            if ($action === 'resend_verification') {
                $notice = mp_registration_resend_verification((string)($_POST['username_or_email'] ?? ''));
                $noticeType = 'success';
            }
            if ($action === 'forgot_password') {
                $notice = mp_password_recovery_request_reset((string)($_POST['username_or_email'] ?? ''));
                $noticeType = 'success';
                $accountAction = 'forgot';
            }
            if ($action === 'reset_password') {
                mp_password_recovery_reset_password((string)($_POST['token'] ?? ''), (string)($_POST['new_password'] ?? ''), (string)($_POST['confirm_password'] ?? ''));
                $notice = 'Password updated. You can sign in now.';
                $noticeType = 'success';
                $accountAction = '';
                $resetToken = '';
                $resetTokenValid = false;
            }
            if ($action === 'profile' && mp_is_logged_in()) {
                mp_update_current_user_profile((string)($_POST['username'] ?? ''), (string)($_POST['display_name'] ?? ''), (string)($_POST['email'] ?? ''), (string)($_POST['bio'] ?? ''), (string)($_POST['website'] ?? ''), (string)($_POST['profile_visibility'] ?? 'public'), is_array($_POST['social_links'] ?? null) ? $_POST['social_links'] : []);
                mp_apply_current_user_avatar_from_request($_FILES, !empty($_POST['remove_avatar']));
                mp_redirect(mp_url_path('account.php'));
            }
            if ($action === 'password' && mp_is_logged_in()) {
                mp_update_current_user_password((string)($_POST['current_password'] ?? ''), (string)($_POST['new_password'] ?? ''), (string)($_POST['confirm_password'] ?? ''));
                $notice = 'Password updated.';
                $noticeType = 'success';
            }
            if ($action === 'logout') {
                mp_logout();
                mp_redirect(mp_url_path('account.php'));
            }
        } catch (Throwable $e) {
            $notice = mp_public_safe_exception_notice($e);
            $noticeType = 'error';
            $postedAction = (string)($_POST['action'] ?? '');
            if ($postedAction === 'forgot_password') {
                $accountAction = 'forgot';
            }
            if ($postedAction === 'reset_password') {
                $accountAction = 'reset';
                $resetToken = (string)($_POST['token'] ?? '');
                $resetTokenValid = mp_password_recovery_token_is_valid($resetToken);
            }
        }
    }

    $user = mp_is_logged_in() ? mp_current_user() : null;
    $canViewAdmin = $user && mp_current_user_can('view_admin');
    $accountDashboard = $user ? mp_account_dashboard_data($user) : [];
    $view = [
        'site_name' => (string)mp_setting_or_config('site_name', 'Bonumark Stream'),
        'style_url' => mp_asset_url('assets/style.css'),
        'script_url' => mp_asset_url('assets/stream.js'),
        'theme_stylesheet_links' => mp_public_theme_stylesheet_links(),
        'theme_script_tags' => mp_public_theme_script_tags(),
        'body_class' => mp_public_theme_class('account-page'),
        'header_html' => mp_render_public_header('account', null, 'account.php'),
        'footer_html' => mp_render_public_footer('account.php'),
        'notice' => $notice,
        'notice_type' => $noticeType,
        'csrf' => mp_csrf_token(),
        'return_to' => $returnTo,
        'account_action' => $accountAction,
        'password_reset_token' => $resetToken,
        'password_reset_token_valid' => $resetTokenValid,
        'password_recovery_mail_ready' => mp_password_recovery_mail_ready(),
        'forgot_password_url' => mp_url_path('account.php?action=forgot'),
        'sign_in_url' => mp_url_path('account.php'),
        'user' => $user,
        'profile_url' => $user ? mp_public_profile_url_for_user($user) : '',
        'avatar_markup' => $user ? mp_user_avatar_markup($user, 'account-avatar-image', 192, 192) : '',
        'has_avatar' => $user ? mp_user_avatar_url($user) !== '' : false,
        'profile_social_link_definitions' => function_exists('mp_profile_social_link_definitions') ? mp_profile_social_link_definitions() : [],
        'profile_social_link_values' => $user && function_exists('mp_profile_social_link_form_values') ? mp_profile_social_link_form_values($user) : [],
        'account_dashboard' => $accountDashboard,
        'account_post_counts' => $accountDashboard['post_counts'] ?? ['published' => 0, 'draft' => 0, 'total' => 0],
        'account_comment_counts' => $accountDashboard['comment_counts'] ?? ['approved' => 0, 'pending' => 0, 'trash' => 0, 'total' => 0],
        'account_recent_comments' => $accountDashboard['recent_comments'] ?? [],
        'account_recent_posts' => $accountDashboard['recent_posts'] ?? [],
        'account_role_label' => $accountDashboard['role_label'] ?? '',
        'account_status_label' => $accountDashboard['status_label'] ?? '',
        'account_visibility_label' => $accountDashboard['visibility_label'] ?? '',
        'account_email_status_label' => $accountDashboard['email_status_label'] ?? '',
        'account_member_since' => $accountDashboard['member_since'] ?? '',
        'account_can_write_posts' => !empty($accountDashboard['can_write_posts']),
        'account_can_comment' => !empty($accountDashboard['can_comment']),
        'can_view_admin' => $canViewAdmin,
        'admin_url' => $canViewAdmin ? mp_admin_url() : '',
        'admin_label' => 'Open Admin',
        'comment_registration_enabled' => mp_comment_registration_enabled(),
        'registration_enabled' => mp_public_registration_enabled(),
        'registration_mode' => mp_registration_mode(),
        'registration_invite_required' => mp_registration_invite_required(),
        'registration_requires_admin_approval' => mp_registration_require_admin_approval(),
        'registration_user_role_requires_approval' => mp_registration_user_role_requires_approval(),
        'registration_default_role' => mp_registration_default_role(),
        'registration_default_role_label' => mp_role_label(mp_registration_default_role()),
        'registration_requires_email_verification' => mp_registration_require_email_verification(),
        'registration_mail_ready' => mp_registration_mail_ready(),
        'verification_resend_available' => mp_registration_require_email_verification() && mp_registration_mail_ready(),
    ];

    echo mp_render_public_theme_template('account', $view);
}

function mp_handle_profile_route(): void
{
    if (!mp_is_installed()) {
        mp_redirect(mp_url_path('install.php'));
    }

    $user = null;
    $id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;
    $username = (string)($_GET['user'] ?? $_GET['username'] ?? '');

    if ($id > 0 && function_exists('mp_find_public_user_by_id')) {
        $user = mp_find_public_user_by_id($id);
    }

    if (!$user && trim($username) !== '') {
        $user = function_exists('mp_find_public_user_by_handle') ? mp_find_public_user_by_handle($username) : mp_find_public_user_by_username($username);
    }

    if (!$user && trim($username) !== '' && function_exists('mp_current_user')) {
        $current = mp_current_user();
        $requested = mp_normalize_username($username);
        $currentUsername = mp_normalize_username((string)($current['username'] ?? ''));
        $currentDisplay = mp_normalize_username((string)($current['display_name'] ?? ''));
        if ((int)($current['id'] ?? 0) > 0 && ($requested === $currentUsername || $requested === $currentDisplay)) {
            $user = mp_find_public_user_by_id((int)$current['id']);
        }
    }

    if (!$user && trim($username) === '' && $id < 1 && function_exists('mp_current_user')) {
        $current = mp_current_user();
        if ((int)($current['id'] ?? 0) > 0 && function_exists('mp_find_public_user_by_id')) {
            $user = mp_find_public_user_by_id((int)$current['id']);
        }
    }

    if (!$user) {
        http_response_code(404);
    }
    echo mp_profile_page_html($user);
}


function mp_handle_author_route(): void
{
    if (!mp_is_installed()) {
        mp_redirect(mp_url_path('install.php'));
    }

    $user = null;
    $id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;
    $username = (string)($_GET['user'] ?? $_GET['username'] ?? '');

    if ($id > 0 && function_exists('mp_find_public_user_by_id')) {
        $user = mp_find_public_user_by_id($id);
    }
    if (!$user && trim($username) !== '') {
        $user = function_exists('mp_find_public_user_by_handle') ? mp_find_public_user_by_handle($username) : mp_find_public_user_by_username($username);
    }

    if ($user) {
        echo mp_author_archive_page_html($user);
        return;
    }

    http_response_code(404);
    echo mp_author_archive_page_html(null);
}



function mp_handle_stream_route(): void
{
    if (!mp_is_installed()) {
        mp_redirect(mp_url_path('install.php'));
    }
    require_once __DIR__ . '/renderer.php';
    $slug = mp_slugify((string)($_GET['slug'] ?? ''));
    if ($slug !== '') {
        $page = null;
        if (function_exists('mp_find_database_content_by_slug_status')) {
            $page = mp_find_database_content_by_slug_status($slug, 'published', 'stream');
        }
        if (!$page) {
            http_response_code(404);
            echo mp_render_public_theme_template('empty', [
                'context' => 'stream-single',
                'title' => 'Stream post not found.',
                'message' => 'The requested stream post could not be found.',
            ]);
            return;
        }
        echo mp_render_stream_single($page);
        return;
    }

    $pageNumber = max(1, (int)($_GET['page'] ?? 1));
    echo mp_render_stream_index(mp_list_content_records('published'), false, $pageNumber, $pageNumber > 1 ? 'archive' : 'archive');
}

function mp_handle_page_public_route(): void
{
    mp_handle_page_route();
}

function mp_handle_comments_route(): void
{
    if (!mp_is_installed()) {
        mp_redirect(mp_url_path('install.php'));
    }
    $slug = mp_slugify((string)($_POST['slug'] ?? $_GET['slug'] ?? ''));
    $notice = '';
    if ($slug === '') {
        http_response_code(400);
        echo 'Missing Stream Post.';
        return;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        try {
            mp_verify_csrf();
            $comment = mp_create_comment($slug, (string)($_POST['body'] ?? ''));
            $notice = ((string)($comment['status'] ?? 'approved') === 'approved') ? 'Comment posted.' : 'Comment saved for review.';
        } catch (Throwable $e) {
            http_response_code(400);
            $notice = mp_public_safe_exception_notice($e, 'Comment could not be saved. Check the form and try again.');
        }
    }
    header('Content-Type: text/html; charset=UTF-8');
    echo mp_render_comments_panel($slug, $notice);
}


function mp_handle_feed_route(string $feedType = 'root'): void
{
    if (!mp_is_installed()) {
        mp_redirect(mp_url_path('install.php'));
    }
    require_once __DIR__ . '/renderer.php';
    header('Content-Type: application/rss+xml; charset=UTF-8');
    echo mp_render_rss_feed(mp_list_content_records('published'), $feedType === 'stream' ? 'stream' : 'root');
}

function mp_handle_sitemap_route(): void
{
    if (!mp_is_installed()) {
        mp_redirect(mp_url_path('install.php'));
    }
    if (!mp_sitemap_enabled()) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Sitemap disabled.';
        return;
    }
    header('Content-Type: application/xml; charset=UTF-8');
    echo mp_render_xml_sitemap();
}

function mp_handle_sitemap_xsl_route(): void
{
    if (!mp_is_installed()) {
        mp_redirect(mp_url_path('install.php'));
    }
    header('Content-Type: text/xsl; charset=UTF-8');
    echo mp_render_sitemap_xsl();
}

function mp_handle_robots_route(): void
{
    if (!mp_is_installed()) {
        mp_redirect(mp_url_path('install.php'));
    }
    header('Content-Type: text/plain; charset=UTF-8');
    echo mp_render_robots_txt();
}

function mp_handle_search_route(): void
{
    if (!mp_is_installed()) {
        mp_redirect(mp_url_path('install.php'));
    }

    require_once __DIR__ . '/renderer.php';
    require_once __DIR__ . '/pages.php';
    $query = trim((string)($_GET['q'] ?? $_GET['s'] ?? ''));
    echo mp_render_stream_search($query);
}

function mp_dispatch_public_route(string $route): bool
{
    $route = strtolower(trim($route));
    if ($route === 'profile') {
        mp_handle_profile_route();
        return true;
    }
    if ($route === 'author') {
        mp_handle_author_route();
        return true;
    }
    if ($route === 'account') {
        mp_handle_account_route();
        return true;
    }
    if ($route === 'stream') {
        mp_handle_stream_route();
        return true;
    }
    if ($route === 'page') {
        mp_handle_page_public_route();
        return true;
    }
    if ($route === 'comments') {
        mp_handle_comments_route();
        return true;
    }
    if ($route === 'search') {
        mp_handle_search_route();
        return true;
    }
    if ($route === 'feed') {
        mp_handle_feed_route((string)($_GET['feed_type'] ?? 'root'));
        return true;
    }
    if ($route === 'sitemap') {
        mp_handle_sitemap_route();
        return true;
    }
    if ($route === 'sitemap_xsl') {
        mp_handle_sitemap_xsl_route();
        return true;
    }
    if ($route === 'robots') {
        mp_handle_robots_route();
        return true;
    }
    return false;
}
