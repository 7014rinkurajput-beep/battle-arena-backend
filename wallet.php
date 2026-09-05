<?php
// Enable error reporting to reveal the exact cause of any future 500 errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// FIX: Capitalized 'Common' to match your exact folder name (fixes the HTTP 500 Error)
require_once "Common/config.php";
date_default_timezone_set('Asia/Kolkata');

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION["user_id"];
$message = "";
$message_type = "";

// AUTO-CREATE WITHDRAWALS TABLE & UPI ID COLUMN IF NOT EXISTS
$conn->query("CREATE TABLE IF NOT EXISTS withdrawals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    upi_id VARCHAR(100) NULL,
    status VARCHAR(50) DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$chk_upi = $conn->query("SHOW COLUMNS FROM withdrawals LIKE 'upi_id'");
if ($chk_upi && $chk_upi->num_rows === 0) {
    try { $conn->query("ALTER TABLE withdrawals ADD COLUMN upi_id VARCHAR(100) NULL"); } catch(Exception $e){}
}

// AUTO-ADD COLUMNS FOR SEPARATE WALLET BALANCES & ACCOUNT STATUS IF MISSING
$check_cols_u = [
    "deposit_balance" => "DECIMAL(10,2) DEFAULT 0.00", 
    "winning_balance" => "DECIMAL(10,2) DEFAULT 0.00",
    "account_status" => "VARCHAR(20) DEFAULT 'Active'"
];
foreach ($check_cols_u as $col => $def) {
    $chk = $conn->query("SHOW COLUMNS FROM users LIKE '$col'");
    if ($chk && $chk->num_rows === 0) {
        try { $conn->query("ALTER TABLE users ADD COLUMN `$col` $def"); } catch(Exception $e){}
    }
}

// GET USER DATA EARLY FOR STATUS CHECKS
$stmt = $conn->prepare("SELECT username, wallet_balance, deposit_balance, winning_balance, account_status FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$account_status = $user["account_status"] ?? "Active";

// HANDLE WITHDRAWAL REQUEST SUBMISSION (WITH PRG PATTERN)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["request_withdrawal"])) {
    $withdraw_amount = floatval($_POST["withdraw_amount"] ?? 0);
    $upi_id = trim($_POST["upi_id"] ?? "");

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT wallet_balance, winning_balance, account_status FROM users WHERE id = ? LIMIT 1 FOR UPDATE");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $user_data = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // BLOCK WITHDRAWALS IF FROZEN
        if (($user_data['account_status'] ?? 'Active') === 'Frozen') {
            throw new Exception("Your account is currently frozen. Transactions are restricted.");
        }

        $winning_balance = (float)($user_data["winning_balance"] ?? 0);

        if ($withdraw_amount < 10) {
            throw new Exception("Minimum withdrawal is 10 rupees.");
        }
        if ($upi_id === "") {
            throw new Exception("Please enter your UPI ID for payout.");
        }
        if ($winning_balance < $withdraw_amount) {
            throw new Exception("Insufficient amount.");
        }

        $new_winning_balance = $winning_balance - $withdraw_amount;
        $deposit_balance = (float)($user_data["deposit_balance"] ?? 0);
        $new_wallet_balance = $deposit_balance + $new_winning_balance;

        $stmt = $conn->prepare("UPDATE users SET wallet_balance = ?, winning_balance = ? WHERE id = ?");
        $stmt->bind_param("ddi", $new_wallet_balance, $new_winning_balance, $user_id);
        if (!$stmt->execute()) throw new Exception("Failed to update wallet balance.");
        $stmt->close();

        $stmt = $conn->prepare("INSERT INTO withdrawals (user_id, amount, upi_id, status) VALUES (?, ?, ?, 'Pending')");
        $stmt->bind_param("ids", $user_id, $withdraw_amount, $upi_id);
        if (!$stmt->execute()) throw new Exception("Failed to submit withdrawal request.");
        $stmt->close();

        $desc = "Withdrawal request submitted (Pending) - UPI: " . $upi_id;
        $stmt = $conn->prepare("INSERT INTO transactions (user_id, amount, type, description) VALUES (?, ?, 'debit', ?)");
        $stmt->bind_param("ids", $user_id, $withdraw_amount, $desc);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        $_SESSION["flash_msg"] = "Withdrawal request of ₹" . number_format($withdraw_amount, 2) . " submitted successfully.";
        $_SESSION["flash_type"] = "success";
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION["flash_msg"] = $e->getMessage();
        $_SESSION["flash_type"] = "error";
    }

    // Redirect to clear form POST data and prevent Android Chrome resubmission
    header("Location: wallet.php");
    exit;
}

