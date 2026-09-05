<?php

// Prevent accidental re-installation
session_start();

// Database Configuration
$host = "127.0.0.1";
$username = "root";
$password = "root";
$database = "battle_arena";

// Connect to MySQL server
$conn = new mysqli($host, $username, $password);

if ($conn->connect_error) {
    die("MySQL Connection Failed: " . $conn->connect_error);
}

// Create database
$sql = "CREATE DATABASE IF NOT EXISTS `$database`
        CHARACTER SET utf8mb4
        COLLATE utf8mb4_unicode_ci";

if (!$conn->query($sql)) {
    die("Database creation failed: " . $conn->error);
}

// Select database
$conn->select_db($database);

// Set charset
$conn->set_charset("utf8mb4");


// =====================================================
// 1. USERS TABLE
// =====================================================

$sql = "CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    wallet_balance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB";

if (!$conn->query($sql)) {
    die("Users table creation failed: " . $conn->error);
}


// =====================================================
// 2. ADMIN TABLE
// =====================================================

$sql = "CREATE TABLE IF NOT EXISTS admin (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL
) ENGINE=InnoDB";

if (!$conn->query($sql)) {
    die("Admin table creation failed: " . $conn->error);
}


// =====================================================
// 3. TOURNAMENTS TABLE
// =====================================================

$sql = "CREATE TABLE IF NOT EXISTS tournaments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    game_name VARCHAR(100) NOT NULL,
    entry_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    prize_pool DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    match_time DATETIME NOT NULL,
    room_id VARCHAR(100) DEFAULT NULL,
    room_password VARCHAR(100) DEFAULT NULL,
    status ENUM('Upcoming','Live','Completed','Cancelled')
        NOT NULL DEFAULT 'Upcoming',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB";

if (!$conn->query($sql)) {
    die("Tournaments table creation failed: " . $conn->error);
}


// =====================================================
// 4. PARTICIPANTS TABLE
// =====================================================

$sql = "CREATE TABLE IF NOT EXISTS participants (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    tournament_id INT UNSIGNED NOT NULL,

    UNIQUE KEY unique_participant (user_id, tournament_id),

    CONSTRAINT fk_participant_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_participant_tournament
        FOREIGN KEY (tournament_id)
        REFERENCES tournaments(id)
        ON DELETE CASCADE

) ENGINE=InnoDB";

if (!$conn->query($sql)) {
    die("Participants table creation failed: " . $conn->error);
}


// =====================================================
// 5. TRANSACTIONS TABLE
// =====================================================

$sql = "CREATE TABLE IF NOT EXISTS transactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    type ENUM('credit','debit') NOT NULL,
    description VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_transaction_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE

) ENGINE=InnoDB";

if (!$conn->query($sql)) {
    die("Transactions table creation failed: " . $conn->error);
}


// =====================================================
// INSERT DEFAULT ADMIN
// =====================================================

$admin_username = "admin";
$admin_password = password_hash("admin123", PASSWORD_DEFAULT);

// Check if admin already exists
$check = $conn->prepare(
    "SELECT id FROM admin WHERE username = ? LIMIT 1"
);

$check->bind_param("s", $admin_username);
$check->execute();
$result = $check->get_result();

if ($result->num_rows === 0) {

    $insert = $conn->prepare(
        "INSERT INTO admin (username, password)
         VALUES (?, ?)"
    );

    $insert->bind_param(
        "ss",
        $admin_username,
        $admin_password
    );

    $insert->execute();
    $insert->close();

    $admin_created = true;

} else {

    $admin_created = false;
}

$check->close();


// Close connection
$conn->close();

?>

<!DOCTYPE html>
<html lang="en">
<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Battle Arena Installation</title>

    <script src="https://cdn.tailwindcss.com"></script>

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

</head>

<body class="bg-gray-950 text-white min-h-screen
             flex items-center justify-center
             px-5">

    <div class="w-full max-w-md">

        <div class="bg-gray-900 border border-gray-800
                    rounded-2xl p-6 shadow-xl">

            <div class="text-center mb-6">

                <div class="w-16 h-16 mx-auto mb-4
                            rounded-2xl bg-indigo-600
                            flex items-center justify-center">

                    <i class="fa-solid fa-trophy text-2xl"></i>

                </div>

                <h1 class="text-2xl font-bold">
                    Battle Arena
                </h1>

                <p class="text-gray-400 text-sm mt-1">
                    Installation Completed
                </p>

            </div>

            <div class="bg-green-950/40
                        border border-green-800
                        rounded-xl p-4 mb-5">

                <div class="flex items-center gap-3">

                    <i class="fa-solid fa-circle-check
                              text-green-400"></i>

                    <div>

                        <p class="font-semibold text-green-300">
                            Database Ready
                        </p>

                        <p class="text-sm text-gray-400">
                            All required tables have been created.
                        </p>

                    </div>

                </div>

            </div>

            <div class="bg-gray-800/70
                        rounded-xl p-4 mb-5">

                <p class="text-sm text-gray-400 mb-2">
                    Default Admin Login
                </p>

                <p class="text-sm">
                    Username:
                    <span class="font-semibold">admin</span>
                </p>

                <p class="text-sm mt-1">
                    Password:
                    <span class="font-semibold">admin123</span>
                </p>

            </div>

            <a href="login.php"
               class="block w-full text-center
                      bg-indigo-600 hover:bg-indigo-500
                      rounded-xl py-3 font-semibold
                      transition">

                Continue to Login

            </a>

        </div>

    </div>

</body>
</html>