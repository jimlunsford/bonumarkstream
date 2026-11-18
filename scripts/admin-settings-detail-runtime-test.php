<?php
/**
 * Settings detail HTTP contract regression test against a disposable install.
 *
 * Uses BMS_DB_HOST, BMS_DB_NAME, BMS_DB_USER, BMS_DB_PASS and requires
 * BMS_DB_DANGER_RESET=1. Only random bms_details_ci_* tables are created and
 * removed. Application files, sessions and the loopback HTTP server are temporary.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
if ((string)getenv('BMS_DB_DANGER_RESET') !== '1') {
    fwrite(STDERR, "Refusing to run without BMS_DB_DANGER_RESET=1.\n");
    exit(1);
}
foreach (['BMS_DB_HOST', 'BMS_DB_NAME', 'BMS_DB_USER'] as $required) {
    if ((string)getenv($required) === '') {
        throw new RuntimeException($required . ' is required.');
    }
}

$source = dirname(__DIR__);
$temporary = sys_get_temp_dir() . '/bms-settings-detail-test-' . bin2hex(random_bytes(6));
$prefix = 'bms_details_ci_' . bin2hex(random_bytes(5)) . '_';
$process = null;
$pdo = null;
$checks = 0;

function bms_settings_test_expect(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
    $GLOBALS['checks']++;
    fwrite(STDOUT, 'PASS ' . $label . "\n");
}

function bms_settings_test_request(string $base, string $path, array &$cookies, ?array $form = null): array
{
    $headers = ['User-Agent: Bonumark Settings Regression', 'Connection: close'];
    if ($cookies !== []) {
        $headers[] = 'Cookie: ' . implode('; ', array_map(static fn(string $key, string $value): string => $key . '=' . $value, array_keys($cookies), $cookies));
    }
    $options = ['method' => $form === null ? 'GET' : 'POST', 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10];
    if ($form !== null) {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $options['content'] = http_build_query($form);
    }
    $options['header'] = implode("\r\n", $headers);
    $body = file_get_contents($base . $path, false, stream_context_create(['http' => $options]));
    if ($body === false || !isset($http_response_header[0])) {
        throw new RuntimeException('HTTP request failed: ' . $path);
    }
    preg_match('/\s(\d{3})\s/', $http_response_header[0], $status);
    $location = '';
    foreach ($http_response_header as $header) {
        if (preg_match('/^Set-Cookie:\s*([^=;]+)=([^;]*)/i', $header, $match)) {
            $cookies[$match[1]] = $match[2];
        }
        if (stripos($header, 'Location:') === 0) {
            $location = trim(substr($header, 9));
        }
    }
    return ['status' => (int)($status[1] ?? 0), 'body' => $body, 'location' => $location];
}

function bms_settings_test_token(string $html): string
{
    if (!preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $match)) {
        throw new RuntimeException('Missing CSRF token in rendered form.');
    }
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
}

function bms_settings_test_values(PDO $pdo, string $prefix): array
{
    return $pdo->query("SELECT setting_key, setting_value FROM `{$prefix}settings` WHERE setting_key IN ('site_name','site_tagline','site_admin_email','timezone','base_url','base_path') ORDER BY setting_key")->fetchAll(PDO::FETCH_KEY_PAIR);
}

function bms_settings_test_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($path);
}

try {
    mkdir($temporary, 0700);
    $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), static function (SplFileInfo $file) use ($source): bool {
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($source) + 1));
        // Never copy an installed checkout's credentials, backups, uploads or
        // generated exports into the disposable HTTP document root.
        return !$file->isLink()
            && !in_array($relative, [
                '.git', '_bonumark_stream/config.php', '_bonumark_stream/installed.lock',
                '_bonumark_stream/data', '_bonumark_stream/tmp', '_bonumark_stream/backups',
                'media', 'index.html', 'stream', 'feed.xml', 'sitemap.xml', 'sitemap.xsl', 'robots.txt',
            ], true)
            && preg_match('/\.(?:log|sql|sqlite|zip|tar|tar\.gz|tgz)$/i', $relative) !== 1;
    }), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $file) {
        $target = $temporary . substr($file->getPathname(), strlen($source));
        $file->isDir() ? mkdir($target, 0700, true) : copy($file->getPathname(), $target);
    }
    mkdir($temporary . '/sessions', 0700);
    mkdir($temporary . '/media', 0700);
    $listener = stream_socket_server('tcp://127.0.0.1:0', $error, $errorMessage);
    if ($listener === false) {
        throw new RuntimeException('Cannot reserve a loopback port: ' . $errorMessage);
    }
    $address = stream_socket_get_name($listener, false);
    fclose($listener);
    $base = 'http://' . $address;
    $config = [
        'site_name' => 'Settings Fixture', 'site_tagline' => 'Saved tagline',
        'site_admin_email' => 'saved@example.test', 'timezone' => 'America/Indiana/Indianapolis',
        'base_url' => $base, 'base_path' => '', 'security_salt' => bin2hex(random_bytes(24)),
        'scheduled_tasks_public_traffic_enabled' => '0', 'scheduled_tasks_heartbeat_enabled' => '0',
        'activitypub_enabled' => '0', 'pwa_enabled' => '0', 'mail_transport' => 'disabled',
        'database' => ['host' => (string)getenv('BMS_DB_HOST'), 'name' => (string)getenv('BMS_DB_NAME'),
            'user' => (string)getenv('BMS_DB_USER'), 'password' => (string)getenv('BMS_DB_PASS'), 'charset' => 'utf8mb4', 'prefix' => $prefix],
    ];
    file_put_contents($temporary . '/_bonumark_stream/config.php', "<?php\nreturn " . var_export($config, true) . ";\n");
    touch($temporary . '/_bonumark_stream/installed.lock');
    require $temporary . '/_bonumark_stream/app/database.php';
    $pdo = bms_db();
    bms_database_require_supported($pdo);
    bms_install_schema($pdo, $prefix);
    $insertSetting = $pdo->prepare("INSERT INTO `{$prefix}settings` (setting_key, setting_value, updated_at) VALUES (?, ?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    foreach ($config as $key => $value) {
        if (is_string($value)) {
            $insertSetting->execute([$key, $value]);
        }
    }
    $insertUser = $pdo->prepare("INSERT INTO `{$prefix}users` (username,display_name,email,email_verified_at,password_hash,role,status,created_at,updated_at) VALUES (?,?,?,UTC_TIMESTAMP(),?,?,'active',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    foreach (['owner' => 'admin', 'commenter' => 'commenter'] as $username => $role) {
        $insertUser->execute([$username, ucfirst($username), $username . '@example.test', password_hash('disposable-password', PASSWORD_DEFAULT), $role]);
    }
    $migrationCount = (int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}migrations`")->fetchColumn();
    $process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $temporary . '/sessions', '-S', $address, '-t', $temporary], [0 => ['pipe', 'r'], 1 => ['file', $temporary . '/http.log', 'a'], 2 => ['file', $temporary . '/http.log', 'a']], $pipes, $temporary);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the isolated PHP HTTP server.');
    }
    fclose($pipes[0]);
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $socket = @stream_socket_client('tcp://' . $address, $error, $errorMessage, 0.1);
        if ($socket !== false) {
            fclose($socket);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    bms_settings_test_expect($ready, 'Isolated loopback server starts');
    fwrite(STDOUT, 'Runtime: PHP ' . PHP_VERSION . ' / ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n");

    $guest = [];
    $original = bms_settings_test_values($pdo, $prefix);
    $response = bms_settings_test_request($base, '/admin/settings.php', $guest, ['site_name' => 'Unauthorized']);
    bms_settings_test_expect($response['status'] === 302 && str_ends_with($response['location'], '/admin/login.php'), 'Unauthenticated settings POST redirects to login');
    bms_settings_test_expect(bms_settings_test_values($pdo, $prefix) === $original, 'Unauthenticated POST leaves settings unchanged');

    $sessions = [];
    $loginTokens = [];
    foreach (['owner', 'commenter'] as $username) {
        $cookies = [];
        $login = bms_settings_test_request($base, '/admin/login.php', $cookies);
        $loginTokens[$username] = bms_settings_test_token($login['body']);
        $login = bms_settings_test_request($base, '/admin/login.php', $cookies, ['csrf_token' => $loginTokens[$username], 'username' => $username, 'password' => 'disposable-password']);
        bms_settings_test_expect($login['status'] === 302, ucfirst($username) . ' fixture authenticates through real login');
        $sessions[$username] = $cookies;
    }
    $setting = static function (string $key) use ($pdo, $prefix): string {
        $q=$pdo->prepare("SELECT setting_value FROM `{$prefix}settings` WHERE setting_key=?");$q->execute([$key]);return (string)$q->fetchColumn();
    };
    $snapshot = static function () use ($pdo,$prefix): array { return $pdo->query("SELECT setting_key,setting_value FROM `{$prefix}settings` ORDER BY setting_key")->fetchAll(PDO::FETCH_KEY_PAIR); };
    $owner=&$sessions['owner']; $csrf=bms_settings_test_token(bms_settings_test_request($base,'/admin/settings.php',$owner)['body']);
    $get=static function(string $route) use($base,&$owner):array{return bms_settings_test_request($base,'/admin/'.$route.'.php',$owner);};
    $post=static function(string $route,array $data) use($base,&$owner,$csrf):array{return bms_settings_test_request($base,'/admin/'.$route.'.php',$owner,['csrf_token'=>$csrf]+$data);};
    $fields=[
        'settings-writing'=>['default_editor_mode','media_upload_limit_mb','media_privacy_mode','autosave_enabled'],
        'settings-reading'=>['stream_posts_per_page','stream_index_policy','stream_show_dates','sitemap_enabled','sitemap_include_stream_posts','sitemap_include_pages','sitemap_include_profiles','pwa_enabled','pwa_share_target_enabled','remember_login_enabled','remember_login_days'],
        'site-identity'=>['site_name','site_tagline','homepage_eyebrow','site_footer_text','show_powered_by','favicon_action','site_favicon_media_id','site_favicon_file'],
        'theme'=>[], 'theme-settings'=>['active_public_theme','theme_settings[accent]','theme_settings[density]','theme_settings[content_width]','theme_settings[show_page_updated_date]','theme_settings[menu_label]'],
        'navigation'=>['nav_action','primary_navigation_enabled','public_navigation_account_links_enabled','custom_label','custom_url','custom_target'],
        'security'=>[], 'registration'=>['action','registration_mode','registration_require_email_verification','registration_require_admin_approval','registration_honeypot_enabled','label','max_uses','expires_at'],
        'mail'=>['mail_action','mail_transport','mail_from_name','mail_from_email','mail_reply_to','mail_smtp_host','mail_smtp_port','mail_smtp_encryption','mail_smtp_username','mail_smtp_password','mail_sendmail_path','test_recipient'],
        'remote-posting'=>['action','remote_posting_enabled','remote_posting_direct_publish_enabled','remote_posting_publish_confirmation_required','remote_media_upload_enabled','remote_posting_default_status','remote_posting_rate_limit_per_minute','token_name','expires_date','scopes[]'],
        'activitypub'=>['activitypub_action','actor_uri','activitypub_enabled','activitypub_paused','activitypub_delivery_suspended','activitypub_follow_policy','permanent_deactivation_confirmation'],
    ];
    foreach($fields as $route=>$names){
        $html=$get($route); bms_settings_test_expect($html['status']===200,$route.' loads for owner');
        bms_settings_test_expect(str_contains($html['body'],'Back to Settings'),$route.' keeps Settings return link');
        foreach($names as $name){bms_settings_test_expect(str_contains($html['body'],'name="'.$name.'"'),$route.' preserves '.$name);}
        $before=$snapshot();$anon=[];
        foreach(['GET','POST'] as $method){
            $form=$method==='POST'?['csrf_token'=>$csrf]:null;
            $r=bms_settings_test_request($base,'/admin/'.$route.'.php',$anon,$form);
            bms_settings_test_expect($r['status']===302 && str_contains($r['location'],'login.php'),$route.' rejects anonymous '.$method);
            $r=bms_settings_test_request($base,'/admin/'.$route.'.php',$sessions['commenter'],$method==='POST'?['csrf_token'=>$loginTokens['commenter']]:null);
            bms_settings_test_expect($r['status']===403,$route.' rejects commenter '.$method);
        }
        if($names){foreach(['','invalid-token'] as $bad){$r=bms_settings_test_request($base,'/admin/'.$route.'.php',$owner,['csrf_token'=>$bad]);bms_settings_test_expect($r['status']===403,$route.' rejects '.($bad===''?'missing':'invalid').' CSRF');}}
        bms_settings_test_expect($snapshot()===$before,$route.' unauthorized writes preserve settings');
    }
    $html=$get('settings-index')['body'];
    foreach(array_merge(['settings'],array_keys($fields)) as $route){bms_settings_test_expect(str_contains($html,'/'.$route.'.php'), 'Settings index retains '.$route);}

    $post('settings-writing',['default_editor_mode'=>'markdown','media_upload_limit_mb'=>64,'media_privacy_mode'=>'strict','autosave_enabled'=>1]);
    bms_settings_test_expect($setting('default_editor_mode')==='markdown'&&$setting('media_upload_limit_mb')==='64'&&$setting('media_privacy_mode')==='strict'&&$setting('autosave_enabled')==='1','Writing saves all four settings');
    $post('settings-writing',['default_editor_mode'=>'unknown','media_upload_limit_mb'=>999,'media_privacy_mode'=>'unknown']);
    bms_settings_test_expect($setting('default_editor_mode')==='visual'&&$setting('media_upload_limit_mb')==='128'&&$setting('media_privacy_mode')==='best_effort'&&$setting('autosave_enabled')==='0','Writing retains invalid-value fallbacks, clamp and absent checkbox');
    $post('settings-writing',['media_upload_limit_mb'=>0]);bms_settings_test_expect($setting('media_upload_limit_mb')==='1','Writing upload lower limit');
    $reading=['stream_posts_per_page'=>44,'stream_index_policy'=>'noindex','remember_login_days'=>45];
    foreach(['stream_show_dates','sitemap_enabled','sitemap_include_stream_posts','sitemap_include_pages','sitemap_include_profiles','pwa_enabled','pwa_share_target_enabled','remember_login_enabled'] as $key){$reading[$key]=1;}
    $post('settings-reading',$reading);foreach($reading as $key=>$value){bms_settings_test_expect($setting($key)===(string)$value,'Reading saves '.$key);}
    $post('settings-reading',['stream_posts_per_page'=>1000,'stream_index_policy'=>'bad','remember_login_days'=>1000]);
    foreach(array_keys($reading) as $key){if(!in_array($key,['stream_posts_per_page','stream_index_policy','remember_login_days'],true)){bms_settings_test_expect($setting($key)==='0','Reading checkbox absence clears '.$key);}}
    bms_settings_test_expect($setting('stream_posts_per_page')==='100'&&$setting('remember_login_days')==='90'&&$setting('stream_index_policy')==='smart','Reading retains upper ranges and indexing fallback');
    $post('settings-reading',['stream_posts_per_page'=>0,'remember_login_days'=>0]);bms_settings_test_expect($setting('stream_posts_per_page')==='1'&&$setting('remember_login_days')==='1','Reading lower ranges');

    $post('registration',['action'=>'save_settings','registration_mode'=>'invite','registration_require_email_verification'=>1,'registration_require_admin_approval'=>1,'registration_honeypot_enabled'=>1]);
    bms_settings_test_expect($setting('registration_default_role')==='commenter'&&$setting('registration_require_email_verification')==='1'&&$setting('registration_require_admin_approval')==='1'&&$setting('registration_honeypot_enabled')==='1','Registration keeps sole-Admin architecture and enabled protections');
    $post('registration',['action'=>'save_settings','registration_mode'=>'open']);
    bms_settings_test_expect($setting('registration_mode')==='open'&&$setting('registration_require_admin_approval')==='0'&&$setting('registration_require_email_verification')==='0'&&$setting('registration_honeypot_enabled')==='0','Registration absent checkbox contract');
    $post('registration',['action'=>'save_settings','registration_mode'=>'bad']);bms_settings_test_expect($setting('registration_mode')==='disabled','Registration invalid mode falls back to disabled');
    $post('registration',['action'=>'create_invite','label'=>'Synthetic invite','max_uses'=>2,'expires_at'=>'2099-01-01T12:00']);
    $invite=$pdo->query("SELECT * FROM `{$prefix}registration_invites` ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    bms_settings_test_expect(is_array($invite)&&$invite['label']==='Synthetic invite'&&(int)$invite['max_uses']===2,'Invite creation preserves limit and label');
    $html=$get('registration')['body'];bms_settings_test_expect(str_contains($html,"confirm('Revoke this invite code?')"),'Invite revoke confirmation remains');
    $post('registration',['action'=>'revoke_invite','invite_id'=>$invite['id']]);
    bms_settings_test_expect($pdo->query("SELECT status FROM `{$prefix}registration_invites` WHERE id=".(int)$invite['id'])->fetchColumn()==='revoked','Invite revocation works');

    $secret=bin2hex(random_bytes(24));
    $mail=['mail_action'=>'save','mail_transport'=>'disabled','mail_from_name'=>'Fixture sender','mail_from_email'=>'sender@example.test','mail_reply_to'=>'reply@example.test','mail_smtp_host'=>'smtp.example.test','mail_smtp_port'=>2525,'mail_smtp_encryption'=>'tls','mail_smtp_username'=>'fixture-user','mail_smtp_password'=>$secret,'mail_sendmail_path'=>'/nonexistent/disposable-sendmail'];
    $post('mail',$mail);$html=$get('mail')['body'];
    bms_settings_test_expect($setting('mail_smtp_password')===$secret&&!str_contains($html,$secret)&&str_contains($html,'name="mail_smtp_password" value=""'),'Saved SMTP password never appears in markup');
    bms_settings_test_expect(str_contains($html,'name="clear_smtp_password"'),'Saved password clearing remains available');
    $post('mail',array_replace($mail,['mail_smtp_password'=>'']));bms_settings_test_expect($setting('mail_smtp_password')===$secret,'Blank SMTP password keeps saved value');
    $get('mail');$before=$snapshot();$post('mail',array_replace($mail,['mail_from_email'=>'invalid','mail_from_name'=>'must-not-save']));$html=$get('mail')['body'];
    bms_settings_test_expect($snapshot()===$before&&str_contains($html,'Enter a valid From Email')&&!str_contains($html,'Mail settings saved.'),'Invalid mail address changes nothing and has no false success');
    $post('mail',array_replace($mail,['mail_smtp_port'=>65536]));bms_settings_test_expect($snapshot()===$before,'Invalid SMTP port is rejected');
    $post('mail',array_replace($mail,['mail_smtp_password'=>'','clear_smtp_password'=>1]));bms_settings_test_expect($setting('mail_smtp_password')==='','Explicit clearing removes saved SMTP password');
    $post('mail',['mail_action'=>'test','test_recipient'=>'test@example.test']);$html=$get('mail')['body'];
    bms_settings_test_expect(str_contains($html,'Test email failed.')&&!str_contains($html,'Test email sent.'),'Disabled test transport reports failure without sending');

    $api=['action'=>'save_settings','remote_posting_enabled'=>1,'remote_posting_direct_publish_enabled'=>1,'remote_posting_publish_confirmation_required'=>1,'remote_media_upload_enabled'=>1,'remote_posting_default_status'=>'published','remote_posting_rate_limit_per_minute'=>123];
    $post('remote-posting',$api);foreach($api as $key=>$value){if($key!=='action')bms_settings_test_expect($setting($key)===(string)$value,'Remote API saves '.$key);}
    $post('remote-posting',['action'=>'save_settings','remote_posting_default_status'=>'published','remote_posting_rate_limit_per_minute'=>1]);
    bms_settings_test_expect($setting('remote_posting_default_status')==='draft'&&$setting('remote_posting_rate_limit_per_minute')==='5'&&$setting('remote_posting_enabled')==='0'&&$setting('remote_media_upload_enabled')==='0','Remote API preserves absent controls, publishing restriction and rate floor');
    $post('remote-posting',['action'=>'save_settings','remote_posting_rate_limit_per_minute'=>1000]);bms_settings_test_expect($setting('remote_posting_rate_limit_per_minute')==='600','Remote rate ceiling');
    $r=$post('remote-posting',['action'=>'create_token','token_name'=>'Disposable client','scopes'=>['status:read','stream:read','stream:draft','stream:publish','media:upload'],'expires_date'=>'2099-01-01']);
    preg_match('/<textarea[^>]*id="new_api_token"[^>]*>([^<]+)<\/textarea>/',$r['body'],$match);$plain=html_entity_decode($match[1]??'',ENT_QUOTES,'UTF-8');
    $row=$pdo->query("SELECT * FROM `{$prefix}api_tokens` ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    bms_settings_test_expect($r['status']===200&&strlen($plain)>30&&is_array($row)&&$row['token_hash']!==$plain&&!str_contains(json_encode($row),$plain),'Token is visible once and stored only as a hash');
    bms_settings_test_expect(count(json_decode($row['scopes_json'],true))===5&&str_starts_with($row['expires_at'],'2099-01-01'),'All five scopes and expiry are preserved');
    $html=$get('remote-posting')['body'];bms_settings_test_expect(!str_contains($html,$plain)&&str_contains($html,'Connected clients using it will stop working.'),'Reload hides full token and keeps revoke consequence');
    $post('remote-posting',['action'=>'revoke_token','token_id'=>$row['id']]);bms_settings_test_expect($pdo->query("SELECT status FROM `{$prefix}api_tokens` WHERE id=".(int)$row['id'])->fetchColumn()==='revoked','API token revoke persists');
    $count=(int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}api_tokens`")->fetchColumn();
    $r=$post('remote-posting',['action'=>'create_token','token_name'=>'','scopes'=>[]]);bms_settings_test_expect((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}api_tokens`")->fetchColumn()===$count&&str_contains($r['body'],'Remote posting action failed.'),'Invalid token creation cannot report success or add a token');

    $post('site-identity',['site_name'=>'New identity','site_tagline'=>'<a href="https://example.test">Safe</a><script>unsafe()</script>','homepage_eyebrow'=>'Hello','site_footer_text'=>'Footer','show_powered_by'=>1,'favicon_action'=>'keep']);
    bms_settings_test_expect($setting('site_name')==='New identity'&&$setting('homepage_eyebrow')==='Hello'&&$setting('site_footer_text')==='Footer'&&$setting('show_powered_by')==='1'&&!str_contains($setting('site_tagline'),'<script'),'Site identity persists text, credit and sanitization');
    $post('site-identity',['site_name'=>'New identity','favicon_action'=>'remove']);bms_settings_test_expect($setting('site_favicon_media_id')==='0'&&$setting('site_favicon_path')===''&&$setting('show_powered_by')==='0','Favicon removal and absent credit checkbox work');
    $get('site-identity');$r=$post('site-identity',['site_name'=>'New identity','favicon_action'=>'select','site_favicon_media_id'=>0]);bms_settings_test_expect(str_contains($r['body'],'Could not save site identity.')&&!str_contains($r['body'],'Site identity saved.'),'Invalid favicon selection reports error, no false success');
    $post('theme-settings',['active_public_theme'=>'default','theme_settings'=>['accent'=>'forest','density'=>'compact','content_width'=>'wide','show_page_updated_date'=>1,'menu_label'=>'Explore']]);
    $html=$get('theme-settings')['body'];bms_settings_test_expect(str_contains($html,'value="forest" selected')&&str_contains($html,'value="compact" selected')&&str_contains($html,'value="Explore"'),'Theme settings save their public presentation values');
    $post('theme',['theme_action'=>'activate','slug'=>'default']);bms_settings_test_expect($setting('active_public_theme')==='default','Bundled public theme activation remains available');

    $nav=['nav_action'=>'save','primary_navigation_enabled'=>1,'public_navigation_account_links_enabled'=>1,'nav_label'=>['First','Second'],'nav_url'=>['/first/','/second/'],'nav_target'=>['_self','_blank'],'nav_source'=>['custom','custom'],'nav_object_type'=>['',''],'nav_object_slug'=>['','']];
    $post('navigation',$nav);$items=json_decode($setting('primary_navigation'),true);bms_settings_test_expect(count($items)===2&&$items[0]['label']==='First'&&$items[1]['target']==='_blank','Navigation saves labels, URLs, order and target');
    $post('navigation',array_replace($nav,['nav_action'=>'move-up:1']));$items=json_decode($setting('primary_navigation'),true);bms_settings_test_expect($items[0]['label']==='Second','Navigation move up saves immediately');
    $post('navigation',array_replace($nav,['nav_action'=>'move-down:0']));$items=json_decode($setting('primary_navigation'),true);bms_settings_test_expect($items[1]['label']==='First','Navigation move down saves immediately');
    $post('navigation',array_replace($nav,['nav_action'=>'remove:0']));$items=json_decode($setting('primary_navigation'),true);bms_settings_test_expect(count($items)===1&&$items[0]['label']==='Second','Navigation removal retains remaining item');
    $post('navigation',['nav_action'=>'add_custom','custom_label'=>'Third','custom_url'=>'https://example.test/third','custom_target'=>'_blank']);$items=json_decode($setting('primary_navigation'),true);bms_settings_test_expect(count($items)===2&&$items[1]['label']==='Third','Custom navigation destination can be added');
    $get('navigation');$before=$setting('primary_navigation');$r=$post('navigation',['nav_action'=>'add_custom','custom_label'=>'','custom_url'=>'']);$html=$r['body'];bms_settings_test_expect($setting('primary_navigation')===$before&&str_contains($html,'Could not update navigation.'),'Invalid custom destination does not save or report success');

    $post('activitypub',['activitypub_action'=>'save','activitypub_follow_policy'=>'automatic']);
    bms_settings_test_expect($setting('activitypub_enabled')==='0'&&$setting('activitypub_paused')==='0'&&$setting('activitypub_delivery_suspended')==='0'&&$setting('activitypub_follow_policy')==='automatic','Federation Save preserves absent checkboxes and approval policy');
    $get('activitypub');$post('activitypub',['activitypub_action'=>'save','activitypub_enabled'=>1]);$html=$get('activitypub')['body'];bms_settings_test_expect($setting('activitypub_enabled')==='0'&&str_contains($html,'could not be completed')&&!str_contains($html,'ActivityPub settings saved.'),'Enable fails honestly when isolated HTTP identity is not ready');
    $post('activitypub',['activitypub_action'=>'permanent_deactivation','permanent_deactivation_confirmation'=>'WRONG']);
    bms_settings_test_expect((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}activitypub_local_actor_lifecycle`")->fetchColumn()===0,'Wrong permanent confirmation cannot retire actor');
    $get('activitypub');
    foreach(['provision_key','reconcile_queue'] as $action){$post('activitypub',['activitypub_action'=>$action]);$html=$get('activitypub')['body'];bms_settings_test_expect(!str_contains($html,'could not be completed'),'Disposable federation action succeeds: '.$action);}
    // Baseline defect, separately documented in docs/WORK-33-KNOWN-ISSUES.md:
    // cleanup_remote_cache references r.id, but reply_targets has no id column.
    // Preserve its error presentation; this interface assignment does not repair SQL.
    $post('activitypub',['activitypub_action'=>'cleanup_remote_cache']);$html=$get('activitypub')['body'];
    bms_settings_test_expect(str_contains($html,'could not be completed')&&!str_contains($html,'Remote cache cleanup cleared'),'Known baseline cache cleanup SQL failure remains visible without false success');
    bms_settings_test_expect((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}activitypub_keys` WHERE status='active'")->fetchColumn()===1,'Key provisioning creates one active key');
    $html=$get('activitypub')['body'];bms_settings_test_expect(!str_contains($html,'PRIVATE KEY')&&!str_contains($html,'private_key_encrypted'),'Signing material is never rendered');
    bms_settings_test_expect((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}activitypub_deliveries`")->fetchColumn()===0,'Local configuration tests create no federation delivery');
    bms_settings_test_expect((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}migrations`")->fetchColumn()===$migrationCount,'Detail screens introduce no migration');
    fwrite(STDOUT,'Settings detail runtime regression passed: '.$checks." checks.\n");
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    if ($pdo instanceof PDO) {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            if (str_starts_with((string)$table, $prefix)) {
                $pdo->exec('DROP TABLE `' . str_replace('`', '``', (string)$table) . '`');
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
    bms_settings_test_remove_tree($temporary);
}