// Check for and display flash messages after redirect
if (isset($_SESSION["flash_msg"])) {
    $message = $_SESSION["flash_msg"];
    $message_type = $_SESSION["flash_type"];
    unset($_SESSION["flash_msg"], $_SESSION["flash_type"]);
}

// FETCH TRANSACTIONS / CATEGORIES
$deposit_requests_tx = [];
$chk_dr = $conn->query("SHOW TABLES LIKE 'deposit_requests'");
if ($chk_dr && $chk_dr->num_rows > 0) {
    $stmt = $conn->prepare("SELECT amount, 'Deposit Request' as description, status, created_at FROM deposit_requests WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) { 
        $deposit_requests_tx[] = $row; 
    }
    $stmt->close();
}

$admin_tx = [];
$stmt = $conn->prepare("
    SELECT amount, description, 'Approved' as status, created_at 
    FROM transactions 
    WHERE user_id = ? 
    AND type = 'credit' 
    AND description LIKE '%Admin%'
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) { $admin_tx[] = $row; }
$stmt->close();

$all_deposits = array_merge($deposit_requests_tx, $admin_tx);
usort($all_deposits, function($a, $b) {
    return strtotime($b['created_at']) - strtotime($a['created_at']);
});

$withdrawals = [];
$stmt = $conn->prepare("SELECT amount, upi_id, status, created_at FROM withdrawals WHERE user_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) { $withdrawals[] = $row; }
$stmt->close();

$all_tx = [];
$stmt = $conn->prepare("
    SELECT amount, type, description, created_at 
    FROM transactions 
    WHERE user_id = ? 
    ORDER BY created_at DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) { $all_tx[] = $row; }
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wallet - Battle Arena</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>
<body class="bg-gray-950 text-white min-h-screen pb-24">

<header class="sticky top-0 z-50 bg-gray-950/95 backdrop-blur border-b border-gray-800">
    <div class="max-w-3xl mx-auto px-4 py-4 flex items-center justify-between">
        <div>
            <p class="text-xs text-gray-500">Battle Arena</p>
            <h1 class="text-xl font-bold">My Wallet</h1>
        </div>
        <div class="bg-gray-900 border border-gray-800 rounded-xl px-3 py-2">
            <i class="fa-solid fa-wallet text-indigo-400 mr-1"></i> ₹<?= number_format((float)($user["deposit_balance"] + $user["winning_balance"]), 2) ?>
        </div>
    </div>
</header>

<main class="max-w-3xl mx-auto px-4 py-6">

    <!-- FROZEN BANNER -->
    <?php if ($account_status === 'Frozen'): ?>
        <div class="mb-6 rounded-xl p-4 bg-yellow-950/50 border border-yellow-800 text-yellow-400 flex items-start gap-3 shadow-lg shadow-yellow-900/20">
            <i class="fa-solid fa-lock mt-1 text-lg"></i>
            <div>
                <h3 class="font-bold">Account Frozen</h3>
                <p class="text-sm mt-1">Your account has been temporarily restricted. Deposits and withdrawals are disabled. Contact support via the Telegram bot for assistance.</p>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($message !== ""): ?>
        <div class="mb-6 rounded-xl p-4 <?= $message_type === 'success' ? 'bg-green-950/50 border border-green-800 text-green-300' : 'bg-red-950/50 border border-red-800 text-red-300' ?>">
            <div class="flex items-center gap-3">
                <i class="fa-solid <?= $message_type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
                <span><?= htmlspecialchars($message) ?></span>
            </div>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-2 gap-4 mb-6">
        <div class="bg-gray-900 border border-gray-800 rounded-2xl p-4">
            <p class="text-xs text-gray-500">Deposit Balance</p>
            <p class="text-xl font-bold text-indigo-400 mt-1">₹<?= number_format((float)($user["deposit_balance"] ?? 0), 2) ?></p>
        </div>
        <div class="bg-gray-900 border border-gray-800 rounded-2xl p-4">
            <p class="text-xs text-gray-500">Winning Balance</p>
            <p class="text-xl font-bold text-yellow-400 mt-1">₹<?= number_format((float)($user["winning_balance"] ?? 0), 2) ?></p>
        </div>
    </div>

    <!-- ACTION BUTTONS: DEPOSIT & WITHDRAW (FROZEN LOCKS) -->
    <div class="grid grid-cols-2 gap-4 mb-6">
        <div class="bg-gradient-to-r from-blue-900/40 to-indigo-900/40 border border-blue-800/50 rounded-2xl p-4 flex items-center justify-between">
            <div>
                <h3 class="font-bold text-sm">Add Money</h3>
                <p class="text-[11px] text-gray-400 mt-0.5">Deposit via QR</p>
            </div>
            <?php if ($account_status === 'Frozen'): ?>
                <button onclick="alert('Your account is frozen. Deposits are disabled.')" class="bg-gray-700 text-gray-400 px-3.5 py-2 rounded-xl text-xs font-semibold cursor-not-allowed">Deposit</button>
            <?php else: ?>
                <button onclick="openDepositModal()" class="bg-blue-600 hover:bg-blue-500 px-3.5 py-2 rounded-xl text-xs font-semibold transition shadow-lg shadow-blue-900/20">Deposit</button>
            <?php endif; ?>
        </div>

        <div class="bg-gradient-to-r from-purple-900/40 to-indigo-900/40 border border-purple-800/50 rounded-2xl p-4 flex items-center justify-between">
            <div>
                <h3 class="font-bold text-sm">Withdraw</h3>
                <p class="text-[11px] text-gray-400 mt-0.5">Payout to UPI</p>
            </div>
            <?php if ($account_status === 'Frozen'): ?>
                <button onclick="alert('Your account is frozen. Withdrawals are disabled.')" class="bg-gray-700 text-gray-400 px-3.5 py-2 rounded-xl text-xs font-semibold cursor-not-allowed">Withdraw</button>
            <?php else: ?>
                <button onclick="openWithdrawModal()" class="bg-indigo-600 hover:bg-indigo-500 px-3.5 py-2 rounded-xl text-xs font-semibold transition shadow-lg shadow-indigo-900/20">Withdraw</button>
            <?php endif; ?>
        </div>
    </div>

    <!-- FALLBACK UTR SUBMISSION CARD (FOR USERS WHO FORGOT TO SUBMIT ON QR PAGE) -->
    <div class="bg-gray-900 border border-gray-800 rounded-2xl p-5 mb-6">
        <h3 class="font-bold text-sm text-white mb-1"><i class="fa-solid fa-circle-exclamation text-amber-400 mr-1.5"></i> Forgot to Submit UTR?</h3>
        <p class="text-xs text-gray-400 mb-4">If you paid via QR but missed submitting your reference number, enter it below to claim your funds instantly.</p>
        
        <form action="claim_deposit.php" method="POST" class="space-y-3">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-[11px] text-gray-400 mb-1">Paid Amount (₹)</label>
                    <input type="number" step="0.01" name="expected_amount" placeholder="E.g., 50" required 
                           class="w-full bg-gray-800 border border-gray-700 text-white text-sm rounded-xl py-2.5 px-3 outline-none focus:border-indigo-500">
                </div>
                <div>
                    <label class="block text-[11px] text-gray-400 mb-1">12-Digit UTR / RRN</label>
                    <input type="text" name="utr" placeholder="Enter 12-digit UTR" maxlength="12" pattern="[0-9]{12}" required 
                           class="w-full bg-gray-800 border border-gray-700 text-white text-sm rounded-xl py-2.5 px-3 outline-none focus:border-indigo-500">
                </div>
            </div>
            <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-500 text-white font-semibold rounded-xl py-2.5 text-xs transition">
                Claim Missing Deposit
            </button>
        </form>
    </div>

    <div class="grid grid-cols-3 bg-gray-900 border border-gray-800 rounded-xl p-1 mb-6 text-center text-xs font-semibold">
        <button type="button" onclick="switchTxTab('deposit')" id="tabDeposit" class="py-2.5 rounded-lg bg-indigo-600 text-white transition">Deposit</button>
        <button type="button" onclick="switchTxTab('withdrawal')" id="tabWithdrawal" class="py-2.5 rounded-lg text-gray-400 transition">Withdrawal</button>
        <button type="button" onclick="switchTxTab('all')" id="tabAll" class="py-2.5 rounded-lg text-gray-400 transition">All</button>
    </div>

    <div id="sectionDeposit" class="space-y-3">
        <?php if (count($all_deposits) > 0): ?>
            <?php foreach ($all_deposits as $tx): 
                $status = $tx["status"] ?? "Pending";
                if ($status === "Approved") {
                    $badge_class = "bg-green-950 text-green-400 border-green-800";
                } elseif ($status === "Rejected") {
                    $badge_class = "bg-red-950 text-red-400 border-red-800";
                } elseif ($status === "Under Review") {
                    $badge_class = "bg-blue-950 text-blue-400 border-blue-800";
                } else {
                    $badge_class = "bg-amber-950 text-amber-400 border-amber-800";
                }
            ?>
                <div class="bg-gray-900 border border-gray-800 rounded-xl p-4 flex items-center justify-between">
                    <div>
                        <p class="font-semibold text-sm"><?= htmlspecialchars($tx["description"]) ?></p>
                        <p class="text-[11px] text-gray-500 mt-0.5"><?= date("d M Y, h:i A", strtotime($tx["created_at"])) ?></p>
                    </div>
                    <div class="text-right">
                        <span class="font-bold text-green-400 block">+₹<?= number_format((float)$tx["amount"], 2) ?></span>
                        <span class="text-[10px] px-2.5 py-0.5 rounded-full border font-semibold mt-1 inline-block <?= $badge_class ?>"><?= $status ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="bg-gray-900 border border-gray-800 rounded-2xl p-8 text-center text-gray-500 text-sm">No deposit history found.</div>
        <?php endif; ?>
    </div>

    <div id="sectionWithdrawal" class="space-y-3 hidden">
        <?php if (count($withdrawals) > 0): ?>
            <?php foreach ($withdrawals as $w): 
                $status = $w["status"] ?? "Pending";
                $badge_class = $status === "Completed" ? "bg-green-950 text-green-400 border-green-800" : ($status === "Rejected" ? "bg-red-950 text-red-400 border-red-800" : "bg-amber-950 text-amber-400 border-amber-800");
            ?>
                <div class="bg-gray-900 border border-gray-800 rounded-xl p-4 flex items-center justify-between">
                    <div>
                        <p class="font-semibold text-sm">Withdrawal Request</p>
                        <?php if (!empty($w["upi_id"])): ?>
                            <p class="text-xs text-indigo-400 mt-0.5">UPI: <?= htmlspecialchars($w["upi_id"]) ?></p>
                        <?php endif; ?>
                        <p class="text-[11px] text-gray-500 mt-0.5"><?= date("d M Y, h:i A", strtotime($w["created_at"])) ?></p>
                    </div>
                    <div class="text-right">
                        <span class="font-bold text-red-400 block">-₹<?= number_format((float)$w["amount"], 2) ?></span>
                        <span class="text-[10px] px-2.5 py-0.5 rounded-full border font-semibold mt-1 inline-block <?= $badge_class ?>"><?= $status ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="bg-gray-900 border border-gray-800 rounded-2xl p-8 text-center text-gray-500 text-sm">No withdrawal history found.</div>
        <?php endif; ?>
    </div>

    <div id="sectionAll" class="space-y-3 hidden">
        <?php if (count($all_tx) > 0): ?>
            <?php foreach ($all_tx as $tx): 
                $is_credit = ($tx["type"] === "credit");
            ?>
                <div class="bg-gray-900 border border-gray-800 rounded-xl p-4 flex items-center justify-between">
                    <div>
                        <p class="font-semibold text-sm"><?= htmlspecialchars($tx["description"]) ?></p>
                        <p class="text-[11px] text-gray-500 mt-0.5"><?= date("d M Y, h:i A", strtotime($tx["created_at"])) ?></p>
                    </div>
                    <span class="font-bold <?= $is_credit ? 'text-green-400' : 'text-red-400' ?>">
                        <?= $is_credit ? '+' : '-' ?>₹<?= number_format((float)$tx["amount"], 2) ?>
                    </span>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="bg-gray-900 border border-gray-800 rounded-2xl p-8 text-center text-gray-500 text-sm">No transaction history found.</div>
        <?php endif; ?>
    </div>

</main>

<div id="depositModal" class="fixed inset-0 z-50 bg-black/80 hidden items-center justify-center px-4 backdrop-blur-sm">
    <div class="bg-gray-900 border border-gray-800 rounded-2xl p-6 w-full max-w-sm relative">
        <button onclick="closeDepositModal()" class="absolute top-4 right-4 text-gray-500 hover:text-white"><i class="fa-solid fa-xmark text-xl"></i></button>
        <h3 class="text-xl font-bold text-white mb-4">Add Money</h3>
        <form method="GET" action="qr.php" class="space-y-4">
            <p class="text-sm text-gray-300 mb-2">Select Amount to Deposit (₹)</p>
            <div class="grid grid-cols-3 gap-3">
                <label class="cursor-pointer">
                    <input type="radio" name="amount" value="10" class="peer sr-only" required>
                    <div class="rounded-xl border border-gray-700 bg-gray-800 py-2.5 text-center font-semibold text-gray-300 hover:bg-gray-700 peer-checked:border-blue-500 peer-checked:bg-blue-600/20 peer-checked:text-blue-400 transition">₹10</div>
                </label>
                <label class="cursor-pointer">
                    <input type="radio" name="amount" value="20" class="peer sr-only">
                    <div class="rounded-xl border border-gray-700 bg-gray-800 py-2.5 text-center font-semibold text-gray-300 hover:bg-gray-700 peer-checked:border-blue-500 peer-checked:bg-blue-600/20 peer-checked:text-blue-400 transition">₹20</div>
                </label>
                <label class="cursor-pointer">
                    <input type="radio" name="amount" value="50" class="peer sr-only">
                    <div class="rounded-xl border border-gray-700 bg-gray-800 py-2.5 text-center font-semibold text-gray-300 hover:bg-gray-700 peer-checked:border-blue-500 peer-checked:bg-blue-600/20 peer-checked:text-blue-400 transition">₹50</div>
                </label>
                <label class="cursor-pointer">
                    <input type="radio" name="amount" value="100" class="peer sr-only">
                    <div class="rounded-xl border border-gray-700 bg-gray-800 py-2.5 text-center font-semibold text-gray-300 hover:bg-gray-700 peer-checked:border-blue-500 peer-checked:bg-blue-600/20 peer-checked:text-blue-400 transition">₹100</div>
                </label>
                <label class="cursor-pointer">
                    <input type="radio" name="amount" value="200" class="peer sr-only">
                    <div class="rounded-xl border border-gray-700 bg-gray-800 py-2.5 text-center font-semibold text-gray-300 hover:bg-gray-700 peer-checked:border-blue-500 peer-checked:bg-blue-600/20 peer-checked:text-blue-400 transition">₹200</div>
                </label>
            </div>
            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-500 text-white rounded-xl py-3.5 font-semibold transition mt-4">Proceed to QR</button>
        </form>
    </div>
</div>

<div id="withdrawModal" class="fixed inset-0 z-50 bg-black/80 hidden items-center justify-center px-4 backdrop-blur-sm">
    <div class="bg-gray-900 border border-gray-800 rounded-2xl p-6 w-full max-w-sm relative">
        <button onclick="closeWithdrawModal()" class="absolute top-4 right-4 text-gray-500 hover:text-white"><i class="fa-solid fa-xmark text-xl"></i></button>
        <h3 class="text-xl font-bold text-white mb-1">Withdraw Winnings</h3>
        <p class="text-xs text-gray-500 mb-5">Available Winning Balance: <strong class="text-yellow-400">₹<?= number_format((float)$user["winning_balance"], 2) ?></strong></p>
        <form method="POST" action="" class="space-y-4">
            <input type="hidden" name="request_withdrawal" value="1">
            <div>
                <label class="block text-sm text-gray-300 mb-2">Amount to Withdraw (₹)</label>
                <input type="number" step="0.01" name="withdraw_amount" placeholder="E.g., 10" required class="w-full bg-gray-800 border border-gray-700 rounded-xl py-3 px-4 text-white outline-none focus:border-indigo-500">
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-2">UPI ID (For Payout)</label>
                <input type="text" name="upi_id" placeholder="E.g., username@oksbi" required class="w-full bg-gray-800 border border-gray-700 rounded-xl py-3 px-4 text-white outline-none focus:border-indigo-500">
            </div>
            <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl py-3.5 font-semibold transition">Submit Request</button>
        </form>
    </div>
</div>

<nav class="fixed bottom-0 left-0 right-0 z-50 bg-gray-900 border-t border-gray-800">
    <div class="max-w-3xl mx-auto grid grid-cols-4">
        <a href="index.php" class="py-3 text-center text-gray-500 hover:text-white"><i class="fa-solid fa-house block text-lg"></i><span class="text-[11px]">Home</span></a>
        <a href="my_tournaments.php" class="py-3 text-center text-gray-500 hover:text-white"><i class="fa-solid fa-trophy block text-lg"></i><span class="text-[11px]">Tournaments</span></a>
        <a href="wallet.php" class="py-3 text-center text-indigo-400"><i class="fa-solid fa-wallet block text-lg"></i><span class="text-[11px]">Wallet</span></a>
        <a href="profile.php" class="py-3 text-center text-gray-500 hover:text-white"><i class="fa-solid fa-user block text-lg"></i><span class="text-[11px]">Profile</span></a>
    </div>
</nav>

<script>
function openDepositModal() {
    document.getElementById('depositModal').classList.replace('hidden', 'flex');
}
function closeDepositModal() {
    document.getElementById('depositModal').classList.replace('flex', 'hidden');
}
function openWithdrawModal() {
    document.getElementById('withdrawModal').classList.replace('hidden', 'flex');
}
function closeWithdrawModal() {
    document.getElementById('withdrawModal').classList.replace('flex', 'hidden');
}
function switchTxTab(tab) {
    const secDep = document.getElementById('sectionDeposit');
    const secWit = document.getElementById('sectionWithdrawal');
    const secAll = document.getElementById('sectionAll');
    const tabDep = document.getElementById('tabDeposit');
    const tabWit = document.getElementById('tabWithdrawal');
    const tabAll = document.getElementById('tabAll');

    secDep.classList.add('hidden');
    secWit.classList.add('hidden');
    secAll.classList.add('hidden');
    tabDep.className = 'py-2.5 rounded-lg text-gray-400 transition';
    tabWit.className = 'py-2.5 rounded-lg text-gray-400 transition';
    tabAll.className = 'py-2.5 rounded-lg text-gray-400 transition';

    if (tab === 'deposit') {
        secDep.classList.remove('hidden');
        tabDep.className = 'py-2.5 rounded-lg bg-indigo-600 text-white transition';
    } else if (tab === 'withdrawal') {
        secWit.classList.remove('hidden');
        tabWit.className = 'py-2.5 rounded-lg bg-indigo-600 text-white transition';
    } else if (tab === 'all') {
        secAll.classList.remove('hidden');
        tabAll.className = 'py-2.5 rounded-lg bg-indigo-600 text-white transition';
    }
}
</script>
</body>
</html>
