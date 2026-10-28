<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

function supportClean(mixed $value, int $max): string
{
    return cleanString($value, $max);
}

function supportSubject(array $data): string
{
    foreach (['subject', '_subject', 'topic'] as $key) {
        if (!empty($data[$key])) return supportClean($data[$key], 180);
    }
    return 'Pro Car support request';
}

function supportInsert(PDO $db, array $data): array
{
    $name = supportClean($data['name'] ?? $data['Name'] ?? $data['sender_name'] ?? 'Pro Car User', 120);
    $email = strtolower(supportClean($data['email'] ?? $data['Email'] ?? $data['sender_email'] ?? '', 190));
    $subject = supportSubject($data);
    $message = supportClean($data['message'] ?? $data['Message'] ?? '', 10000);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('A valid email address is required.');
    if ($name === '' || $subject === '' || mb_strlen($message) < 10) throw new InvalidArgumentException('Enter your name, subject, and a message of at least 10 characters.');

    $userId = null;
    foreach (['user_id', 'account_id'] as $key) {
        if (isset($data[$key]) && (int) $data[$key] > 0) { $userId = (int) $data[$key]; break; }
    }
    $externalKey = supportClean($data['external_key'] ?? '', 64);
    if ($externalKey === '') {
        $submittedAt = supportClean($data['_date'] ?? $data['submission_date'] ?? '', 40);
        $fingerprint = implode('|', [$submittedAt, strtolower($email), $name, $subject, hash('sha256', $message)]);
        $externalKey = hash('sha256', $fingerprint);
    }

    $submittedAt = null;
    foreach (['_date', 'submission_date', 'submitted_at'] as $key) {
        if (!empty($data[$key])) {
            try { $submittedAt = (new DateTimeImmutable((string) $data[$key]))->format('Y-m-d H:i:s'); } catch (Throwable) {}
            break;
        }
    }

    $existing = $db->prepare('SELECT id FROM support_tickets WHERE external_key = ? LIMIT 1');
    $existing->execute([$externalKey]);
    if ($ticket = $existing->fetch()) return ['id' => (int) $ticket['id'], 'duplicate' => true];

    $statement = $db->prepare('INSERT INTO support_tickets (external_key, user_id, sender_name, sender_email, subject, message, status, unread, source, submitted_at) VALUES (?, ?, ?, ?, ?, ?, \'new\', 1, ?, ?)');
    $statement->execute([
        $externalKey,
        $userId,
        $name,
        $email,
        $subject,
        $message,
        !empty($data['source']) ? supportClean($data['source'], 20) : 'formspree',
        $submittedAt ?: date('Y-m-d H:i:s'),
    ]);
    return ['id' => (int) $db->lastInsertId(), 'duplicate' => false];
}

function supportForwardToFormspree(array $fields): bool
{
    $endpoint = envValue('FORMSPREE_ENDPOINT', 'https://formspree.io/f/meaagydz');
    if (!function_exists('curl_init')) return false;
    $curl = curl_init($endpoint);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    return $response !== false && $status >= 200 && $status < 300;
}

function supportVerifyWebhookSignature(string $rawBody): bool
{
    $secret = envValue('FORMSPREE_WEBHOOK_SECRET', '');
    if ($secret === '') return false;
    $header = $_SERVER['HTTP_FORMSPREE_SIGNATURE'] ?? '';
    if (!preg_match('/(?:^|,)\s*t=(\d+)/', $header, $timeMatch) || !preg_match('/(?:^|,)\s*v1=([a-fA-F0-9]+)/', $header, $signatureMatch)) return false;
    $timestamp = $timeMatch[1];
    if (abs(time() - (int) $timestamp) > 300) return false;
    $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    return hash_equals($expected, strtolower($signatureMatch[1]));
}

