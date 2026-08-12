<?php
// ════════════════════════════════════════════════════════════════════════════
// TASJIL BOT — Facebook Messenger + Telegram Admin Bot
// يعمل الآن بنظام Polling بالكامل (بدون Webhook) — انظر قسم "Polling Engine" في آخر الملف
// ════════════════════════════════════════════════════════════════════════════

$IS_CLI = (php_sapi_name() === 'cli');

if (!$IS_CLI) {
    if (!isset($input)) {
        $input = json_decode(file_get_contents("php://input"), true);
    }
    if (!isset($event)) {
        $event = $input['entry'][0]['messaging'][0] ?? [];
    }
    $sender_id = $event['sender']['id'] ?? null;
    $message   = $event['message']['text'] ?? '';
}

// ════════ Facebook Config ════════
define('FB_TOKEN',        'EAAFYLlWaXQkBSKOLbJTAIwb10m8U4r5UfeLQnbF2XetMzTBWPYOtJZAh7lML3Tk5RfkAOSGNwktPMiFwMfEabw1bdZAF63nwBUVwzdR1HoodJgnb2DWJy3JxfGlLuS3VGJ8A88zPedVZBEWTvYMQJvrZCioVrrISwiD8uMMbNZCDAGZBWTo6mtZAUF57lLFicCuqHXfdZC1qSQZDZD');
define('VERIFY_TOKEN',    'Yacin');

// ════════ Telegram Config ════════
define('TG_TOKEN',   '8723811941:AAGi5C0AwV-G45PAoou2rYOZJovI5AmhdJM');
define('TG_ADMIN_ID', '8499896271');
define('TG_API',     'https://api.telegram.org/bot' . TG_TOKEN);

// ════════ Paths ════════
define('PROXY_LIST_FILE',  '/tmp/proxies.json');
define('PROXY_API_URL',    'https://dev-bendjarayacine.pantheonsite.io/wp-admin/maint/proxy.json');
define('SESSIONS_DIR',     '/tmp/fb_sessions');
define('USERS_DIR',        '/tmp/fb_users');
define('PHONE_MAP_FILE',   '/tmp/fb_phone_map.json');
define('PENDING_DIR',      '/tmp/fb_pending');
define('DB_FILE',          '/tmp/fb_dedup.sqlite');
define('NEW_USERS_FILE',   '/tmp/fb_new_users.json');
define('RATE_LIMIT_DIR',   '/tmp/fb_rate_limit');
define('TG_STATE_DIR',     '/tmp/tg_states');
define('MATCH_GIFT_FILE',  '/tmp/match_gift_config.json');
define('BROADCAST_LOG',    '/tmp/broadcast_log.json');

define('RATE_LIMIT_SECONDS', 600);

// ════════ Polling Engine Config (بدون Webhook) ════════
define('QUEUE_DIR',            '/tmp/fb_job_queue');       // طابور المهام المشتركة بين الفاحص والعمال
define('POLL_STATE_FILE',      '/tmp/fb_poll_state.json'); // آخر نقطة توقف عند فحص فيسبوك
define('TG_OFFSET_FILE',       '/tmp/tg_update_offset.json'); // آخر update_id تم استلامه من تلغرام
define('PAGE_ID_CACHE_FILE',   '/tmp/fb_page_id.txt');
define('WORKER_LOG',           '/tmp/worker.log');
define('SUPERVISOR_LOG',       '/tmp/supervisor.log');

define('WORKER_COUNT', 8);                       // عدد العمال المتوازيين لمعالجة الرسائل (مثل ThreadPoolExecutor)
define('FB_POLL_INTERVAL_MICROSECONDS', 700000); // ~0.7 ثانية بين كل فحص لمحادثات فيسبوك
define('FB_POLL_WINDOW_SECONDS', 120);           // نافذة زمنية منزلقة عند كل فحص (تحمي من فقدان أي رسالة)
define('WORKER_IDLE_SLEEP_MICROSECONDS', 40000); // 40ms عند فراغ الطابور — استجابة شبه فورية
define('SUPERVISOR_TICK_MICROSECONDS', 500000);  // فحص صحة العمليات الفرعية كل 0.5 ثانية

@mkdir(QUEUE_DIR, 0777, true);

// خريطة تحويل عنوان زر الرد السريع (quick reply) إلى الـ payload الأصلي
// (لأن جلب الرسائل عبر Polling يُعيد نص الزر وليس الـ payload، بعكس الـ Webhook)
define('QUICK_REPLY_TITLE_MAP', [
    '📶 تفعيل 2G'          => 'MENU_2G',
    '💰 عرض 70دج - 4جيقا'   => 'MENU_70DZ',
    '📨 إرسال دعوة'         => 'MENU_INVITE',
    '📦 المزيد من العروض'   => 'MENU_MORE_OFFERS',
    '🇩🇿 هدية المباراة'     => 'ACTIVATE_ALGERIA_MATCH',
    '🔙 رجوع للقائمة'       => 'BACK_MENU',
    '5 - 5GB 90دج 🔥'      => 'ACTIVATE_OFFER_BTL500MBDAY',
    '6 - 300Mo 30دج'       => 'ACTIVATE_OFFER_DOVINTSPEEDDAY100MoPRE',
    '7 - 600Mo 50دج'       => 'ACTIVATE_OFFER_DOVINTSPEEDDAY250MoPRE',
    '8 - 2Go 100دج'        => 'ACTIVATE_OFFER_DOVINTSPEEDDAY1GoPRE',
    '9 - 1Go 50دج'         => 'ACTIVATE_OFFER_OFFREJEUNE50',
    '10 - 4GB 70دج'        => 'ACTIVATE_OFFER_BTLINTSPEEDDAY2Go',
    '11 - 5GB 190دج'       => 'ACTIVATE_OFFER_BTL4GBDAY',
    '13 - 4Go 150دج'       => 'ACTIVATE_OFFER_DOVINTSPEEDWEEK2GoPRE',
    '14 - 10Go 300دج'      => 'ACTIVATE_OFFER_DOVINTSPEEDWEEK3GoPRE',
    '17 - 12Go 500دج'      => 'ACTIVATE_OFFER_DOVINTSPEEDMONTH6GoPRE',
    '18 - 30Go 1000دج'     => 'ACTIVATE_OFFER_DOVINTSPEEDMONTH15GoPRE',
    '21 - 1GB 40دج⚡'      => 'ACTIVATE_OFFER_BTL500MBHOUR',
]);

// ════════ Client Credentials ════════
define('CLIENT_ID_OLD',     '87pIExRhxBb3_wGsA5eSEfyATloa');
define('CLIENT_SECRET_OLD', 'uf82p68Bgisp8Yg1Uz8Pf6_v1XYa');
define('CLIENT_ID_NEW',     '6E6CwTkp8H1CyQxraPmcEJPQ7xka');
define('CLIENT_SECRET_NEW', 'MVpXHW_ImuMsxKIwrJpoVVMHjRsa');

@mkdir(SESSIONS_DIR,   0777, true);
@mkdir(USERS_DIR,      0777, true);
@mkdir(PENDING_DIR,    0777, true);
@mkdir(RATE_LIMIT_DIR, 0777, true);
@mkdir(TG_STATE_DIR,   0777, true);

// ════════════════════════════════════════════════════════════════════════════
// Match Gift Config (يتحكم بها من تلقرام)
// ════════════════════════════════════════════════════════════════════════════
function getMatchGiftConfig(): array
{
    $defaults = [
        'enabled'   => false,
        'qr_code'   => 'https://www.djezzy.dz/scanwin-wd26?1',
        'gift_label'=> '12Go',
        'start_hour'=> 1,
        'end_hour'  => 5,
    ];
    if (!file_exists(MATCH_GIFT_FILE)) return $defaults;
    $d = json_decode(file_get_contents(MATCH_GIFT_FILE), true);
    return is_array($d) ? array_merge($defaults, $d) : $defaults;
}
function saveMatchGiftConfig(array $cfg): void
{
    file_put_contents(MATCH_GIFT_FILE, json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

// ════════════════════════════════════════════════════════════════════════════
// قائمة العروض
// ════════════════════════════════════════════════════════════════════════════
define('OFFERS', [
    'BTL500MBDAY'                => ['name' => '📦 5GB - 90دج - 24h',           'display' => "الإنترنت: 5GB | السعر: 90 دج | المدة: 24 ساعة"],
    'DOVINTSPEEDDAY100MoPRE'     => ['name' => '📦 300Mo - 30دج - 24h',         'display' => "الإنترنت: 300Mo | السعر: 30 دج | المدة: 24 ساعة"],
    'DOVINTSPEEDDAY250MoPRE'     => ['name' => '📦 600Mo - 50دج - 24h',         'display' => "الإنترنت: 600Mo | السعر: 50 دج | المدة: 24 ساعة"],
    'DOVINTSPEEDDAY1GoPRE'       => ['name' => '📦 2Go - 100دج - 24h',          'display' => "الإنترنت: 2Go | السعر: 100 دج | المدة: 24 ساعة"],
    'OFFREJEUNE50'               => ['name' => '📦 1Go - 50دج - 24h',           'display' => "الإنترنت: 1Go | السعر: 50 دج | المدة: 24 ساعة"],
    'BTLINTSPEEDDAY2Go'          => ['name' => '🏷️ 4GB - 70دج - 24h',          'display' => "الإنترنت: 4GB | السعر: 70 دج | المدة: 24 ساعة"],
    'BTL4GBDAY'                  => ['name' => '📦 5GB - 190دج - 24h',          'display' => "الإنترنت: 5GB | السعر: 190 دج | المدة: 24 ساعة"],
    'BTL1GBDAY'                  => ['name' => '📦 4GB - 140دج - 24h',          'display' => "الإنترنت: 4GB | السعر: 140 دج | المدة: 24 ساعة"],
    'DOVINTSPEEDWEEK2GoPRE'      => ['name' => '📦 4Go - 150دج - 7أيام',        'display' => "الإنترنت: 4Go | السعر: 150 دج | المدة: 7 أيام"],
    'DOVINTSPEEDWEEK3GoPRE'      => ['name' => '📦 10Go - 300دج - 7أيام',       'display' => "الإنترنت: 10Go | السعر: 300 دج | المدة: 7 أيام"],
    'BTLDATA2WEEKS'              => ['name' => '📦 4GB - 400دج - 15يوم',        'display' => "الإنترنت: 4GB | السعر: 400 دج | المدة: 15 يوم"],
    '1GBFB3DAYInternet'          => ['name' => '📦 1GB(FB) - 70دج - 3أيام',     'display' => "الإنترنت: 1GB (Facebook) | السعر: 70 دج | المدة: 3 أيام"],
    'DOVINTSPEEDMONTH6GoPRE'     => ['name' => '📦 12Go - 500دج - 30يوم',       'display' => "الإنترنت: 12Go | السعر: 500 دج | المدة: 30 يوم"],
    'DOVINTSPEEDMONTH15GoPRE'    => ['name' => '📦 30Go - 1000دج - 30يوم',      'display' => "الإنترنت: 30Go | السعر: 1000 دج | المدة: 30 يوم"],
    'DOVINTSPEEDMONTH30GoPRE'    => ['name' => '📦 60Go - 1500دج - 30يوم',      'display' => "الإنترنت: 60Go | السعر: 1500 دج | المدة: 30 يوم"],
    '2GBMONTH'                   => ['name' => '📦 3GB - 250دج - 30يوم',        'display' => "الإنترنت: 3GB | السعر: 250 دج | المدة: 30 يوم"],
    'BTL500MBHOUR'               => ['name' => '⚡ 1GB - 40دج - 1ساعة',         'display' => "الإنترنت: 1GB | السعر: 40 دج | المدة: 1 ساعة"],
    'ImtiyazSurpriseData2hfbPRE' => ['name' => '📘 FB غير محدود - 50دج - 4h',  'display' => "الإنترنت: Facebook غير محدود | السعر: 50 دج | المدة: 4 ساعات"],
]);

define('OFFER_SHORTCUTS', [
    '5'  => 'BTL500MBDAY',
    '6'  => 'DOVINTSPEEDDAY100MoPRE',
    '7'  => 'DOVINTSPEEDDAY250MoPRE',
    '8'  => 'DOVINTSPEEDDAY1GoPRE',
    '9'  => 'OFFREJEUNE50',
    '10' => 'BTLINTSPEEDDAY2Go',
    '11' => 'BTL4GBDAY',
    '12' => 'BTL1GBDAY',
    '13' => 'DOVINTSPEEDWEEK2GoPRE',
    '14' => 'DOVINTSPEEDWEEK3GoPRE',
    '15' => 'BTLDATA2WEEKS',
    '16' => '1GBFB3DAYInternet',
    '17' => 'DOVINTSPEEDMONTH6GoPRE',
    '18' => 'DOVINTSPEEDMONTH15GoPRE',
    '19' => 'DOVINTSPEEDMONTH30GoPRE',
    '20' => '2GBMONTH',
    '21' => 'BTL500MBHOUR',
    '22' => 'ImtiyazSurpriseData2hfbPRE',
]);

// ════════════════════════════════════════════════════════════════════════════
// SQLite — Dedup + User Lock
// ════════════════════════════════════════════════════════════════════════════
function getDB(): PDO
{
    static $db = null;
    if ($db !== null) return $db;
    $db = new PDO('sqlite:' . DB_FILE);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode=WAL; PRAGMA synchronous=NORMAL;");
    $db->exec("CREATE TABLE IF NOT EXISTS processed_events (event_id TEXT PRIMARY KEY, created_at INTEGER NOT NULL)");
    $db->exec("CREATE TABLE IF NOT EXISTS user_locks (psid TEXT PRIMARY KEY, locked_at INTEGER NOT NULL)");
    $db->exec("DELETE FROM processed_events WHERE created_at < " . (time() - 3600));
    $db->exec("DELETE FROM user_locks WHERE locked_at < " . (time() - 600));
    return $db;
}
function tryMarkEvent(string $id): bool
{
    try {
        $s = getDB()->prepare("INSERT OR IGNORE INTO processed_events (event_id, created_at) VALUES (?,?)");
        $s->execute([$id, time()]);
        return $s->rowCount() > 0;
    } catch (Throwable $e) { return true; }
}
function unmarkEvent(string $id): void
{
    try { getDB()->prepare("DELETE FROM processed_events WHERE event_id=?")->execute([$id]); } catch (Throwable $e) {}
}
function tryLockUser(string $psid): bool
{
    try {
        $s = getDB()->prepare("INSERT OR IGNORE INTO user_locks (psid, locked_at) VALUES (?,?)");
        $s->execute([$psid, time()]);
        return $s->rowCount() > 0;
    } catch (Throwable $e) { return true; }
}
function unlockUser(string $psid): void
{
    try { getDB()->prepare("DELETE FROM user_locks WHERE psid=?")->execute([$psid]); } catch (Throwable $e) {}
}
function dbg(string $m): void
{
    file_put_contents('/tmp/fb_debug.log', date('Y-m-d H:i:s') . " $m\n", FILE_APPEND);
}

// ════════════════════════════════════════════════════════════════════════════
// Rate Limit
// ════════════════════════════════════════════════════════════════════════════
function rateLimitFile(string $psid): string { return RATE_LIMIT_DIR . "/{$psid}.json"; }
function getFinalResultTimestamps(string $psid): array
{
    $f = rateLimitFile($psid);
    if (!file_exists($f)) return [];
    $d = json_decode(@file_get_contents($f), true);
    return is_array($d) ? $d : [];
}
function recordFinalResult(string $psid): void
{
    $list   = getFinalResultTimestamps($psid);
    $list[] = time();
    if (count($list) > 2) $list = array_slice($list, -2);
    @file_put_contents(rateLimitFile($psid), json_encode($list));
}
function checkRateLimit(string $psid): ?int
{
    $list = getFinalResultTimestamps($psid);
    if (count($list) < 2) return null;
    $elapsed = time() - $list[0];
    if ($elapsed < RATE_LIMIT_SECONDS) return RATE_LIMIT_SECONDS - $elapsed;
    return null;
}
function formatRemainingRateLimit(int $secondsLeft): string
{
    $minutes = (int)ceil($secondsLeft / 60);
    if ($minutes <= 1) return "أقل من دقيقة";
    return "{$minutes} دقيقة";
}
function rateLimitMessage(int $secondsLeft): string
{
    return "⏳ أنت ترسل طلبات كثيرة خلال فترة قصيرة.\n\n🔁 يرجى إعادة المحاولة بعد:\n🕐 الوقت المتبقي: " . formatRemainingRateLimit($secondsLeft) . "\n\nيتم تطبيق هذا القيد لضمان استمرارية عمل خدمة البوت بشكل جيد لجميع المستخدمين.";
}

// ════════════════════════════════════════════════════════════════════════════
// New Users Tracking
// ════════════════════════════════════════════════════════════════════════════
function isNewUser(string $psid): bool
{
    $map = file_exists(NEW_USERS_FILE) ? (json_decode(file_get_contents(NEW_USERS_FILE), true) ?? []) : [];
    return !isset($map[$psid]);
}
function markUserAsSeen(string $psid): void
{
    $map = file_exists(NEW_USERS_FILE) ? (json_decode(file_get_contents(NEW_USERS_FILE), true) ?? []) : [];
    if (!isset($map[$psid])) {
        $map[$psid] = ['first_seen' => time(), 'last_active' => time()];
        file_put_contents(NEW_USERS_FILE, json_encode($map));
    } else {
        $map[$psid]['last_active'] = time();
        file_put_contents(NEW_USERS_FILE, json_encode($map));
    }
}
function getAllKnownUsers(): array
{
    if (!file_exists(NEW_USERS_FILE)) return [];
    return array_keys(json_decode(file_get_contents(NEW_USERS_FILE), true) ?? []);
}
function getActiveUsers(int $days = 7): array
{
    if (!file_exists(NEW_USERS_FILE)) return [];
    $map   = json_decode(file_get_contents(NEW_USERS_FILE), true) ?? [];
    $since = time() - ($days * 86400);
    $active = [];
    foreach ($map as $psid => $data) {
        $lastActive = is_array($data) ? ($data['last_active'] ?? 0) : $data;
        if ($lastActive >= $since) $active[] = $psid;
    }
    return $active;
}
function getUserStats(): array
{
    if (!file_exists(NEW_USERS_FILE)) return ['total' => 0, 'active_7d' => 0, 'active_30d' => 0];
    $map   = json_decode(file_get_contents(NEW_USERS_FILE), true) ?? [];
    $total = count($map);
    $now   = time();
    $a7 = 0; $a30 = 0;
    foreach ($map as $psid => $data) {
        $lastActive = is_array($data) ? ($data['last_active'] ?? 0) : $data;
        if ($now - $lastActive <= 7  * 86400) $a7++;
        if ($now - $lastActive <= 30 * 86400) $a30++;
    }
    return ['total' => $total, 'active_7d' => $a7, 'active_30d' => $a30];
}

// ════════════════════════════════════════════════════════════════════════════
// Broadcast Log — لمنع الإرسال المكرر
// ════════════════════════════════════════════════════════════════════════════
function getBroadcastLog(): array
{
    if (!file_exists(BROADCAST_LOG)) return [];
    return json_decode(file_get_contents(BROADCAST_LOG), true) ?? [];
}
function saveBroadcastLog(array $log): void
{
    file_put_contents(BROADCAST_LOG, json_encode($log, JSON_UNESCAPED_UNICODE));
}
function markBroadcastSent(string $broadcastId, string $psid): void
{
    $log = getBroadcastLog();
    if (!isset($log[$broadcastId])) $log[$broadcastId] = [];
    $log[$broadcastId][$psid] = time();
    saveBroadcastLog($log);
}
function wasBroadcastSent(string $broadcastId, string $psid): bool
{
    $log = getBroadcastLog();
    return isset($log[$broadcastId][$psid]);
}

// ════════════════════════════════════════════════════════════════════════════
// Pending Operations
// ════════════════════════════════════════════════════════════════════════════
function setPending(string $psid, string $op): void
{
    file_put_contents(PENDING_DIR . "/{$psid}.json", json_encode(['op' => $op, 'ts' => time()]));
}
function clearPending(string $psid): void
{
    $f = PENDING_DIR . "/{$psid}.json";
    if (file_exists($f)) @unlink($f);
}
function getPending(string $psid): ?string
{
    $f = PENDING_DIR . "/{$psid}.json";
    if (!file_exists($f)) return null;
    $d = json_decode(@file_get_contents($f), true);
    if (!$d) return null;
    if (time() - ($d['ts'] ?? 0) > 600) { @unlink($f); return null; }
    return $d['op'] ?? null;
}

// ════════════════════════════════════════════════════════════════════════════
// Proxy System — المحسّن (يستخدم جميع البروكسيات
// ════════════════════════════════════════════════════════════════════════════
function loadProxies(): array
{
    if (file_exists(PROXY_LIST_FILE)) {
        $d = json_decode(file_get_contents(PROXY_LIST_FILE), true);
        if (is_array($d) && count($d) > 0) return $d;
    }
    return [
    "http://gate.kookeey.info:1000:6922252-d6a8d87b51:9a981add8a-DZ-02326339-1m",
    "http://gate.kookeey.info:1000:6922252-d6a8d87b51:9a981add8a-DZ-90188864-1m",
    "http://gate.kookeey.info:1000:6922252-d6a8d87b51:9a981add8a-DZ-90442202-1m",
    "http://gate.kookeey.info:1000:6922252-d6a8d87b51:9a981add8a-DZ-91355326-1m",
    "http://gate.kookeey.info:1000:6922252-d6a8d87b51:9a981add8a-DZ-40519545-1m",
    "http://mobile.kookeey.info:1086:6922252-d6a8d87b51:9a981add8a-DZ-80722182-1m",
    "http://mobile.kookeey.info:1086:6922252-d6a8d87b51:9a981add8a-DZ-26644934-1m",
    "http://mobile.kookeey.info:1086:6922252-d6a8d87b51:9a981add8a-DZ-03441653-1m",
    "http://mobile.kookeey.info:1086:6922252-d6a8d87b51:9a981add8a-DZ-29368732-1m",
    "http://mobile.kookeey.info:1086:6922252-d6a8d87b51:9a981add8a-DZ-60340145-1m",
    "http://gate.kookeey.info:1000:4725188-2bf0566d5d:f27eabc915-DZ-79999531-1m",
    "http://gate.kookeey.info:1000:4725188-2bf0566d5d:f27eabc915-DZ-04078101-1m",
    "http://gate.kookeey.info:1000:4725188-2bf0566d5d:f27eabc915-DZ-31559785-1m",
    "http://gate.kookeey.info:1000:4725188-2bf0566d5d:f27eabc915-DZ-95052284-1m",
    "http://gate.kookeey.info:1000:4725188-2bf0566d5d:f27eabc915-DZ-28276664-1m",
    "http://mobile.kookeey.info:1086:4725188-2bf0566d5d:f27eabc915-DZ-32298696-1m",
    "http://mobile.kookeey.info:1086:4725188-2bf0566d5d:f27eabc915-DZ-86945061-1m",
    "http://mobile.kookeey.info:1086:4725188-2bf0566d5d:f27eabc915-DZ-47524777-1m",
    "http://mobile.kookeey.info:1086:4725188-2bf0566d5d:f27eabc915-DZ-83185339-1m",
    "http://mobile.kookeey.info:1086:4725188-2bf0566d5d:f27eabc915-DZ-63490539-1m",
    "http://gate.kookeey.info:1000:5710151-2919593b9b:0df70e35c3-DZ-53312865-1m",
    "http://gate.kookeey.info:1000:5710151-2919593b9b:0df70e35c3-DZ-62082167-1m",
    "http://gate.kookeey.info:1000:5710151-2919593b9b:0df70e35c3-DZ-68899241-1m",
    "http://gate.kookeey.info:1000:5710151-2919593b9b:0df70e35c3-DZ-92286516-1m",
    "http://gate.kookeey.info:1000:5710151-2919593b9b:0df70e35c3-DZ-10859378-1m",
    "http://mobile.kookeey.info:1086:5710151-2919593b9b:0df70e35c3-DZ-83417861-1m",
    "http://mobile.kookeey.info:1086:5710151-2919593b9b:0df70e35c3-DZ-06028904-1m",
    "http://mobile.kookeey.info:1086:5710151-2919593b9b:0df70e35c3-DZ-85395572-1m",
    "http://mobile.kookeey.info:1086:5710151-2919593b9b:0df70e35c3-DZ-27852885-1m",
    "http://mobile.kookeey.info:1086:5710151-2919593b9b:0df70e35c3-DZ-61847447-1m"
];
}
function saveProxies(array $proxies): void
{
    file_put_contents(PROXY_LIST_FILE, json_encode($proxies));
}
function refreshProxies(): array
{
    $ch = curl_init(PROXY_API_URL);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_SSL_VERIFYPEER => false]);
    $body = curl_exec($ch);
    curl_close($ch);
    $list = json_decode($body, true);
    if (is_array($list) && count($list) > 0) {
        saveProxies($list);
        return $list;
    }
    return loadProxies();
}
function parseProxy(string $proxy): array
{
    $raw = preg_replace('#^https?://#', '', $proxy);
    $p   = explode(':', $raw, 4);
    return ['host' => ($p[0] ?? '') . ':' . ($p[1] ?? ''), 'userpass' => ($p[2] ?? '') . ':' . ($p[3] ?? '')];
}

