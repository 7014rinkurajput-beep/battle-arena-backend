<?php
// Turn on error reporting to debug HTTP 500
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "../Common/config.php";

// =====================================================
// ADMIN ACCESS CHECK
// =====================================================
if (!isset($_SESSION["admin_logged_in"]) || $_SESSION["admin_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

// Ensure account_status and wallet columns exist in users table
$check_col = $conn->query("SHOW COLUMNS FROM users LIKE 'account_status'");
if ($check_col && $check_col->num_rows == 0) {
    $conn->query("ALTER TABLE users ADD COLUMN account_status VARCHAR(20) DEFAULT 'Active'");
}
$chk_u_dep = $conn->query("SHOW COLUMNS FROM users LIKE 'deposit_balance'");
if ($chk_u_dep && $chk_u_dep->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN deposit_balance DECIMAL(10,2) DEFAULT 0.00");
}
$chk_u_win = $conn->query("SHOW COLUMNS FROM users LIKE 'winning_balance'");
if ($chk_u_win && $chk_u_win->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN winning_balance DECIMAL(10,2) DEFAULT 0.00");
}

$message = "";
$message_type = "";

// =====================================================
// HANDLE ADMIN ACTIONS (POST)
// =====================================================
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";
    $target_user_id = intval($_POST["user_id"] ?? 0);

    if ($target_user_id > 0) {
        if ($action === "adjust_balance") {
            $type = $_POST["adjustment_type"] ?? ""; // add or deduct
            $amount = floatval($_POST["amount"] ?? 0);
            $reason = trim($_POST["reason"] ?? "Admin adjustment");

            if ($amount > 0 && in_array($type, ["add", "deduct"])) {
                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare("SELECT deposit_balance, winning_balance FROM users WHERE id = ? FOR UPDATE");
                    $stmt->bind_param("i", $target_user_id);
                    $stmt->execute();
                    $u_data = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if (!$u_data) throw new Exception("Player not found.");

                    $current_dep = (float)($u_data["deposit_balance"] ?? 0);
                    $current_win = (float)($u_data["winning_balance"] ?? 0);

                    // Fix: Properly calculate across both balances
                    if ($type === "add") {
                        $new_dep = $current_dep + $amount;
                        $new_win = $current_win;
                    } else {
                        // Priority deduction: Take from deposit first, then from winnings if needed
                        if ($amount <= $current_dep) {
                            $new_dep = $current_dep - $amount;
                            $new_win = $current_win;
                        } else {
                            $remaining_deduction = $amount - $current_dep;
                            $new_dep = 0;
                            $new_win = max(0, $current_win - $remaining_deduction);
                        }
                    }
                    
                    // Fix: Keep master wallet_balance perfectly synced
                    $new_wallet = $new_dep + $new_win;

                    $stmt = $conn->prepare("UPDATE users SET wallet_balance = ?, deposit_balance = ?, winning_balance = ? WHERE id = ?");
                    $stmt->bind_param("dddi", $new_wallet, $new_dep, $new_win, $target_user_id);
                    $stmt->execute();
                    $stmt->close();

                    $trans_type = ($type === "add") ? "credit" : "debit";
                    $desc = "Admin Adjustment: " . $reason;
                    $stmt = $conn->prepare("INSERT INTO transactions (user_id, type, amount, description) VALUES (?, ?, ?, ?)");
                    $stmt->bind_param("isds", $target_user_id, $trans_type, $amount, $desc);
                    $stmt->execute();
                    $stmt->close();

                    $conn->commit();
                    $message = "Successfully adjusted player balance by ₹{$amount} ({$type}).";
                    $message_type = "success";
                } catch (Exception $e) {
                    $conn->rollback();
                    $message = "Error: " . $e->getMessage();
                    $message_type = "error";
                }
            } else {
                $message = "Invalid amount or adjustment type.";
                $message_type = "error";
            }
        } elseif ($action === "change_status") {
            $new_status = $_POST["account_status"] ?? "Active";
            if (in_array($new_status, ["Active", "Frozen", "Banned"])) {
                $stmt = $conn->prepare("UPDATE users SET account_status = ? WHERE id = ?");
                $stmt->bind_param("si", $new_status, $target_user_id);
                $stmt->execute();
                $stmt->close();
                $message = "Player status updated to {$new_status}.";
                $message_type = "success";
            }
        } elseif ($action === "delete_user") {
            $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
            $stmt->bind_param("i", $target_user_id);
            $stmt->execute();
            $stmt->close();
            $message = "Player account deleted successfully.";
            $message_type = "success";
        }
    }
}

