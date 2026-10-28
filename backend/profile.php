<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

try {
    $db = database();
    $user = requireUser();
    $body = requestBody();
    $action = $_GET['action'] ?? 'update';

    if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'PUT') {
        $name = cleanString($body['name'] ?? $user['name'], 120);
        $phone = cleanString($body['phone'] ?? '', 40);
        if ($name === '') {
            jsonResponse(['error' => 'Name cannot be empty.'], 422);
        }
        $statement = $db->prepare('UPDATE users SET name = ?, phone = ? WHERE id = ?');
        $statement->execute([$name, $phone ?: null, $user['id']]);
        $_SESSION['user']['name'] = $name;
        $_SESSION['user']['phone'] = $phone ?: null;
        jsonResponse(['user' => $_SESSION['user']]);
    }

    if ($action === 'password' && $_SERVER['REQUEST_METHOD'] === 'PUT') {
        $current = (string) ($body['current_password'] ?? '');
        $next = (string) ($body['new_password'] ?? '');
        $statement = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
        $statement->execute([$user['id']]);
        $record = $statement->fetch();
        if (!$record || !password_verify($current, $record['password_hash']) || strlen($next) < 8) {
            jsonResponse(['error' => 'Current password is incorrect or the new password is too short.'], 422);
        }
        $statement = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $statement->execute([password_hash($next, PASSWORD_DEFAULT), $user['id']]);
        jsonResponse(['success' => true]);
    }

    jsonResponse(['error' => 'Unknown profile action.'], 400);
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    jsonResponse(['error' => 'Unable to update your profile.'], 500);
}