/**
 * getAllProxies — تجميع جميع البروكسيات المتاحة (محلية + API) مع تخزين مؤقت (Cache)
 * لتفادي إرسال طلب HTTP لجلب قائمة البروكسيات من الخادم الخارجي مع كل طلب Djezzy —
 * هذا كان يُبطئ كل استدعاء بشكل كبير جداً تحت الضغط (كل استدعاء = طلب شبكة إضافي).
 */
function getAllProxies(): array
{
    static $memCache = null;
    static $memCacheTs = 0;
    $now = time();
    if ($memCache !== null && ($now - $memCacheTs) < PROXY_CACHE_SECONDS) {
        return $memCache; // نفس العملية (Worker) تعيد استخدام النتيجة دون إعادة الجلب
    }

    $cacheFile = PROXY_LIST_FILE . '.combined_cache.json';
    if (file_exists($cacheFile) && ($now - (int)@filemtime($cacheFile)) < PROXY_CACHE_SECONDS) {
        $d = json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($d) && count($d) > 0) {
            $memCache = $d; $memCacheTs = $now;
            return $d;
        }
    }

    $local    = loadProxies();
    $fromApi  = refreshProxies();
    $combined = array_values(array_unique(array_merge($local, $fromApi)));

    @file_put_contents($cacheFile, json_encode($combined));
    $memCache = $combined; $memCacheTs = $now;
    return $combined;
}

/**
 * curlWithAllProxies — يجرّب مجموعات من البروكسيات بالتوازي (curl_multi) بدل واحد تلو الآخر،
 * فيأخذ أول استجابة ناجحة من كل دفعة بدل الانتظار التسلسلي الطويل — هذا يقلّص أسوأ زمن انتظار
 * من عدة دقائق إلى ثوانٍ معدودة تحت الضغط.
 */
function curlWithAllProxies(
    string $url,
    string $method,
    string $payload,
    array  $headers,
    string $logTag,
    int    $timeout = 8,
    string $logFile = '/tmp/proxy_curl.log'
): ?array {
    $proxies = getAllProxies();
    shuffle($proxies); // توزيع الحمل على كل البروكسيات بدل تكديس الجميع على نفس "الأول" في القائمة
    $totalProxies = count($proxies);

    if ($totalProxies === 0) {
        dbg("[{$logTag}] No proxies available!");
        tgNotifyAdmin("⚠️ لا توجد بروكسيات متاحة! [{$logTag}]");
        return null;
    }

    $batchSize = PROXY_PARALLEL_BATCH;
    $batches   = array_chunk($proxies, $batchSize);

    foreach ($batches as $batchIdx => $batch) {
        $mh      = curl_multi_init();
        $handles = [];

        foreach ($batch as $p) {
            $pp = parseProxy($p);
            $ch = curl_init($url);
            $opts = [
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING       => 'gzip',
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_PROXY          => $pp['host'],
                CURLOPT_PROXYUSERPWD   => $pp['userpass'],
                CURLOPT_PROXYTYPE      => CURLPROXY_HTTP,
                CURLOPT_FOLLOWLOCATION => true,
            ];
            if ($method === 'POST') {
                $opts[CURLOPT_POST]       = true;
                $opts[CURLOPT_POSTFIELDS] = $payload;
            } else {
                $opts[CURLOPT_HTTPGET] = true;
            }
            curl_setopt_array($ch, $opts);
            curl_multi_add_handle($mh, $ch);
            $handles[] = $ch;
        }

        // نفّذ كل طلبات الدفعة بالتوازي
        $running = null;
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 0.2);
        } while ($running > 0);

        $result = null;
        foreach ($handles as $idx => $ch) {
            $body     = curl_multi_getcontent($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno    = curl_errno($ch);
            $errMsg   = curl_error($ch);
            $bodyStr  = (string)$body;

            file_put_contents($logFile,
                date('Y-m-d H:i:s') . " [{$logTag}] batch[{$batchIdx}] proxy[{$idx}] http={$httpCode} errno={$errno} err={$errMsg} body=" . substr($bodyStr, 0, 200) . "\n",
                FILE_APPEND
            );

            $isBad = $errno || !$body || $httpCode === 0 || stripos($bodyStr, '<html') !== false || stripos($bodyStr, '<!DOCTYPE') !== false;
            if (!$isBad && $result === null) {
                $result = ['http_code' => $httpCode, 'body' => $bodyStr, 'json' => @json_decode($bodyStr, true)];
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);

        if ($result !== null) return $result; // أول نجاح في الدفعة -> أعِد فوراً دون انتظار البقية
    }

    // كل البروكسيات فشلت
    dbg("[{$logTag}] ALL {$totalProxies} proxies failed!");
    tgNotifyAdmin("🚨 تنبيه: جميع البروكسيات ({$totalProxies}) فشلت في [{$logTag}]!\n\n🔄 يرجى إرسال قائمة بروكسيات جديدة باستخدام:\n/setproxies");
    return null;
}

// ════════════════════════════════════════════════════════════════════════════
// Telegram Notification
// ════════════════════════════════════════════════════════════════════════════
function tgNotifyAdmin(string $text): void
{
    tgSendMessage(TG_ADMIN_ID, $text);
}
function tgSendMessage(string $chatId, string $text, array $keyboard = []): void
{
    $data = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'];
    if (!empty($keyboard)) {
        $data['reply_markup'] = json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE);
    }
    $ch = curl_init(TG_API . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    file_put_contents('/tmp/tg_send.log', date('Y-m-d H:i:s') . " chat={$chatId} resp={$resp}\n", FILE_APPEND);
}
function tgAnswerCallback(string $callbackId, string $text = ''): void
{
    $ch = curl_init(TG_API . '/answerCallbackQuery');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['callback_query_id' => $callbackId, 'text' => $text]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    curl_exec($ch);
    curl_close($ch);
}
function tgEditMessage(string $chatId, int $messageId, string $text, array $keyboard = []): void
{
    $data = ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text, 'parse_mode' => 'HTML'];
    if (!empty($keyboard)) {
        $data['reply_markup'] = json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE);
    }
    $ch = curl_init(TG_API . '/editMessageText');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// ════════════════════════════════════════════════════════════════════════════
// Telegram State (للأوامر متعددة الخطوات)
// ════════════════════════════════════════════════════════════════════════════
function getTgState(string $chatId): array
{
    $f = TG_STATE_DIR . "/{$chatId}.json";
    if (!file_exists($f)) return [];
    return json_decode(file_get_contents($f), true) ?? [];
}
function setTgState(string $chatId, array $state): void
{
    file_put_contents(TG_STATE_DIR . "/{$chatId}.json", json_encode($state));
}
function clearTgState(string $chatId): void
{
    $f = TG_STATE_DIR . "/{$chatId}.json";
    if (file_exists($f)) @unlink($f);
}

// ════════════════════════════════════════════════════════════════════════════
// Telegram Webhook Handler
// ════════════════════════════════════════════════════════════════════════════
function handleTelegramUpdate(array $update): void
{
    // Callback Query (ضغط على زر)
    if (isset($update['callback_query'])) {
        $cb     = $update['callback_query'];
        $cbId   = $cb['id'];
        $chatId = (string)($cb['message']['chat']['id'] ?? '');
        $msgId  = (int)($cb['message']['message_id'] ?? 0);
        $data   = $cb['data'] ?? '';

        if ($chatId !== TG_ADMIN_ID) { tgAnswerCallback($cbId, '⛔ غير مصرح'); return; }
        tgAnswerCallback($cbId);
        handleTgCallback($chatId, $msgId, $data);
        return;
    }

    // رسالة عادية
    if (!isset($update['message'])) return;
    $msg    = $update['message'];
    $chatId = (string)($msg['chat']['id'] ?? '');
    $text   = trim($msg['text'] ?? '');

    if ($chatId !== TG_ADMIN_ID) {
        tgSendMessage($chatId, '⛔ أنت لست مصرحاً باستخدام هذا البوت.');
        return;
    }

    $state = getTgState($chatId);

    // حالة انتظار نص البث
    if (($state['action'] ?? '') === 'awaiting_broadcast') {
        handleTgBroadcastText($chatId, $text, $state);
        return;
    }
    // حالة انتظار بروكسيات جديدة
    if (($state['action'] ?? '') === 'awaiting_proxies') {
        handleTgProxiesInput($chatId, $text);
        return;
    }
    // حالة انتظار QR Code للهدية
    if (($state['action'] ?? '') === 'awaiting_qr') {
        handleTgQrInput($chatId, $text);
        return;
    }

    // أوامر
    switch ($text) {
        case '/start':
        case '/help':
            sendTgMainMenu($chatId);
            break;
        case '/stats':
            handleTgStats($chatId);
            break;
        case '/broadcast':
            handleTgBroadcastStart($chatId);
            break;
        case '/setproxies':
            handleTgSetProxies($chatId);
            break;
        case '/proxies':
            handleTgShowProxies($chatId);
            break;
        case '/matchgift':
            handleTgMatchGift($chatId);
            break;
        case '/cancel':
            clearTgState($chatId);
            tgSendMessage($chatId, '✅ تم إلغاء العملية الحالية.');
            sendTgMainMenu($chatId);
            break;
        default:
            sendTgMainMenu($chatId);
    }
}

function sendTgMainMenu(string $chatId): void
{
    $cfg     = getMatchGiftConfig();
    $status  = $cfg['enabled'] ? '✅ مفعّلة' : '❌ معطلة';
    $qr      = $cfg['qr_code'];
    $label   = $cfg['gift_label'];

    $text = "🤖 <b>لوحة تحكم Tasjil BOT</b>\n\n"
          . "📊 اختر أمراً من القائمة أدناه:\n\n"
          . "━━━━━━━━━━━━━━━━━━━━\n"
          . "🎁 هدية المباراة: <b>{$status}</b>\n"
          . "📦 الهدية: <b>{$label}</b>\n"
          . "🔗 QR: <code>{$qr}</code>\n"
          . "━━━━━━━━━━━━━━━━━━━━";

    $keyboard = [
        [
            ['text' => '📊 إحصائيات المستخدمين', 'callback_data' => 'tg_stats'],
            ['text' => '📢 إرسال إعلان',          'callback_data' => 'tg_broadcast'],
        ],
        [
            ['text' => '🔗 إدارة البروكسيات',      'callback_data' => 'tg_proxies'],
            ['text' => '➕ إضافة بروكسيات',         'callback_data' => 'tg_setproxies'],
        ],
        [
            ['text' => $cfg['enabled'] ? '🔴 إيقاف هدية المباراة' : '🟢 تفعيل هدية المباراة', 'callback_data' => 'tg_toggle_match'],
            ['text' => '✏️ تغيير QR Code',          'callback_data' => 'tg_set_qr'],
        ],
        [
            ['text' => '🔄 تحديث البروكسيات (API)',  'callback_data' => 'tg_refresh_proxies'],
        ],
    ];

    tgSendMessage($chatId, $text, $keyboard);
}