function supportSyncFormspree(PDO $db): void
{
    $apiKey = envValue('FORMSPREE_API_KEY', '');
    $hashId = envValue('FORMSPREE_FORM_HASHID', 'meaagydz');
    if ($apiKey === '' || !function_exists('curl_init')) return;
    $curl = curl_init('https://formspree.io/api/0/forms/' . rawurlencode($hashId) . '/submissions?order=desc&limit=100');
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Basic ' . base64_encode(':' . $apiKey)]]);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if (!is_string($response) || $status < 200 || $status >= 300) return;
    $payload = json_decode($response, true);
    if (!is_array($payload) || !isset($payload['submissions']) || !is_array($payload['submissions'])) return;
    foreach ($payload['submissions'] as $submission) {
        if (!is_array($submission)) continue;
        $submissionId = $submission['id'] ?? $submission['_id'] ?? null;
        $externalKey = supportClean($submission['external_key'] ?? '', 64);
        if ($externalKey === '' && $submissionId !== null && $submissionId !== '') {
            $externalKey = 'formspree_' . supportClean($submissionId, 48);
        }
        try {
            supportInsert($db, [
                'name' => $submission['name'] ?? $submission['sender_name'] ?? 'Pro Car User',
                'email' => $submission['email'] ?? '',
                'subject' => $submission['subject'] ?? $submission['_subject'] ?? $submission['topic'] ?? 'Pro Car support request',
                'message' => $submission['message'] ?? '',
                'user_id' => $submission['user_id'] ?? null,
                'external_key' => $externalKey,
                '_date' => $submission['_date'] ?? $submission['created_at'] ?? null,
                'source' => 'formspree_api',
            ]);
        } catch (Throwable) {}
    }
}

