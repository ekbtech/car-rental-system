<?php

declare(strict_types=1);

function loadProjectEnv(): void
{
    $envFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
    if (!is_file($envFile)) {
        return;
    }

    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        $value = trim($value, "\"'");

        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
        }
    }
}

function envValue(string $key, string $default = ''): string
{
    $value = getenv($key);
    return $value === false ? $default : $value;
}

function database(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    loadProjectEnv();

    $host = envValue('MYSQL_HOST', '127.0.0.1');
    $port = envValue('MYSQL_PORT', '3306');
    $name = envValue('MYSQL_DB', 'pro_car');
    $user = envValue('MYSQL_USER', 'root');
    $pass = envValue('MYSQL_PASS', '');
    $charset = envValue('MYSQL_CHARSET', 'utf8mb4');

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    try {
        $pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset={$charset}", $user, $pass, $options);
    } catch (PDOException $exception) {
        if (stripos($exception->getMessage(), 'Unknown database') === false) {
            throw $exception;
        }

        $server = new PDO("mysql:host={$host};port={$port};charset={$charset}", $user, $pass, $options);
        $server->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $name) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset={$charset}", $user, $pass, $options);
    }

    initialiseSchema($pdo);
    return $pdo;
}

function initialiseSchema(PDO $pdo): void
{
    static $initialised = false;
    if ($initialised) {
        return;
    }

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            email VARCHAR(190) NOT NULL UNIQUE,
            phone VARCHAR(40) NULL,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('customer', 'driver', 'company', 'admin') NOT NULL DEFAULT 'customer',
            approval_status ENUM('approved', 'pending', 'rejected') NOT NULL DEFAULT 'approved',
            cv_path VARCHAR(500) NULL,
            rejection_reason VARCHAR(500) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
            totp_secret_encrypted TEXT NULL,
            totp_enrolled_at DATETIME NULL,
            totp_pending_secret_encrypted TEXT NULL,
            totp_pending_expires_at DATETIME NULL,
            totp_pending_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            totp_last_step BIGINT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS cars (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            make VARCHAR(80) NOT NULL,
            model VARCHAR(80) NOT NULL,
            year SMALLINT UNSIGNED NOT NULL,
            category VARCHAR(40) NOT NULL DEFAULT 'Sedan',
            price_per_day DECIMAL(10, 2) NOT NULL,
            location VARCHAR(120) NOT NULL DEFAULT 'Available nationwide',
            image_url VARCHAR(500) NULL,
            description TEXT NULL,
            transmission VARCHAR(40) NULL,
            fuel_type VARCHAR(40) NULL,
            seats TINYINT UNSIGNED NULL,
            owner_user_id INT UNSIGNED NULL,
            approval_status ENUM('approved', 'pending', 'rejected') NOT NULL DEFAULT 'approved',
            rejection_reason VARCHAR(500) NULL,
            is_available TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_cars_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS bookings (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            car_id INT UNSIGNED NOT NULL,
            start_date DATETIME NOT NULL,
            end_date DATETIME NOT NULL,
            pickup_location VARCHAR(180) NULL,
            need_driver TINYINT(1) NOT NULL DEFAULT 0,
            total_price DECIMAL(10, 2) NOT NULL,
            status ENUM('pending', 'approved', 'rejected', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_bookings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_bookings_car FOREIGN KEY (car_id) REFERENCES cars(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS payments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            booking_id INT UNSIGNED NOT NULL,
            method VARCHAR(30) NOT NULL,
            reference VARCHAR(120) NULL,
            amount DECIMAL(10, 2) NOT NULL,
            status ENUM('pending', 'paid', 'failed') NOT NULL DEFAULT 'paid',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_payments_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS reviews (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            booking_id INT UNSIGNED NULL,
            rating TINYINT UNSIGNED NOT NULL,
            comment TEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_reviews_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_reviews_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS email_verification_codes (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(190) NOT NULL,
            name VARCHAR(120) NOT NULL,
            phone VARCHAR(40) NULL,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(20) NOT NULL DEFAULT 'customer',
            code_hash VARCHAR(255) NOT NULL,
            expires_at DATETIME NOT NULL,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_verification_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS support_tickets (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            external_key VARCHAR(64) NULL UNIQUE,
            user_id INT UNSIGNED NULL,
            sender_name VARCHAR(120) NOT NULL,
            sender_email VARCHAR(190) NOT NULL,
            subject VARCHAR(180) NOT NULL,
            message TEXT NOT NULL,
            status ENUM('new', 'in_progress', 'resolved') NOT NULL DEFAULT 'new',
            unread TINYINT(1) NOT NULL DEFAULT 1,
            source VARCHAR(20) NOT NULL DEFAULT 'formspree',
            submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            resolved_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_support_status (status, submitted_at),
            INDEX idx_support_email (sender_email, submitted_at),
            CONSTRAINT fk_support_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS support_ticket_replies (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ticket_id BIGINT UNSIGNED NOT NULL,
            admin_user_id INT UNSIGNED NULL,
            message TEXT NOT NULL,
            emailed TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_support_reply_ticket (ticket_id, created_at),
            CONSTRAINT fk_support_reply_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
            CONSTRAINT fk_support_reply_admin FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    SQL);

    // Keep existing WAMP databases usable after an application update.
    $migrations = [
        "ALTER TABLE users ADD COLUMN approval_status ENUM('approved', 'pending', 'rejected') NOT NULL DEFAULT 'approved'",
        'ALTER TABLE users ADD COLUMN cv_path VARCHAR(500) NULL',
        'ALTER TABLE users ADD COLUMN rejection_reason VARCHAR(500) NULL',
        'ALTER TABLE users ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0',
        'ALTER TABLE users ADD COLUMN totp_secret_encrypted TEXT NULL',
        'ALTER TABLE users ADD COLUMN totp_enrolled_at DATETIME NULL',
        'ALTER TABLE users ADD COLUMN totp_pending_secret_encrypted TEXT NULL',
        'ALTER TABLE users ADD COLUMN totp_pending_expires_at DATETIME NULL',
        'ALTER TABLE users ADD COLUMN totp_pending_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0',
        'ALTER TABLE users ADD COLUMN totp_last_step BIGINT UNSIGNED NULL',
        'ALTER TABLE cars ADD COLUMN description TEXT NULL',
        'ALTER TABLE cars ADD COLUMN transmission VARCHAR(40) NULL',
        'ALTER TABLE cars ADD COLUMN fuel_type VARCHAR(40) NULL',
        'ALTER TABLE cars ADD COLUMN seats TINYINT UNSIGNED NULL',
        'ALTER TABLE cars ADD COLUMN owner_user_id INT UNSIGNED NULL',
        "ALTER TABLE cars ADD COLUMN approval_status ENUM('approved', 'pending', 'rejected') NOT NULL DEFAULT 'approved'",
        'ALTER TABLE cars ADD COLUMN rejection_reason VARCHAR(500) NULL',
    ];
    foreach ($migrations as $migration) {
        try {
            $pdo->exec($migration);
        } catch (PDOException $exception) {
            if (stripos($exception->getMessage(), 'Duplicate column') === false) {
                throw $exception;
            }
        }
    }

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS totp_challenges (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            purpose ENUM('login', 'enrollment') NOT NULL,
            expires_at DATETIME NOT NULL,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            used_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_totp_challenge_user (user_id, expires_at),
            CONSTRAINT fk_totp_challenge_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS recovery_codes (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            code_hash VARCHAR(255) NOT NULL,
            used_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_recovery_user (user_id, used_at),
            CONSTRAINT fk_recovery_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS auth_security_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NULL,
            event_type VARCHAR(64) NOT NULL,
            outcome VARCHAR(32) NOT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            metadata JSON NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_auth_event_user (user_id, created_at),
            CONSTRAINT fk_auth_event_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    SQL);

    try {
        $pdo->exec('ALTER TABLE cars ADD CONSTRAINT fk_cars_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL');
    } catch (PDOException $exception) {
        if (stripos($exception->getMessage(), 'Duplicate') === false) {
            throw $exception;
        }
    }

    $adminExists = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email = 'admin@procar.local'")->fetchColumn() > 0;
    if (!$adminExists) {
        $statement = $pdo->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)');
        $statement->execute(['Pro Car Admin', 'admin@procar.local', password_hash('admin123', PASSWORD_DEFAULT), 'admin']);
    }

    $statement = $pdo->prepare('INSERT INTO cars (make, model, year, category, price_per_day, location, image_url, description, transmission, fuel_type, seats) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $cars = [
        ['Toyota', 'Corolla', 2022, 'Sedan', 35, 'Lagos', 'https://images.unsplash.com/photo-1549317661-bd32c8ce0db2?auto=format&fit=crop&w=900&q=80', 'Reliable, economical city driving with a spacious cabin.', 'Automatic', 'Petrol', 5],
        ['Toyota', 'RAV4', 2023, 'SUV', 62, 'Abuja', 'https://images.unsplash.com/photo-1568844293986-8c8c3f7f4d6f?auto=format&fit=crop&w=900&q=80', 'A confident SUV for road trips, errands, and family travel.', 'Automatic', 'Hybrid', 5],
        ['Mercedes-Benz', 'C-Class', 2021, 'Luxury', 110, 'Lagos', 'https://images.unsplash.com/photo-1618843479313-40f8afb4b4d8?auto=format&fit=crop&w=900&q=80', 'Quiet luxury, premium comfort, and composed performance.', 'Automatic', 'Petrol', 5],
        ['Tesla', 'Model 3', 2023, 'Electric', 95, 'Abuja', 'https://images.unsplash.com/photo-1560958089-b8a1929cea89?auto=format&fit=crop&w=900&q=80', 'Fast, quiet electric travel with a minimalist interior.', 'Automatic', 'Electric', 5],
        ['Honda', 'CR-V', 2022, 'SUV', 68, 'Lagos', 'https://images.unsplash.com/photo-1606664515524-ed2f786a0bd6?auto=format&fit=crop&w=900&q=80', 'Practical comfort and excellent visibility for every journey.', 'Automatic', 'Petrol', 5],
        ['BMW', '3 Series', 2022, 'Executive', 125, 'Abuja', 'https://images.unsplash.com/photo-1555215695-3004980ad54e?auto=format&fit=crop&w=900&q=80', 'Sporty executive transport with a premium cabin.', 'Automatic', 'Petrol', 5],
        ['Audi', 'Q5', 2021, 'Luxury SUV', 118, 'Lagos', 'https://images.unsplash.com/photo-1606664515524-ed2f786a0bd6?auto=format&fit=crop&w=900&q=80', 'Refined SUV comfort with confident all-weather handling.', 'Automatic', 'Petrol', 5],
        ['Ford', 'Ranger', 2023, 'Pickup', 88, 'Port Harcourt', 'https://images.unsplash.com/photo-1551830820-330a71b99659?auto=format&fit=crop&w=900&q=80', 'Capable pickup power for work, cargo, and long routes.', 'Automatic', 'Diesel', 5],
        ['Hyundai', 'Elantra', 2022, 'Sedan', 42, 'Lagos', 'https://images.unsplash.com/photo-1605559424843-9e4c228bf1c2?auto=format&fit=crop&w=900&q=80', 'Comfortable everyday mobility with excellent fuel economy.', 'Automatic', 'Petrol', 5],
        ['Kia', 'Sportage', 2023, 'SUV', 65, 'Abuja', 'https://images.unsplash.com/photo-1621007947382-bb3c3994e3fb?auto=format&fit=crop&w=900&q=80', 'Modern family SUV space with smart safety features.', 'Automatic', 'Hybrid', 5],
        ['Nissan', 'X-Trail', 2021, 'SUV', 59, 'Ibadan', 'https://images.unsplash.com/photo-1542362567-b07e54358753?auto=format&fit=crop&w=900&q=80', 'Flexible seating and confident comfort for longer drives.', 'Automatic', 'Petrol', 7],
        ['Volkswagen', 'Tiguan', 2022, 'SUV', 72, 'Lagos', 'https://images.unsplash.com/photo-1533473359331-0135ef1b58bf?auto=format&fit=crop&w=900&q=80', 'European design and practical space for the whole crew.', 'Automatic', 'Petrol', 5],
        ['Lexus', 'RX 350', 2022, 'Luxury SUV', 135, 'Abuja', 'https://images.unsplash.com/photo-1518987048-93e29699e79a?auto=format&fit=crop&w=900&q=80', 'Smooth, serene luxury for important journeys.', 'Automatic', 'Hybrid', 5],
        ['Porsche', 'Cayenne', 2021, 'Premium SUV', 180, 'Lagos', 'https://images.unsplash.com/photo-1503376780353-7e6692767b70?auto=format&fit=crop&w=900&q=80', 'Performance-led luxury for a memorable drive.', 'Automatic', 'Petrol', 5],
        ['Renault', 'Duster', 2022, 'Economy SUV', 48, 'Kano', 'https://images.unsplash.com/photo-1519641471654-76ce0107ad1b?auto=format&fit=crop&w=900&q=80', 'A value-focused SUV ready for city and country roads.', 'Manual', 'Petrol', 5],
        ['Toyota', 'Hiace', 2020, 'Van', 105, 'Lagos', 'https://images.unsplash.com/photo-1544620347-c4fd4a3d5957?auto=format&fit=crop&w=900&q=80', 'Roomy group transport for airport transfers and events.', 'Automatic', 'Diesel', 12],
        ['Mazda', 'MX-5', 2022, 'Convertible', 98, 'Lagos', 'https://images.unsplash.com/photo-1504215680853-026ed2a45def?auto=format&fit=crop&w=900&q=80', 'Open-air driving with sharp handling and unmistakable style.', 'Manual', 'Petrol', 2],
        ['Jeep', 'Wrangler', 2021, 'Adventure', 115, 'Calabar', 'https://images.unsplash.com/photo-1533473359331-0135ef1b58bf?auto=format&fit=crop&w=900&q=80', 'Adventure-ready capability for weekends beyond the city.', 'Automatic', 'Petrol', 5],
        ['Toyota', 'Camry', 2023, 'Executive', 78, 'Lagos', 'https://images.unsplash.com/photo-1621007947382-bb3c3994e3fb?auto=format&fit=crop&w=900&q=80', 'A polished sedan with generous space and a smooth ride.', 'Automatic', 'Hybrid', 5],
        ['Honda', 'Civic', 2023, 'Sedan', 52, 'Abuja', 'https://images.unsplash.com/photo-1605559424843-9e4c228bf1c2?auto=format&fit=crop&w=900&q=80', 'Modern, efficient, and easy to live with every day.', 'Automatic', 'Petrol', 5],
        ['Mercedes-Benz', 'E-Class', 2022, 'Executive', 145, 'Lagos', 'https://images.unsplash.com/photo-1563720223185-11003d516935?auto=format&fit=crop&w=900&q=80', 'Business-class comfort with elegant detail throughout.', 'Automatic', 'Petrol', 5],
        ['BMW', 'X5', 2023, 'Luxury SUV', 155, 'Abuja', 'https://images.unsplash.com/photo-1556189250-72ba954cfc2b?auto=format&fit=crop&w=900&q=80', 'Confident power, premium space, and long-distance comfort.', 'Automatic', 'Petrol', 5],
        ['Audi', 'A4', 2022, 'Executive', 108, 'Port Harcourt', 'https://images.unsplash.com/photo-1606664515524-ed2f786a0bd6?auto=format&fit=crop&w=900&q=80', 'Clean design and composed performance for city travel.', 'Automatic', 'Petrol', 5],
        ['Volvo', 'XC60', 2022, 'Luxury SUV', 128, 'Lagos', 'https://images.unsplash.com/photo-1492144534655-ae79c964c9d7?auto=format&fit=crop&w=900&q=80', 'Scandinavian calm with a strong focus on safety.', 'Automatic', 'Hybrid', 5],
        ['Land Rover', 'Discovery', 2021, 'Adventure', 160, 'Calabar', 'https://images.unsplash.com/photo-1503736334956-4c8f8e92946d?auto=format&fit=crop&w=900&q=80', 'A capable seven-seat explorer for ambitious routes.', 'Automatic', 'Diesel', 7],
        ['Nissan', 'Micra', 2022, 'Economy', 30, 'Ibadan', 'https://images.unsplash.com/photo-1494905998402-395d579af36f?auto=format&fit=crop&w=900&q=80', 'Compact city mobility that is simple and affordable.', 'Automatic', 'Petrol', 5],
        ['Peugeot', '3008', 2022, 'SUV', 64, 'Lagos', 'https://images.unsplash.com/photo-1542362567-b07e54358753?auto=format&fit=crop&w=900&q=80', 'Distinctive style with a comfortable, flexible interior.', 'Automatic', 'Petrol', 5],
        ['Ford', 'Transit', 2021, 'Van', 120, 'Abuja', 'https://images.unsplash.com/photo-1570125909232-eb263c188f7e?auto=format&fit=crop&w=900&q=80', 'Practical people-moving space for groups and events.', 'Manual', 'Diesel', 14],
        ['Hyundai', 'Ioniq 5', 2023, 'Electric', 102, 'Lagos', 'https://images.unsplash.com/photo-1619767886558-efdc259cde1a?auto=format&fit=crop&w=900&q=80', 'Bold electric design with fast, quiet touring.', 'Automatic', 'Electric', 5],
        ['Toyota', 'Land Cruiser', 2022, 'Premium SUV', 175, 'Abuja', 'https://images.unsplash.com/photo-1519641471654-76ce0107ad1b?auto=format&fit=crop&w=900&q=80', 'Legendary capability and comfort for every terrain.', 'Automatic', 'Diesel', 7],
        ['Chevrolet', 'Camaro', 2021, 'Convertible', 140, 'Lagos', 'https://images.unsplash.com/photo-1552519507-da3b142c6e3d?auto=format&fit=crop&w=900&q=80', 'A spirited drive with unmistakable character.', 'Automatic', 'Petrol', 4],
        ['Suzuki', 'Swift', 2022, 'Economy', 32, 'Kano', 'https://images.unsplash.com/photo-1542282088-fe8426682b8f?auto=format&fit=crop&w=900&q=80', 'Light, nimble, and friendly for daily city trips.', 'Automatic', 'Petrol', 5],
    ];
    $existing = $pdo->query('SELECT make, model FROM cars')->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($cars as $car) {
        if (!isset($existing[$car[0]]) || !in_array($car[1], array_keys($existing), true)) {
            $check = $pdo->prepare('SELECT id FROM cars WHERE make = ? AND model = ?');
            $check->execute([$car[0], $car[1]]);
            if (!$check->fetch()) {
                $statement->execute($car);
            }
        }
    }

    $initialised = true;
}