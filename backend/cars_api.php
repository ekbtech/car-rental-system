<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

try {
    $db = database();
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $conditions = ['1 = 1'];
        $parameters = [];
        if (!empty($_GET['category'])) {
            $conditions[] = 'category = ?';
            $parameters[] = cleanString($_GET['category'], 40);
        }
        if (!empty($_GET['search'])) {
            $conditions[] = '(make LIKE ? OR model LIKE ? OR category LIKE ? OR location LIKE ?)';
            $search = '%' . cleanString($_GET['search'], 80) . '%';
            array_push($parameters, $search, $search, $search, $search);
        }
        if (isset($_GET['mine'])) {
            $user = requireRole(['company']);
            $conditions[] = 'owner_user_id = ' . (int) $user['id'];
        } elseif (!isset($_GET['admin']) && !isset($_GET['pending'])) {
            $conditions[] = 'is_available = 1';
            $conditions[] = "approval_status = 'approved'";
        } else {
            requireRole(['admin']);
            if (isset($_GET['pending'])) {
                $conditions[] = "approval_status = 'pending'";
            }
        }

        $statement = $db->prepare('SELECT id, make, model, year, category, price_per_day, location, image_url, description, transmission, fuel_type, seats, owner_user_id, approval_status, rejection_reason, is_available FROM cars WHERE ' . implode(' AND ', $conditions) . ' ORDER BY created_at DESC');
        $statement->execute($parameters);
        jsonResponse(['cars' => $statement->fetchAll()]);
    }

    $user = requireUser();
    $body = requestBody();
    if (!in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
        jsonResponse(['error' => 'Method not allowed.'], 405);
    }

    if ($method === 'DELETE') {
        requireRole(['admin']);
        $id = (int) ($_GET['id'] ?? $body['id'] ?? 0);
        $statement = $db->prepare('DELETE FROM cars WHERE id = ?');
        $statement->execute([$id]);
        jsonResponse(['success' => true]);
    }

    $make = cleanString($body['make'] ?? '', 80);
    $model = cleanString($body['model'] ?? '', 80);
    $year = (int) ($body['year'] ?? 0);
    $category = cleanString($body['category'] ?? 'Sedan', 40);
    $price = (float) ($body['price_per_day'] ?? $body['price'] ?? 0);
    $location = cleanString($body['location'] ?? 'Available nationwide', 120);
    $image = cleanString($body['image_url'] ?? '', 500);
    $description = cleanString($body['description'] ?? '', 2000);
    $transmission = cleanString($body['transmission'] ?? '', 40);
    $fuelType = cleanString($body['fuel_type'] ?? '', 40);
    $seats = max(1, (int) ($body['seats'] ?? 5));
    $available = !empty($body['is_available']) ? 1 : 0;

    if ($make === '' || $model === '' || $year < 1950 || $price <= 0) {
        jsonResponse(['error' => 'Make, model, year, and a positive daily price are required.'], 422);
    }

    if ($method === 'PUT') {
        requireRole(['admin']);
        $id = (int) ($body['id'] ?? 0);
        if (isset($body['approval_status']) && !isset($body['make'])) {
            $approvalStatus = cleanString($body['approval_status'], 20);
            if (!in_array($approvalStatus, ['approved', 'rejected'], true)) {
                jsonResponse(['error' => 'Invalid vehicle approval status.'], 422);
            }
            $rejectionReason = cleanString($body['rejection_reason'] ?? '', 500);
            $statement = $db->prepare('UPDATE cars SET approval_status = ?, rejection_reason = ?, is_available = ? WHERE id = ?');
            $statement->execute([$approvalStatus, $rejectionReason ?: null, $approvalStatus === 'approved' ? 1 : 0, $id]);
            jsonResponse(['success' => true]);
        }
        $approvalStatus = cleanString($body['approval_status'] ?? 'approved', 20);
        $rejectionReason = cleanString($body['rejection_reason'] ?? '', 500);
        $statement = $db->prepare('UPDATE cars SET make = ?, model = ?, year = ?, category = ?, price_per_day = ?, location = ?, image_url = ?, description = ?, transmission = ?, fuel_type = ?, seats = ?, is_available = ?, approval_status = ?, rejection_reason = ? WHERE id = ?');
        $statement->execute([$make, $model, $year, $category, $price, $location, $image ?: null, $description ?: null, $transmission ?: null, $fuelType ?: null, $seats, $available, $approvalStatus, $rejectionReason ?: null, $id]);
        jsonResponse(['success' => true]);
    }

    if ($user['role'] === 'admin') {
        $approvalStatus = 'approved';
        $ownerId = null;
    } elseif ($user['role'] === 'company') {
        $approvalStatus = 'pending';
        $ownerId = $user['id'];
        $available = 0;
    } else {
        jsonResponse(['error' => 'Only approved companies and admins can submit cars.'], 403);
    }
    $statement = $db->prepare('INSERT INTO cars (make, model, year, category, price_per_day, location, image_url, description, transmission, fuel_type, seats, owner_user_id, approval_status, is_available) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $statement->execute([$make, $model, $year, $category, $price, $location, $image ?: null, $description ?: null, $transmission ?: null, $fuelType ?: null, $seats, $ownerId, $approvalStatus, $available]);
    jsonResponse(['id' => (int) $db->lastInsertId()], 201);
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    jsonResponse(['error' => 'Unable to load or update cars. Check your MySQL settings.'], 500);
}