try {
    $db = database();
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';

    if ($action === 'webhook' && $method === 'POST') {
        $rawBody = file_get_contents('php://input');
        if (!supportVerifyWebhookSignature($rawBody)) jsonResponse(['error' => 'Invalid webhook signature.'], 401);
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) jsonResponse(['error' => 'Invalid webhook payload.'], 400);
        $submission = is_array($payload['submission'] ?? null) ? $payload['submission'] : $payload;
        $submission['source'] = 'formspree_webhook';
        foreach (['_date', 'id', '_id'] as $key) {
            if (!empty($payload[$key]) && !array_key_exists($key, $submission)) $submission[$key] = $payload[$key];
        }
        $submissionId = $submission['id'] ?? $submission['_id'] ?? null;
        if ($submissionId !== null && $submissionId !== '' && empty($submission['external_key'])) {
            $submission['external_key'] = 'formspree_' . supportClean($submissionId, 48);
        }
        supportInsert($db, $submission);
        jsonResponse(['received' => true]);
    }

    $body = $method === 'GET' ? [] : (!empty($_POST) ? $_POST : requestBody());

    if ($action === 'submit' && $method === 'POST') {
        $currentUser = currentUser();
        $name = supportClean($body['name'] ?? '', 120);
        $email = strtolower(supportClean($body['email'] ?? '', 190));
        $subject = supportClean($body['subject'] ?? '', 180);
        $message = supportClean($body['message'] ?? '', 10000);
        if ($currentUser) {
            if ($name === '') $name = $currentUser['name'];
            if ($email === '') $email = $currentUser['email'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '' || $subject === '' || mb_strlen($message) < 10) {
            jsonResponse(['error' => 'Enter your name, a valid email, subject, and a message of at least 10 characters.'], 422);
        }
        $externalKey = bin2hex(random_bytes(32));
        $ticketData = ['name' => $name, 'email' => $email, 'subject' => $subject, 'message' => $message, 'external_key' => $externalKey, 'user_id' => $currentUser['id'] ?? null, 'source' => 'formspree'];
        $result = supportInsert($db, $ticketData);
        $forwarded = supportForwardToFormspree([
            'name' => $name,
            'email' => $email,
            'subject' => $subject,
            'message' => $message,
            'user_id' => $currentUser['id'] ?? '',
            'account_name' => $name,
            '_subject' => 'New ProCar support request: ' . $subject,
            'submission_date' => date('c'),
            'external_key' => $externalKey,
        ]);
        jsonResponse([
            'success' => true,
            'ticket_id' => $result['id'],
            'forwarded_to_formspree' => $forwarded,
            'message' => $forwarded ? 'Support request sent successfully.' : 'Support request saved. Formspree delivery is currently unavailable.',
        ], $forwarded ? 201 : 202);
    }

    $admin = requireRole(['admin']);

    if ($action === 'list' && $method === 'GET') {
        supportSyncFormspree($db);
        $filter = cleanString($_GET['status'] ?? 'all', 20);
        if (!in_array($filter, ['all', 'new', 'in_progress', 'resolved'], true)) $filter = 'all';
        $where = $filter === 'all' ? '' : ' WHERE status = ?';
        $statement = $filter === 'all'
            ? $db->query('SELECT id, user_id, sender_name, sender_email, subject, LEFT(message, 180) AS message_preview, status, unread, submitted_at, updated_at FROM support_tickets ORDER BY submitted_at DESC, id DESC')
            : $db->prepare('SELECT id, user_id, sender_name, sender_email, subject, LEFT(message, 180) AS message_preview, status, unread, submitted_at, updated_at FROM support_tickets' . $where . ' ORDER BY submitted_at DESC, id DESC');
        if ($filter !== 'all') $statement->execute([$filter]);
        $tickets = $statement->fetchAll();
        $counts = $db->query("SELECT SUM(status='new') AS new_count, SUM(status='in_progress') AS in_progress_count, SUM(status='resolved') AS resolved_count, SUM(unread=1) AS unread_count FROM support_tickets")->fetch();
        jsonResponse(['tickets' => $tickets, 'counts' => $counts ?: ['new_count' => 0, 'in_progress_count' => 0, 'resolved_count' => 0, 'unread_count' => 0]]);
    }

    if ($action === 'detail' && $method === 'GET') {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id < 1) jsonResponse(['error' => 'Invalid support ticket.'], 422);
        $db->prepare('UPDATE support_tickets SET unread = 0 WHERE id = ?')->execute([$id]);
        $statement = $db->prepare('SELECT id, user_id, sender_name, sender_email, subject, message, status, unread, source, submitted_at, resolved_at, created_at, updated_at FROM support_tickets WHERE id = ?');
        $statement->execute([$id]);
        $ticket = $statement->fetch();
        if (!$ticket) jsonResponse(['error' => 'Support ticket not found.'], 404);
        $replies = $db->prepare('SELECT r.id, r.message, r.emailed, r.created_at, u.name AS admin_name FROM support_ticket_replies r LEFT JOIN users u ON u.id = r.admin_user_id WHERE r.ticket_id = ? ORDER BY r.created_at ASC, r.id ASC');
        $replies->execute([$id]);
        jsonResponse(['ticket' => $ticket, 'replies' => $replies->fetchAll()]);
    }

    if ($action === 'update' && $method === 'PUT') {
        $id = (int) ($body['id'] ?? 0);
        $status = cleanString($body['status'] ?? '', 20);
        $reply = cleanString($body['reply'] ?? '', 10000);
        if ($id < 1) jsonResponse(['error' => 'Invalid support ticket.'], 422);
        if (!in_array($status, ['new', 'in_progress', 'resolved'], true)) jsonResponse(['error' => 'Choose a valid ticket status.'], 422);
        $statement = $db->prepare('SELECT sender_email, sender_name, subject FROM support_tickets WHERE id = ?');
        $statement->execute([$id]);
        $ticket = $statement->fetch();
        if (!$ticket) jsonResponse(['error' => 'Support ticket not found.'], 404);

        $db->prepare("UPDATE support_tickets SET status = ?, unread = 0, resolved_at = ? WHERE id = ?")->execute([$status, $status === 'resolved' ? date('Y-m-d H:i:s') : null, $id]);
        $emailed = false;
        if ($reply !== '') {
            $db->prepare('INSERT INTO support_ticket_replies (ticket_id, admin_user_id, message, emailed) VALUES (?, ?, ?, ?)')->execute([$id, $admin['id'], $reply, 0]);
            $headers = "From: " . envValue('MAIL_FROM', 'no-reply@procar.local') . "\r\nReply-To: " . envValue('MAIL_FROM', 'no-reply@procar.local') . "\r\nContent-Type: text/plain; charset=UTF-8\r\n";
            $emailed = mail($ticket['sender_email'], 'Re: ' . $ticket['subject'], $reply . "\n\nPro Car Support\n", $headers);
            if ($emailed) {
                $replyId = (int) $db->lastInsertId();
                $db->prepare('UPDATE support_ticket_replies SET emailed = 1 WHERE id = ?')->execute([$replyId]);
            }
        }
        jsonResponse(['success' => true, 'emailed' => $emailed]);
    }

    jsonResponse(['error' => 'Unknown support action.'], 404);
} catch (InvalidArgumentException $exception) {
    jsonResponse(['error' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    jsonResponse(['error' => 'Unable to process support request.'], 500);
}
