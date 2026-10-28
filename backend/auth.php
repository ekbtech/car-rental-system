<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

try {
    $db = database();
    $action = $_GET['action'] ?? 'me';

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'me') {
        $user = currentUser();
        if (!$user) jsonResponse(['error' => 'Two-factor authentication is required.'], 401);
        jsonResponse(['user' => $user]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['error' => 'Method not allowed.'], 405);
    }

    $body = !empty($_POST) ? $_POST : requestBody();

    if ($action === 'request_code') {
        $name = cleanString($body['name'] ?? '', 120);
        $email = strtolower(cleanString($body['email'] ?? '', 190));
        $password = (string) ($body['password'] ?? '');
        $phone = cleanString($body['phone'] ?? '', 40);
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            jsonResponse(['error' => 'Enter a name, valid email, and password of at least 8 characters.'], 422);
        }
        $exists = $db->prepare('SELECT id FROM users WHERE email = ?');
        $exists->execute([$email]);
        if ($exists->fetch()) {
            jsonResponse(['error' => 'An account with that email already exists.'], 409);
        }
        $code = (string) random_int(100000, 999999);
        $statement = $db->prepare('DELETE FROM email_verification_codes WHERE email = ?');
        $statement->execute([$email]);
        $statement = $db->prepare('INSERT INTO email_verification_codes (email, name, phone, password_hash, role, code_hash, expires_at) VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))');
        $statement->execute([$email, $name, $phone ?: null, password_hash($password, PASSWORD_DEFAULT), 'customer', password_hash($code, PASSWORD_DEFAULT)]);
        $subject = 'Your Pro Car verification code';
        $message = "Your Pro Car verification code is {$code}. It expires in 10 minutes.";
        $headers = "From: " . envValue('MAIL_FROM', 'no-reply@procar.local') . "\r\n" . "Reply-To: " . envValue('MAIL_FROM', 'no-reply@procar.local') . "\r\n" . "Content-Type: text/plain; charset=UTF-8\r\n";
        if (!mail($email, $subject, $message, $headers)) {
            jsonResponse(['error' => 'The verification email could not be sent. Configure MAIL_FROM and SMTP in PHP/WAMP.'], 503);
        }
        jsonResponse(['sent' => true, 'message' => 'A verification code was sent to your email.']);
    }

    if ($action === 'verify_code') {
        $email = strtolower(cleanString($body['email'] ?? '', 190));
        $code = cleanString($body['code'] ?? '', 6);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^\d{6}$/', $code)) {
            jsonResponse(['error' => 'Enter the six-digit code sent to your valid email.'], 422);
        }
        $statement = $db->prepare('SELECT * FROM email_verification_codes WHERE email = ? AND expires_at > NOW() ORDER BY id DESC LIMIT 1');
        $statement->execute([$email]);
        $pending = $statement->fetch();
        if (!$pending || (int) $pending['attempts'] >= 5 || !password_verify($code, $pending['code_hash'])) {
            if ($pending) {
                $db->prepare('UPDATE email_verification_codes SET attempts = attempts + 1 WHERE id = ?')->execute([$pending['id']]);
            }
            jsonResponse(['error' => 'That verification code is invalid or expired.'], 422);
        }
        $statement = $db->prepare('INSERT INTO users (name, email, phone, password_hash, role, approval_status) VALUES (?, ?, ?, ?, ?, ?)');
        $statement->execute([$pending['name'], $pending['email'], $pending['phone'], $pending['password_hash'], 'customer', 'approved']);
        $userId = (int) $db->lastInsertId();
        $user = ['id' => $userId, 'name' => $pending['name'], 'email' => $pending['email'], 'phone' => $pending['phone'], 'role' => 'customer'];
        $db->prepare('DELETE FROM email_verification_codes WHERE id = ?')->execute([$pending['id']]);
        session_regenerate_id(true);
        $challenge = createTotpChallenge($db, $userId, 'enrollment');
        jsonResponse(['requires_2fa' => true, 'requires_enrollment' => true, 'challenge' => $challenge], 201);
    }

    if ($action === 'google') {
        $idToken = cleanString($body['id_token'] ?? '', 4096);
        $clientId = envValue('GOOGLE_WEB_CLIENT_ID', '');
        if ($idToken === '' || $clientId === '') {
            jsonResponse(['error' => 'Google sign-in is not configured.'], 503);
        }
        $tokenResponse = file_get_contents('https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($idToken));
        $claims = $tokenResponse ? json_decode($tokenResponse, true) : null;
        if (!is_array($claims) || ($claims['aud'] ?? '') !== $clientId || ($claims['email_verified'] ?? '') !== 'true' || !filter_var($claims['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            jsonResponse(['error' => 'Google could not verify this account.'], 401);
        }
        $email = strtolower($claims['email']);
        $statement = $db->prepare('SELECT id, name, email, phone, role, approval_status, rejection_reason, totp_enabled FROM users WHERE email = ? AND is_active = 1');
        $statement->execute([$email]);
        $record = $statement->fetch();
        if (!$record) {
            $name = cleanString($claims['name'] ?? $claims['given_name'] ?? 'Pro Car Customer', 120);
            $statement = $db->prepare('INSERT INTO users (name, email, password_hash, role, approval_status) VALUES (?, ?, ?, ?, ?)');
            $statement->execute([$name, $email, password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), 'customer', 'approved']);
            $record = ['id' => $db->lastInsertId(), 'name' => $name, 'email' => $email, 'phone' => null, 'role' => 'customer'];
        } elseif (($record['approval_status'] ?? 'approved') !== 'approved') {
            jsonResponse(['error' => 'This account is awaiting approval.'], 403);
        }
        $user = normaliseUser($record);
        session_regenerate_id(true);
        $enabled = (bool) ($record['totp_enabled'] ?? false);
        $challenge = createTotpChallenge($db, (int) $user['id'], $enabled ? 'login' : 'enrollment');
        jsonResponse(['requires_2fa' => true, 'requires_enrollment' => !$enabled, 'challenge' => $challenge]);
    }

    if ($action === 'register') {
        $name = cleanString($body['name'] ?? '', 120);
        $email = strtolower(cleanString($body['email'] ?? '', 190));
        $password = (string) ($body['password'] ?? '');
        $phone = cleanString($body['phone'] ?? '', 40);
        $role = cleanString($body['role'] ?? 'customer', 20);

        if (!in_array($role, ['customer', 'driver', 'company'], true)) {
            $role = 'customer';
        }
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            jsonResponse(['error' => 'Enter a name, valid email, and password of at least 8 characters.'], 422);
        }
        if ($role === 'driver' && empty($_FILES['cv']['name'])) {
            jsonResponse(['error' => 'Drivers must upload a CV for admin approval.'], 422);
        }

        $exists = $db->prepare('SELECT id FROM users WHERE email = ?');
        $exists->execute([$email]);
        if ($exists->fetch()) {
            jsonResponse(['error' => 'An account with that email already exists.'], 409);
        }

        $approvalStatus = $role === 'customer' ? 'approved' : 'pending';
        $cvPath = null;
        if ($role === 'driver') {
            $file = $_FILES['cv'];
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowedExtensions = ['pdf', 'doc', 'docx'];
            $mime = $file['error'] === UPLOAD_ERR_OK && class_exists('finfo')
                ? (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name'])
                : $file['type'];
            $allowedMimes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/octet-stream'];
            if ($file['error'] !== UPLOAD_ERR_OK || !in_array($extension, $allowedExtensions, true) || !in_array($mime, $allowedMimes, true) || $file['size'] > 5 * 1024 * 1024) {
                jsonResponse(['error' => 'Upload a PDF or Word CV smaller than 5 MB.'], 422);
            }
            $directory = dirname(__DIR__) . '/uploads/driver-cvs';
            if (!is_dir($directory)) {
                mkdir($directory, 0775, true);
            }
            $fileName = bin2hex(random_bytes(12)) . '.' . $extension;
            if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $fileName)) {
                jsonResponse(['error' => 'The CV could not be saved. Check the uploads folder permissions.'], 500);
            }
            $cvPath = 'uploads/driver-cvs/' . $fileName;
        }

        $statement = $db->prepare('INSERT INTO users (name, email, phone, password_hash, role, approval_status, cv_path) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $statement->execute([$name, $email, $phone ?: null, password_hash($password, PASSWORD_DEFAULT), $role, $approvalStatus, $cvPath]);
        $userId = (int) $db->lastInsertId();
        if ($approvalStatus === 'pending') {
            jsonResponse(['pending' => true, 'message' => 'Your application was submitted and is waiting for admin approval.'], 201);
        }
        session_regenerate_id(true);
        $challenge = createTotpChallenge($db, $userId, 'enrollment');
        jsonResponse(['requires_2fa' => true, 'requires_enrollment' => true, 'challenge' => $challenge], 201);
    }

    if ($action === 'login') {
        $email = strtolower(cleanString($body['email'] ?? '', 190));
        $password = (string) ($body['password'] ?? '');
        $statement = $db->prepare('SELECT id, name, email, phone, password_hash, role, approval_status, rejection_reason, totp_enabled FROM users WHERE email = ? AND is_active = 1');
        $statement->execute([$email]);
        $record = $statement->fetch();

        if (!$record || !password_verify($password, $record['password_hash'])) {
            jsonResponse(['error' => 'Email or password is incorrect.'], 401);
        }
        if ($record['approval_status'] !== 'approved') {
            $message = $record['approval_status'] === 'rejected'
                ? 'Your application was rejected. ' . ($record['rejection_reason'] ?: 'Please contact support.')
                : 'Your application is waiting for admin approval.';
            jsonResponse(['error' => $message], 403);
        }

        $enabled = (bool) $record['totp_enabled'];
        session_regenerate_id(true);
        $challenge = createTotpChallenge($db, (int) $record['id'], $enabled ? 'login' : 'enrollment');
        jsonResponse([
            'requires_2fa' => true,
            'requires_enrollment' => !$enabled,
            'challenge' => $challenge,
        ]);
    }

    if ($action === 'logout') {
        if (!empty($_SESSION['2fa_user_id'])) {
            $db->prepare('DELETE FROM totp_challenges WHERE user_id = ?')->execute([(int) $_SESSION['2fa_user_id']]);
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
        }
        session_destroy();
        jsonResponse(['success' => true]);
    }

    jsonResponse(['error' => 'Unknown authentication action.'], 400);
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    jsonResponse(['error' => 'The authentication service is unavailable. Check your MySQL settings.'], 500);
}