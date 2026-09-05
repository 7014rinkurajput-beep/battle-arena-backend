<?php
ob_start();
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// FIX 1: Exact case-sensitive path
require_once "../Common/config.php";

if (!isset($_SESSION["admin_logged_in"]) || $_SESSION["admin_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

$message = "";
$message_type = "";

// Ensure compatible columns exist without crashing
try { $conn->query("ALTER TABLE withdrawals ADD COLUMN upi_id VARCHAR(100) NULL"); } catch (Exception $e) {}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["withdraw_action"])) {
    $withdraw_id = intval($_POST["withdraw_id"] ?? 0);
    $action = $_POST["withdraw_action"] ?? "";

    if ($withdraw_id > 0 && in_array($action, ["approve", "reject"])) {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("SELECT id, user_id, amount, status FROM withdrawals WHERE id = ? LIMIT 1 FOR UPDATE");
            $stmt->bind_param("i", $withdraw_id);
            $stmt->execute();
            $withdrawal = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$withdrawal || $withdrawal["status"] !== "Pending") {
                throw new Exception("Request already processed.");
            }

            $user_id = (int)$withdrawal["user_id"];
            $amount = (float)$withdrawal["amount"];

            if ($action === "approve") {
                // Update withdrawal status to Completed
                $stmt = $conn->prepare("UPDATE withdrawals SET status = 'Completed' WHERE id = ?");
                $stmt->bind_param("i", $withdraw_id);
                $stmt->execute();
                $stmt->close();

                $message = "Withdrawal approved. Transfer funds via your UPI app.";
                $message_type = "success";

            } elseif ($action === "reject") {
                // Refund deducted balance back to player's winning and wallet balances
                $stmt = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance + ?, winning_balance = winning_balance + ? WHERE id = ?");
                $stmt->bind_param("ddi", $amount, $amount, $user_id);
                $stmt->execute();
                $stmt->close();

                // Log the refund transaction
                $desc = "Refund: Withdrawal request #" . $withdraw_id . " rejected";
                $stmt = $conn->prepare("INSERT INTO transactions (user_id, amount, type, description) VALUES (?, ?, 'credit', ?)");
                $stmt->bind_param("ids", $user_id, $amount, $desc);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare("UPDATE withdrawals SET status = 'Rejected' WHERE id = ?");
                $stmt->bind_param("i", $withdraw_id);
                $stmt->execute();
                $stmt->close();

                $message = "Withdrawal rejected. Funds successfully refunded to player.";
                $message_type = "success";
            }
            $conn->commit();
        } catch (Exception $e) {
            $conn->rollback();
            $message = "Error: " . $e->getMessage();
            $message_type = "error";
        }
    }
}

// FIX 2: Query w.upi_id instead of non-existent w.payment_details
$withdrawals = [];
$sql = "SELECT w.id, w.user_id, w.amount, w.upi_id, w.status, w.created_at, u.username 
        FROM withdrawals w 
        LEFT JOIN users u ON u.id = w.user_id 
        ORDER BY CASE WHEN w.status = 'Pending' THEN 0 ELSE 1 END, w.created_at DESC";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) { 
        $withdrawals[] = $row; 
    }
}

$pending_count = count(array_filter($withdrawals, function($w) { return $w["status"] === "Pending"; }));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Withdrawal Requests</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>
<body class="bg-gray-950 text-white min-h-screen">
<header class="sticky top-0 z-50 bg-gray-950/95 backdrop-blur border-b border-gray-800">
    <div class="max-w-5xl mx-auto px-4 py-4 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <a href="dashboard.php" class="w-10 h-10 rounded-xl bg-gray-800 flex items-center justify-center hover:bg-gray-700"><i class="fa-solid fa-arrow-left"></i></a>
            <div>
                <p class="text-xs text-gray-500">Admin Panel</p>
                <h1 class="font-bold">Withdrawal Requests</h1>
            </div>
        </div>
        <span class="text-xs bg-yellow-950 text-yellow-400 rounded-full px-3 py-1"><?= $pending_count ?> Pending</span>
    </div>
</header>
<main class="max-w-5xl mx-auto px-4 py-6 pb-12">
    <?php if ($message !== ""): ?>
        <div class="mb-6 rounded-xl p-4 border <?= $message_type === "success" ? "bg-green-950/50 border-green-800 text-green-300" : "bg-red-950/50 border-red-800 text-red-300" ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <?php if (count($withdrawals) > 0): ?>
        <div class="space-y-4">
            <?php foreach ($withdrawals as $withdrawal): ?>
                <?php
                $status = $withdrawal["status"];
                $status_class = $status === "Pending" ? "bg-yellow-950 text-yellow-400" : ($status === "Completed" ? "bg-green-950 text-green-400" : "bg-red-950 text-red-400");
                ?>
                <section class="bg-gray-900 border border-gray-800 rounded-2xl p-5">
                    <div class="flex items-start justify-between gap-3 mb-5">
                        <div>
                            <p class="text-xs text-gray-500">Request #<?= (int)$withdrawal["id"] ?></p>
                            <h2 class="font-bold text-lg mt-1"><?= htmlspecialchars($withdrawal["username"] ?? 'Unknown') ?></h2>
                        </div>
                        <span class="text-xs font-semibold rounded-full px-3 py-1 <?= $status_class ?>"><?= htmlspecialchars($status) ?></span>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-5">
                        <div class="bg-gray-950 rounded-xl p-4 border border-gray-800">
                            <p class="text-xs text-gray-500">Amount Requested</p>
                            <p class="text-2xl font-bold mt-1 text-yellow-400">₹<?= number_format((float)$withdrawal["amount"], 2) ?></p>
                        </div>
                        <div class="bg-gray-950 rounded-xl p-4 border border-gray-800">
                            <p class="text-xs text-gray-500">Date</p>
                            <p class="font-semibold mt-1"><?= date("d M Y, h:i A", strtotime($withdrawal["created_at"])) ?></p>
                        </div>
                    </div>
                    <div class="bg-gray-950 border border-gray-800 rounded-xl p-4 mb-5">
                        <p class="text-xs text-gray-500 uppercase tracking-wide font-semibold">Pay To UPI ID</p>
                        <p class="font-mono text-lg text-indigo-300 break-all mt-2"><?= htmlspecialchars($withdrawal["upi_id"] ?? 'None provided') ?></p>
                    </div>
                    <?php if ($status === "Pending"): ?>
                        <div class="grid grid-cols-2 gap-3">
                            <form method="POST" onsubmit="return confirm('Have you transferred the money? Approve this request?');">
                                <input type="hidden" name="withdraw_id" value="<?= (int)$withdrawal["id"] ?>">
                                <input type="hidden" name="withdraw_action" value="approve">
                                <button type="submit" class="w-full bg-green-600 hover:bg-green-500 rounded-xl py-3 font-semibold"><i class="fa-solid fa-check mr-1"></i> Approve</button>
                            </form>
                            <form method="POST" onsubmit="return confirm('Reject this withdrawal? The money will be refunded to the player\'s winning balance.');">
                                <input type="hidden" name="withdraw_id" value="<?= (int)$withdrawal["id"] ?>">
                                <input type="hidden" name="withdraw_action" value="reject">
                                <button type="submit" class="w-full border border-red-800 text-red-400 hover:bg-red-950 rounded-xl py-3 font-semibold"><i class="fa-solid fa-xmark mr-1"></i> Reject & Refund</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="bg-gray-900 border border-gray-800 rounded-2xl p-10 text-center text-gray-500">No requests found.</div>
    <?php endif; ?>
</main>
</body>
</html>