function handleTgCallback(string $chatId, int $msgId, string $data): void
{
    switch ($data) {
        case 'tg_stats':
            handleTgStats($chatId);
            break;
        case 'tg_broadcast':
            handleTgBroadcastStart($chatId);
            break;
        case 'tg_proxies':
            handleTgShowProxies($chatId);
            break;
        case 'tg_setproxies':
            handleTgSetProxies($chatId);
            break;
        case 'tg_toggle_match':
            handleTgToggleMatch($chatId);
            break;
        case 'tg_set_qr':
            handleTgSetQr($chatId);
            break;
        case 'tg_refresh_proxies':
            handleTgRefreshProxies($chatId);
            break;
        case 'tg_broadcast_all':
            setTgState($chatId, ['action' => 'awaiting_broadcast', 'target' => 'all']);
            tgSendMessage($chatId, "📝 أرسل نص الإعلان الذي تريد إرساله لـ <b>جميع المستخدمين</b>:\n\n/cancel للإلغاء");
            break;
        case 'tg_broadcast_active':
            setTgState($chatId, ['action' => 'awaiting_broadcast', 'target' => 'active_7d']);
            tgSendMessage($chatId, "📝 أرسل نص الإعلان الذي تريد إرساله للمستخدمين <b>النشطين (7 أيام)</b>:\n\n/cancel للإلغاء");
            break;
        default:
            // broadcast confirm
            if (str_starts_with($data, 'tg_confirm_broadcast_')) {
                $broadcastId = substr($data, strlen('tg_confirm_broadcast_'));
                executeBroadcast($chatId, $broadcastId);
            }
            break;
    }
}

// ─── Stats ───────────────────────────────────────────────────────────────────
function handleTgStats(string $chatId): void
{
    $stats   = getUserStats();
    $proxies = loadProxies();
    $cfg     = getMatchGiftConfig();

    $text = "📊 <b>إحصائيات Tasjil BOT</b>\n\n"
          . "👥 إجمالي المستخدمين: <b>{$stats['total']}</b>\n"
          . "🟢 نشط (7 أيام): <b>{$stats['active_7d']}</b>\n"
          . "🟡 نشط (30 يوم): <b>{$stats['active_30d']}</b>\n\n"
          . "🔗 عدد البروكسيات: <b>" . count($proxies) . "</b>\n"
          . "🎁 هدية المباراة: <b>" . ($cfg['enabled'] ? '✅ مفعّلة' : '❌ معطلة') . "</b>\n\n"
          . "📅 التاريخ: " . date('Y-m-d H:i:s');

    $keyboard = [[['text' => '🔙 رجوع', 'callback_data' => 'tg_stats']]];
    tgSendMessage($chatId, $text, $keyboard);
}

// ─── Broadcast ───────────────────────────────────────────────────────────────
function handleTgBroadcastStart(string $chatId): void
{
    $stats = getUserStats();
    $text  = "📢 <b>إرسال إعلان</b>\n\n"
           . "👥 إجمالي المستخدمين: {$stats['total']}\n"
           . "🟢 النشطين (7 أيام): {$stats['active_7d']}\n\n"
           . "اختر الجمهور المستهدف:";

    $keyboard = [
        [
            ['text' => "📢 الكل ({$stats['total']})",           'callback_data' => 'tg_broadcast_all'],
            ['text' => "✅ النشطين ({$stats['active_7d']})",     'callback_data' => 'tg_broadcast_active'],
        ],
        [['text' => '❌ إلغاء', 'callback_data' => 'tg_stats']],
    ];
    tgSendMessage($chatId, $text, $keyboard);
}

function handleTgBroadcastText(string $chatId, string $text, array $state): void
{
    if (trim($text) === '' || $text === '/cancel') {
        clearTgState($chatId);
        tgSendMessage($chatId, '❌ تم إلغاء الإعلان.');
        return;
    }

    $target      = $state['target'] ?? 'all';
    $broadcastId = 'bc_' . time() . '_' . substr(md5($text), 0, 6);

    // حفظ بيانات البث للتأكيد
    setTgState($chatId, [
        'action'       => 'pending_broadcast',
        'target'       => $target,
        'broadcast_id' => $broadcastId,
        'message'      => $text,
    ]);

    $users     = ($target === 'all') ? getAllKnownUsers() : getActiveUsers(7);
    $userCount = count($users);

    $preview = "📢 <b>معاينة الإعلان</b>\n\n"
             . "🎯 المستهدفون: <b>{$userCount} مستخدم</b>\n"
             . "🆔 معرف البث: <code>{$broadcastId}</code>\n\n"
             . "━━━━━━━━━━━━━━\n"
             . htmlspecialchars($text)
             . "\n━━━━━━━━━━━━━━\n\n"
             . "⚠️ هل تريد إرسال هذا الإعلان؟";

    $keyboard = [
        [
            ['text' => "✅ إرسال للـ {$userCount}",         'callback_data' => "tg_confirm_broadcast_{$broadcastId}"],
            ['text' => '❌ إلغاء',                          'callback_data' => 'tg_broadcast'],
        ],
    ];
    tgSendMessage($chatId, $preview, $keyboard);
}

function executeBroadcast(string $chatId, string $broadcastId): void
{
    $state = getTgState($chatId);
    if (($state['broadcast_id'] ?? '') !== $broadcastId) {
        tgSendMessage($chatId, '❌ معرف البث غير متطابق.');
        return;
    }

    $target  = $state['target']  ?? 'all';
    $msgText = $state['message'] ?? '';
    $users   = ($target === 'all') ? getAllKnownUsers() : getActiveUsers(7);

    clearTgState($chatId);

    tgSendMessage($chatId, "🚀 جاري الإرسال لـ <b>" . count($users) . "</b> مستخدم...");

    $sent   = 0;
    $failed = 0;
    $skipped = 0;

    foreach ($users as $uid) {
        if (wasBroadcastSent($broadcastId, $uid)) { $skipped++; continue; }
        $result = sendFbMessage($uid, "📢 إعلان:\n\n" . $msgText);
        if ($result) {
            markBroadcastSent($broadcastId, $uid);
            $sent++;
        } else {
            $failed++;
        }
        usleep(80000); // 80ms delay لتجنب الحظر
    }

    $summary = "✅ <b>اكتمل الإعلان</b>\n\n"
             . "📤 مُرسَل: <b>{$sent}</b>\n"
             . "❌ فشل: <b>{$failed}</b>\n"
             . "⏭️ تم تخطيه (سبق الإرسال): <b>{$skipped}</b>\n"
             . "🆔 معرف البث: <code>{$broadcastId}</code>";

    tgSendMessage($chatId, $summary);
    sendTgMainMenu($chatId);
}

// ─── Proxies ─────────────────────────────────────────────────────────────────
function handleTgShowProxies(string $chatId): void
{
    $proxies = loadProxies();
    $count   = count($proxies);

    if ($count === 0) {
        tgSendMessage($chatId, "⚠️ لا توجد بروكسيات محفوظة!\n\nأرسل /setproxies لإضافة بروكسيات جديدة.");
        return;
    }

    $lines = ["🔗 <b>البروكسيات المحفوظة ({$count})</b>\n"];
    foreach ($proxies as $i => $p) {
        $pp     = parseProxy($p);
        $host   = explode(':', $pp['host'])[0] ?? $pp['host'];
        $lines[] = ($i + 1) . ". <code>{$host}</code>";
    }
    $lines[] = "\n/setproxies لإضافة بروكسيات جديدة";

    $keyboard = [
        [
            ['text' => '🔄 تحديث من API',    'callback_data' => 'tg_refresh_proxies'],
            ['text' => '➕ إضافة بروكسيات',  'callback_data' => 'tg_setproxies'],
        ],
        [['text' => '🔙 رجوع', 'callback_data' => 'tg_stats']],
    ];
    tgSendMessage($chatId, implode("\n", $lines), $keyboard);
}

function handleTgSetProxies(string $chatId): void
{
    setTgState($chatId, ['action' => 'awaiting_proxies']);
    tgSendMessage($chatId,
        "📝 <b>إضافة بروكسيات جديدة</b>\n\n"
        . "أرسل قائمة البروكسيات، كل بروكسي في سطر بالصيغة:\n"
        . "<code>https://host:port:user:pass</code>\n\n"
        . "أو JSON array مثل:\n"
        . "<code>[\"https://host:port:user:pass\"]</code>\n\n"
        . "⚠️ ستُستبدل القائمة الحالية بالقائمة الجديدة.\n"
        . "/cancel للإلغاء"
    );
}

function handleTgProxiesInput(string $chatId, string $text): void
{
    if ($text === '/cancel') { clearTgState($chatId); tgSendMessage($chatId, '❌ تم الإلغاء.'); return; }

    // محاولة JSON
    $list = @json_decode($text, true);
    if (!is_array($list)) {
        // سطر بسطر
        $list = array_filter(array_map('trim', explode("\n", $text)));
        $list = array_values($list);
    }

    if (empty($list)) {
        tgSendMessage($chatId, '❌ لم أتمكن من قراءة البروكسيات، تحقق من الصيغة وأعد المحاولة.');
        return;
    }

    // التحقق من الصيغة
    $valid = [];
    foreach ($list as $item) {
        $item = trim($item);
        if (preg_match('#^https?://.+:.+:.+:.+$#', $item)) {
            $valid[] = $item;
        }
    }

    if (empty($valid)) {
        tgSendMessage($chatId, '❌ لا توجد بروكسيات بصيغة صحيحة. الصيغة: https://host:port:user:pass');
        return;
    }

    saveProxies($valid);
    clearTgState($chatId);
    tgSendMessage($chatId, "✅ تم حفظ <b>" . count($valid) . "</b> بروكسي بنجاح!");
    sendTgMainMenu($chatId);
}

function handleTgRefreshProxies(string $chatId): void
{
    tgSendMessage($chatId, '🔄 جاري تحديث البروكسيات من API...');
    $proxies = refreshProxies();
    tgSendMessage($chatId, "✅ تم تحديث البروكسيات: <b>" . count($proxies) . "</b> بروكسي.");
    sendTgMainMenu($chatId);
}

// ─── Match Gift ───────────────────────────────────────────────────────────────
function handleTgToggleMatch(string $chatId): void
{
    $cfg = getMatchGiftConfig();
    $cfg['enabled'] = !$cfg['enabled'];
    saveMatchGiftConfig($cfg);
    $status = $cfg['enabled'] ? '✅ مفعّلة' : '❌ معطلة';
    tgSendMessage($chatId, "🎁 هدية المباراة الآن: <b>{$status}</b>");
    sendTgMainMenu($chatId);
}

function handleTgMatchGift(string $chatId): void
{
    $cfg    = getMatchGiftConfig();
    $status = $cfg['enabled'] ? '✅ مفعّلة' : '❌ معطلة';

    $text = "🎁 <b>إعدادات هدية المباراة</b>\n\n"
          . "الحالة: <b>{$status}</b>\n"
          . "الهدية: <b>{$cfg['gift_label']}</b>\n"
          . "QR Code: <code>{$cfg['qr_code']}</code>\n";

    $keyboard = [
        [
            ['text' => $cfg['enabled'] ? '🔴 إيقاف' : '🟢 تفعيل', 'callback_data' => 'tg_toggle_match'],
            ['text' => '✏️ تغيير QR',                              'callback_data' => 'tg_set_qr'],
        ],
        [['text' => '🔙 رجوع', 'callback_data' => 'tg_stats']],
    ];
    tgSendMessage($chatId, $text, $keyboard);
}

function handleTgSetQr(string $chatId): void
{
    $cfg = getMatchGiftConfig();
    setTgState($chatId, ['action' => 'awaiting_qr']);
    tgSendMessage($chatId,
        "✏️ <b>تغيير QR Code لهدية المباراة</b>\n\n"
        . "QR الحالي:\n<code>{$cfg['qr_code']}</code>\n\n"
        . "أرسل الـ QR Code الجديد:\n"
        . "/cancel للإلغاء"
    );
}

function handleTgQrInput(string $chatId, string $text): void
{
    if ($text === '/cancel') { clearTgState($chatId); tgSendMessage($chatId, '❌ تم الإلغاء.'); return; }

    $cfg = getMatchGiftConfig();
    $cfg['qr_code'] = trim($text);
    saveMatchGiftConfig($cfg);
    clearTgState($chatId);
    tgSendMessage($chatId, "✅ تم تحديث QR Code:\n<code>{$cfg['qr_code']}</code>");
    sendTgMainMenu($chatId);
}

// ════════════════════════════════════════════════════════════════════════════
// Webhook Routing — Facebook & Telegram
// ════════════════════════════════════════════════════════════════════════════
// ════════════════════════════════════════════════════════════════════════════
// نقطة الدخول — Polling Engine (لا يوجد Webhook)
// ════════════════════════════════════════════════════════════════════════════
//
// تشغيل البوت الآن يتم حصراً عبر سطر الأوامر (CLI)، وليس عبر طلبات HTTP من فيسبوك/تلغرام:
//
//   php bot1.php poll      -> يشغّل المُشرِف (Supervisor) الذي يدير كل شيء ويعيد
//                              تشغيل أي عملية فرعية تتعطل تلقائياً — هذا هو الأمر الذي
//                              يجب تشغيله بشكل دائم (عبر systemd/supervisor/screen/nohup).
//   php bot1.php fbpoll    -> فقط حلقة فحص فيسبوك (تُستخدم داخلياً من الأب المُشرِف).
//   php bot1.php tgpoll    -> فقط حلقة فحص تلغرام (Long Polling).
//   php bot1.php worker N  -> عامل واحد يسحب المهام من الطابور ويعالجها (تُستخدم داخلياً).
//
if ($IS_CLI) {
    $cmd = $argv[1] ?? '';
    switch ($cmd) {
        case 'poll':    runSupervisor(); exit;
        case 'fbpoll':  runFacebookPollLoop(); exit;
        case 'tgpoll':  runTelegramPollLoop(); exit;
        case 'worker':  runWorkerLoop((int)($argv[2] ?? 0)); exit;
        default:
            fwrite(STDERR, "الاستخدام:\n  php " . basename(__FILE__) . " poll\n  php " . basename(__FILE__) . " fbpoll\n  php " . basename(__FILE__) . " tgpoll\n  php " . basename(__FILE__) . " worker <id>\n");
            exit(1);
    }
}

// أي طلب HTTP لم يعد له أي دور — البوت لا يعتمد على Webhook بتاتاً الآن.
http_response_code(200);
header('Content-Type: text/plain; charset=utf-8');
$stats = getUserStats();
echo "Tasjil BOT — يعمل بنظام Polling وليس Webhook.\n";
echo "شغّل العملية الدائمة عبر: php " . basename(__FILE__) . " poll\n";
echo "المستخدمون: {$stats['total']} | نشِطون(7 أيام): {$stats['active_7d']}\n";
exit;

// ════════════════════════════════════════════════════════════════════════════
function buildEventId(string $psid, array $event): string
{
    if (isset($event['message'])) {
        $mid = $event['message']['mid'] ?? '';
        if ($mid !== '') return "msg_{$mid}";
        $ts = (int)($event['timestamp'] ?? time());
        return "msg_{$psid}_" . md5(trim($event['message']['text'] ?? '')) . "_" . (int)($ts / 10);
    }
    if (isset($event['postback'])) {
        $ts = (int)($event['timestamp'] ?? time());
        return "pb_{$psid}_" . md5($event['postback']['payload'] ?? '') . "_" . (int)($ts / 10);
    }
    return "ev_{$psid}_" . md5(json_encode($event));
}

// ════════════════════════════════════════════════════════════════════════════
// Process Facebook Event
// ════════════════════════════════════════════════════════════════════════════
function processEvent(string $psid, array $event): void
{
    $isNew = isNewUser($psid);
    markUserAsSeen($psid);

    if (isset($event['postback'])) { handlePostback($psid, $event['postback']['payload'] ?? ''); return; }
    if (!isset($event['message'])) return;

    $msg = $event['message'];
    if (isset($msg['sticker_id']) && $msg['sticker_id'] == 369239263222822) { sendMessage($psid, '👍'); return; }
    if (isset($msg['attachments']) && empty($msg['text'])) { sendMessage($psid, randomSticker()); return; }
    if (isset($msg['quick_reply']['payload'])) { handlePostback($psid, $msg['quick_reply']['payload']); return; }

    $text   = trim($msg['text'] ?? '');
    $digits = preg_replace('/\D/', '', $text);
    if ($text === '') { if ($isNew) sendWelcomeNew($psid); else sendWelcome($psid); return; }

    // Admin Broadcast
    if (preg_match('/@#(.+?)@#/su', $text, $adMatch)) { handleAdminBroadcast($psid, trim($adMatch[1])); return; }

    $session = getSession($psid);
    $state   = $session['state'] ?? 'idle';

    if ($state === 'awaiting_otp')          { handleAwaitingOtp($psid, $text, $session); return; }
    if ($state === 'awaiting_offer_otp')    { handleOfferOtp($psid, $text, $session); return; }
    if ($state === 'awaiting_invite_phone') { handleInvitePhoneInput($psid, $text, $session); return; }
    if ($state === 'awaiting_invitee_otp')  { handleInviteeOtp($psid, $text, $session); return; }

    $pending = getPending($psid);
    if ($pending !== null) { sendMessage($psid, "⏳ انتظر، نحن نقوم بـ {$pending}\nبعدها يمكنك الطلب."); return; }

    if (preg_match('/^07\d{8}$/', $digits)) { handleNewPhone($psid, $digits); return; }
    if (preg_match('/^05\d{8}$/', $digits)) { sendMessage($psid, "⏳ سيتم إضافة Ooredoo قريباً."); return; }
    if (preg_match('/^06\d{8}$/', $digits)) { sendMessage($psid, "❌ لا يوجد تسجيل Mobilis."); return; }

    if ($state === 'menu' || $state === 'offers') {
        if     ($text === '1')  handlePostback($psid, 'MENU_2G');
        elseif ($text === '2')  handlePostback($psid, 'MENU_70DZ');
        elseif ($text === '3')  handlePostback($psid, 'MENU_INVITE');
        elseif ($text === '4')  handlePostback($psid, 'MENU_MORE_OFFERS');
        elseif ($text === '30') {
            $cfg  = getMatchGiftConfig();
            $sess = getSession($psid);
            $user = getUser($psid);
            if (!$cfg['enabled']) {
                sendMessage($psid, "⏰ هدية المباراة غير متاحة حالياً.\n\n⚡ قناة التلغرام: https://t.me/tasjilbott");
                return;
            }
            if (!$user || empty($user['access_token'])) {
                sendMessage($psid, "⚠️ يجب تسجيل الدخول أولاً، أرسل رقم هاتفك.");
                return;
            }
            if (!empty($sess['msisdn'])) $user['msisdn'] = $sess['msisdn'];
            activateAlgeriaMatchGift($psid, $user);
        }
        elseif (isset(OFFER_SHORTCUTS[$text])) {
            handlePostback($psid, 'ACTIVATE_OFFER_' . OFFER_SHORTCUTS[$text]);
        } else {
            sendMessage($psid,
                "❌ اختيار خاطئ\n\n📌 قم باستخدام الأزرار الموجودة بالأسفل\nإذا لم تظهر لك الأزرار أرسل الرقم المناسب 👇\n\n━━━━━━━━━━━━━━\n\n" .
                "1️⃣ لتفعيل 2G الأسبوعية\n📩 أرسل: 1\n\n2️⃣ لتفعيل عرض 4GB بـ 70دج 🏷️\n📩 أرسل: 2\n\n3️⃣ لإرسال دعوة 🎁\n📩 أرسل: 3\n\n4️⃣ للمزيد من العروض 📦\n📩 أرسل: 4\n\n━━━━━━━━━━━━━━"
            );
        }
        return;
    }
    if ($isNew) sendWelcomeNew($psid); else sendWelcome($psid);
}

