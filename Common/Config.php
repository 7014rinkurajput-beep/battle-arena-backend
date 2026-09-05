<?php

// =====================================================
// BATTLE ARENA
// COMMON DATABASE CONFIGURATION (UPDATED & OPTIMIZED)
// =====================================================

// 1. Enable Strict Error Reporting for MySQLi (PHP 8.1+ Standard)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// 2. Start Session Safely
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// =====================================================
// CONFIGURATION SETTINGS
// =====================================================

define('DB_HOST', '127.0.0.1');
define('DB_USER', 'root');
define('DB_PASS', 'root');
define('DB_NAME', 'battle_arena');

// Set to 'false' after your tables are created to speed up page loading!
define('AUTO_MIGRATE', true); 


// =====================================================
// CREATE DATABASE & CONNECTION
// =====================================================

try {
    // Connect without DB first to ensure we can create it if missing
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS);
    $conn->set_charset("utf8mb4");

    // Create DB if it does not exist
    $conn->query("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    
    // Select the DB
    $conn->select_db(DB_NAME);

} catch (mysqli_sql_exception $e) {
    // Catch block prevents DB credentials from leaking on a connection error
    error_log("Database connection error: " . $e->getMessage());
    die("Database connection failed. Please check your configuration.");
}


// =====================================================
// HELPER FUNCTIONS
// =====================================================

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function require_user_login()
{
    if (!isset($_SESSION["user_id"]) || !is_numeric($_SESSION["user_id"])) {
        header("Location: login.php");
        exit;
    }
}

function require_admin_login()
{
    if (!isset($_SESSION["admin_logged_in"]) || $_SESSION["admin_logged_in"] !== true) {
        header("Location: login.php");
        exit;
    }
}

function current_user_id(): int
{
    return (isset($_SESSION["user_id"]) && is_numeric($_SESSION["user_id"])) ? (int)$_SESSION["user_id"] : 0;
}

function current_admin_name(): string
{
    return $_SESSION["admin_username"] ?? "Admin";
}

function redirect_self($url)
{
    header("Location: " . filter_var($url, FILTER_SANITIZE_URL));
    exit;
}


// =====================================================
// AUTO-CREATE TABLES & COLUMNS
// =====================================================

if (AUTO_MIGRATE) {
    
    // Create Tables
    $conn->query("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        full_name VARCHAR(100) NULL,
        fullname VARCHAR(100) NULL,
        username VARCHAR(50) NOT NULL,
        mobile VARCHAR(15) NOT NULL,
        phone VARCHAR(15) NULL,
        email VARCHAR(100) NOT NULL,
        password VARCHAR(255) NOT NULL,
        wallet_balance DECIMAL(10,2) DEFAULT 0.00,
        deposit_balance DECIMAL(10,2) DEFAULT 0.00,
        winning_balance DECIMAL(10,2) DEFAULT 0.00,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $conn->query("CREATE TABLE IF NOT EXISTS tournaments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(150) NOT NULL,
        game_name VARCHAR(100) DEFAULT 'Free Fire',
        entry_fee DECIMAL(10,2) DEFAULT 0.00,
        prize_pool DECIMAL(10,2) DEFAULT 0.00,
        per_kill DECIMAL(10,2) DEFAULT 0.00,
        map VARCHAR(50) DEFAULT 'Bermuda',
        version VARCHAR(50) DEFAULT 'Squad',
        match_time DATETIME NULL,
        status VARCHAR(50) DEFAULT 'Upcoming',
        room_id VARCHAR(100) NULL,
        room_password VARCHAR(100) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $conn->query("CREATE TABLE IF NOT EXISTS participants (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        tournament_id INT NOT NULL,
        in_game_name VARCHAR(100) NULL,
        ff_uid VARCHAR(50) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $conn->query("CREATE TABLE IF NOT EXISTS joined_tournaments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        tournament_id INT NOT NULL,
        in_game_name VARCHAR(100) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $conn->query("CREATE TABLE IF NOT EXISTS transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        type VARCHAR(20) NOT NULL,
        description TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $conn->query("CREATE TABLE IF NOT EXISTS withdrawals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        upi_id VARCHAR(100) NULL,
        status VARCHAR(50) DEFAULT 'Pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $conn->query("CREATE TABLE IF NOT EXISTS deposit_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        status VARCHAR(50) DEFAULT 'Pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Safe Column Updates (Checks if column exists before altering)
    $columns_to_sync = [
        ['table' => 'users', 'column' => 'full_name', 'def' => 'VARCHAR(100) NULL'],
        ['table' => 'users', 'column' => 'fullname', 'def' => 'VARCHAR(100) NULL'],
        ['table' => 'users', 'column' => 'mobile', 'def' => 'VARCHAR(15) NULL'],
        ['table' => 'participants', 'column' => 'in_game_name', 'def' => 'VARCHAR(100) NULL'],
        ['table' => 'participants', 'column' => 'ff_uid', 'def' => 'VARCHAR(50) NULL']
    ];

    foreach ($columns_to_sync as $col) {
        $table = $col['table'];
        $column = $col['column'];
        $def = $col['def'];
        
        $check = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '" . DB_NAME . "' AND TABLE_NAME = '{$table}' AND COLUMN_NAME = '{$column}'");
        
        if ($check->num_rows === 0) {
            $conn->query("ALTER TABLE {$table} ADD COLUMN {$column} {$def}");
        }
    }
}
?>

