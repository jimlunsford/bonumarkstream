<?php
/**
 * System interface and HTTP preservation checks using synthetic data only.
 * Requires explicit BMS_DB_DANGER_RESET=1 and BMS_DB_HOST/NAME/USER/PASS.
 * Creates only random bms_system_ci_* tables and a private loopback installation.
 * Diagnostic/capability doubles exist ONLY in the temporary copy. An unconditional
 * barrier stops the real upgrader immediately before backup/copy. No source
 * replacement or upgrade migrations can run. Archives and secrets are never logged.
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
$temporary = sys_get_temp_dir() . '/bms-system-test-' . bin2hex(random_bytes(6));
$prefix = 'bms_system_ci_' . bin2hex(random_bytes(5)) . '_';
$process = null;
$pdo = null;
$checks = 0;

function bms_system_test_expect(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
    $GLOBALS['checks']++;
    fwrite(STDOUT, 'PASS ' . $label . "\n");
}

function bms_system_test_request(string $base, string $path, array &$cookies, ?array $form = null): array
{
    $headers = ['User-Agent: Bonumark System Regression', 'Connection: close'];
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
    return ['status' => (int)($status[1] ?? 0), 'body' => $body, 'location' => $location, 'headers' => $http_response_header];
}

function bms_system_test_token(string $html): string
{
    if (!preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $match)) {
        throw new RuntimeException('Missing CSRF token in rendered form.');
    }
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
}

function bms_system_test_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($path);
}


function bms_system_test_upload(string $base, string $path, array &$cookies, array $form, string $field, string $filename, string $bytes): array
{
    $boundary = 'bms-system-' . bin2hex(random_bytes(12));
    $body = '';
    foreach ($form as $key => $value) {
        $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$key\"\r\n\r\n$value\r\n";
    }
    $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$field\"; filename=\"$filename\"\r\nContent-Type: application/octet-stream\r\n\r\n$bytes\r\n--$boundary--\r\n";
    $headers = ['Content-Type: multipart/form-data; boundary=' . $boundary, 'Connection: close'];
    $headers[] = 'Cookie: ' . implode('; ', array_map(static fn($k, $v) => "$k=$v", array_keys($cookies), $cookies));
    $result = file_get_contents($base . $path, false, stream_context_create(['http' => ['method' => 'POST', 'content' => $body, 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 20]]));
    preg_match('/\s(\d{3})\s/', $http_response_header[0], $match);
    $location = '';
    foreach ($http_response_header as $header) { if (stripos($header, 'Location:') === 0) { $location = trim(substr($header, 9)); } }
    return ['status' => (int)$match[1], 'body' => (string)$result, 'location' => $location, 'headers' => $http_response_header];
}
function bms_system_test_zip(array $entries): string
{
    $path = $GLOBALS['temporary'] . '/test.zip';
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($entries as $name => $bytes) { $zip->addFromString($name, $bytes); }
    $zip->close();
    $bytes = (string)file_get_contents($path); unlink($path); return $bytes;
}
function bms_system_test_state(array $state): void
{
    file_put_contents($GLOBALS['temporary'] . '/_bonumark_stream/data/system-test.json', json_encode($state));
}
function bms_system_test_session(array $cookies, string $code): void
{
    $key = array_key_first($cookies);
    $script = 'ini_set("session.use_cookies", "0"); session_cache_limiter(""); session_name($argv[1]); session_id($argv[2]); session_start(); ' . $code . ' session_write_close();';
    $process = proc_open([PHP_BINARY, '-d', 'opcache.enable=0', '-d', 'session.save_path=' . $GLOBALS['temporary'] . '/sessions', '-r', $script, $key, $cookies[$key]], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0 || $out !== '') { throw new RuntimeException('Fixture session setup failed.'); }
}
function bms_system_test_cbor(mixed $value): string
{
    $length = static fn(int $major, int $n): string => $n < 24 ? chr(($major << 5) | $n) : chr(($major << 5) | 24) . chr($n);
    if (is_int($value)) { return $length(0, $value); }
    if (is_string($value)) { return $length(3, strlen($value)) . $value; }
    $out = $length(5, count($value));
    foreach ($value as $k => $v) { $out .= bms_system_test_cbor((string)$k) . bms_system_test_cbor($v); }
    return $out;
}
function bms_system_test_varint(int $n): string
{
    $s=''; do { $s .= chr(($n & 127) | ($n > 127 ? 128 : 0)); $n >>= 7; } while ($n); return $s;
}
function bms_system_test_car(): string
{
    $header = bms_system_test_cbor(['version' => 1]); $out = bms_system_test_varint(strlen($header)) . $header;
    foreach (['Synthetic Bluesky one', 'Synthetic Bluesky two'] as $text) {
        $data = bms_system_test_cbor(['$type' => 'app.bsky.feed.post', 'text' => $text, 'createdAt' => '2026-01-02T10:00:00Z']);
        $block = "\x01\x71\x12\x20" . hash('sha256', $data, true) . $data;
        $out .= bms_system_test_varint(strlen($block)) . $block;
    }
    return $out;
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
                '_bonumark_stream/content',
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
        'site_name' => 'System Fixture', 'site_tagline' => 'Saved tagline',
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

    if (!is_dir($temporary . '/_bonumark_stream/data')) { mkdir($temporary . '/_bonumark_stream/data', 0700); }
    $functionFile = $temporary . '/_bonumark_stream/app/functions.php';
    $functions = (string)file_get_contents($functionFile);
    $functions = str_replace('function bms_security_status(): array', 'function bms_system_test_real_security_status(): array', $functions);
    $functions = str_replace('function bms_automatic_upgrade_capability(?array $relativePaths = null): array', 'function bms_system_test_real_upgrade_capability(?array $relativePaths = null): array', $functions);
    $functions .= <<<'FIXTURE'

function bms_security_status(): array {
    $state = json_decode((string)file_get_contents(__DIR__ . '/../data/system-test.json'), true) ?: [];
    if (array_key_exists('checks', $state)) {
        if ($state['checks'] === null) { throw new RuntimeException('Synthetic diagnostic outage'); }
        return $state['checks'];
    }
    return bms_system_test_real_security_status();
}
function bms_automatic_upgrade_capability(?array $relativePaths = null): array {
    $state = json_decode((string)file_get_contents(__DIR__ . '/../data/system-test.json'), true) ?: [];
    return $state['capability'] ?? bms_system_test_real_upgrade_capability($relativePaths);
}
FIXTURE;
    file_put_contents($functionFile, $functions);
    $upgraderFile = $temporary . '/_bonumark_stream/app/upgrader.php';
    $upgrader = (string)file_get_contents($upgraderFile);
    // Existing validation, recovery, write-access and backup-directory guards run.
    // Stop unconditionally immediately before backup/copy, so no software can be replaced.
    $barrier = "file_put_contents(__DIR__ . '/../data/install-barrier.txt', 'reached'); throw new RuntimeException('Synthetic install barrier: no replacement performed.');";
    $upgrader = str_replace('    bms_upgrade_backup_existing($softwareItems, $backupRoot, $publicRoot);', '    ' . $barrier, $upgrader, $barrierCount);
    if ($barrierCount !== 1) { throw new RuntimeException('Cannot install upgrade safety barrier.'); }
    file_put_contents($upgraderFile, $upgrader);
    bms_system_test_state(['checks' => [['status' => 'pass', 'label' => 'Synthetic hosting', 'message' => 'Synthetic diagnostic result.']]]);
    $process = proc_open([PHP_BINARY, '-d', 'opcache.enable=0', '-d', 'session.save_path=' . $temporary . '/sessions', '-S', $address, '-t', $temporary], [0 => ['pipe', 'r'], 1 => ['file', $temporary . '/http.log', 'a'], 2 => ['file', $temporary . '/http.log', 'a']], $pipes, $temporary);
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
    bms_system_test_expect($ready, 'Isolated loopback server starts');
    fwrite(STDOUT, 'Runtime: PHP ' . PHP_VERSION . ' / ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n");

    $guest = [];
    $sessions = [];
    foreach (['owner', 'commenter'] as $username) {
        $cookies = [];
        $response = bms_system_test_request($base, '/admin/login.php', $cookies);
        $response = bms_system_test_request($base, '/admin/login.php', $cookies, ['csrf_token' => bms_system_test_token($response['body']), 'username' => $username, 'password' => 'disposable-password']);
        bms_system_test_expect($response['status'] === 302, $username . ' authenticates through real login');
        $sessions[$username] = $cookies;
    }
    $owner =& $sessions['owner'];
    $get = static function(string $route) use ($base, &$owner): array { return bms_system_test_request($base, '/admin/' . $route . '.php', $owner); };
    $token = bms_system_test_token($get('tools')['body']);
    $post = static function(string $route, array $form) use ($base, &$owner, $token): array { return bms_system_test_request($base, '/admin/' . $route . '.php', $owner, ['csrf_token' => $token] + $form); };
    $stage = static function(string $name, string $bytes) use ($base, &$owner, $token): array { return bms_system_test_upload($base, '/admin/import.php', $owner, ['csrf_token' => $token, 'import_action' => 'preview'], 'import_file', $name, $bytes); };
    $countPosts = static fn(): int => (int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}posts`")->fetchColumn();
    $setting = static function(string $key) use ($pdo, $prefix): string { $q=$pdo->prepare("SELECT setting_value FROM `{$prefix}settings` WHERE setting_key=?");$q->execute([$key]);return (string)$q->fetchColumn(); };
    $set = static function(string $key, string $value) use ($insertSetting): void { $insertSetting->execute([$key,$value]); };
    $confirm = static function(string $html, array $options = []) use ($post): array {
        preg_match('/name="preview_token" value="([^"]+)"/', $html, $match);
        if (empty($match[1])) { throw new RuntimeException('No staged import token.'); }
        return $post('import', ['import_action'=>'import','preview_token'=>$match[1]] + $options + ['target_status'=>'draft','duplicate_policy'=>'skip','media_policy'=>'remote','preserve_dates'=>'1','range_start'=>'1','batch_size'=>'0']);
    };
    $actions = [
        'tools'=>['tool_action'=>'run_due_scheduled_posts'], 'scheduled-tasks'=>['scheduled_tasks_action'=>'save_settings'],
        'export'=>['export_kind'=>'database'], 'import'=>['import_action'=>'import','preview_token'=>'invalid'],
        'import-markdown'=>['force_import'=>'1'], 'upgrade'=>['confirm_upgrade'=>'1'],
    ];
    $baselinePosts=$countPosts();$baselineVersion=hash_file('sha256',$temporary.'/VERSION');
    foreach ($actions as $route=>$form) {
        $response=bms_system_test_request($base,'/admin/'.$route.'.php',$guest,$form);
        bms_system_test_expect($response['status']===302 && str_ends_with($response['location'],'/login.php'),$route.': guest mutation refused');
        $response=bms_system_test_request($base,'/admin/'.$route.'.php',$sessions['commenter'],['csrf_token'=>$token]+$form);
        bms_system_test_expect($response['status']===403,$route.': commenter mutation refused');
        foreach (['', 'invalid'] as $badToken) {
            $response=bms_system_test_request($base,'/admin/'.$route.'.php',$owner,['csrf_token'=>$badToken]+$form);
            bms_system_test_expect($response['status']===403,$route.': '.($badToken===''?'missing':'invalid').' CSRF refused');
        }
    }
    bms_system_test_expect($countPosts()===$baselinePosts && hash_file('sha256',$temporary.'/VERSION')===$baselineVersion,'Rejected actions preserve content and software');
    foreach (['tools','system-check','scheduled-tasks','export','import','import-markdown','upgrade','help'] as $route) {
        $response=$get($route);
        bms_system_test_expect($response['status']===200 && substr_count($response['body'],'<h1>')===1,$route.': one semantic page heading');
        bms_system_test_expect(str_contains($response['body'],'system-workspace') && str_contains($response['body'],'aria-label="System navigation"'),$route.': scoped System presentation and contextual navigation');
    }
    $overview=$get('tools')['body'];
    foreach (['system-check','scheduled-tasks','export','import','import-markdown','upgrade','help','analytics','security','remote-posting'] as $route) {
        bms_system_test_expect(str_contains($overview,'/admin/'.$route.'.php'),'Overview retains '.$route.' destination');
    }
    // Render diagnostic states through the real page and a source-only diagnostic double.
    foreach ([
        'passing'=>[[['status'=>'pass','label'=>'Passing fixture','message'=>'Read-only result.']], 'Healthy', [1,0,0]],
        'warning'=>[[['status'=>'warning','label'=>'Optional capability','message'=>'Intentional host choice.']], 'Review warnings', [0,1,0]],
        'failing'=>[[['status'=>'error','label'=>'Failed fixture','message'=>'Action required.']], 'Needs attention', [0,0,1]],
        'mixed'=>[[['status'=>'pass'],['status'=>'warning'],['status'=>'fail']], 'Needs attention', [1,1,1]],
        'empty'=>[[], 'No check results', [0,0,0]], 'unavailable'=>[null, 'Checks unavailable', [0,0,0]],
    ] as $name=>[$checksData,$label,$countsExpected]) {
        bms_system_test_state(['checks'=>$checksData]);$html=$get('system-check')['body'];
        bms_system_test_expect(str_contains($html,$label),'Diagnostics '.$name.': truthful state');
        foreach (['passing','warning','failing'] as $i=>$suffix) { bms_system_test_expect(str_contains($html,$countsExpected[$i].' '.$suffix),'Diagnostics '.$name.': '.$suffix.' count'); }
        if (empty($checksData)) { bms_system_test_expect(!str_contains($html,'>Healthy<'),'Diagnostics '.$name.': no false Healthy'); }
        bms_system_test_expect(str_contains($get('tools')['body'],$label),'Overview reflects '.$name.' diagnostic source');
    }
    bms_system_test_state(['checks'=>[]]);
    // Settings, timing sources and real publication on disposable content.
    foreach ([1,5,15,30,60,7] as $interval) {
        $post('scheduled-tasks',['scheduled_tasks_action'=>'save_settings','scheduled_tasks_expected_interval_minutes'=>(string)$interval,'scheduled_tasks_public_traffic_enabled'=>'1','scheduled_tasks_heartbeat_enabled'=>'1']);
        bms_system_test_expect($setting('scheduled_tasks_expected_interval_minutes')===(string)($interval===7?5:$interval),'Expected interval persists allowed value '.$interval);
    }
    $post('scheduled-tasks',['scheduled_tasks_action'=>'save_settings','scheduled_tasks_expected_interval_minutes'=>'5']);
    bms_system_test_expect($setting('scheduled_tasks_public_traffic_enabled')==='0' && $setting('scheduled_tasks_heartbeat_enabled')==='0','Unchecked fallbacks save as disabled');
    foreach (['healthy'=>time(),'stale'=>time()-7200,'unknown'=>0] as $state=>$last) {
        $set('scheduled_tasks_last_run_at',(string)$last);
        $html=$get('scheduled-tasks')['body'];
        bms_system_test_expect(str_contains($html,match($state){'healthy'=>'Healthy','stale'=>'Stale',default=>'Not checked yet'}),'Runner '.$state.' remains distinct');
    }
    foreach (['server_cron'=>'Server cron','public_traffic'=>'Public traffic','heartbeat'=>'Browser heartbeat','web_cron'=>'Web cron','manual'=>'Manual run'] as $sourceLabel=>$label) {
        $set('scheduled_tasks_last_run_at',(string)time());$set('scheduled_tasks_last_source',$sourceLabel);
        bms_system_test_expect(str_contains($get('scheduled-tasks')['body'],$label),'Runner source: '.$label);
    }
    bms_upsert_database_content(['slug'=>'system-due','title'=>'Disposable due post','body'=>'Synthetic scheduled body','date'=>'2026-01-01','scheduled_at'=>gmdate('Y-m-d H:i:s',time()-60)],'scheduled','system-due.md',1);
    $set('scheduled_tasks_last_run_at',(string)time());$post('scheduled-tasks',['scheduled_tasks_action'=>'run_now']);
    bms_system_test_expect($pdo->query("SELECT status FROM `{$prefix}posts` WHERE slug='system-due'")->fetchColumn()==='published','Run Tasks Now publishes disposable due record');
    bms_system_test_expect((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}scheduled_task_runs` WHERE source='manual' AND scheduled_posts_published=1")->fetchColumn()>0,'Manual result and published count recorded in history');
    // Force a safe runner failure without changing code, then restore the isolated path.
    $lock=$temporary.'/_bonumark_stream/tmp/scheduled-tasks.lock';if(is_file($lock)){unlink($lock);}mkdir($lock);
    $post('scheduled-tasks',['scheduled_tasks_action'=>'run_now']);$html=$get('scheduled-tasks')['body'];rmdir($lock);
    bms_system_test_expect(str_contains($html,'Could not create the scheduled-task lock file.'),'Manual runner failure is reported honestly');
    $keys=[];
    for($i=0;$i<2;$i++) {
        $post('scheduled-tasks',['scheduled_tasks_action'=>'generate_web_cron_key']);$html=$get('scheduled-tasks')['body'];
        preg_match('/id="web-cron-key"[^>]*value="([^"]+)"/',$html,$m);$key=$m[1]??'';
        bms_system_test_expect(strlen($key)===64 && password_verify($key,$setting('scheduled_tasks_web_cron_key_hash')),'Synthetic cron key generated and stored only as a hash');
        bms_system_test_expect(str_contains($html,'X-Bonumark-Cron-Key:') && str_contains($html,'interrupts'),'One-time reveal retains header command and rotation warning');
        bms_system_test_expect(!str_contains($get('scheduled-tasks')['body'],$key),'Cron key is not redisplayed');$keys[]=$key;
    }
    bms_system_test_expect($keys[0]!==$keys[1] && !password_verify($keys[0],$setting('scheduled_tasks_web_cron_key_hash')),'Rotation invalidates previous synthetic key');
    $post('scheduled-tasks',['scheduled_tasks_action'=>'disable_web_cron']);
    bms_system_test_expect($setting('scheduled_tasks_web_cron_enabled')==='0' && $setting('scheduled_tasks_web_cron_key_hash')==='','Disable removes cron key and enablement');unset($keys,$key,$html);
    $exportPath=$temporary.'/admin/export.php';$originalExportSource=(string)file_get_contents($exportPath);
    $html=$get('export')['body'];
    bms_system_test_expect(str_contains($html,'Static output unavailable') && preg_match_all('/<button\b[^>]*\sdisabled\b/', $html)===2,'Missing renderer honestly disables Static Site and Full controls');
    // The baseline route omits renderer.php. Exercise the existing renderer-present
    // path only in this isolated copy; do not repair that backend dependency here.
    $rendererExportSource=str_replace("require_once __DIR__ . '/_layout.php';", "require_once __DIR__ . '/_layout.php';\nrequire_once __DIR__ . '/../_bonumark_stream/app/renderer.php';",$originalExportSource);
    file_put_contents($exportPath,$rendererExportSource);
    bms_system_test_expect(!str_contains($get('export')['body'],'Static output unavailable'),'Renderer-present fixture makes all export controls available');
    file_put_contents($exportPath,$originalExportSource);
    fwrite(STDOUT, "KNOWN BASELINE LIMITATION: Static Site and Full exports omit rendered HTML because this route does not load renderer.php. These archive checks preserve existing contents, not static-site completeness.\n");
    // No archive bytes are printed or retained outside the private temporary directory.
    file_put_contents($temporary.'/media/synthetic.txt','Synthetic media');
    foreach (['markdown','static','media','database','full'] as $kind) {
        $response=$post('export',['export_kind'=>$kind]);$headers=implode("\n",$response['headers']);
        bms_system_test_expect($response['status']===200 && str_contains($headers,'application/zip') && str_contains($headers,'attachment; filename="bonumark-stream-export-'.$kind.'-'),'Export '.$kind.': ZIP download and filename contract');
        $path=$temporary.'/download.zip';file_put_contents($path,$response['body']);$zip=new ZipArchive();bms_system_test_expect($zip->open($path)===true,'Export '.$kind.': readable ZIP');
        bms_system_test_expect(str_contains((string)$zip->getFromName('EXPORT.txt'),'Type: '.$kind),'Export '.$kind.': correct declared type');
        $entry=match($kind){'markdown'=>'markdown/manifest.json','static'=>'static/assets/style.css','media'=>'media/synthetic.txt','database'=>'database/bonumark.sql','full'=>'database/bonumark.sql'};
        bms_system_test_expect($zip->locateName($entry)!==false,'Export '.$kind.': baseline content entry');
        if($kind==='full'){bms_system_test_expect($zip->locateName('markdown/manifest.json')!==false && $zip->locateName('media/synthetic.txt')!==false,'Full export retains content and media');}
        if(in_array($kind,['database','full'],true)){bms_system_test_expect(str_contains((string)$zip->getFromName($entry),'password_hash'),'Private export includes synthetic account data and must remain private');}
        $zip->close();unlink($path);unset($response);
        bms_system_test_expect(glob($temporary.'/_bonumark_stream/tmp/exports/*.zip')===[],'Export '.$kind.': temporary output removed');
    }
    // ZIP absence is simulated only in the copied export template, then restored.
    $exportPath=$temporary.'/admin/export.php';$exportSource=(string)file_get_contents($exportPath);
    file_put_contents($exportPath,str_replace("class_exists('ZipArchive')",'false',$exportSource));
    $html=$get('export')['body'];bms_system_test_expect(preg_match_all('/<button\b[^>]*\sdisabled\b/', $html)===5 && str_contains($html,'Exports unavailable'),'ZipArchive unavailable disables all five exports');
    bms_system_test_expect($post('export',['export_kind'=>'markdown'])['status']===302,'ZipArchive unavailable rejects forced POST');file_put_contents($exportPath,$originalExportSource);
    // Every supported parser stages actual bytes over HTTP, without committing content.
    $markdown="---\ntitle: Synthetic import\nslug: system-markdown\ndate: 2026-01-02\nstatus: draft\n---\nSynthetic body.\n";
    $wxr='<?xml version="1.0"?><rss xmlns:wp="http://wordpress.org/export/1.2/" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel><wp:wxr_version>1.2</wp:wxr_version><item><title>WXR fixture</title><wp:post_type>post</wp:post_type><wp:status>publish</wp:status><wp:post_name>system-wxr</wp:post_name><wp:post_date>2026-01-02 10:00:00</wp:post_date><content:encoded><![CDATA[<p>Synthetic WXR body.</p>]]></content:encoded></item></channel></rss>';
    $sources=[
        'fixture.md'=>$markdown,
        'fixture.json'=>json_encode([['title'=>'JSON fixture','slug'=>'system-json','body'=>'Synthetic JSON body','date'=>'2026-01-02','status'=>'published']]),
        'fixture.xml'=>$wxr,
        'bonumark.zip'=>bms_system_test_zip(['EXPORT.txt'=>"Bonumark Stream export\nType: markdown\nVersion: 0.8.3\n",'markdown/posts/drafts/system-bonumark.md'=>str_replace('system-markdown','system-bonumark',$markdown)]),
        'twitter.zip'=>bms_system_test_zip(['data/tweets.js'=>'window.YTD.tweets.part0 = '.json_encode([['tweet'=>['id_str'=>'123456','full_text'=>'Synthetic tweet #test','created_at'=>'Fri Jan 02 10:00:00 +0000 2026']]])]),
        'fixture.car'=>bms_system_test_car(),
    ];
    foreach($sources as $name=>$bytes){$before=$countPosts();$response=$stage($name,$bytes);
        bms_system_test_expect($response['status']===200 && str_contains($response['body'],'name="preview_token"'),'Import '.$name.': real parser creates preview');
        bms_system_test_expect($countPosts()===$before,'Import '.$name.': preview makes no content commit');
        bms_system_test_expect(count(glob($temporary.'/_bonumark_stream/import-staging/previews/*')?:[])>0,'Import '.$name.': private preview storage inspected');
        if($name==='fixture.car'){
            bms_system_test_expect(str_contains($response['body'],'name="batch_size" value="0"') && str_contains($response['body'],'name="media_policy" value="remote"'),'Bluesky preserves whole-set and media restrictions');
            $confirm($response['body'],['batch_size'=>'1','range_start'=>'2','media_policy'=>'import']);bms_system_test_expect($countPosts()===$before+2,'Bluesky ignores incompatible batch/media overrides and imports full text set');
        }else{$post('import',['import_action'=>'clear']);bms_system_test_expect(!str_contains($get('import')['body'],'name="preview_token"'),'Import '.$name.': Clear Preview removes staged UI');}
    }
    $response=$stage('bad.json','{broken');bms_system_test_expect($response['status']===302 && !str_contains($get('import')['body'],'name="preview_token"'),'Malformed source fails staging without a preview');
    $response=$stage('empty.json','[]');bms_system_test_expect($response['status']===302,'Empty source does not masquerade as a usable preview');
    $response=$stage('fixture.md',$markdown);$before=$countPosts();$post('import',['import_action'=>'import','preview_token'=>'invalid']);
    bms_system_test_expect($countPosts()===$before && str_contains($get('import')['body'],'preview expired'),'Invalid preview token rejects content commit');
    bms_system_test_session($owner,'foreach ($_SESSION as &$value) { if (is_array($value) && isset($value["preview_file"])) { $value["created_at"] = 1; } } unset($value);');
    bms_system_test_expect(!str_contains($get('import')['body'],'name="preview_token"'),'Expired preview removes confirmation controls');
    $response=$stage('fixture.md',$markdown);$confirm($response['body']);
    bms_system_test_expect($pdo->query("SELECT status FROM `{$prefix}posts` WHERE slug='system-markdown'")->fetchColumn()==='draft','Draft is the default confirmed target');
    bms_system_test_expect($pdo->query("SELECT date_published FROM `{$prefix}posts` WHERE slug='system-markdown'")->fetchColumn()==='2026-01-02','Imported date is preserved');
    $before=$countPosts();$response=$stage('fixture.md',$markdown);$confirm($response['body']);bms_system_test_expect($countPosts()===$before,'Duplicate skip leaves record count unchanged');
    $response=$stage('fixture.md',$markdown);$confirm($response['body'],['duplicate_policy'=>'rename']);bms_system_test_expect($countPosts()===$before+1,'Duplicate rename creates separate content');
    foreach(['published','original'] as $status){$bytes=json_encode([['slug'=>'target-'.$status,'body'=>'Synthetic target','status'=>'published','date'=>'2026-01-02']]);$response=$stage('target.json',$bytes);$confirm($response['body'],['target_status'=>$status]);bms_system_test_expect($pdo->query("SELECT status FROM `{$prefix}posts` WHERE slug='target-$status'")->fetchColumn()==='published',$status.' target is preserved');}
    $items=[];for($i=1;$i<=52;$i++){$items[]=['slug'=>'batch-'.$i,'body'=>'Synthetic batch '.$i,'date'=>'2026-01-02'];}
    $response=$stage('long-synthetic-'.str_repeat('filename-',15).'.json',json_encode($items));
    bms_system_test_expect(str_contains($response['body'],'Displaying 50 of 52 staged item(s)'),'Preview sample count differs explicitly from total');
    $before=$countPosts();$confirm($response['body'],['batch_size'=>'2']);$html=$get('import')['body'];
    bms_system_test_expect($countPosts()===$before+2 && str_contains($html,'2 of 52 prepared item(s) processed') && str_contains($html,'Import Next Batch'),'Partial batch advances progress and retains confirmation');
    $confirm($html,['range_start'=>'3','batch_size'=>'0']);bms_system_test_expect($countPosts()===$before+52 && !str_contains($get('import')['body'],'name="preview_token"'),'Final batch commits remainder and clears preview');
    foreach(['remote','skip','import'] as $policy){$response=$stage('media.json',json_encode([['slug'=>'policy-'.$policy,'body'=>'Synthetic ![test](http://127.0.0.1:1/private.png)']]));$confirm($response['body'],['media_policy'=>$policy]);$body=(string)$pdo->query("SELECT content_body FROM `{$prefix}posts` WHERE slug='policy-$policy'")->fetchColumn();
        bms_system_test_expect($policy==='skip'?!str_contains($body,'private.png'):str_contains($body,'private.png'),'Media policy '.$policy.' preserves existing behavior');
        if($policy==='import'){bms_system_test_expect(str_contains($get('content')['body'],'Some media could not be imported'),'Partial media failure is reported after content import');}
    }
    // Direct private-folder import has no fabricated preview.
    $folder=$temporary.'/_bonumark_stream/content/drafts';if(!is_dir($folder)){mkdir($folder,0700,true);}
    file_put_contents($folder.'/folder-fixture.md',str_replace(['system-markdown','Synthetic body.'],['folder-fixture','Folder first body.'],$markdown));$set('database_first_import_complete','0');
    $post('import-markdown',[]);bms_system_test_expect(trim((string)$pdo->query("SELECT content_body FROM `{$prefix}posts` WHERE slug='folder-fixture'")->fetchColumn())==='Folder first body.','Markdown folders normal import writes disposable record');
    file_put_contents($folder.'/folder-fixture.md',str_replace(['system-markdown','Synthetic body.'],['folder-fixture','Folder refreshed body.'],$markdown));$post('import-markdown',[]);
    bms_system_test_expect(trim((string)$pdo->query("SELECT content_body FROM `{$prefix}posts` WHERE slug='folder-fixture'")->fetchColumn())==='Folder first body.','Completed folder import skips normal repeat');$post('import-markdown',['force_import'=>'1']);
    bms_system_test_expect(trim((string)$pdo->query("SELECT content_body FROM `{$prefix}posts` WHERE slug='folder-fixture'")->fetchColumn())==='Folder refreshed body.','Force folder import refreshes existing record');
    // Genuine package prechecks, with an unconditional barrier before backup/copy.
    $packageEntries=['_bonumark_stream/VERSION'=>"99.0.0\n",'VERSION'=>"99.0.0\n",'_bonumark_stream/PACKAGE.json'=>json_encode(['name'=>'bonumark-stream','version'=>'99.0.0','release_name'=>'Synthetic validation fixture']), 'admin/index.php'=>"<?php // Synthetic, never installed.\n",'_bonumark_stream/migrations/9999_system_fixture.php'=>"<?php return [];\n"];
    $manifest=[];foreach($packageEntries as $path=>$bytes){$manifest[]=['path'=>$path,'sha256'=>hash('sha256',$bytes)];}
    $packageEntries['_bonumark_stream/RELEASE-MANIFEST.json']=json_encode(['name'=>'bonumark-stream','version'=>'99.0.0','files'=>$manifest]);
    $package=bms_system_test_zip($packageEntries);
    $uploadUpgrade=static function(string $bytes, string $name='synthetic-release.zip') use ($base,&$owner,$token):array{return bms_system_test_upload($base,'/admin/upgrade.php',$owner,['csrf_token'=>$token],'upgrade_zip',$name,$bytes);};
    bms_system_test_expect(str_contains($get('upgrade')['body'],'Not uploaded'),'Upgrade initial state has no pending package');
    $response=$uploadUpgrade('not a zip');bms_system_test_expect($response['status']===302 && str_contains($get('upgrade')['body'],'Upgrade check failed'),'Invalid package fails precheck');
    $response=$uploadUpgrade($package);bms_system_test_expect($response['status']===200 && str_contains($response['body'],'99.0.0') && str_contains($response['body'],'9999_system_fixture'),'Valid manifest package and pending migration display');
    bms_system_test_expect(str_contains($response['body'],'Backup folder') && str_contains($response['body'],'Writable') && str_contains($response['body'],'does not verify an external database backup'),'Backup readiness language reflects directory capability only');
    bms_system_test_expect(!str_contains($response['body'],'/tmp/upgrades/pending/') && !str_contains($response['body'],'"zip_path"'),'Private pending package paths are not exposed');
    $post('upgrade',['cancel_upgrade'=>'1']);bms_system_test_expect(!str_contains($get('upgrade')['body'],'name="confirm_upgrade"') && glob($temporary.'/_bonumark_stream/tmp/upgrades/pending/*.zip')===[],'Cancel Package removes staged archive without replacement');
    $post('upgrade',['confirm_upgrade'=>'1']);bms_system_test_expect(str_contains($get('upgrade')['body'],'confirmation expired') && !file_exists($temporary.'/_bonumark_stream/data/install-barrier.txt'),'Expired confirmation never reaches installer');
    bms_system_test_state(['checks'=>[],'capability'=>['available'=>false,'status'=>'unavailable','blocked'=>[['relative_path'=>'admin/synthetic-locked.php']]]]);
    $response=$uploadUpgrade($package);$html=$response['body'];
    bms_system_test_expect(preg_match('/name="confirm_upgrade"[^>]*disabled/',$html)===1 && str_contains($html,'Keep those permissions locked'),'Locked hosting disables Run Upgrade and retains manual guidance');
    $post('upgrade',['confirm_upgrade'=>'1']);bms_system_test_expect(!file_exists($temporary.'/_bonumark_stream/data/install-barrier.txt') && str_contains($get('upgrade')['body'],'cannot safely replace'),'Forced POST still hits existing server-side capability guard');
    bms_system_test_state(['checks'=>[]]);$response=$uploadUpgrade($package);
    bms_system_test_session($owner,'$_SESSION["pending_upgrade"]["backup_ready"] = false;');$html=$get('upgrade')['body'];
    bms_system_test_expect(preg_match('/name="confirm_upgrade"[^>]*disabled/',$html)===1 && str_contains($html,'blocked until the backup folder is writable'),'Backup-unavailable UI preserves disabled guard');
    $post('upgrade',['cancel_upgrade'=>'1']);
    $recovery=['status'=>'recovery_required','phase'=>'database_migration','from_version'=>'0.8.3','to_version'=>'99.0.0','backup_path'=>'/private/synthetic/fixture-backup','started_at'=>'2026-01-02T10:00:00Z'];
    file_put_contents($temporary.'/_bonumark_stream/data/upgrade-recovery.json',json_encode($recovery));
    $html=$get('upgrade')['body'];bms_system_test_expect(str_contains($html,'role="alert"') && strpos($html,'upgrade-recovery-panel')<strpos($html,'upgrade-upload-panel') && str_contains($html,'Do not use another package'),'Recovery warning is prominent before upload and preserves same-package instruction');
    bms_system_test_expect(str_contains($html,'fixture-backup') && !str_contains($html,'/private/synthetic/'),'Recovery backup reference uses basename only');
    $response=$uploadUpgrade($package);bms_system_test_expect($response['status']===200 && str_contains($response['body'],'This package matches the recorded recovery target'),'Recovery accepts matching target precheck');
    $different=$packageEntries;$different['_bonumark_stream/PACKAGE.json']=json_encode(['name'=>'bonumark-stream','version'=>'99.0.1']);$different['_bonumark_stream/VERSION']="99.0.1\n";$different['VERSION']="99.0.1\n";unset($different['_bonumark_stream/RELEASE-MANIFEST.json']);$manifest=[];foreach($different as $path=>$bytes){$manifest[]=['path'=>$path,'sha256'=>hash('sha256',$bytes)];}$different['_bonumark_stream/RELEASE-MANIFEST.json']=json_encode(['name'=>'bonumark-stream','version'=>'99.0.1','files'=>$manifest]);
    $response=$uploadUpgrade(bms_system_test_zip($different));bms_system_test_expect($response['status']===302 && !str_contains($get('upgrade')['body'],'name="confirm_upgrade"'),'Recovery rejects a different target package');
    unlink($temporary.'/_bonumark_stream/data/upgrade-recovery.json');$uploadUpgrade($package);$post('upgrade',['confirm_upgrade'=>'1']);
    bms_system_test_expect(file_exists($temporary.'/_bonumark_stream/data/install-barrier.txt'),'Explicit valid confirmation reaches controlled installation barrier');
    bms_system_test_expect(hash_file('sha256',$temporary.'/VERSION')===$baselineVersion && (int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}migrations`")->fetchColumn()===$migrationCount,'Upgrade tests replace no software and run no migrations');
    $pdo->exec("INSERT INTO `{$prefix}upgrade_history` (from_version,to_version,status,notes,ran_at) VALUES ('0.8.2','0.8.3','completed','Synthetic history',UTC_TIMESTAMP())");
    bms_system_test_expect(str_contains($get('upgrade')['body'],'COMPLETED'),'Upgrade history shows recorded result');
    $public=bms_system_test_request($base,'/',$guest);bms_system_test_expect($public['status']===200 && !str_contains($public['body'],'admin-operations.css'),'Public frontend is unaffected by System presentation');
    fwrite(STDOUT, 'System runtime regression passed: ' . $checks . " checks.\n");
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
    bms_system_test_remove_tree($temporary);
}