// ════════════════════════════════════════════════════════════════════════════
// Admin Broadcast (من فيسبوك)
// ════════════════════════════════════════════════════════════════════════════
function handleAdminBroadcast(string $psid, string $adText): void
{
    $users = getAllKnownUsers();
    $broadcastId = 'fb_bc_' . time();
    $count = 0;
    foreach ($users as $uid) {
        if (wasBroadcastSent($broadcastId, $uid)) continue;
        if (sendFbMessage($uid, "📢 إعلان:\n\n" . $adText)) {
            markBroadcastSent($broadcastId, $uid);
            $count++;
        }
        usleep(100000);
    }
    sendMessage($psid, "✅ تم إرسال الإعلان إلى {$count} مستخدم.");
    dbg("[BROADCAST] from={$psid} users={$count}");
}

// ════════════════════════════════════════════════════════════════════════════
// OTP — تسجيل الدخول
// ════════════════════════════════════════════════════════════════════════════
function handleAwaitingOtp(string $psid, string $text, array $session): void
{
    $msisdn       = $session['msisdn'] ?? '';
    $phoneDisplay = '0' . substr($msisdn, 3);
    if (trim($text) === '0') {
        clearSession($psid);
        sendMessage($psid, "✅ تم إلغاء عملية التسجيل.\n\n📱 أرسل رقمك في أي وقت للبدء من جديد.");
        return;
    }
    $digits = preg_replace('/\D/', '', $text);
    if (preg_match('/^07\d{8}$/', $digits)) {
        $newMsisdn = '213' . substr($digits, 1);
        sendMessage($psid, "📲 جاري إعادة إرسال رمز التحقق إلى الرقم {$digits}...");
        sendOTPAndWait($psid, $newMsisdn, $digits);
        return;
    }
    if (!preg_match('/\b(\d{6})\b/', $text, $m)) {
        sendMessage($psid,
            "⚠️ الرجاء إدخال رمز التحقق المكوّن من 6 أرقام.\n\n📱 أو أرسل رقم هاتفك مجدداً لاستقبال رمز جديد\n🔢 الرمز أُرسل إلى: {$phoneDisplay}\n\n❌ لإلغاء العملية أرسل: 0"
        );
        return;
    }
    if (empty($msisdn)) { clearSession($psid); sendMessage($psid, "❌ حدث خطأ في الجلسة، أرسل رقمك مجدداً."); return; }
    $result = verifyOTP($msisdn, $m[1]);
    if ($result === 'wrong_otp') {
        sendMessage($psid, "❌ الرمز المُدخل خاطئ!\n\n🔄 أعد إرسال الرمز الصحيح\n📱 أو أرسل رقم هاتفك مجدداً لاستقبال رمز جديد\n\n❌ لإلغاء العملية أرسل: 0");
    } elseif ($result === false) {
        sendMessage($psid, "❌ حدث خطأ، حاول مجدداً.\n\n📱 يمكنك إرسال رقمك مجدداً لاستقبال رمز جديد\n\n❌ لإلغاء العملية أرسل: 0");
    } else {
        saveUser($psid, ['user_id' => $psid, 'msisdn' => $msisdn, 'access_token' => $result['access_token'], 'refresh_token' => $result['refresh_token']]);
        savePhoneOwner($msisdn, $psid);
        setSession($psid, ['state' => 'menu', 'msisdn' => $msisdn]);
        sendMessage($psid, "✅ تم تسجيل الدخول بنجاح!");
        sendMenu($psid);
        sendAlgeriaMatchGiftPromo($psid);
    }
}

// ════════════════════════════════════════════════════════════════════════════
// OTP — تفعيل العرض (جديد)
// ════════════════════════════════════════════════════════════════════════════
function handleOfferOtp(string $psid, string $text, array $session): void
{
    $msisdn      = $session['msisdn'] ?? '';
    $packageCode = $session['pending_package'] ?? '';
    $phoneDisplay = '0' . substr($msisdn, 3);

    if (trim($text) === '0') {
        clearSession($psid);
        setSession($psid, ['state' => 'menu', 'msisdn' => $msisdn]);
        sendMessage($psid, "✅ تم إلغاء عملية تفعيل العرض.");
        sendMenu($psid);
        return;
    }
    $digits = preg_replace('/\D/', '', $text);
    if (preg_match('/^07\d{8}$/', $digits)) {
        $newMsisdn = '213' . substr($digits, 1);
        sendMessage($psid, "📲 جاري إعادة إرسال رمز التحقق إلى الرقم {$digits}...");
        sendNewOTPAndWaitForOffer($psid, $newMsisdn, $digits, $packageCode);
        return;
    }
    if (!preg_match('/\b(\d{6})\b/', $text, $m)) {
        sendMessage($psid,
            "⚠️ الرجاء إدخال رمز التحقق المكوّن من 6 أرقام.\n\n📱 أو أرسل رقم هاتفك مجدداً لاستقبال رمز جديد\n🔢 الرمز أُرسل إلى: {$phoneDisplay}\n\n❌ لإلغاء العملية أرسل: 0"
        );
        return;
    }
    if (empty($msisdn) || empty($packageCode)) {
        clearSession($psid);
        sendMessage($psid, "❌ حدث خطأ في الجلسة، أرسل رقمك مجدداً.");
        return;
    }
    $result = verifyOTPNew($msisdn, $m[1]);
    if ($result === 'wrong_otp') {
        sendMessage($psid, "❌ الرمز المُدخل خاطئ!\n\n🔄 أعد إرسال الرمز الصحيح\n📱 أو أرسل رقم هاتفك مجدداً لاستقبال رمز جديد\n\n❌ لإلغاء العملية أرسل: 0");
        return;
    }
    if ($result === false) {
        sendMessage($psid, "❌ حدث خطأ في التحقق، حاول مجدداً.\n\n📱 يمكنك إرسال رقمك مجدداً لاستقبال رمز جديد\n\n❌ لإلغاء العملية أرسل: 0");
        return;
    }
    $userForOffer = array_merge(getUser($psid) ?? [], [
        'msisdn'        => $msisdn,
        'access_token'  => $result['access_token'],
        'refresh_token' => $result['refresh_token'],
    ]);
    setSession($psid, ['state' => 'menu', 'msisdn' => $msisdn]);
    sendMessage($psid, "✅ تم التحقق بنجاح! جاري تفعيل العرض...");
    activateOfferNew($psid, $userForOffer, $packageCode);
}

// ════════════════════════════════════════════════════════════════════════════
// Phone Handler
// ════════════════════════════════════════════════════════════════════════════
function handleNewPhone(string $psid, string $phone): void
{
    $msisdn = '213' . substr($phone, 1);
    $owner  = getPhoneOwner($msisdn);
    if ($owner === $psid) {
        $user = getUser($psid);
        if ($user && !empty($user['access_token'])) {
            $user['msisdn'] = $msisdn;
            saveUser($psid, $user);
            $refreshed = refreshAccessToken($user['refresh_token'], $msisdn, $psid);
            if ($refreshed === 'expired') return; // تم إرسال رمز تحقق جديد بالفعل من داخل refreshAccessToken
            if (is_array($refreshed)) {
                saveUser($psid, array_merge($user, ['msisdn' => $msisdn, 'access_token' => $refreshed['access_token'], 'refresh_token' => $refreshed['refresh_token']]));
                setSession($psid, ['state' => 'menu', 'msisdn' => $msisdn]);
                sendMessage($psid, "✅ تم التعرف على رقمك بنجاح!");
                sendMenu($psid);
                sendAlgeriaMatchGiftPromo($psid);
                return;
            }
        }
    } elseif ($owner !== null) {
        sendMessage($psid, "🚫 أنت لست صاحب الرقم، يجب إثبات الهوية.\n\n📲 سيتم إرسال رمز تحقق إلى هذا الرقم...");
    }
    sendOTPAndWait($psid, $msisdn, $phone);
}
function sendOTPAndWait(string $psid, string $msisdn, string $phone): void
{
    if (sendDjezzyOTP($msisdn)) {
        setSession($psid, ['state' => 'awaiting_otp', 'msisdn' => $msisdn]);
        sendMessage($psid,
            "✅ تم إرسال رمز التحقق إلى الرقم {$phone}.\n\n🔢 الرجاء إدخال الرمز المكوّن من 6 أرقام:\n\n📱 أو أرسل رقمك مجدداً لاستقبال رمز جديد\n\n❌ لإلغاء العملية أرسل: 0"
        );
    } else {
        sendMessage($psid, "سيرفر جازي غير متاح حاليا نعمل على اصلاحه 🧑‍🔧 يمكنك التسجيل عبر التطبيق الخاص بنا رابط تحميله https://tasjil-app.lovestoblog.com
او من
https://t.me/tasjilbott/130");
    }
}
function sendNewOTPAndWaitForOffer(string $psid, string $msisdn, string $phone, string $packageCode): void
{
    if (sendDjezzyOTPNew($msisdn)) {
        setSession($psid, ['state' => 'awaiting_offer_otp', 'msisdn' => $msisdn, 'pending_package' => $packageCode]);
        $offerInfo  = OFFERS[$packageCode] ?? null;
        $offerLabel = $offerInfo ? $offerInfo['name'] : $packageCode;
        sendMessage($psid,
            "✅ تم إرسال رمز التحقق إلى الرقم {$phone}.\n\n" .
            "📌 نقوم الآن بتجربة طريقة تفعيل أخرى للعرض.\n\n" .
            "🔢 الرجاء إدخال الرمز المكوّن من 6 أرقام لتفعيل العرض:\n" .
            "📦 العرض: {$offerLabel}\n\n" .
            "📱 أو أرسل رقمك مجدداً لاستقبال رمز جديد\n\n" .
            "❌ لإلغاء العملية أرسل: 0"
        );
    } else {
        sendMessage($psid, "سيرفر جازي غير متاح حاليا نعمل على اصلاحه 🧑‍🔧 يمكنك التسجيل عبر التطبيق الخاص بنا رابط تحميله https://tasjil-app.lovestoblog.com
او من
https://t.me/tasjilbott/130");
    }
}

// ════════════════════════════════════════════════════════════════════════════
// Postback Handler
// ════════════════════════════════════════════════════════════════════════════
function handlePostback(string $psid, string $payload): void
{
    if (str_starts_with($payload, 'ACTIVATE_OFFER_')) {
        $packageCode = substr($payload, strlen('ACTIVATE_OFFER_'));
        $sess = getSession($psid); $user = getUser($psid);
        if (!$user || empty($user['access_token'])) { sendMessage($psid, "⚠️ يجب تسجيل الدخول أولاً، أرسل رقم هاتفك."); return; }
        if (!empty($sess['msisdn'])) $user['msisdn'] = $sess['msisdn'];
        setSession($psid, array_merge($sess, ['state' => 'menu']));
        activateOffer($psid, $user, $packageCode);
        return;
    }
    switch ($payload) {
        case 'GET_STARTED':
            sendWelcomeNew($psid);
            break;
        case 'ACTIVATE_ALGERIA_MATCH':
            $cfg  = getMatchGiftConfig();
            $sess = getSession($psid);
            $user = getUser($psid);
            if (!$cfg['enabled']) {
                sendMessage($psid, "⏰ هدية المباراة غير متاحة حالياً.\n\n⚡ قناة التلغرام: https://t.me/tasjilbott");
                return;
            }
            if (!$user || empty($user['access_token'])) {
                sendMessage($psid, "⚠️ يجب تسجيل الدخول أولاً، أرسل رقم هاتفك.");
                return;
            }
            if (!empty($sess['msisdn'])) $user['msisdn'] = $sess['msisdn'];
            activateAlgeriaMatchGift($psid, $user);
            break;
        case 'MENU_2G':
            $sess = getSession($psid); $user = getUser($psid);
            if (!$user || empty($user['access_token'])) { sendMessage($psid, "⚠️ يجب تسجيل الدخول أولاً، أرسل رقم هاتفك."); return; }
            if (!empty($sess['msisdn'])) $user['msisdn'] = $sess['msisdn'];
            setSession($psid, array_merge($sess, ['state' => 'menu']));
            activate2G($psid, $user);
            break;
        case 'MENU_70DZ':
            $sess = getSession($psid); $user = getUser($psid);
            if (!$user || empty($user['access_token'])) { sendMessage($psid, "⚠️ يجب تسجيل الدخول أولاً، أرسل رقم هاتفك."); return; }
            if (!empty($sess['msisdn'])) $user['msisdn'] = $sess['msisdn'];
            setSession($psid, array_merge($sess, ['state' => 'menu']));
            activate70DZ($psid, $user);
            break;
        case 'MENU_INVITE':
            $sess = getSession($psid); $user = getUser($psid);
            if (!$user || empty($user['access_token'])) { sendMessage($psid, "⚠️ يجب تسجيل الدخول أولاً، أرسل رقم هاتفك."); return; }
            if (!empty($sess['msisdn'])) $user['msisdn'] = $sess['msisdn'];
            handleInviteStart($psid, $user);
            break;
        case 'MENU_MORE_OFFERS':
            sendMoreOffers($psid);
            break;
        case 'BACK_MENU':
            sendMenu($psid);
            break;
        default:
            sendWelcome($psid);
    }
}

// ════════════════════════════════════════════════════════════════════════════
// Algeria Match Gift — يتحكم بها من تلقرام
// ════════════════════════════════════════════════════════════════════════════
function sendAlgeriaMatchGiftPromo(string $psid): void
{
    $cfg = getMatchGiftConfig();
    if (!$cfg['enabled']) return;

    $label = $cfg['gift_label'];
    fbApiCall(json_encode([
        'recipient'      => ['id' => $psid],
        'messaging_type' => 'RESPONSE',
        'message'        => [
            'text' =>
                "🇩🇿🔥 هدية خاصة في اليوم الذي تلعب فيه الجزائر مباراة ⚽!\n\n" .
                "━━━━━━━━━━━━━━━━━━━━━━\n\n" .
                "🎁 احصل على {$label} مجاناً 🎉\n\n" .
                "━━━━━━━━━━━━━━━━━━━━━━\n\n" .
                "📩 أرسل الرقم 30 للتفعيل الفوري",
            'quick_replies' => [[
                'content_type' => 'text',
                'title'        => '🇩🇿 تفعيل هدية المباراة',
                'payload'      => 'ACTIVATE_ALGERIA_MATCH',
            ]],
        ],
    ], JSON_UNESCAPED_UNICODE));
}

function activateAlgeriaMatchGift(string $psid, array $user): void
{
    $cfg = getMatchGiftConfig();
    if (!$cfg['enabled']) {
        sendMessage($psid, "⏰ هدية المباراة غير متاحة حالياً.\n\n⚡ قناة التلغرام: https://t.me/tasjilbott");
        return;
    }

    $rl = checkRateLimit($psid);
    if ($rl !== null) { sendMessage($psid, rateLimitMessage($rl)); return; }

    $msisdn        = $user['msisdn'];
    $accessToken   = $user['access_token'];
    $displayMasked = substr($msisdn, 0, 4) . 'xxxx' . substr($msisdn, -2);
    $qrCode        = $cfg['qr_code'];
    $label         = $cfg['gift_label'];

    sendMessage($psid, "🔄 جاري تفعيل هدية مباراة الجزائر 🇩🇿...");

    $url     = "https://apim.djezzy.dz/mobile-api/api/v1/services/scan/activate-reward/{$msisdn}";
    $payload = json_encode(['qrCode' => $qrCode]);
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'Accept-Encoding: gzip',
        "Authorization: Bearer {$accessToken}",
        'User-Agent: MobileApp/3.0.0',
    ];

    $raw = curlWithAllProxies($url, 'POST', $payload, $headers, 'MATCH_GIFT', 12, '/tmp/match_gift.log');

    if ($raw === null) {
        sendMessage($psid, "❌ حدث خطأ أثناء تفعيل الهدية. يرجى المحاولة مجدداً.\n\n⚡ قناة التلغرام: https://t.me/tasjilbott");
        return;
    }

    $httpCode = $raw['http_code'];

    if ($httpCode === 200 || $httpCode === 201) {
        recordFinalResult($psid);
        sendMessage($psid,
            "🎉 تم تفعيل الهدية بنجاح!\n\n" .
            "استمتع بمباراة الجزائر 🇩🇿⚽\n\n" .
            "✅ الرقم: {$displayMasked}\n" .
            "🎁 الهدية: {$label}\n\n" .
            "⚡ قناة التلغرام: https://t.me/tasjilbott"
        );
        sendMessage($psid, "\n\n🥰 اذا كنت تريد دعمنا حتى نطور الخدمة ونستمر 🥰\n\n🔴 ادخل للموقع 👇\n\nhttps://timebucks.com/?refID=227870531\n\n✅ وسجل بحساب جوجل فقط 🥰\n\n🥹 ولا تنسَ متابعة حساب المطور 👇\nhttps://www.facebook.com/profile.php?id=100052854003446\n\nوشكراً ❤️");
        clearSession($psid);
        return;
    }

    if ($httpCode === 400) {
        recordFinalResult($psid);
        sendMessage($psid,
            "⚠️ اما اليوم ليس يوم مباراة الجزائر أو انك استفدت من الهدية مسبقاً أو أن رقمك غير مؤهل لهذه الهدية.\n\n" .
            "⚡ قناة التلغرام: https://t.me/tasjilbott"
        );
        clearSession($psid);
        return;
    }

    sendMessage($psid, "❌ حدث خطأ أثناء تفعيل الهدية (HTTP {$httpCode}). يرجى المحاولة مجدداً.\n\n⚡ قناة التلغرام: https://t.me/tasjilbott");
    clearSession($psid);
}

// ════════════════════════════════════════════════════════════════════════════
// activateOffer — قديم ثم جديد
// ════════════════════════════════════════════════════════════════════════════
function activateOffer(string $psid, array $user, string $packageCode): void
{
    $rl = checkRateLimit($psid);
    if ($rl !== null) { sendMessage($psid, rateLimitMessage($rl)); return; }

    $msisdn        = $user['msisdn'];
    $accessToken   = $user['access_token'];
    $displayMasked = substr($msisdn, 0, 4) . 'xxxx' . substr($msisdn, -2);
    $offerInfo     = OFFERS[$packageCode] ?? null;
    $offerLabel    = $offerInfo ? $offerInfo['name'] : $packageCode;

    setPending($psid, "تفعيل {$offerLabel} 🔖");
    sendMessage($psid, "جاري تفعيل العرض {$offerLabel} 🔄...");

    $url     = "https://apim.djezzy.dz/mobile-api/api/v1/subscribers/activate-product/{$msisdn}";
    $payload = json_encode(['packageCode' => $packageCode]);
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'Accept-Encoding: gzip',
        'accept-language: fr',
        "authorization: Bearer {$accessToken}",
        'User-Agent: MobileApp/3.0.0',
    ];

    $raw = curlWithAllProxies($url, 'POST', $payload, $headers, "OFFER_OLD:{$packageCode}", 10, '/tmp/activate70.log');

    if ($raw !== null) {
        $httpCode     = $raw['http_code'];
        $responseData = $raw['json'];
        $bodyStr      = $raw['body'];
        dbg("[OFFER_OLD:{$packageCode}] http={$httpCode} body=" . substr($bodyStr, 0, 300));

        if (is_array($responseData)) {
            $innerStatus = (int)($responseData['status'] ?? 0);
            $innerMsg    = $responseData['message'] ?? '';

            if ($httpCode === 402 || $innerStatus === 402) {
                clearPending($psid); recordFinalResult($psid);
                $balance    = $responseData['data']['mainBalance'] ?? null;
                $balanceMsg = ($balance !== null) ? "رصيدك الحالي: {$balance} دج 💳\n" : "";
                sendMessage($psid, "حدث خطأ ⚠️ رصيدك غير كافي 💰 لتفعيل هذا العرض 🔖 😔\n{$balanceMsg}\n⚡ قناة التلقرام : https://t.me/tasjilbott");
                clearSession($psid); sendMessage($psid, ""); return;
            }
            if ($httpCode === 200 || $httpCode === 201 || $innerStatus === 200) {
                $msgStr = is_array($innerMsg) ? ($innerMsg['en'] ?? '') : (string)$innerMsg;
                if (stripos($msgStr, 'successfully') !== false || $httpCode === 201 || $innerStatus === 200) {
                    clearPending($psid); recordFinalResult($psid);
                    $detailMsg = $offerInfo ? "\n✅ تفاصيل العرض: " . $offerInfo['display'] : "";
                    sendMessage($psid, "⭐ تم تفعيل العرض بنجاح 🎁 للرقم {$displayMasked}\n✅ اسم العرض: {$offerLabel}{$detailMsg}\n\n⚡ قناة التلقرام : https://t.me/tasjilbott");
                    sendMessage($psid, "");
                    clearSession($psid);
                    sendMessage($psid, "\n\n🥰 اذا كنت تريد دعمنا حتى نطور الخدمة ونستمر 🥰\n\n🔴 ادخل للموقع 👇\n\nhttps://timebucks.com/?refID=227870531\n\n✅ وسجل بحساب جوجل فقط 🥰\n\n🥹 ولا تنسَ متابعة حساب المطور 👇\nhttps://www.facebook.com/profile.php?id=100052854003446\n\nوشكراً ❤️");
                    return;
                }
            }
        }
    }

    // فشل — جرب الجديدة
    clearPending($psid);
    sendMessage($psid, "⚠️ تعذر تفعيل العرض بالطريقة الأولى، جارٍ تجربة طريقة أخرى...");
    $phoneDisplay = '0' . substr($msisdn, 3);
    sendNewOTPAndWaitForOffer($psid, $msisdn, $phoneDisplay, $packageCode);
}

