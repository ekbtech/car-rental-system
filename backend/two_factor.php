<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

try {
    $db = database();
    $action = $_GET['action'] ?? 'status';
    $body = !empty($_POST) ? $_POST : requestBody();
    $challengeToken = cleanString($body['challenge'] ?? '', 128);
    $challengeUserId = (int) ($_SESSION['2fa_user_id'] ?? 0);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $action !== 'status') {
        jsonResponse(['error' => 'Method not allowed.'], 405);
    }

    if ($action === 'status') {
        $user = requireUser();
        $statement = $db->prepare('SELECT totp_enabled, totp_enrolled_at FROM users WHERE id = ? AND is_active = 1');
        $statement->execute([(int) $user['id']]);
        $record = $statement->fetch();
        $recovery = $db->prepare('SELECT COUNT(*) FROM recovery_codes WHERE user_id = ? AND used_at IS NULL');
        $recovery->execute([(int) $user['id']]);
        jsonResponse([
            'fully_authenticated' => true,
            'totp_enabled' => (bool) ($record['totp_enabled'] ?? false),
            'enrolled_at' => $record['totp_enrolled_at'] ?? null,
            'recovery_codes_remaining' => (int) $recovery->fetchColumn(),
        ]);
    }

    if ($action === 'enrollment_start') {
        if ($challengeToken === '' || !$challengeUserId || !consumeTotpChallenge($db, $challengeToken, 'enrollment', $challengeUserId)) {
            jsonResponse(['error' => 'The enrollment challenge is invalid or expired.'], 401);
        }
        $statement = $db->prepare('SELECT id, name, email, totp_enabled FROM users WHERE id = ? AND is_active = 1');
        $statement->execute([$challengeUserId]);
        $user = $statement->fetch();
        if (!$user || $user['totp_enabled']) jsonResponse(['error' => 'Authenticator enrollment is already complete.'], 409);

        $statement = $db->prepare('SELECT totp_pending_secret_encrypted, totp_pending_expires_at FROM users WHERE id = ?');
        $statement->execute([$challengeUserId]);
        $pending = $statement->fetch();
        $secret = '';
        if ($pending && $pending['totp_pending_secret_encrypted'] && $pending['totp_pending_expires_at'] && strtotime($pending['totp_pending_expires_at']) > time()) {
            $secret = decryptTotpSecret($pending['totp_pending_secret_encrypted']);
        } else {
            $secret = base32Encode(random_bytes(20));
            $encrypted = encryptTotpSecret($secret);
            $db->prepare('UPDATE users SET totp_pending_secret_encrypted = ?, totp_pending_expires_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE), totp_pending_attempts = 0 WHERE id = ?')->execute([$encrypted, $challengeUserId]);
        }
        $issuer = envValue('APP_NAME', 'Pro Car');
        $label = $issuer . ':' . $user['email'];
        $uri = 'otpauth://totp/' . rawurlencode($label) . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
        recordAuthEvent($db, $challengeUserId, 'authenticator_enrollment_started', 'success');
        jsonResponse(['secret' => $secret, 'otpauth_uri' => $uri]);
    }

    if ($action === 'enrollment_confirm') {
        $code = cleanString($body['code'] ?? '', 6);
        if ($challengeToken === '' || !$challengeUserId || !consumeTotpChallenge($db, $challengeToken, 'enrollment', $challengeUserId)) jsonResponse(['error' => 'The enrollment challenge is invalid or expired.'], 401);
        $statement = $db->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1');
        $statement->execute([$challengeUserId]);
        $user = $statement->fetch();
        if (!$user || $user['totp_enabled'] || !$user['totp_pending_secret_encrypted'] || !$user['totp_pending_expires_at'] || strtotime($user['totp_pending_expires_at']) <= time()) jsonResponse(['error' => 'Authenticator enrollment expired. Start again.'], 422);
        if ((int) $user['totp_pending_attempts'] >= 5) jsonResponse(['error' => 'Too many enrollment attempts. Start the login again.'], 429);
        $secret = decryptTotpSecret($user['totp_pending_secret_encrypted']);
        if (!verifyTotp($secret, $code)) {
            $db->prepare('UPDATE users SET totp_pending_attempts = totp_pending_attempts + 1 WHERE id = ?')->execute([$challengeUserId]);
            recordAuthEvent($db, $challengeUserId, 'authenticator_enrollment_verified', 'failure');
            jsonResponse(['error' => 'That authenticator code is invalid or expired.'], 422);
        }

        $db->beginTransaction();
        $db->prepare('UPDATE users SET totp_enabled = 1, totp_secret_encrypted = ?, totp_enrolled_at = NOW(), totp_pending_secret_encrypted = NULL, totp_pending_expires_at = NULL, totp_pending_attempts = 0, totp_last_step = NULL WHERE id = ?')->execute([$user['totp_pending_secret_encrypted'], $challengeUserId]);
        $db->prepare('DELETE FROM recovery_codes WHERE user_id = ?')->execute([$challengeUserId]);
        $codes = [];
        $insert = $db->prepare('INSERT INTO recovery_codes (user_id, code_hash) VALUES (?, ?)');
        for ($i = 0; $i < 10; $i++) {
            $plain = strtoupper(substr(bin2hex(random_bytes(5)), 0, 10));
            $display = substr($plain, 0, 5) . '-' . substr($plain, 5);
            $codes[] = $display;
            $insert->execute([$challengeUserId, password_hash(str_replace('-', '', $display), PASSWORD_DEFAULT)]);
        }
        $db->prepare('UPDATE totp_challenges SET used_at = NOW() WHERE token_hash = ?')->execute([hash('sha256', $challengeToken)]);
        $db->commit();
        recordAuthEvent($db, $challengeUserId, 'authenticator_enrollment_verified', 'success');
        if (!empty($_SESSION['user'])) {
            $_SESSION['2fa_verified'] = true;
            unset($_SESSION['2fa_challenge'], $_SESSION['2fa_user_id']);
            jsonResponse(['fully_authenticated' => true, 'recovery_codes' => $codes]);
        }
        $_SESSION['2fa_enrollment_user_id'] = $challengeUserId;
        jsonResponse(['requires_recovery_acknowledgement' => true, 'recovery_codes' => $codes]);
    }

    if ($action === 'recovery_acknowledgement') {
        $challengeToken = cleanString($body['challenge'] ?? '', 128);
        $userId = (int) ($_SESSION['2fa_enrollment_user_id'] ?? $challengeUserId);
        if (!$userId) jsonResponse(['error' => 'Complete authenticator enrollment first.'], 401);
        $statement = $db->prepare('SELECT id, name, email, phone, role FROM users WHERE id = ? AND is_active = 1 AND approval_status = \'approved\' AND totp_enabled = 1');
        $statement->execute([$userId]);
        $userRecord = $statement->fetch();
        if (!$userRecord) jsonResponse(['error' => 'This account is not eligible for sign-in yet.'], 403);
        session_regenerate_id(true);
        $_SESSION['user'] = normaliseUser($userRecord);
        $_SESSION['2fa_verified'] = true;
        unset($_SESSION['2fa_challenge'], $_SESSION['2fa_user_id'], $_SESSION['2fa_enrollment_user_id']);
        jsonResponse(['user' => $_SESSION['user']]);
    }

    if ($action === 'login_verify') {
        if ($challengeToken === '' || !$challengeUserId || !consumeTotpChallenge($db, $challengeToken, 'login', $challengeUserId)) jsonResponse(['error' => 'The authentication challenge is invalid or expired.'], 401);
        $code = cleanString($body['code'] ?? '', 7);
        $statement = $db->prepare('SELECT id, name, email, phone, role, approval_status, totp_secret_encrypted, totp_last_step FROM users WHERE id = ? AND is_active = 1 AND totp_enabled = 1');
        $statement->execute([$challengeUserId]);
        $user = $statement->fetch();
        if (!$user || $user['approval_status'] !== 'approved') jsonResponse(['error' => 'This account is not eligible for sign-in.'], 403);
        if (!preg_match('/^\d{6}$/', $code)) jsonResponse(['error' => 'Enter the six-digit authenticator code.'], 422);

        $secret = decryptTotpSecret($user['totp_secret_encrypted']);
        $valid = verifyTotp($secret, $code);
        $currentStep = intdiv(time(), 30);
        if ($valid && ((int) $user['totp_last_step']) === $currentStep) $valid = false;
        if (!$valid) {
            $db->prepare('UPDATE totp_challenges SET attempts = attempts + 1 WHERE token_hash = ?')->execute([hash('sha256', $challengeToken)]);
            recordAuthEvent($db, $challengeUserId, 'second_factor_verified', 'failure');
            jsonResponse(['error' => 'That authenticator code is invalid or expired.'], 422);
        }
        $db->beginTransaction();
        $db->prepare('UPDATE users SET totp_last_step = ? WHERE id = ?')->execute([$currentStep, $challengeUserId]);
        $db->prepare('UPDATE totp_challenges SET used_at = NOW() WHERE token_hash = ?')->execute([hash('sha256', $challengeToken)]);
        $db->commit();
        session_regenerate_id(true);
        $_SESSION['user'] = normaliseUser($user);
        $_SESSION['2fa_verified'] = true;
        unset($_SESSION['2fa_challenge'], $_SESSION['2fa_user_id']);
        recordAuthEvent($db, $challengeUserId, 'second_factor_verified', 'success');
        jsonResponse(['user' => $_SESSION['user']]);
    }

    if ($action === 'recovery_verify') {
        if ($challengeToken === '' || !$challengeUserId || !consumeTotpChallenge($db, $challengeToken, 'login', $challengeUserId)) jsonResponse(['error' => 'The authentication challenge is invalid or expired.'], 401);
        $plain = strtoupper(preg_replace('/[^A-Z0-9]/', '', cleanString($body['code'] ?? '', 20)));
        if (strlen($plain) !== 10) jsonResponse(['error' => 'Enter a valid recovery code.'], 422);
        $db->beginTransaction();
        $rows = $db->prepare('SELECT id, code_hash FROM recovery_codes WHERE user_id = ? AND used_at IS NULL');
        $rows->execute([$challengeUserId]);
        $matched = null;
        foreach ($rows->fetchAll() as $row) if (password_verify($plain, $row['code_hash'])) { $matched = $row['id']; break; }
        if (!$matched) {
            $db->rollBack();
            $db->prepare('UPDATE totp_challenges SET attempts = attempts + 1 WHERE token_hash = ?')->execute([hash('sha256', $challengeToken)]);
            recordAuthEvent($db, $challengeUserId, 'recovery_code_used', 'failure');
            jsonResponse(['error' => 'That recovery code is invalid or has already been used.'], 422);
        }
        $update = $db->prepare('UPDATE recovery_codes SET used_at = NOW() WHERE id = ? AND used_at IS NULL');
        $update->execute([$matched]);
        if ($update->rowCount() !== 1) {
            $db->rollBack();
            jsonResponse(['error' => 'That recovery code has already been used.'], 409);
        }
        $db->prepare('UPDATE totp_challenges SET used_at = NOW() WHERE token_hash = ?')->execute([hash('sha256', $challengeToken)]);
        $statement = $db->prepare('SELECT id, name, email, phone, role FROM users WHERE id = ? AND is_active = 1 AND approval_status = \'approved\'');
        $statement->execute([$challengeUserId]);
        $user = $statement->fetch();
        $db->commit();
        session_regenerate_id(true);
        $_SESSION['user'] = normaliseUser($user);
        $_SESSION['2fa_verified'] = true;
        unset($_SESSION['2fa_challenge'], $_SESSION['2fa_user_id']);
        recordAuthEvent($db, $challengeUserId, 'recovery_code_used', 'success');
        jsonResponse(['user' => $_SESSION['user']]);
    }

    if ($action === 'replacement_start') {
        $user = requireUser();
        $password = (string) ($body['password'] ?? '');
        $statement = $db->prepare('SELECT password_hash FROM users WHERE id = ? AND is_active = 1');
        $statement->execute([(int) $user['id']]);
        $record = $statement->fetch();
        if (!$record || !password_verify($password, $record['password_hash'])) jsonResponse(['error' => 'Password verification failed.'], 403);
        $db->beginTransaction();
        $db->prepare('UPDATE users SET totp_enabled = 0, totp_secret_encrypted = NULL, totp_enrolled_at = NULL, totp_pending_secret_encrypted = NULL, totp_pending_expires_at = NULL, totp_pending_attempts = 0, totp_last_step = NULL WHERE id = ?')->execute([(int) $user['id']]);
        $db->prepare('DELETE FROM recovery_codes WHERE user_id = ?')->execute([(int) $user['id']]);
        $challenge = createTotpChallenge($db, (int) $user['id'], 'enrollment');
        $db->commit();
        recordAuthEvent($db, (int) $user['id'], 'authenticator_replacement_started', 'success');
        jsonResponse(['requires_enrollment' => true, 'challenge' => $challenge]);
    }

    if ($action === 'regenerate_recovery_codes') {
        $user = requireUser();
        $password = (string) ($body['password'] ?? '');
        $statement = $db->prepare('SELECT password_hash, totp_enabled FROM users WHERE id = ? AND is_active = 1');
        $statement->execute([(int) $user['id']]);
        $record = $statement->fetch();
        if (!$record || !$record['totp_enabled'] || !password_verify($password, $record['password_hash'])) jsonResponse(['error' => 'Password verification failed or authenticator is not enrolled.'], 403);
        $db->beginTransaction();
        $db->prepare('DELETE FROM recovery_codes WHERE user_id = ?')->execute([(int) $user['id']]);
        $codes = [];
        $insert = $db->prepare('INSERT INTO recovery_codes (user_id, code_hash) VALUES (?, ?)');
        for ($i = 0; $i < 10; $i++) {
            $plain = strtoupper(substr(bin2hex(random_bytes(5)), 0, 10));
            $display = substr($plain, 0, 5) . '-' . substr($plain, 5);
            $codes[] = $display;
            $insert->execute([(int) $user['id'], password_hash($plain, PASSWORD_DEFAULT)]);
        }
        $db->commit();
        recordAuthEvent($db, (int) $user['id'], 'recovery_codes_regenerated', 'success');
        jsonResponse(['recovery_codes' => $codes]);
    }

    if ($action === 'admin_reset') {
        $admin = requireRole(['admin']);
        $userId = (int) ($body['user_id'] ?? 0);
        $reason = cleanString($body['reason'] ?? '', 500);
        if ($userId < 1 || $reason === '') jsonResponse(['error' => 'A user ID and audit reason are required.'], 422);
        $db->beginTransaction();
        $db->prepare('UPDATE users SET totp_enabled = 0, totp_secret_encrypted = NULL, totp_enrolled_at = NULL, totp_pending_secret_encrypted = NULL, totp_pending_expires_at = NULL, totp_pending_attempts = 0, totp_last_step = NULL WHERE id = ?')->execute([$userId]);
        $db->prepare('DELETE FROM recovery_codes WHERE user_id = ?')->execute([$userId]);
        $db->prepare('DELETE FROM totp_challenges WHERE user_id = ?')->execute([$userId]);
        $db->commit();
        recordAuthEvent($db, $userId, 'administrative_recovery', 'success', ['reason' => $reason, 'admin_user_id' => $admin['id']]);
        jsonResponse(['success' => true]);
    }

    jsonResponse(['error' => 'Unknown two-factor action.'], 400);
} catch (Throwable $exception) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log($exception->getMessage());
    jsonResponse(['error' => 'The two-factor service is unavailable.'], 500);
}
