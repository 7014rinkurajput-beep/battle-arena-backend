<?php
require_once "Common/config.php";

function run_query($conn, $sql, $label) {
    if ($conn->query($sql)) {
        return ["success" => true, "message" => $label . " completed."];
    }
    return ["success" => false, "message" => $label . " failed: " . $conn->error];
}

$messages = [];
$has_error = false;

// 1. CREATE SETTINGS TABLE
$sql = "CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$result = run_query($conn, $sql, "Settings table");
$messages[] = $result["message"];

// 2. CREATE DEPOSITS TABLE
$sql = "CREATE TABLE IF NOT EXISTS deposits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    transaction_id VARCHAR(255) NOT NULL,
    status ENUM('Pending', 'Completed', 'Rejected') NOT NULL DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_deposits_user_id (user_id),
    INDEX idx_deposits_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$result = run_query($conn, $sql, "Deposits table");
$messages[] = $result["message"];

// 3. CREATE WITHDRAWALS TABLE
$sql = "CREATE TABLE IF NOT EXISTS withdrawals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_details VARCHAR(255) NULL,
    status ENUM('Pending', 'Completed', 'Rejected') NOT NULL DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_withdrawals_user_id (user_id),
    INDEX idx_withdrawals_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$result = run_query($conn, $sql, "Withdrawals table");
$messages[] = $result["message"];

// 4. ADD NEW WALLET BALANCES TO USERS
$columns_to_add = [
    'wallet_balance' => "DECIMAL(10,2) NOT NULL DEFAULT 0.00",
    'deposit_balance' => "DECIMAL(10,2) NOT NULL DEFAULT 0.00",
    'winning_balance' => "DECIMAL(10,2) NOT NULL DEFAULT 0.00",
    'upi_id' => "VARCHAR(255) NULL"
];

foreach ($columns_to_add as $col => $definition) {
    $check = $conn->query("SHOW COLUMNS FROM users LIKE '$col'");
    if ($check && $check->num_rows == 0) {
        $result = run_query($conn, "ALTER TABLE users ADD COLUMN $col $definition", "Users $col column");
        $messages[] = $result["message"];
    } else {
        $messages[] = "Users $col column already exists.";
    }
}

// 5. CREATE TRANSACTIONS TABLE
$sql = "CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type ENUM('credit','debit') NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    description VARCHAR(255) NOT NULL,
    reference_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_transactions_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$result = run_query($conn, $sql, "Transactions table");
$messages[] = $result["message"];

// Ensure reference_id column exists if transactions table already existed
$check_trans = $conn->query("SHOW COLUMNS FROM transactions LIKE 'reference_id'");
if ($check_trans && $check_trans->num_rows == 0) {
    $result = run_query($conn, "ALTER TABLE transactions ADD COLUMN reference_id INT NULL", "Transactions reference_id column");
    $messages[] = $result["message"];
} else {
    $messages[] = "Transactions reference_id column already exists.";
}

// 6. DEFAULT SETTINGS
$sql = "INSERT IGNORE INTO settings (setting_key, setting_value) VALUES 
('admin_upi_id', ''), ('admin_qr_code', ''), ('wallet_system', 'test_credits')";
$result = run_query($conn, $sql, "Default wallet settings");
$messages[] = $result["message"];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Update</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-950 text-white min-h-screen flex items-center justify-center px-4">
<div class="w-full max-w-lg bg-gray-900 border border-gray-800 rounded-2xl p-6">
    <div class="text-center mb-6">
        <h1 class="text-2xl font-bold">Battle Arena Update</h1>
    </div>
    <div class="space-y-2">
        <?php foreach ($messages as $msg): ?>
            <div class="bg-gray-950 border border-gray-800 rounded-lg px-4 py-3 text-sm text-gray-300">
                <?= htmlspecialchars($msg) ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>
</body>
</html>