// ════════════════════════════════════════════════════════════════════════════
// activateOfferNew
// ════════════════════════════════════════════════════════════════════════════
function activateOfferNew(string $psid, array $user, string $packageCode): void
{
    $msisdn        = $user['msisdn'];
    $accessToken   = $user['access_token'];
    $refreshToken  = $user['refresh_token'];
    $displayMasked = substr($msisdn, 0, 4) . 'xxxx' . substr($msisdn, -2);
    $offerInfo     = OFFERS[$packageCode] ?? null;
    $offerLabel    = $offerInfo ? $offerInfo['name'] : $packageCode;

    $maxAttempts       = 10;
    $maxTokenRefresh   = 3;
    $tokenRefreshCount = 0;

    setPending($psid, "تفعيل {$offerLabel} 🔖");

    $url     = "https://apim.djezzy.dz/djezzy-api/api/v1/subscribers/{$msisdn}/subscription-product?include=";
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'Accept-Encoding: gzip',
        'Connection: Keep-Alive',
        "Authorization: Bearer {$accessToken}",
        'User-Agent: Djezzy/2.7.0',
        'X-Csrf-Token: YACIN_DZ',
    ];

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $payload = json_encode(['data' => ['id' => $packageCode, 'type' => 'products']]);

        // تحديث Authorization header عند تجديد التوكن
        $hdrs = $headers;
        foreach ($hdrs as &$h) {
            if (str_starts_with($h, 'Authorization:')) {
                $h = "Authorization: Bearer {$accessToken}";
            }
        }
        unset($h);

        $raw = curlWithAllProxies($url, 'POST', $payload, $hdrs, "OFFER_NEW:{$packageCode}:#{$attempt}", 10, '/tmp/activate_offer_new.log');
        if ($raw === null) { usleep(1000000); continue; }

        $httpCode     = $raw['http_code'];
        $responseData = $raw['json'];
        dbg("[OFFER_NEW:{$packageCode}] attempt={$attempt} http={$httpCode}");

        if (!is_array($responseData)) { if ($httpCode === 429) usleep(2000000); else usleep(1000000); continue; }

        $fault = $responseData['fault'] ?? null;
        if ($fault !== null) {
            $faultCode = (int)($fault['code'] ?? 0);
            if ($faultCode === 900901) {
                if ($tokenRefreshCount >= $maxTokenRefresh) break;
                $tokenRefreshCount++;
                $refreshed = refreshAccessTokenNew($refreshToken, $msisdn, $psid, $packageCode);
                if ($refreshed === 'expired') { clearPending($psid); return; }
                if ($refreshed === false) { clearPending($psid); return; }
                $accessToken  = $refreshed['access_token'];
                $refreshToken = $refreshed['refresh_token'];
                $attempt--;
                continue;
            }
            usleep(1000000); continue;
        }

        $innerStatus = (int)($responseData['status'] ?? 0);
        if ($httpCode === 402 || $innerStatus === 402) {
            clearPending($psid); recordFinalResult($psid);
            $balance    = $responseData['data']['mainBalance'] ?? null;
            $balanceMsg = ($balance !== null) ? "رصيدك الحالي: {$balance} دج 💳\n" : "";
            sendMessage($psid, "حدث خطأ ⚠️ رصيدك غير كافي 💰 لتفعيل هذا العرض 🔖 😔\n{$balanceMsg}\n⚡ قناة التلقرام : https://t.me/tasjilbott");
            clearSession($psid); sendMessage($psid, ""); return;
        }
        if ($httpCode === 200 || $httpCode === 201) {
            clearPending($psid); recordFinalResult($psid);
            $detailMsg = $offerInfo ? "\n✅ تفاصيل العرض: " . $offerInfo['display'] : "";
            sendMessage($psid, "⭐ تم تفعيل العرض بنجاح 🎁 للرقم {$displayMasked}\n✅ اسم العرض: {$offerLabel}{$detailMsg}\n\n⚡ قناة التلقرام : https://t.me/tasjilbott");
            sendMessage($psid, "");
            clearSession($psid);
            sendMessage($psid, "\n\n🥰 اذا كنت تريد دعمنا حتى نطور الخدمة ونستمر 🥰\n\n🔴 ادخل للموقع 👇\n\nhttps://timebucks.com/?refID=227870531\n\n✅ وسجل بحساب جوجل فقط 🥰\n\n🥹 ولا تنسَ متابعة حساب المطور 👇\nhttps://www.facebook.com/profile.php?id=100052854003446\n\nوشكراً ❤️");
            return;
        }
        if ($httpCode === 429) { usleep(2000000); continue; }
        usleep(1000000);
    }

    clearPending($psid); recordFinalResult($psid);
    sendMessage($psid, "عذرا ⚠️ تعذر تفعيل العرض، يبدو أن شريحتك لا تدعم هذا العرض أو حدث خطأ مؤقت\n\n⚡ قناة التلقرام : https://t.me/tasjilbott");
    clearSession($psid);
    sendMessage($psid, "");
}

// ════════════════════════════════════════════════════════════════════════════
// activate70DZ & activate2G
// ════════════════════════════════════════════════════════════════════════════
function activate70DZ(string $psid, array $user): void
{
    activateOffer($psid, $user, 'BTLINTSPEEDDAY2Go');
}

function activate2G(string $psid, array $user): void
{
    $rl = checkRateLimit($psid);
    if ($rl !== null) { sendMessage($psid, rateLimitMessage($rl)); return; }

    $msisdn        = $user['msisdn'];
    $accessToken   = $user['access_token'];
    $refreshToken  = $user['refresh_token'];
    $displayMasked = substr($msisdn, 0, 4) . 'xxxx' . substr($msisdn, -2);

    sendMessage($psid, "🔍 جاري فحص تاريخ آخر تفعيل...");
    $history = fetchSubscriptionHistory($msisdn, $accessToken);
    if ($history !== null) {
        $lastTs = getLastWalkWinDate($history);
        if ($lastTs !== null) {
            $elapsed   = time() - $lastTs;
            $sevenDays = 7 * 24 * 3600;
            if ($elapsed < $sevenDays) {
                $remaining = $sevenDays - $elapsed;
                recordFinalResult($psid);
                sendMessage($psid,
                    "عذرا 😬 لم تكمل أسبوع ⚠️\n\n⏳ الوقت المتبقي: " . formatTimeRemaining($remaining) .
                    "\n\nأعد المحاولة بعد انتهاء هذه المدة 📆\n\n⚡ قناة التلقرام : https://t.me/tasjilbott"
                );
                clearSession($psid); sendMessage($psid, ""); return;
            }
        }
    }

    $maxAttempts       = 30;
    $maxTokenRefresh   = 3;
    $tokenRefreshCount = 0;
    setPending($psid, 'تفعيل 2G 🎁');
    sendMessage($psid, "جاري تفعيل 2G 🎁 🔄...");

    $url     = "https://apim.djezzy.dz/mobile-api/api/v1/services/walk/activate-reward/{$msisdn}";
    $payload = json_encode(['packageCode' => 'GIFTWALKWIN2GO']);
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'Accept-Encoding: gzip',
        "Authorization: Bearer {$accessToken}",
        'User-Agent: MobileApp/3.0.0',
    ];

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $hdrs = $headers;
        foreach ($hdrs as &$h) {
            if (str_starts_with($h, 'Authorization:')) $h = "Authorization: Bearer {$accessToken}";
        }
        unset($h);

        $raw = curlWithAllProxies($url, 'POST', $payload, $hdrs, "2G:#{$attempt}", 10, '/tmp/activate2g.log');
        if ($raw === null) { usleep(1000000); continue; }

        $httpCode     = $raw['http_code'];
        $responseData = $raw['json'];
        dbg("[2G] attempt={$attempt} http={$httpCode}");

        if (!is_array($responseData)) { if ($httpCode === 429) usleep(2000000); else usleep(1000000); continue; }

        $fault = $responseData['fault'] ?? null;
        if ($fault !== null && (int)($fault['code'] ?? 0) === 900901) {
            if ($tokenRefreshCount >= $maxTokenRefresh) {
                clearPending($psid);
                sendMessage($psid, "فشل تحديث الجلسة بعد عدة محاولات، الرجاء إعادة ارسال رقمك للتسجيل من جديد");
                clearSession($psid); return;
            }
            $tokenRefreshCount++;
            $refreshed = refreshAccessToken($refreshToken, $msisdn, $psid);
            if ($refreshed === 'expired') { clearPending($psid); return; }
            if ($refreshed === false) { clearPending($psid); clearSession($psid); return; }
            $accessToken  = $refreshed['access_token'];
            $refreshToken = $refreshed['refresh_token'];
            saveUser($psid, array_merge($user, ['access_token' => $accessToken, 'refresh_token' => $refreshToken]));
            $attempt--; continue;
        }

        $innerStatus = (int)($responseData['status'] ?? 0);
        if ($httpCode === 402 || $innerStatus === 402 || $httpCode === 403 || $innerStatus === 403) {
            clearPending($psid); recordFinalResult($psid);
            sendMessage($psid,
                "عذرا ⚠️ يلزمك الاشتراك في باقة 100da 💰 (عشرة الاف) او اكثر ثم بعدها يمكنك الاستفادة من 2G 🎁 المجانية كل اسبوع طيلة شهر كامل 📆\n\n" .
                "🔴 ملاحظة 1️⃣: هذا التحديث من المتعامل جيزي ولا يمكن تجاوزه ⚠️\n🔴 ملاحظة 2️⃣: يلزمك عرض ابتداءا من 100da او اكثر 💰\n⚡ قناة التلقرام : https://t.me/tasjilbott"
            );
            clearSession($psid); sendMessage($psid, ""); return;
        }
        if ($httpCode === 201 || $httpCode === 200 || $innerStatus === 200) {
            $msgStr = $responseData['message'] ?? '';
            if (is_array($msgStr)) $msgStr = $msgStr['en'] ?? '';
            if (stripos($msgStr, 'successfully') !== false || $httpCode === 201 || $innerStatus === 200) {
                clearPending($psid); recordFinalResult($psid);
                sendMessage($psid, "⭐ تم تفعيل 2G بنجاح 🎁 للرقم {$displayMasked}\n\n⚡ قناة التلقرام : https://t.me/tasjilbott");
                sendMessage($psid, "");
                clearSession($psid);
                sendMessage($psid, "\n\n🥰 اذا كنت تريد دعمنا حتى نطور الخدمة ونستمر 🥰\n\n🔴 ادخل للموقع 👇\n\nhttps://timebucks.com/?refID=227870531\n\n✅ وسجل بحساب جوجل فقط 🥰\n\n🥹 ولا تنسَ متابعة حساب المطور 👇\nhttps://www.facebook.com/profile.php?id=100052854003446\n\nوشكراً ❤️");
                return;
            }
            usleep(1000000); continue;
        }
        if ($httpCode === 429) { usleep(2000000); continue; }
        usleep(1000000);
    }
    clearPending($psid);
    sendMessage($psid, "هناك اشكال في سيرفر جيزي ⚠️ لم نستطع التفعيل لرقمك \n\n⚡ قناة التلقرام : https://t.me/tasjilbott");
    clearSession($psid); sendMessage($psid, "");
}

// ════════════════════════════════════════════════════════════════════════════
// MGM — بداية عملية الدعوة
// ════════════════════════════════════════════════════════════════════════════
function handleInviteStart(string $psid, array $user): void
{
    $rl = checkRateLimit($psid);
    if ($rl !== null) { sendMessage($psid, rateLimitMessage($rl)); return; }

    $msisdn      = $user['msisdn'];
    $accessToken = $user['access_token'];

    sendMessage($psid, "🔍 جاري فحص المكافآت المعلقة...");
    $bonusResult = tryActivateMgmBonus($psid, $msisdn, $accessToken, $user);

    if ($bonusResult === 'SUCCESS_1GO') {
        recordFinalResult($psid);
        sendMessage($psid, "🎁 تم تفعيل مكافأة معلقة وحصلت على 1 جيقا 🎉\n\n⏳ عد بعد 24 ساعة للحصول على مكافأة جديدة 📆\n\n⚡ قناة التلقرام : https://t.me/tasjilbott");
        clearSession($psid);
        sendMessage($psid, "\n\n🥰 اذا كنت تريد دعمنا حتى نطور الخدمة ونستمر 🥰\n\n🔴 ادخل للموقع 👇\n\nhttps://timebucks.com/?refID=227870531\n\n✅ وسجل بحساب جوجل فقط 🥰\n\n🥹 ولا تنسَ متابعة حساب المطور 👇\nhttps://www.facebook.com/profile.php?id=100052854003446\n\nوشكراً ❤️");
        return;
    }
    if ($bonusResult === 'SUCCESS_500MO') {
        recordFinalResult($psid);
        sendMessage($psid, "🎁 تم تفعيل مكافأة معلقة وحصلت على 500Mo 🎉\n\n⏳ عد بعد 24 ساعة للحصول على مكافأة جديدة 📆\n\n⚡ قناة التلقرام : https://t.me/tasjilbott");
        clearSession($psid);
        sendMessage($psid, "\n\n🥰 اذا كنت تريد دعمنا حتى نطور الخدمة ونستمر 🥰\n\n🔴 ادخل للموقع 👇\n\nhttps://timebucks.com/?refID=227870531\n\n✅ وسجل بحساب جوجل فقط 🥰\n\n🥹 ولا تنسَ متابعة حساب المطور 👇\nhttps://www.facebook.com/profile.php?id=100052854003446\n\nوشكراً ❤️");
        return;
    }
    if ($bonusResult === 'ALREADY_CLAIMED') {
        recordFinalResult($psid);
        sendMessage($psid, "⚠️ لقد استفدت من الدعوة اليوم ولديك مكافأة أخرى معلقة.\nتأكد من مرور 24 ساعة على آخر استلام للمكافأة وأعد المحاولة 📆\n\n⚡ قناة التلقرام : https://t.me/tasjilbott");
        clearSession($psid); sendMessage($psid, ""); return;
    }

    sendMessage($psid, "🔍 جاري الفحص اذا كانت لديك دعوات متاحة ...");
    $invitations = fetchMgmInvitations($msisdn, $accessToken);
    if ($invitations === null) {
        sendMessage($psid, "❌ حدث خطأ أثناء جلب بيانات الدعوات، حاول مجدداً.");
        clearSession($psid); sendMessage($psid, ""); return;
    }

    $maxInvitations = $invitations['campaign']['maxInvitation'] ?? 5;
    $invitationList = $invitations['invitations'] ?? [];
    $doneCount = 0; $pendingCount = 0; $pendingIds = [];
    foreach ($invitationList as $inv) {
        if (($inv['status'] ?? '') === 'DONE')    $doneCount++;
        if (($inv['status'] ?? '') === 'PENDING') { $pendingCount++; $pendingIds[] = $inv['id'] ?? null; }
    }
    $totalCount = count($invitationList);

    if ($doneCount >= $maxInvitations) {
        recordFinalResult($psid);
        sendMessage($psid, "🚫 لقد وصلت للحد الأقصى لعدد الدعوات {$maxInvitations} ✅\n\n⚡ قناة التلقرام : https://t.me/tasjilbott");
        clearSession($psid); sendMessage($psid, ""); return;
    }
    if ($totalCount >= $maxInvitations && $pendingCount > 0) {
        sendMessage($psid, "🔄 جاري حذف الدعوات المعلقة لتوفير مكان...");
        $deleted = deletePendingInvitations($msisdn, $accessToken, $pendingIds);
        if (!$deleted) {
            sendMessage($psid, "❌ تعذر حذف الدعوات المعلقة، حاول لاحقاً.");
            clearSession($psid); sendMessage($psid, ""); return;
        }
        sendMessage($psid, "✅ تم حذف الدعوات المعلقة بنجاح.");
    }

    setSession($psid, [
        'state'         => 'awaiting_invite_phone',
        'msisdn'        => $msisdn,
        'access_token'  => $accessToken,
        'refresh_token' => $user['refresh_token'],
    ]);
    sendMessage($psid, "📲 أرسل رقم هاتف الشخص الذي تريد دعوته (جيزي فقط)\nمثال: 0770000000\n\n❌ لإلغاء العملية أرسل: 1");
}

