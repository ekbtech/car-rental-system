<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

try {
    $db = database();
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $statement = $db->query('SELECT r.id, r.rating, r.comment, r.created_at, u.name FROM reviews r JOIN users u ON u.id = r.user_id ORDER BY r.created_at DESC LIMIT 12');
        jsonResponse(['reviews' => $statement->fetchAll()]);
    }

    $user = requireUser();
    $body = requestBody();
    $rating = (int) ($body['rating'] ?? 0);
    $comment = cleanString($body['comment'] ?? '', 1000);
    $bookingId = (int) ($body['booking_id'] ?? $body['bookingId'] ?? 0);
    if ($rating < 1 || $rating > 5 || $comment === '') {
        jsonResponse(['error' => 'Choose a rating and write a short review.'], 422);
    }
    $statement = $db->prepare('INSERT INTO reviews (user_id, booking_id, rating, comment) VALUES (?, ?, ?, ?)');
    $statement->execute([$user['id'], $bookingId ?: null, $rating, $comment]);
    jsonResponse(['success' => true], 201);
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    jsonResponse(['error' => 'Unable to process the review.'], 500);
}