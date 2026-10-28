<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

try {
    $db = database();
    $admin = requireRole(['admin']);
    $method = $_SERVER['REQUEST_METHOD'];
    $body = requestBody();

    if ($method === 'GET') {
        $where = isset($_GET['all']) ? '' : " WHERE role IN ('driver', 'company')";
        $statement = $db->query("SELECT id, name, email, phone, role, approval_status, cv_path, created_at FROM users{$where} ORDER BY created_at DESC");
        jsonResponse(['users' => $statement->fetchAll()]);
    }

    if ($method !== 'PUT') {
        jsonResponse(['error' => 'Method not allowed.'], 405);
    }
    $id = (int) ($body['id'] ?? 0);
    $status = cleanString($body['approval_status'] ?? '', 20);
    if (!in_array($status, ['approved', 'rejected'], true)) {
        jsonResponse(['error' => 'Choose approve or reject.'], 422);
    }
    $reason = cleanString($body['rejection_reason'] ?? '', 500);
    $statement = $db->prepare('UPDATE users SET approval_status = ?, rejection_reason = ? WHERE id = ? AND role IN (\'driver\', \'company\')');
    $statement->execute([$status, $reason ?: null, $id]);
    jsonResponse(['success' => true]);
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    jsonResponse(['error' => 'Unable to process user approvals.'], 500);
}