function handleInvitePhoneInput(string $psid, string $text, array $session): void
{
    if (trim($text) === '1') {
        clearSession($psid); sendMessage($psid, "✅ تم إلغاء عملية الدعوة."); sendMenu($psid); return;
    }
    $rl = checkRateLimit($psid);
    if ($rl !== null) { sendMessage($psid, rateLimitMessage($rl)); return; }

    $digits = preg_replace('/\D/', '', $text);
    if (!preg_match('/^07\d{8}$/', $digits)) {
        sendMessage($psid, "❌ الرقم غير صحيح، أرسل رقم جيزي بصيغة 07xxxxxxxx\n\n❌ لإلغاء العملية أرسل: 1");
        return;
    }

    $receiverMsisdn = '213' . substr($digits, 1);
    $senderMsisdn   = $session['msisdn'];
    if ($receiverMsisdn === $senderMsisdn) {
        sendMessage($psid, "❌ لا يمكنك دعوة رقمك الخاص!\n\nأرسل رقم شخص آخر.\n\n❌ لإلغاء العملية أرسل: 1");
        return;
    }

    $accessToken  = $session['access_token'];
    $refreshToken = $session['refresh_token'];
    sendMessage($psid, "📤 جاري إرسال الدعوة...");
    $result = sendMgmInvitation($senderMsisdn, $receiverMsisdn, $accessToken, $refreshToken, $psid);

    switch ($result['status']) {
        case 'SUCCESS':
            recordFinalResult($psid);
            sendMessage($psid,
                "✅ تم إرسال الدعوة بنجاح إلى الرقم 0" . substr($receiverMsisdn, 3) . " 🎉\n\n📲 تم إرسال رسالة نصية إلى الرقم المدعو.\nسيتم الآن تفعيل مكافأتك بعد تسجيل المدعو...\n\n🔢 الرجاء إدخال رمز التحقق الذي وصل لرقم المدعو:\n\n❌ لإلغاء العملية أرسل: 1"
            );
            if (sendDjezzyOTP($receiverMsisdn)) {
                setSession($psid, [
                    'state'          => 'awaiting_invitee_otp',
                    'msisdn'         => $senderMsisdn,
                    'access_token'   => $result['access_token'] ?? $accessToken,
                    'refresh_token'  => $result['refresh_token'] ?? $refreshToken,
                    'invitee_msisdn' => $receiverMsisdn,
                ]);
            } else {
                sendMessage($psid, "⚠️ تعذر إرسال رمز التحقق للمدعو. حاول لاحقاً.");
                clearSession($psid); sendMessage($psid, "");
            }
            break;
        case 'MAX_INVITATIONS':
            recordFinalResult($psid);
            sendMessage($psid, "🚫 لقد وصلت للحد الأقصى لعدد الدعوات 5 ✅\n\n⚡ قناة التلقرام : https://t.me/tasjilbott");
            clearSession($psid); sendMessage($psid, ""); break;
        case 'ALREADY_INVITED':
            sendMessage($psid, "⚠️ لقد تمت دعوة هذا الرقم من قبل، استخدم رقماً آخر.\n❌ لإلغاء العملية أرسل: 1"); break;
        case 'CUSTOMER_NOT_EXIST':
        case 'INVALID_NUMBER':
            sendMessage($psid, "❌ الرقم المدرج غير موجود أو غير نشط، تأكد من الرقم وأعد المحاولة.\n❌ لإلغاء العملية أرسل: 1"); break;
        case 'TOKEN_EXPIRED':
            sendMessage($psid, "🔄 انتهت صلاحية الجلسة، الرجاء إعادة إرسال رقمك للتسجيل.");
            clearSession($psid); sendMessage($psid, ""); break;
        default:
            sendMessage($psid, "❌ حدث خطأ غير متوقع، حاول لاحقاً.");
            clearSession($psid); sendMessage($psid, ""); break;
    }
}

function handleInviteeOtp(string $psid, string $text, array $session): void
{
    if (trim($text) === '1') {
        clearSession($psid); sendMessage($psid, "✅ تم إلغاء عملية الدعوة."); sendMenu($psid); return;
    }
    if (!preg_match('/\b(\d{6})\b/', $text, $m)) {
        sendMessage($psid, "⚠️ الرجاء إدخال رمز التحقق المكوّن من 6 أرقام.\n\n❌ لإلغاء العملية أرسل: 1"); return;
    }
    $rl = checkRateLimit($psid);
    if ($rl !== null) { sendMessage($psid, rateLimitMessage($rl)); return; }

    $inviteeMsisdn = $session['invitee_msisdn'];
    $senderMsisdn  = $session['msisdn'];
    $senderToken   = $session['access_token'];

    sendMessage($psid, "🔐 جاري التحقق من الرمز...");
    $inviteeResult = verifyOTP($inviteeMsisdn, $m[1]);
    if ($inviteeResult === 'wrong_otp') {
        sendMessage($psid, "❌ الرمز خاطئ، أعد إرسال الرمز الصحيح.\n\n❌ لإلغاء العملية أرسل: 1"); return;
    }
    if ($inviteeResult === false) {
        sendMessage($psid, "❌ حدث خطأ أثناء التحقق، حاول مجدداً.");
        clearSession($psid); sendMessage($psid, ""); return;
    }
    $inviteeToken = $inviteeResult['access_token'];
    sendMessage($psid, "🎁 تم التحقق بنجاح! جاري تفعيل المكافآت...");

    $senderBonus  = activateMgmReward($senderMsisdn, $senderToken, 'MGMBONUS1Go');
    $inviteeBonus = activateMgmReward($inviteeMsisdn, $inviteeToken, 'MGMBONUS500Mo');

    $senderMsg = match($senderBonus) {
        'SUCCESS'          => "✅ مكافأتك (1 جيقا) تم تفعيلها بنجاح 🎉",
        'ALREADY_CLAIMED'  => "⚠️ مكافأتك محجوزة، تأكد من مرور 24 ساعة وأعد المحاولة.",
        'REWARD_NOT_EXIST' => "❌ لا توجد مكافأة متاحة لرقمك حالياً.",
        default            => "⚠️ تعذر تفعيل مكافأتك مؤقتاً.",
    };
    $inviteeMsg = match($inviteeBonus) {
        'SUCCESS'          => "✅ مكافأة المدعو (500Mo) تم تفعيلها بنجاح 🎉",
        'ALREADY_CLAIMED'  => "⚠️ مكافأة الرقم المدعو محجوزة، تأكد من مرور 24 ساعة وأعد المحاولة.",
        'REWARD_NOT_EXIST' => "❌ لا توجد مكافأة متاحة للمدعو حالياً.",
        default            => "⚠️ تعذر تفعيل مكافأة المدعو مؤقتاً.",
    };
    recordFinalResult($psid);
    sendMessage($psid, "📊 نتيجة تفعيل المكافآت:\n\n👤 أنت (الداعي):\n{$senderMsg}\n\n👤 المدعو:\n{$inviteeMsg}\n\n⚡ قناة التلقرام : https://t.me/tasjilbott");
    clearSession($psid);
    sendMessage($psid, "\n\n🥰 اذا كنت تريد دعمنا حتى نطور الخدمة ونستمر 🥰\n\n🔴 ادخل للموقع 👇\n\nhttps://timebucks.com/?refID=227870531\n\n✅ وسجل بحساب جوجل فقط 🥰\n\n🥹 ولا تنسَ متابعة حساب المطور 👇\nhttps://www.facebook.com/profile.php?id=100052854003446\n\nوشكراً ❤️");
}

// ════════════════════════════════════════════════════════════════════════════
// MGM API Calls
// ════════════════════════════════════════════════════════════════════════════
function fetchMgmInvitations(string $msisdn, string $accessToken): ?array
{
    $url     = "https://apim.djezzy.dz/mobile-api/api/v1/services/mgm/invitations/{$msisdn}";
    $headers = ['Accept: application/json', "Authorization: Bearer {$accessToken}", 'User-Agent: MobileApp/3.0.0', 'accept-language: ar'];
    $raw     = curlWithAllProxies($url, 'GET', '', $headers, 'MGM_FETCH', 12);
    if ($raw === null) return null;
    $json = $raw['json'];
    if (is_array($json) && ($json['status'] ?? 0) == 200) return $json['data'] ?? [];
    return null;
}

function deletePendingInvitations(string $msisdn, string $accessToken, array $pendingIds): bool
{
    $url     = "https://apim.djezzy.dz/mobile-api/api/v1/services/mgm/delete-invitation/{$msisdn}";
    $headers = ['Content-Type: application/json', 'Accept: application/json', 'Accept-Encoding: gzip', "Authorization: Bearer {$accessToken}", 'User-Agent: MobileApp/3.0.0', 'accept-language: ar'];
    $success = false;
    foreach ($pendingIds as $id) {
        if ($id === null) continue;
        $raw = curlWithAllProxies($url, 'POST', json_encode(['invitationId' => $id]), $headers, 'MGM_DEL', 10);
        if ($raw && ($raw['http_code'] === 200 || $raw['http_code'] === 201)) $success = true;
    }
    return $success;
}

function sendMgmInvitation(string $senderMsisdn, string $receiverMsisdn, string $accessToken, string $refreshToken, string $psid): array
{
    $url     = "https://apim.djezzy.dz/mobile-api/api/v1/services/mgm/send-invitation/{$senderMsisdn}";
    $payload = json_encode(['msisdnReciever' => $receiverMsisdn]);
    $headers = ['Content-Type: application/json', 'Accept: application/json', 'Accept-Encoding: gzip', 'accept-language: ar', "Authorization: Bearer {$accessToken}", 'User-Agent: MobileApp/3.0.0'];

    $maxTokenRefresh = 3; $tokenRefreshCount = 0;

    for ($attempts = 0; $attempts < 10; $attempts++) {
        $hdrs = $headers;
        foreach ($hdrs as &$h) {
            if (str_starts_with($h, 'Authorization:')) $h = "Authorization: Bearer {$accessToken}";
        }
        unset($h);

        $raw = curlWithAllProxies($url, 'POST', $payload, $hdrs, 'MGM_INVITE', 10);
        if ($raw === null) { usleep(1000000); continue; }

        $httpCode = $raw['http_code'];
        $json     = $raw['json'];
        dbg("[MGM_INVITE] http={$httpCode}");

        if (!is_array($json)) { usleep(1000000); continue; }

        $fault = $json['fault'] ?? null;
        if ($fault && (int)($fault['code'] ?? 0) === 900901) {
            if ($tokenRefreshCount >= $maxTokenRefresh) return ['status' => 'TOKEN_EXPIRED'];
            $tokenRefreshCount++;
            $refreshed = refreshAccessToken($refreshToken, $senderMsisdn, $psid);
            if ($refreshed === 'expired') return ['status' => 'TOKEN_EXPIRED'];
            if ($refreshed === false) return ['status' => 'TOKEN_EXPIRED'];
            $accessToken = $refreshed['access_token']; $refreshToken = $refreshed['refresh_token'];
            continue;
        }
        $msgField = $json['message'] ?? '';
        $arMsg    = is_array($msgField) ? ($msgField['ar'] ?? '') : (string)$msgField;
        if ($httpCode === 201 && str_contains($arMsg, 'تمت العملية بنجاح')) return ['status' => 'SUCCESS', 'access_token' => $accessToken, 'refresh_token' => $refreshToken];
        if ($httpCode === 400 && str_contains($arMsg, 'وصلت إلى الحد الأقصى')) return ['status' => 'MAX_INVITATIONS'];
        if (str_contains($arMsg, 'تمت دعوة هذا المستلم') || str_contains($arMsg, 'هذه العملية غير متوفرة')) return ['status' => 'ALREADY_INVITED'];
        if (str_contains($arMsg, 'العميل غير موجود')) return ['status' => 'CUSTOMER_NOT_EXIST'];
        if (str_contains($arMsg, 'غير نشط أو غير صالح')) return ['status' => 'INVALID_NUMBER'];
        usleep(1000000);
    }
    return ['status' => 'ERROR'];
}

function activateMgmReward(string $msisdn, string $accessToken, string $packageCode): string
{
    $url     = "https://apim.djezzy.dz/mobile-api/api/v1/services/mgm/activate-reward/{$msisdn}";
    $payload = json_encode(['packageCode' => $packageCode]);
    $headers = ['Content-Type: application/json', 'Accept: application/json', 'Accept-Encoding: gzip', 'accept-language: ar', "Authorization: Bearer {$accessToken}", 'User-Agent: MobileApp/3.0.0'];

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $raw = curlWithAllProxies($url, 'POST', $payload, $headers, "MGM_REWARD:{$packageCode}:#{$attempt}", 15);
        if ($raw === null) { usleep(500000); continue; }

        $httpCode = $raw['http_code'];
        $json     = $raw['json'];
        dbg("[MGM_REWARD:{$packageCode}] attempt={$attempt} http={$httpCode}");

        if ($httpCode === 200 || $httpCode === 201) {
            if (is_array($json)) {
                $msg    = $json['message'] ?? '';
                $msgStr = is_array($msg) ? ($msg['en'] ?? ($msg['ar'] ?? '')) : (string)$msg;
                $status = (int)($json['status'] ?? 0);
                if (stripos($msgStr, 'successfully') !== false || stripos($msgStr, 'تم') !== false || $httpCode === 201 || $status === 200 || $status === 201 || empty($msgStr)) return 'SUCCESS';
            } else { return 'SUCCESS'; }
        }
        if (!is_array($json)) { usleep(500000); continue; }
        if ($httpCode === 404) {
            $msg  = $json['message'] ?? '';
            $msgEn = is_array($msg) ? ($msg['en'] ?? '') : (string)$msg;
            if (stripos($msgEn, 'Eligibility not found') !== false || stripos($msgEn, 'eligibility') !== false) return 'ALREADY_CLAIMED';
            return 'REWARD_NOT_EXIST';
        }
        if ($httpCode === 400) {
            $msg  = $json['message'] ?? '';
            $msgAr = is_array($msg) ? ($msg['ar'] ?? '') : (string)$msg;
            $msgEn = is_array($msg) ? ($msg['en'] ?? '') : (string)$msg;
            if (str_contains($msgAr, 'تعذر معالجة طلبك') || str_contains($msgAr, 'لم تمر') || stripos($msgEn, 'cannot be processed') !== false) return 'ALREADY_CLAIMED';
        }
        if ($httpCode === 401) return 'ERROR';
        usleep(500000);
    }
    return 'ERROR';
}

function tryActivateMgmBonus(string $psid, string $msisdn, string $accessToken, array $user): string
{
    $r1 = activateMgmReward($msisdn, $accessToken, 'MGMBONUS1Go');
    if ($r1 === 'SUCCESS')          return 'SUCCESS_1GO';
    if ($r1 === 'ALREADY_CLAIMED')  return 'ALREADY_CLAIMED';
    if ($r1 === 'REWARD_NOT_EXIST') {
        $r2 = activateMgmReward($msisdn, $accessToken, 'MGMBONUS500Mo');
        if ($r2 === 'SUCCESS')         return 'SUCCESS_500MO';
        if ($r2 === 'ALREADY_CLAIMED') return 'ALREADY_CLAIMED';
        return 'REWARD_NOT_EXIST';
    }
    return 'ERROR';
}

