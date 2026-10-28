<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

$secureCookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secureCookie,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

function jsonResponse(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function requestBody(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}

function currentUser(): ?array
{
    if (($_SESSION['2fa_verified'] ?? false) !== true) return null;
    return $_SESSION['user'] ?? null;
}

function requireUser(): array
{
    $user = currentUser();
    if (!$user || ($_SESSION['2fa_verified'] ?? false) !== true) {
        jsonResponse(['error' => 'Two-factor authentication is required.'], 401);
    }
    return $user;
}

function requireRole(array $roles): array
{
    $user = requireUser();
    if (!in_array($user['role'], $roles, true)) {
        jsonResponse(['error' => 'You do not have permission for this action.'], 403);
    }
    return $user;
}

function cleanString(mixed $value, int $maxLength = 255): string
{
    return mb_substr(trim((string) $value), 0, $maxLength);
}

function normaliseUser(array $user): array
{
    return [
        'id' => (int) $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'phone' => $user['phone'] ?? null,
        'role' => $user['role'],
    ];
}

function parseDate(string $value): ?DateTimeImmutable
{
    try {
        return new DateTimeImmutable($value);
    } catch (Exception) {
        return null;
    }
}

function totpEncryptionKey(): string
{
    loadProjectEnv();
    $configured = envValue('TOTP_ENCRYPTION_KEY', '');
    if ($configured !== '') {
        $decoded = base64_decode($configured, true);
        return $decoded !== false && strlen($decoded) === 32 ? $decoded : hash('sha256', $configured, true);
    }
    $envFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
    $key = bin2hex(random_bytes(32));
    @file_put_contents($envFile, "TOTP_ENCRYPTION_KEY={$key}\n", FILE_APPEND | LOCK_EX);
    putenv('TOTP_ENCRYPTION_KEY=' . $key);
    return hash('sha256', $key, true);
}

function encryptTotpSecret(string $secret): string
{
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($secret, 'aes-256-gcm', totpEncryptionKey(), OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($ciphertext === false) throw new RuntimeException('Could not encrypt authenticator secret');
    return base64_encode($nonce . $tag . $ciphertext);
}

function decryptTotpSecret(string $encrypted): string
{
    $raw = base64_decode($encrypted, true);
    if ($raw === false || strlen($raw) < 28) throw new RuntimeException('Invalid encrypted authenticator secret');
    $secret = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', totpEncryptionKey(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), '');
    if ($secret === false) throw new RuntimeException('Could not decrypt authenticator secret');
    return $secret;
}

function base32Encode(string $data): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($data) as $byte) $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
    $output = '';
    for ($i = 0; $i < strlen($bits); $i += 5) $output .= $alphabet[bindec(str_pad(substr($bits, $i, 5), 5, '0', STR_PAD_RIGHT))];
    return $output;
}

function base32Decode(string $secret): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret));
    $bits = '';
    foreach (str_split($secret) as $char) {
        $position = strpos($alphabet, $char);
        if ($position === false) return '';
        $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
    }
    $bytes = '';
    for ($i = 0; $i + 8 <= strlen($bits); $i += 8) $bytes .= chr(bindec(substr($bits, $i, 8)));
    return $bytes;
}

function totpCode(string $secret, ?int $timestamp = null): string
{
    $decoded = base32Decode($secret);
    if ($decoded === '') return '';
    $counter = intdiv($timestamp ?? time(), 30);
    $binary = pack('N2', ($counter >> 32) & 0xffffffff, $counter & 0xffffffff);
    $digest = hash_hmac('sha1', $binary, $decoded, true);
    $offset = ord($digest[strlen($digest) - 1]) & 0xf;
    $value = ((ord($digest[$offset]) & 0x7f) << 24) | ((ord($digest[$offset + 1]) & 0xff) << 16) | ((ord($digest[$offset + 2]) & 0xff) << 8) | (ord($digest[$offset + 3]) & 0xff);
    return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
}

function verifyTotp(string $secret, string $code): bool
{
    if (!preg_match('/^\d{6}$/', $code)) return false;
    $now = time();
    foreach ([0, -30, 30] as $skew) if (hash_equals(totpCode($secret, $now + $skew), $code)) return true;
    return false;
}

function recordAuthEvent(PDO $db, ?int $userId, string $type, string $outcome, array $metadata = []): void
{
    $statement = $db->prepare('INSERT INTO auth_security_events (user_id, event_type, outcome, user_agent, metadata) VALUES (?, ?, ?, ?, ?)');
    $statement->execute([$userId, $type, $outcome, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255), $metadata ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null]);
}

function createTotpChallenge(PDO $db, int $userId, string $purpose): string
{
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $db->prepare('DELETE FROM totp_challenges WHERE user_id = ? OR expires_at <= NOW()')->execute([$userId]);
    $statement = $db->prepare('INSERT INTO totp_challenges (user_id, token_hash, purpose, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))');
    $statement->execute([$userId, $hash, $purpose]);
    $_SESSION['2fa_verified'] = false;
    $_SESSION['2fa_challenge'] = $hash;
    $_SESSION['2fa_user_id'] = $userId;
    return $token;
}

function consumeTotpChallenge(PDO $db, string $token, string $purpose, int $userId): bool
{
    $hash = hash('sha256', $token);
    if (($_SESSION['2fa_challenge'] ?? '') !== $hash) return false;
    $statement = $db->prepare('SELECT id FROM totp_challenges WHERE token_hash = ? AND user_id = ? AND purpose = ? AND expires_at > NOW() AND used_at IS NULL AND attempts < 5 LIMIT 1');
    $statement->execute([$hash, $userId, $purpose]);
    return (bool) $statement->fetch();
}