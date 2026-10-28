<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

try {
    $db = database();
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $user = requireUser();
        $adminView = !empty($_GET['admin']);
        if ($adminView && $user['role'] !== 'admin') {
            jsonResponse(['error' => 'Admin access required.'], 403);
        }
        $where = $adminView ? '' : 'WHERE b.user_id = ' . (int) $user['id'];
        $statement = $db->query("SELECT b.*, CONCAT(c.make, ' ', c.model) AS car_name, c.location AS car_location, u.name AS customer_name, u.email AS customer_email FROM bookings b JOIN cars c ON c.id = b.car_id JOIN users u ON u.id = b.user_id {$where} ORDER BY b.created_at DESC");
        jsonResponse(['bookings' => $statement->fetchAll()]);
    }

    $body = requestBody();
    if ($method === 'POST') {
        $user = requireUser();
        $userId = (int) ($body['user_id'] ?? $body['userId'] ?? $user['id']);
        if ($userId !== (int) $user['id'] && $user['role'] !== 'admin') {
            $userId = (int) $user['id'];
        }
        $carId = (int) ($body['car_id'] ?? $body['carId'] ?? 0);
        $start = parseDate((string) ($body['start_date'] ?? $body['startDate'] ?? ''));
        $end = parseDate((string) ($body['end_date'] ?? $body['endDate'] ?? ''));
        $pickup = cleanString($body['pickup_location'] ?? $body['pickupLocation'] ?? '', 180);
        $driver = !empty($body['need_driver']) || !empty($body['needDriver']);

        if (!$start || !$end || $end <= $start || $start < new DateTimeImmutable('now') || $carId < 1) {
            jsonResponse(['error' => 'Choose a valid car and a future date range.'], 422);
        }
        $carStatement = $db->prepare('SELECT price_per_day FROM cars WHERE id = ? AND is_available = 1');
        $carStatement->execute([$carId]);
        $car = $carStatement->fetch();
        if (!$car) {
            jsonResponse(['error' => 'That car is no longer available.'], 404);
        }
        $days = max(1, (int) $start->diff($end)->days);
        $total = ($days * (float) $car['price_per_day']) + ($driver ? 25 : 0);
        $statement = $db->prepare('INSERT INTO bookings (user_id, car_id, start_date, end_date, pickup_location, need_driver, total_price) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $statement->execute([$userId, $carId, $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $pickup ?: null, $driver ? 1 : 0, $total]);
        jsonResponse(['id' => (int) $db->lastInsertId(), 'total_price' => $total], 201);
    }

    $user = requireUser();
    $id = (int) ($_GET['id'] ?? $body['id'] ?? 0);
    if ($method === 'DELETE') {
        $statement = $db->prepare('UPDATE bookings SET status = \'cancelled\' WHERE id = ? AND (user_id = ? OR ? = 1)');
        $statement->execute([$id, $user['id'], $user['role'] === 'admin' ? 1 : 0]);
        jsonResponse(['success' => true]);
    }
    if ($method === 'PUT' && $user['role'] === 'admin') {
        $status = cleanString($body['status'] ?? 'pending', 20);
        if (!in_array($status, ['pending', 'approved', 'rejected', 'completed', 'cancelled'], true)) {
            jsonResponse(['error' => 'Invalid booking status.'], 422);
        }
        $statement = $db->prepare('UPDATE bookings SET status = ? WHERE id = ?');
        $statement->execute([$status, $id]);
        jsonResponse(['success' => true]);
    }
    jsonResponse(['error' => 'Method not allowed.'], 405);
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    jsonResponse(['error' => 'Unable to process the booking. Check your MySQL settings.'], 500);
}