// =====================================================
// SEARCH & FETCH PLAYERS
// =====================================================
$search = trim($_GET["search"] ?? "");
$players = [];

if ($search !== "") {
    $like = "%" . $search . "%";
    $stmt = $conn->prepare("SELECT id, username, email, wallet_balance, deposit_balance, winning_balance, account_status FROM users WHERE username LIKE ? OR email LIKE ? ORDER BY id DESC");
    $stmt->bind_param("ss", $like, $like);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $players[] = $row;
    }
    $stmt->close();
} else {
    $result = $conn->query("SELECT id, username, email, wallet_balance, deposit_balance, winning_balance, account_status FROM users ORDER BY id DESC");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $players[] = $row;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Players Management - Battle Arena</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>
<body class="bg-gray-950 text-white min-h-screen">

<header class="sticky top-0 z-50 bg-gray-950/95 backdrop-blur border-b border-gray-800">
    <div class="max-w-5xl mx-auto px-4 py-4 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <a href="dashboard.php" class="w-10 h-10 rounded-xl bg-gray-800 flex items-center justify-center hover:bg-gray-700">
                <i class="fa-solid fa-arrow-left"></i>
            </a>
            <div>
                <p class="text-xs text-gray-500">Admin Panel</p>
                <h1 class="font-bold">Player Management</h1>
            </div>
        </div>
        <a href="dashboard.php" class="text-sm text-indigo-400 hover:text-indigo-300">Dashboard</a>
    </div>
</header>

<main class="max-w-5xl mx-auto px-4 py-6 pb-16">

    <?php if ($message !== ""): ?>
        <div class="mb-6 rounded-xl p-4 border <?= $message_type === "success" ? "bg-green-950/50 border-green-800 text-green-300" : "bg-red-950/50 border-red-800 text-red-300" ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <!-- SEARCH BAR -->
    <form method="GET" class="mb-6">
        <div class="flex gap-2">
            <div class="relative flex-1">
                <i class="fa-solid fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-gray-500"></i>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search username or email..." class="w-full bg-gray-900 border border-gray-800 rounded-xl py-3.5 pl-11 pr-4 outline-none focus:border-indigo-500">
            </div>
            <button type="submit" class="bg-indigo-600 hover:bg-indigo-500 rounded-xl px-5 font-semibold">Search</button>
        </div>
    </form>

    <!-- PLAYER COUNT -->
    <div class="bg-gray-900 border border-gray-800 rounded-2xl p-4 mb-5 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-blue-950 flex items-center justify-center">
                <i class="fa-solid fa-users text-blue-400"></i>
            </div>
            <div>
                <p class="text-xs text-gray-500">Total Results</p>
                <p class="font-bold"><?= count($players) ?> Players</p>
            </div>
        </div>
        <?php if ($search !== ""): ?>
            <a href="players.php" class="text-sm text-gray-400 hover:text-white">Clear Search</a>
        <?php endif; ?>
    </div>

    <!-- PLAYERS LIST -->
    <?php if (count($players) > 0): ?>
        <div class="space-y-4">
            <?php foreach ($players as $player): 
                $status = $player["account_status"] ?? "Active";
                $status_class = $status === "Active" ? "bg-green-950 text-green-400 border-green-800" : ($status === "Frozen" ? "bg-yellow-950 text-yellow-400 border-yellow-800" : "bg-red-950 text-red-400 border-red-800");
            ?>
                <div class="bg-gray-900 border border-gray-800 rounded-2xl p-5">
                    <div class="flex items-start justify-between gap-4 mb-4">
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="w-12 h-12 shrink-0 rounded-xl bg-indigo-950 flex items-center justify-center">
                                <i class="fa-solid fa-user text-indigo-400"></i>
                            </div>
                            <div class="min-w-0">
                                <h3 class="font-semibold text-lg truncate"><?= htmlspecialchars($player["username"]) ?></h3>
                                <p class="text-xs text-gray-500 truncate"><?= htmlspecialchars($player["email"]) ?></p>
                            </div>
                        </div>
                        <div class="flex flex-col items-end gap-1">
                            <span class="text-xs font-semibold px-3 py-1 rounded-full border <?= $status_class ?>"><?= $status ?></span>
                            <span class="text-[10px] text-gray-500 font-mono">ID #<?= (int)$player["id"] ?></span>
                        </div>
                    </div>

                    <!-- WALLET & QUICK STATS -->
                    <div class="grid grid-cols-2 gap-3 mb-4">
                        <div class="bg-gray-950 rounded-xl p-3 border border-gray-800">
                            <p class="text-[11px] text-gray-500">Wallet Balance</p>
                            <p class="font-bold text-lg text-green-400 mt-1">₹<?= number_format((float)(($player["deposit_balance"] ?? 0) + ($player["winning_balance"] ?? 0)), 2) ?></p>
                        </div>
                        <div class="bg-gray-950 rounded-xl p-3 border border-gray-800 flex flex-col justify-center">
                            <p class="text-[11px] text-gray-500">Account ID</p>
                            <p class="font-mono font-semibold mt-1">#<?= (int)$player["id"] ?></p>
                        </div>
                    </div>

                    <!-- ADMIN ACTIONS ACCORDION / CONTROLS -->
                    <div class="border-t border-gray-800 pt-4 grid grid-cols-1 md:grid-cols-2 gap-3">
                        
                        <!-- 1. ADJUST WALLET BALANCE / PENALTY -->
                        <form method="POST" class="bg-gray-950 p-3 rounded-xl border border-gray-800 space-y-3">
                            <input type="hidden" name="action" value="adjust_balance">
                            <input type="hidden" name="user_id" value="<?= (int)$player["id"] ?>">
                            <p class="text-xs font-semibold text-gray-400"><i class="fa-solid fa-wallet mr-1"></i> Adjust Funds / Penalty</p>
                            <div class="grid grid-cols-2 gap-2">
                                <select name="adjustment_type" class="bg-gray-900 border border-gray-800 rounded-lg p-2 text-xs outline-none">
                                    <option value="deduct">Deduct (Penalty)</option>
                                    <option value="add">Add Funds</option>
                                </select>
                                <input type="number" step="0.01" name="amount" placeholder="Amount ₹" required class="bg-gray-900 border border-gray-800 rounded-lg p-2 text-xs outline-none">
                            </div>
                            <input type="text" name="reason" placeholder="Reason (e.g. Rule violation)" required class="w-full bg-gray-900 border border-gray-800 rounded-lg p-2 text-xs outline-none">
                            <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-500 text-xs py-2 rounded-lg font-semibold">Apply Balance Change</button>
                        </form>

                        <!-- 2. STATUS & BAN CONTROLS -->
                        <div class="bg-gray-950 p-3 rounded-xl border border-gray-800 flex flex-col justify-between space-y-3">
                            <div>
                                <p class="text-xs font-semibold text-gray-400 mb-2"><i class="fa-solid fa-shield-halved mr-1"></i> Account Security & Status</p>
                                <form method="POST" class="flex gap-2">
                                    <input type="hidden" name="action" value="change_status">
                                    <input type="hidden" name="user_id" value="<?= (int)$player["id"] ?>">
                                    <select name="account_status" class="flex-1 bg-gray-900 border border-gray-800 rounded-lg p-2 text-xs outline-none">
                                        <option value="Active" <?= $status === "Active" ? "selected" : "" ?>>Active</option>
                                        <option value="Frozen" <?= $status === "Frozen" ? "selected" : "" ?>>Freeze Account</option>
                                        <option value="Banned" <?= $status === "Banned" ? "selected" : "" ?>>Ban Account</option>
                                    </select>
                                    <button type="submit" class="bg-gray-800 hover:bg-gray-700 px-3 py-2 rounded-lg text-xs font-semibold">Update</button>
                                </form>
                            </div>

                            <form method="POST" onsubmit="return confirm('WARNING: Permanently delete this player account? This cannot be undone.');">
                                <input type="hidden" name="action" value="delete_user">
                                <input type="hidden" name="user_id" value="<?= (int)$player["id"] ?>">
                                <button type="submit" class="w-full border border-red-900/60 text-red-400 hover:bg-red-950 text-xs py-2 rounded-lg font-semibold"><i class="fa-solid fa-trash mr-1"></i> Delete Player</button>
                            </form>
                        </div>

                    </div>

                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="bg-gray-900 border border-gray-800 rounded-2xl p-10 text-center">
            <div class="w-16 h-16 mx-auto rounded-2xl bg-gray-800 flex items-center justify-center">
                <i class="fa-solid fa-user-slash text-2xl text-gray-600"></i>
            </div>
            <h3 class="font-bold text-lg mt-4">No Players Found</h3>
            <p class="text-gray-500 text-sm mt-2"><?= $search !== "" ? "Try a different username or email." : "No players have registered yet." ?></p>
        </div>
    <?php endif; ?>

</main>

<script>
document.addEventListener("contextmenu", function(event) { event.preventDefault(); });
document.addEventListener("selectstart", function(event) { event.preventDefault(); });
</script>

</body>
</html>
