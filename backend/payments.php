<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

try {
    $db = database();
    $user = requireRole(['admin']);
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $statement = $db->query('SELECT p.*, b.user_id, CONCAT(c.make, " ", c.model) AS car_name, u.name AS customer_name FROM payments p JOIN bookings b ON b.id = p.booking_id JOIN cars c ON c.id = b.car_id JOIN users u ON u.id = b.user_id ORDER BY p.created_at DESC');
        jsonResponse(['payments' => $statement->fetchAll()]);
    }
    $body = requestBody();
    $bookingId = (int) ($body['booking_id'] ?? 0);
    $method = cleanString($body['method'] ?? '', 30);
    $reference = cleanString($body['reference'] ?? '', 120);
    $statement = $db->prepare('SELECT total_price FROM bookings WHERE id = ?');
    $statement->execute([$bookingId]);
    $booking = $statement->fetch();
    if (!$booking || $method === '') {
        jsonResponse(['error' => 'A valid booking and payment method are required.'], 422);
    }
    $statement = $db->prepare('INSERT INTO payments (booking_id, method, reference, amount) VALUES (?, ?, ?, ?)');
    $statement->execute([$bookingId, $method, $reference ?: null, $booking['total_price']]);
    jsonResponse(['success' => true], 201);
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    jsonResponse(['error' => 'Unable to process the payment.'], 500);
}