// ════════════════════════════════════════════════════════════════════════════
// fetchSubscriptionHistory
// ════════════════════════════════════════════════════════════════════════════
function fetchSubscriptionHistory(string $msisdn, string $accessToken): ?array
{
    $url     = "https://apim.djezzy.dz/mobile-api/api/v1/subscribers/subscription-history/{$msisdn}";
    $headers = ['Accept: application/json', "Authorization: Bearer {$accessToken}", 'User-Agent: MobileApp/3.0.0', 'Connection: Keep-Alive', 'Accept-Language: fr'];
    $raw     = curlWithAllProxies($url, 'GET', '', $headers, 'SUB_HISTORY', 12);
    if ($raw === null) return null;
    $json = $raw['json'];
    if (is_array($json) && ($json['status'] ?? 0) == 200) return $json['data'] ?? [];
    return null;
}
function getLastWalkWinDate(array $history): ?int
{
    $latest = null;
    foreach ($history as $item) {
        $code = $item['packageCode'] ?? '';
        if (in_array($code, ['GIFTWALKWIN2GO', 'GIFTWALKWIN1GO'])) {
            $dt = $item['subscriptionDateTime'] ?? null;
            if ($dt) {
                $ts = strtotime($dt);
                if ($ts !== false && ($latest === null || $ts > $latest)) $latest = $ts;
            }
        }
    }
    return $latest;
}
function formatTimeRemaining(int $secondsLeft): string
{
    if ($secondsLeft <= 0) return "0 ثانية";
    $days    = (int)($secondsLeft / 86400);
    $hours   = (int)(($secondsLeft % 86400) / 3600);
    $minutes = (int)(($secondsLeft % 3600) / 60);
    $secs    = $secondsLeft % 60;
    $parts   = [];
    if ($days > 0)    $parts[] = "{$days} يوم";
    if ($hours > 0)   $parts[] = "{$hours} ساعة";
    if ($minutes > 0) $parts[] = "{$minutes} دقيقة";
    if ($secs > 0 && $days === 0 && $hours === 0) $parts[] = "{$secs} ثانية";
    return implode(' و', $parts);
}

// ════════════════════════════════════════════════════════════════════════════
// ملصقات عشوائية للرد على المرفقات بدون نص
// ════════════════════════════════════════════════════════════════════════════
function randomSticker(): string
{
    $stickers = ['🙎🏻', '🛌🏻', '😝', '🙃', '😶', '👽', '🫠', '🤨', '🫣', '🙋🏻'];
    return $stickers[array_rand($stickers)];
}

// ════════════════════════════════════════════════════════════════════════════
// Token Refresh — credentials قديمة
// ════════════════════════════════════════════════════════════════════════════
function refreshAccessToken(string $refreshToken, string $msisdn, string $psid): mixed
{
    $allProxies = getAllProxies();
    $maxAttempts = count($allProxies) * 3;
    for ($i = 0; $i < $maxAttempts; $i++) {
        $pp     = parseProxy($allProxies[$i % count($allProxies)]);
        $result = refreshTokenRequest($refreshToken, $pp['host'], $pp['userpass']);
        if ($result === 'expired') {
            sendMessage($psid, "🔄 انتهت صلاحية الجلسة، سيتم إرسال رمز تحقق جديد...");
            sendOTPAndWait($psid, $msisdn, '0' . substr($msisdn, 3));
            return 'expired'; // تم إعلام المستخدم وإرسال رمز جديد بالفعل — على المستدعي التوقف بصمت
        }
        if ($result === 'html' || $result === false) { usleep(300000); continue; }
        saveUser($psid, array_merge(getUser($psid) ?? [], ['access_token' => $result['access_token'], 'refresh_token' => $result['refresh_token']]));
        return $result;
    }
    return false; // خطأ شبكة/بروكسي مؤقت — لم يتم إعلام المستخدم بشيء بعد
}
function refreshTokenRequest(string $refreshToken, string $proxyHost, string $proxyAuth): mixed
{
    $r = djezzyCurl('https://apim.djezzy.dz/oauth2/token',
        http_build_query(['scope' => 'djezzyAppV2', 'client_secret' => CLIENT_SECRET_OLD, 'client_id' => CLIENT_ID_OLD, 'grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]),
        $proxyHost, $proxyAuth, 'refresh');
    if ($r === 'html' || $r === false) return $r;
    $json = @json_decode($r['body'], true);
    if ($r['code'] === 400 && ($json['error'] ?? '') === 'invalid_grant') return 'expired';
    if ($r['code'] === 200 && isset($json['access_token'])) return ['access_token' => $json['access_token'], 'refresh_token' => $json['refresh_token'] ?? $refreshToken];
    return false;
}

// ════════════════════════════════════════════════════════════════════════════
// Token Refresh — credentials جديدة
// ════════════════════════════════════════════════════════════════════════════
function refreshAccessTokenNew(string $refreshToken, string $msisdn, string $psid, string $packageCode): mixed
{
    $allProxies  = getAllProxies();
    $maxAttempts = count($allProxies) * 3;
    for ($i = 0; $i < $maxAttempts; $i++) {
        $pp     = parseProxy($allProxies[$i % count($allProxies)]);
        $result = refreshTokenRequestNew($refreshToken, $pp['host'], $pp['userpass']);
        if ($result === 'expired') {
            sendMessage($psid, "🔄 انتهت صلاحية الجلسة، سيتم إرسال رمز تحقق جديد...");
            sendNewOTPAndWaitForOffer($psid, $msisdn, '0' . substr($msisdn, 3), $packageCode);
            return 'expired';
        }
        if ($result === 'html' || $result === false) { usleep(300000); continue; }
        return $result;
    }
    return false;
}
function refreshTokenRequestNew(string $refreshToken, string $proxyHost, string $proxyAuth): mixed
{
    $r = djezzyCurl('https://apim.djezzy.dz/oauth2/token',
        http_build_query(['scope' => 'djezzyAppV2', 'client_secret' => CLIENT_SECRET_NEW, 'client_id' => CLIENT_ID_NEW, 'grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]),
        $proxyHost, $proxyAuth, 'refresh_new');
    if ($r === 'html' || $r === false) return $r;
    $json = @json_decode($r['body'], true);
    if ($r['code'] === 400 && ($json['error'] ?? '') === 'invalid_grant') return 'expired';
    if ($r['code'] === 200 && isset($json['access_token'])) return ['access_token' => $json['access_token'], 'refresh_token' => $json['refresh_token'] ?? $refreshToken];
    return false;
}

// ════════════════════════════════════════════════════════════════════════════
// OTP / Token — قديم
// ════════════════════════════════════════════════════════════════════════════
function sendDjezzyOTP(string $msisdn): bool
{
    $q = http_build_query(['scope' => 'smsotp', 'client_id' => CLIENT_ID_OLD, 'msisdn' => $msisdn]);
    foreach (getAllProxies() as $p) {
        $pp = parseProxy($p);
        if (djezzyCurl('https://apim.djezzy.dz/oauth2/registration', $q, $pp['host'], $pp['userpass'], 'otp') === true) return true;
    }
    return false;
}
function verifyOTP(string $msisdn, string $otp): mixed
{
    foreach (getAllProxies() as $p) {
        $pp  = parseProxy($p);
        $res = djezzyTokenReq($msisdn, $otp, $pp['host'], $pp['userpass']);
        if ($res === 'wrong_otp') return 'wrong_otp';
        if (is_array($res)) return $res;
    }
    return false;
}
function djezzyTokenReq(string $msisdn, string $otp, string $ph, string $pa): mixed
{
    $r = djezzyCurl('https://apim.djezzy.dz/oauth2/token',
        http_build_query(['scope' => 'djezzyAppV2', 'client_secret' => CLIENT_SECRET_OLD, 'client_id' => CLIENT_ID_OLD, 'otp' => $otp, 'mobileNumber' => $msisdn, 'grant_type' => 'mobile']),
        $ph, $pa, 'token');
    if ($r === 'html' || $r === false) return false;
    $json = @json_decode($r['body'], true);
    if ($r['code'] === 400 && ($json['error'] ?? '') === 'invalid_grant') return 'wrong_otp';
    if ($r['code'] === 200 && isset($json['access_token'])) return ['access_token' => $json['access_token'], 'refresh_token' => $json['refresh_token'] ?? ''];
    return false;
}

// ════════════════════════════════════════════════════════════════════════════
// OTP / Token — جديد
// ════════════════════════════════════════════════════════════════════════════
function sendDjezzyOTPNew(string $msisdn): bool
{
    $q = http_build_query(['scope' => 'smsotp', 'client_id' => CLIENT_ID_NEW, 'msisdn' => $msisdn]);
    foreach (getAllProxies() as $p) {
        $pp = parseProxy($p);
        if (djezzyCurl('https://apim.djezzy.dz/oauth2/registration', $q, $pp['host'], $pp['userpass'], 'otp_new') === true) return true;
    }
    return false;
}
function verifyOTPNew(string $msisdn, string $otp): mixed
{
    foreach (getAllProxies() as $p) {
        $pp  = parseProxy($p);
        $res = djezzyTokenReqNew($msisdn, $otp, $pp['host'], $pp['userpass']);
        if ($res === 'wrong_otp') return 'wrong_otp';
        if (is_array($res)) return $res;
    }
    return false;
}
function djezzyTokenReqNew(string $msisdn, string $otp, string $ph, string $pa): mixed
{
    $r = djezzyCurl('https://apim.djezzy.dz/oauth2/token',
        http_build_query(['scope' => 'djezzyAppV2', 'client_secret' => CLIENT_SECRET_NEW, 'client_id' => CLIENT_ID_NEW, 'otp' => $otp, 'mobileNumber' => $msisdn, 'grant_type' => 'mobile']),
        $ph, $pa, 'token_new');
    if ($r === 'html' || $r === false) return false;
    $json = @json_decode($r['body'], true);
    if ($r['code'] === 400 && ($json['error'] ?? '') === 'invalid_grant') return 'wrong_otp';
    if ($r['code'] === 200 && isset($json['access_token'])) return ['access_token' => $json['access_token'], 'refresh_token' => $json['refresh_token'] ?? ''];
    return false;
}

// ════════════════════════════════════════════════════════════════════════════
// djezzyCurl — مشترك
// ════════════════════════════════════════════════════════════════════════════
function djezzyCurl(string $url, string $data, string $ph, string $pa, string $tag): mixed
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $data,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded', 'Accept: */*', 'User-Agent: Dalvik/2.1.0 (Linux; U; Android 6.0; PGN610 Build/MRA58K)', 'Connection: Keep-Alive', 'Accept-Encoding: gzip'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING       => 'gzip',
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_PROXY          => $ph,
        CURLOPT_PROXYUSERPWD   => $pa,
        CURLOPT_PROXYTYPE      => CURLPROXY_HTTP,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    file_put_contents('/tmp/djezzy.log', date('Y-m-d H:i:s') . " [$tag] CODE:$code ERR:$err BODY:" . substr((string)$body, 0, 400) . "\n", FILE_APPEND);
    if ($err || $body === false) return false;
    if (stripos((string)$body, '<!DOCTYPE') !== false || stripos((string)$body, '<html') !== false) return 'html';
    if (str_starts_with($tag, 'otp')) return ($code >= 200 && $code < 300) ? true : false;
    return ['code' => $code, 'body' => (string)$body];
}

// ════════════════════════════════════════════════════════════════════════════
// Session / User / PhoneMap
// ════════════════════════════════════════════════════════════════════════════
function getSession(string $p): array  { $f = SESSIONS_DIR . "/$p.json"; return file_exists($f) ? (json_decode(file_get_contents($f), true) ?? []) : []; }
function setSession(string $p, array $d): void { file_put_contents(SESSIONS_DIR . "/$p.json", json_encode($d)); }
function clearSession(string $p): void { $f = SESSIONS_DIR . "/$p.json"; if (file_exists($f)) unlink($f); }
function saveUser(string $p, array $d): void { file_put_contents(USERS_DIR . "/$p.json", json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); }
function getUser(string $p): ?array { $f = USERS_DIR . "/$p.json"; return file_exists($f) ? json_decode(file_get_contents($f), true) : null; }
function savePhoneOwner(string $m, string $p): void { $map = file_exists(PHONE_MAP_FILE) ? (json_decode(file_get_contents(PHONE_MAP_FILE), true) ?? []) : []; $map[$m] = $p; file_put_contents(PHONE_MAP_FILE, json_encode($map)); }
function getPhoneOwner(string $m): ?string { if (!file_exists(PHONE_MAP_FILE)) return null; return (json_decode(file_get_contents(PHONE_MAP_FILE), true) ?? [])[$m] ?? null; }

// ════════════════════════════════════════════════════════════════════════════
// Messenger UI
// ════════════════════════════════════════════════════════════════════════════
function sendWelcomeNew(string $psid): void
{
    sendMessage($psid,
        "👋 أهلاً وسهلاً بك في Tasjil BOT! 🎉\n\n🌟 نرحب بك كمستخدم جديد!\n\n📌 مزايا البوت:\n\n✅ تفعيل 2G الأسبوعية 🎁\n✅ إرسال الدعوات 📨\n✅ تفعيل عرض 4GB بـ 70دج 🏷️\n✅ جميع عروض الإنترنت متوفرة ⭐\n\n━━━━━━━━━━━━━━\n\n📱 للبدء، أرسل رقم هاتفك (جيزي)\n🔹 مثال: 0770000000\n\n⚡ قناة التلغرام:\nhttps://t.me/tasjilbott"
    );
}
function sendWelcome(string $psid): void { sendMessage($psid, "يرجى ارسال أرقام هواتف فقط 📱\n"); }

function sendMenu(string $psid): void
{
    setSession($psid, array_merge(getSession($psid), ['state' => 'menu']));
    $cfg = getMatchGiftConfig();
    $extraRow = $cfg['enabled'] ? [['content_type' => 'text', 'title' => '🇩🇿 هدية المباراة', 'payload' => 'ACTIVATE_ALGERIA_MATCH']] : [];

    fbApiCall(json_encode([
        'recipient'      => ['id' => $psid],
        'messaging_type' => 'RESPONSE',
        'message'        => [
            'text' =>
                "📱 اختر العرض المناسب\n\n📌 إذا لم تظهر لك الأزرار أرسل الرقم المناسب 👇\n\n━━━━━━━━━━━━━━\n\n" .
                "1️⃣ لتفعيل 2G الأسبوعية\n📩 أرسل: 1\n\n" .
                "2️⃣ لتفعيل عرض 4GB بـ 70دج 🏷️\n📩 أرسل: 2\n\n" .
                "3️⃣ لإرسال دعوة 🎁\n📩 أرسل: 3\n\n" .
                "4️⃣ للمزيد من العروض 📦\n📩 أرسل: 4\n\n━━━━━━━━━━━━━━\n\n" .
                ($cfg['enabled'] ? "🇩🇿 هدية المباراة متاحة! أرسل: 30\n\n" : ""),
            'quick_replies' => array_merge([
                ['content_type' => 'text', 'title' => '📶 تفعيل 2G',         'payload' => 'MENU_2G'],
                ['content_type' => 'text', 'title' => '💰 عرض 70دج - 4جيقا', 'payload' => 'MENU_70DZ'],
                ['content_type' => 'text', 'title' => '📨 إرسال دعوة',        'payload' => 'MENU_INVITE'],
                ['content_type' => 'text', 'title' => '📦 المزيد من العروض',  'payload' => 'MENU_MORE_OFFERS'],
            ], $extraRow),
        ],
    ], JSON_UNESCAPED_UNICODE));
}

function sendMoreOffers(string $psid): void
{
    setSession($psid, array_merge(getSession($psid), ['state' => 'offers']));
    $text  = "📦 قائمة عروض الإنترنت المتوفرة 📦\n\n";
    $text .= "━━━━━━━━━━━ 📅 العروض اليومية ━━━━━━━━━━━\n\n";
    $text .= "5️⃣ 5GB 🔥\n🌐 الانترنت : 5GB\n💰 السعر: 90 دج\n⏳ المدة: 24 ساعة\n📩 للتفعيل أرسل: 5\n\n\n\n";
    $text .= "6️⃣ 300MB\n🌐 الانترنت : 300Mo\n💰 السعر: 30 دج\n⏳ المدة: 24 ساعة\n📩 للتفعيل أرسل: 6\n\n\n\n";
    $text .= "7️⃣ 600MB\n🌐 الانترنت : 600Mo\n💰 السعر: 50 دج\n⏳ المدة: 24 ساعة\n📩 للتفعيل أرسل: 7\n\n\n\n";
    $text .= "8️⃣ 2GB\n🌐 الانترنت : 2G\n💰 السعر: 100 دج\n⏳ المدة: 24 ساعة\n📩 للتفعيل أرسل: 8\n\n\n\n";
    $text .= "9️⃣ 1GB\n🌐 الانترنت : 1G\n💰 السعر: 50 دج\n⏳ المدة: 24 ساعة\n📩 للتفعيل أرسل: 9\n\n\n\n";
    $text .= "🔟 4GB 🏷️\n🌐 الانترنت : 4G\n💰 السعر: 70 دج\n⏳ المدة: 24 ساعة\n📩 للتفعيل أرسل: 10\n\n\n\n";
    $text .= "1️⃣1️⃣ 5GB\n🌐 الانترنت : 5G\n💰 السعر: 190 دج\n⏳ المدة: 24 ساعة\n📩 للتفعيل أرسل: 11\n\n\n\n";
    $text .= "1️⃣2️⃣ 4GB\n🌐 الانترنت : 4G\n💰 السعر: 140 دج\n⏳ المدة: 24 ساعة\n📩 للتفعيل أرسل: 12\n\n\n\n";
    $text .= "━━━━━━━━━━━ 📆 العروض الأسبوعية ━━━━━━━━━━━\n\n";
    $text .= "1️⃣3️⃣ 4GB\n🌐 الانترنت : 4G\n💰 السعر: 150 دج\n⏳ المدة: 7 أيام\n📩 للتفعيل أرسل: 13\n\n\n\n";
    $text .= "1️⃣4️⃣ 10GB\n🌐 الانترنت : 10G\n💰 السعر: 300 دج\n⏳ المدة: 7 أيام\n📩 للتفعيل أرسل: 14\n\n\n\n";
    $text .= "1️⃣5️⃣ 4GB\n🌐 الانترنت : 4G\n💰 السعر: 400 دج\n⏳ المدة: 15 يوم\n📩 للتفعيل أرسل: 15\n\n\n\n";
    $text .= "1️⃣6️⃣ 1GB Facebook 📘\n🌐 الانترنت : 1G فيسبوك فقط\n💰 السعر: 70 دج\n⏳ المدة: 3 أيام\n📩 للتفعيل أرسل: 16\n\n\n\n";
    $text .= "━━━━━━━━━━━ 🗓️ العروض الشهرية ━━━━━━━━━━━\n\n";
    $text .= "1️⃣7️⃣ 12GB\n🌐 الانترنت : 12G\n💰 السعر: 500 دج\n⏳ المدة: 30 يوم\n📩 للتفعيل أرسل: 17\n\n\n\n";
    $text .= "1️⃣8️⃣ 30GB\n🌐 الانترنت : 30G\n💰 السعر: 1000 دج\n⏳ المدة: 30 يوم\n📩 للتفعيل أرسل: 18\n\n\n\n";
    $text .= "1️⃣9️⃣ 60GB\n🌐 الانترنت : 60G\n💰 السعر: 1500 دج\n⏳ المدة: 30 يوم\n📩 للتفعيل أرسل: 19\n\n\n\n";
    $text .= "2️⃣0️⃣ 3GB\n🌐 الانترنت : 3G\n💰 السعر: 250 دج\n⏳ المدة: 30 يوم\n📩 للتفعيل أرسل: 20\n\n\n\n";
    $text .= "━━━━━━━━━━━ ⚡ العروض الخاصة ━━━━━━━━━━━\n\n";
    $text .= "2️⃣1️⃣ 1GB سريع ⚡\n🌐 الانترنت : 1G\n💰 السعر: 40 دج\n⏳ المدة: 1 ساعة\n📩 للتفعيل أرسل: 21\n\n\n\n";
    $text .= "2️⃣2️⃣ Facebook غير محدود 📘\n🌐 الانترنت : فيسبوك فقط غير محدود\n💰 السعر: 50 دج\n⏳ المدة: 4 ساعات\n📩 للتفعيل أرسل: 22\n\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━━━\n\n📨 أرسل رقم العرض فقط لتفعيله مباشرة";

    fbApiCall(json_encode([
        'recipient'      => ['id' => $psid],
        'messaging_type' => 'RESPONSE',
        'message'        => [
            'text'          => $text,
            'quick_replies' => [
                ['content_type' => 'text', 'title' => '5 - 5GB 90دج 🔥',   'payload' => 'ACTIVATE_OFFER_BTL500MBDAY'],
                ['content_type' => 'text', 'title' => '6 - 300Mo 30دج',    'payload' => 'ACTIVATE_OFFER_DOVINTSPEEDDAY100MoPRE'],
                ['content_type' => 'text', 'title' => '7 - 600Mo 50دج',    'payload' => 'ACTIVATE_OFFER_DOVINTSPEEDDAY250MoPRE'],
                ['content_type' => 'text', 'title' => '8 - 2Go 100دج',     'payload' => 'ACTIVATE_OFFER_DOVINTSPEEDDAY1GoPRE'],
                ['content_type' => 'text', 'title' => '9 - 1Go 50دج',      'payload' => 'ACTIVATE_OFFER_OFFREJEUNE50'],
                ['content_type' => 'text', 'title' => '10 - 4GB 70دج',     'payload' => 'ACTIVATE_OFFER_BTLINTSPEEDDAY2Go'],
                ['content_type' => 'text', 'title' => '11 - 5GB 190دج',    'payload' => 'ACTIVATE_OFFER_BTL4GBDAY'],
                ['content_type' => 'text', 'title' => '13 - 4Go 150دج',    'payload' => 'ACTIVATE_OFFER_DOVINTSPEEDWEEK2GoPRE'],
                ['content_type' => 'text', 'title' => '14 - 10Go 300دج',   'payload' => 'ACTIVATE_OFFER_DOVINTSPEEDWEEK3GoPRE'],
                ['content_type' => 'text', 'title' => '17 - 12Go 500دج',   'payload' => 'ACTIVATE_OFFER_DOVINTSPEEDMONTH6GoPRE'],
                ['content_type' => 'text', 'title' => '18 - 30Go 1000دج',  'payload' => 'ACTIVATE_OFFER_DOVINTSPEEDMONTH15GoPRE'],
                ['content_type' => 'text', 'title' => '21 - 1GB 40دج⚡',   'payload' => 'ACTIVATE_OFFER_BTL500MBHOUR'],
                ['content_type' => 'text', 'title' => '🔙 رجوع للقائمة',   'payload' => 'BACK_MENU'],
            ],
        ],
    ], JSON_UNESCAPED_UNICODE));
}

// ════════════════════════════════════════════════════════════════════════════
// Facebook Send Helpers
// ════════════════════════════════════════════════════════════════════════════

/**
 * sendFbMessage — يُعيد true/false بدل void لدعم البث
 */
function sendFbMessage(string $psid, string $text): bool
{
    $ch = curl_init('https://graph.facebook.com/v19.0/me/messages?access_token=' . FB_TOKEN);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['recipient' => ['id' => $psid], 'message' => ['text' => $text], 'messaging_type' => 'RESPONSE'], JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_errno($ch);
    curl_close($ch);
    $data = @json_decode($resp, true);
    return !$err && isset($data['message_id']);
}

function sendMessage(string $psid, string $text): void
{
    fbApiCall(json_encode(['recipient' => ['id' => $psid], 'message' => ['text' => $text], 'messaging_type' => 'RESPONSE'], JSON_UNESCAPED_UNICODE));
}
function fbApiCall(string $payload): void
{
    $ch = curl_init('https://graph.facebook.com/v19.0/me/messages?access_token=' . FB_TOKEN);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    file_put_contents('/tmp/fb_send.log', date('Y-m-d H:i:s') . " ERR:$err RESP:$resp\n", FILE_APPEND);
}

// ════════════════════════════════════════════════════════════════════════════
// Polling Engine — بدون Webhook
// ════════════════════════════════════════════════════════════════════════════

function plog(string $file, string $m): void
{
    @file_put_contents($file, date('Y-m-d H:i:s') . " [pid=" . getmypid() . "] $m\n", FILE_APPEND);
}

/**
 * getPageId — يجلب Page ID مرة واحدة ويخزّنه محلياً (يُستخدم لتجاهل رسائل الصفحة نفسها)
 */
function getPageId(): ?string
{
    static $cached = null;
    if ($cached !== null) return $cached;
    if (file_exists(PAGE_ID_CACHE_FILE)) {
        $v = trim((string)@file_get_contents(PAGE_ID_CACHE_FILE));
        if ($v !== '') { $cached = $v; return $cached; }
    }
    $ch = curl_init('https://graph.facebook.com/v19.0/me?fields=id&access_token=' . FB_TOKEN);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = @json_decode($resp, true);
    $id   = $data['id'] ?? null;
    if ($id) { @file_put_contents(PAGE_ID_CACHE_FILE, $id); $cached = $id; }
    return $cached;
}

/**
 * fbCurlGetPersistent — يستخدم مقبض curl واحد قابل لإعادة الاستخدام (أسرع من فتح اتصال جديد كل مرة)
 */
function fbCurlGetPersistent(string $url, int $timeout = 8): ?array
{
    static $ch = null;
    if ($ch === null) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TCP_KEEPALIVE  => 1,
        ]);
    }
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    $resp = curl_exec($ch);
    if ($resp === false) { plog(SUPERVISOR_LOG, "[curl-err] " . curl_error($ch) . " url=$url"); return null; }
    $data = json_decode($resp, true);
    return is_array($data) ? $data : null;
}

// ──────────────── طابور المهام (Job Queue) ────────────────

/**
 * enqueueJob — يكتب مهمة جديدة في الطابور بشكل ذرّي (atomic) حتى لا يقرأها عامل وهي غير مكتملة
 */
function enqueueJob(array $job): void
{
    $name = sprintf('%020.6f_%s.job', microtime(true), bin2hex(random_bytes(4)));
    $tmp  = QUEUE_DIR . "/.tmp_{$name}";
    $final = QUEUE_DIR . "/{$name}";
    if (@file_put_contents($tmp, json_encode($job, JSON_UNESCAPED_UNICODE)) !== false) {
        @rename($tmp, $final); // rename ذرّية على نفس نظام الملفات
    }
}

/**
 * claimNextJob — يحاول عامل واحد "حجز" أقدم مهمة في الطابور بأمان حتى مع تعدد العمال
 * (rename ذرّية: لو نجحت فهذا العامل هو من حصل على الملف حصرياً)
 */
function claimNextJob(): ?array
{
    $files = glob(QUEUE_DIR . '/*.job');
    if (!$files) return null;
    sort($files); // الاسم يبدأ بالوقت -> ترتيب زمني تلقائي (أقدم مهمة أولاً)
    foreach ($files as $f) {
        $locked = $f . '.claimed';
        if (@rename($f, $locked)) {
            $content = @file_get_contents($locked);
            @unlink($locked);
            $job = json_decode((string)$content, true);
            if (is_array($job)) return $job;
            return null;
        }
        // فشل rename يعني عامل آخر سبقه لهذا الملف بالذات — جرّب التالي
    }
    return null;
}

// ──────────────── فحص فيسبوك (Polling) ────────────────

function loadFbPollState(): array
{
    if (!file_exists(POLL_STATE_FILE)) return ['since' => time() - FB_POLL_WINDOW_SECONDS];
    $d = json_decode((string)@file_get_contents(POLL_STATE_FILE), true);
    return is_array($d) ? $d : ['since' => time() - FB_POLL_WINDOW_SECONDS];
}
function saveFbPollState(array $state): void
{
    @file_put_contents(POLL_STATE_FILE, json_encode($state));
}

/**
 * pollFacebookOnce — دورة فحص واحدة: يجلب كل المحادثات المُحدَّثة ضمن نافذة زمنية منزلقة،
 * ثم كل الرسائل الجديدة داخلها، ويستبعد رسائل الصفحة نفسها (الرد الآلي)، ويحول
 * زر الرد السريع (quick reply) الظاهري إلى payload الأصلي، ثم يضع كل رسالة جديدة في الطابور.
 * الاعتماد الأساسي لمنع "فقدان أي رسالة" هو الاعتماد على قاعدة processed_events (tryMarkEvent)
 * وليس على دقة "since" — لذلك نستخدم نافذة منزلقة (overlap) بدل تقدّم متسلسل صارم.
 */
function pollFacebookOnce(): void
{
    $pageId = getPageId();
    $since  = time() - FB_POLL_WINDOW_SECONDS;

    $url = 'https://graph.facebook.com/v19.0/me/conversations'
         . '?fields=' . urlencode('updated_time,messages.limit(15){message,from,id,created_time,attachments}')
         . '&limit=100'
         . '&since=' . $since
         . '&access_token=' . FB_TOKEN;

    $pages = 0;
    while ($url !== null && $pages < 5) {
        $pages++;
        $data = fbCurlGetPersistent($url, 10);
        if ($data === null) { plog(SUPERVISOR_LOG, "[fbpoll] فشل الطلب، سيُعاد المحاولة بالدورة القادمة"); return; }
        if (isset($data['error'])) {
            plog(SUPERVISOR_LOG, "[fbpoll] Graph API error: " . json_encode($data['error'], JSON_UNESCAPED_UNICODE));
            return;
        }

        foreach ($data['data'] ?? [] as $conversation) {
            $msgs = $conversation['messages']['data'] ?? [];
            foreach (array_reverse($msgs) as $message) { // من الأقدم للأحدث ضمن نفس المحادثة
                $mid = $message['id'] ?? null;
                if (!$mid) continue;

                $fromId = $message['from']['id'] ?? null;
                if ($pageId !== null && $fromId === $pageId) continue; // تجاهل ردود الصفحة نفسها

                $eid = "msg_{$mid}";
                if (!tryMarkEvent($eid)) continue; // مُعالجة مسبقاً (dedup)

                $psid = $fromId;
                if (!$psid) continue;

                $text = trim((string)($message['message'] ?? ''));

                $eventMsg = ['mid' => $mid, 'text' => $text];
                if (!empty($message['attachments'])) $eventMsg['attachments'] = $message['attachments'];
                if ($text !== '' && isset(QUICK_REPLY_TITLE_MAP[$text])) {
                    $eventMsg['quick_reply'] = ['payload' => QUICK_REPLY_TITLE_MAP[$text]];
                }

                enqueueJob(['type' => 'fb', 'psid' => $psid, 'event' => ['message' => $eventMsg]]);
            }
        }

        $url = $data['paging']['next'] ?? null;
    }
}

function runFacebookPollLoop(): void
{
    plog(SUPERVISOR_LOG, "[fbpoll] بدء حلقة فحص فيسبوك");
    while (true) {
        try { pollFacebookOnce(); }
        catch (Throwable $e) { plog(SUPERVISOR_LOG, "[fbpoll][ERR] " . $e->getMessage()); }
        usleep(FB_POLL_INTERVAL_MICROSECONDS);
    }
}

// ──────────────── فحص تلغرام (Long Polling) ────────────────

function loadTgOffset(): int
{
    if (!file_exists(TG_OFFSET_FILE)) return 0;
    $d = json_decode((string)@file_get_contents(TG_OFFSET_FILE), true);
    return is_array($d) ? (int)($d['offset'] ?? 0) : 0;
}
function saveTgOffset(int $offset): void
{
    @file_put_contents(TG_OFFSET_FILE, json_encode(['offset' => $offset]));
}

/**
 * runTelegramPollLoop — Long Polling عبر getUpdates (يبقي الاتصال مفتوحاً حتى وصول تحديث
 * جديد أو انتهاء المهلة)، وهذا فعّال جداً وشبه فوري بعكس فحص فيسبوك الدوري.
 */
function runTelegramPollLoop(): void
{
    plog(SUPERVISOR_LOG, "[tgpoll] بدء حلقة فحص تلغرام");
    $offset = loadTgOffset();
    while (true) {
        try {
            $url = TG_API . '/getUpdates?timeout=25&offset=' . ($offset) . '&allowed_updates=' . urlencode('["message","callback_query"]');
            $ch  = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $resp = curl_exec($ch);
            curl_close($ch);
            $data = json_decode((string)$resp, true);

            if (!empty($data['ok']) && is_array($data['result'] ?? null)) {
                foreach ($data['result'] as $update) {
                    enqueueJob(['type' => 'tg', 'update' => $update]);
                    $offset = max($offset, (int)$update['update_id'] + 1);
                }
                saveTgOffset($offset);
            }
        } catch (Throwable $e) {
            plog(SUPERVISOR_LOG, "[tgpoll][ERR] " . $e->getMessage());
            sleep(2);
        }
    }
}

// ──────────────── العمال (Workers) ────────────────

/**
 * runWorkerLoop — عامل دائم يسحب المهام من الطابور المشترك ويعالجها فوراً.
 * يعيد استخدام نفس آليات القفل والحماية الأصلية (tryLockUser/unlockUser) لضمان
 * عدم معالجة نفس المستخدم من عاملين في نفس اللحظة (منع تضارب الحالة/الجلسة).
 */
function runWorkerLoop(int $workerId): void
{
    plog(WORKER_LOG, "[worker-{$workerId}] بدء التشغيل");
    while (true) {
        $job = claimNextJob();
        if ($job === null) { usleep(WORKER_IDLE_SLEEP_MICROSECONDS); continue; }

        try {
            $type = $job['type'] ?? '';
            if ($type === 'fb') {
                $psid  = $job['psid']  ?? null;
                $event = $job['event'] ?? null;
                if (!$psid || !is_array($event)) continue;

                if (!tryLockUser($psid)) {
                    // المستخدم قيد المعالجة حالياً من عامل آخر (نادر جداً) — أعد المهمة للطابور بعد فترة قصيرة
                    usleep(30000);
                    enqueueJob($job);
                    continue;
                }
                try { processEvent($psid, $event); }
                catch (Throwable $e) { plog(WORKER_LOG, "[worker-{$workerId}][ERR][fb:$psid] " . $e->getMessage()); }
                finally { unlockUser($psid); }

            } elseif ($type === 'tg') {
                $update = $job['update'] ?? null;
                if (!is_array($update)) continue;
                $chatId = (string)(
                    $update['message']['chat']['id']
                    ?? $update['callback_query']['message']['chat']['id']
                    ?? 'tg_unknown'
                );
                $lockKey = 'tg_' . $chatId;
                if (!tryLockUser($lockKey)) { usleep(30000); enqueueJob($job); continue; }
                try { handleTelegramUpdate($update); }
                catch (Throwable $e) { plog(WORKER_LOG, "[worker-{$workerId}][ERR][tg:$chatId] " . $e->getMessage()); }
                finally { unlockUser($lockKey); }
            }
        } catch (Throwable $e) {
            plog(WORKER_LOG, "[worker-{$workerId}][FATAL] " . $e->getMessage());
        }
    }
}

// ──────────────── المُشرِف (Supervisor) ────────────────

/**
 * runSupervisor — يشغّل جميع العمليات الفرعية (فحص فيسبوك + فحص تلغرام + N عامل)
 * ويراقبها باستمرار، وإن تعطلت أي عملية فرعية لأي سبب يعيد تشغيلها فوراً تلقائياً.
 * هذا هو الأمر الوحيد الذي يجب إبقاؤه يعمل بشكل دائم (عبر systemd أو supervisor أو screen).
 */
function runSupervisor(): void
{
    plog(SUPERVISOR_LOG, "════ بدء تشغيل المُشرِف (Supervisor) ════");
    $self = __FILE__;
    $phpBin = PHP_BINARY ?: 'php';

    $spawn = function (array $args) use ($phpBin, $self): array {
        $cmd = array_merge([$phpBin, $self], $args);
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptors, $pipes);
        if (is_array($pipes)) { foreach ($pipes as $p) if (is_resource($p)) fclose($p); }
        return ['proc' => $proc, 'args' => $args];
    };

    $children = [];
    $children['fbpoll'] = $spawn(['fbpoll']);
    $children['tgpoll'] = $spawn(['tgpoll']);
    for ($i = 1; $i <= WORKER_COUNT; $i++) {
        $children["worker{$i}"] = $spawn(['worker', (string)$i]);
    }
    plog(SUPERVISOR_LOG, "تم تشغيل: fbpoll, tgpoll, و " . WORKER_COUNT . " عامل");

    while (true) {
        foreach ($children as $key => $child) {
            if (!is_resource($child['proc'])) continue;
            $status = proc_get_status($child['proc']);
            if ($status && !$status['running']) {
                plog(SUPERVISOR_LOG, "⚠️ العملية [{$key}] توقفت (exit={$status['exitcode']}) — إعادة تشغيلها الآن");
                @proc_close($child['proc']);
                $children[$key] = $spawn($child['args']);
            }
        }
        usleep(SUPERVISOR_TICK_MICROSECONDS);
    }
}
