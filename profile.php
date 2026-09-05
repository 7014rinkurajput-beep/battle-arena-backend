<?php
// Enable error reporting to reveal the exact cause of any future 500 errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// FIX: Capitalized 'Common'
require_once "Common/config.php";

// =====================================================
// USER ACCESS CHECK
// =====================================================

if (!isset($_SESSION["user_id"]) || (int)$_SESSION["user_id"] <= 0) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION["user_id"];

$message = "";
$message_type = "";

// FIX: Wrap database alterations in try/catch to prevent Fatal 500 Errors
try { $conn->query("ALTER TABLE users ADD COLUMN full_name VARCHAR(100) NULL"); } catch (Exception $e) {}
try { $conn->query("ALTER TABLE users ADD COLUMN mobile VARCHAR(20) NULL"); } catch (Exception $e) {}

// =====================================================
// FETCH USER
// =====================================================

try {
    $query = "SELECT id, full_name, username, mobile, email FROM users WHERE id = ? LIMIT 1";
    $stmt = $conn->prepare($query);

    if (!$stmt) {
        die("<div style='color:red; background:#333; padding:20px; font-family:sans-serif;'>Prepare Failed: " . $conn->error . "</div>");
    }

    $stmt->bind_param("i", $user_id);
    $stmt->execute();

    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    $stmt->close();

    if (!$user) {
        session_destroy();
        header("Location: login.php");
        exit;
    }
} catch (Exception $e) {
    die("<div style='color:red; background:#333; padding:20px; font-family:sans-serif;'>Database Error: " . $e->getMessage() . "</div>");
}

// =====================================================
// CHANGE PASSWORD
// =====================================================

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["change_password"])) {

    $current_password = $_POST["current_password"] ?? "";
    $new_password = $_POST["new_password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";

    if ($current_password === "" || $new_password === "" || $confirm_password === "") {
        $message = "Please fill in all password fields.";
        $message_type = "error";
    } elseif (strlen($new_password) < 6) {
        $message = "New password must be at least 6 characters.";
        $message_type = "error";
    } elseif ($new_password !== $confirm_password) {
        $message = "New passwords do not match.";
        $message_type = "error";
    } else {
        try {
            // Fetch current password
            $stmt = $conn->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $password_result = $stmt->get_result();
                $password_user = $password_result->fetch_assoc();
                $stmt->close();

                if (!$password_user || !password_verify($current_password, $password_user["password"])) {
                    $message = "Current password is incorrect.";
                    $message_type = "error";
                } else {
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                    if ($stmt) {
                        $stmt->bind_param("si", $hashed_password, $user_id);

                        if ($stmt->execute()) {
                            $message = "Password changed successfully.";
                            $message_type = "success";
                        } else {
                            $message = "Unable to change password.";
                            $message_type = "error";
                        }
                        $stmt->close();
                    }
                }
            }
        } catch (Exception $e) {
            $message = "Database Error: " . $e->getMessage();
            $message_type = "error";
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Battle Arena</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>

<body class="bg-gray-950 text-white min-h-screen pb-24">

<!-- =====================================================
     HEADER
===================================================== -->
<header class="sticky top-0 z-50 bg-gray-950/95 backdrop-blur border-b border-gray-800">
    <div class="max-w-5xl mx-auto px-4 py-4 flex items-center justify-between">
        <div>
            <p class="text-xs text-indigo-400 font-medium">BATTLE ARENA</p>
            <h1 class="text-2xl font-bold mt-1">My Profile</h1>
        </div>
        <div class="w-10 h-10 rounded-full bg-indigo-600 flex items-center justify-center">
            <i class="fa-solid fa-user"></i>
        </div>
    </div>
</header>

<!-- =====================================================
     MAIN
===================================================== -->
<main class="max-w-5xl mx-auto px-4 py-6">

    <!-- MESSAGE -->
    <?php if ($message !== ""): ?>
        <div class="mb-6 rounded-xl p-4 border <?php echo $message_type === "success" ? "bg-green-950/50 border-green-800 text-green-300" : "bg-red-950/50 border-red-800 text-red-300"; ?>">
            <div class="flex items-center gap-3">
                <i class="fa-solid <?php echo $message_type === "success" ? "fa-circle-check" : "fa-circle-exclamation"; ?>"></i>
                <span><?php echo htmlspecialchars($message); ?></span>
            </div>
        </div>
    <?php endif; ?>

    <!-- PROFILE INFORMATION -->
    <section class="bg-gray-900 border border-gray-800 rounded-2xl p-5 mb-6">
        <div class="flex items-center gap-4 mb-6">
            <div class="w-16 h-16 rounded-full bg-indigo-600 flex items-center justify-center text-2xl shadow-lg shadow-indigo-900/50">
                <i class="fa-solid fa-user"></i>
            </div>
            <div>
                <h2 class="text-xl font-bold">
                    <?php echo htmlspecialchars($user["full_name"] ?? $user["username"]); ?>
                </h2>
                <p class="text-sm text-gray-500 mt-1">
                    @<?php echo htmlspecialchars($user["username"]); ?>
                </p>
            </div>
        </div>

        <!-- FULL NAME -->
        <div class="mb-4">
            <label class="block text-sm text-gray-400 mb-2">Full Name</label>
            <div class="bg-gray-800 border border-gray-700 rounded-xl px-4 py-3.5 text-gray-300">
                <?php echo htmlspecialchars($user["full_name"] ?? "Not provided"); ?>
            </div>
        </div>

        <!-- USERNAME (LOCKED) -->
        <div class="mb-4">
            <div class="flex justify-between items-center mb-2">
                <label class="block text-sm text-gray-400">Username (Unique ID)</label>
                <span class="text-[10px] bg-red-950/50 text-red-400 px-2 py-0.5 rounded border border-red-900/50 flex items-center gap-1">
                    <i class="fa-solid fa-lock text-[9px]"></i> Cannot change
                </span>
            </div>
            <div class="bg-gray-800 border border-gray-700 rounded-xl px-4 py-3.5 text-gray-500 cursor-not-allowed flex justify-between items-center">
                <span><?php echo htmlspecialchars($user["username"]); ?></span>
                <i class="fa-solid fa-lock text-gray-600"></i>
            </div>
        </div>

        <!-- MOBILE NUMBER -->
        <div class="mb-4">
            <label class="block text-sm text-gray-400 mb-2">Mobile Number</label>
            <div class="bg-gray-800 border border-gray-700 rounded-xl px-4 py-3.5 text-gray-300">
                <?php echo htmlspecialchars($user["mobile"] ?? "Not provided"); ?>
            </div>
        </div>

        <!-- EMAIL -->
        <div>
            <label class="block text-sm text-gray-400 mb-2">Email</label>
            <div class="bg-gray-800 border border-gray-700 rounded-xl px-4 py-3.5 text-gray-300">
                <?php echo htmlspecialchars($user["email"]); ?>
            </div>
        </div>
    </section>

    <!-- CHANGE PASSWORD -->
    <section class="bg-gray-900 border border-gray-800 rounded-2xl p-5 mb-6">
        <div class="flex items-start gap-4 mb-5">
            <div class="w-12 h-12 rounded-xl bg-orange-950 text-orange-400 flex items-center justify-center shrink-0">
                <i class="fa-solid fa-lock"></i>
            </div>
            <div>
                <p class="text-orange-400 text-xs font-medium">SECURITY</p>
                <h2 class="text-xl font-bold mt-1">Change Password</h2>
            </div>
        </div>

        <form method="POST" class="space-y-4">
            <input type="hidden" name="change_password" value="1">
            <div>
                <label class="block text-sm text-gray-300 mb-2">Current Password</label>
                <input type="password" name="current_password" autocomplete="current-password" class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-3.5 outline-none focus:border-indigo-500" required>
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-2">New Password</label>
                <input type="password" name="new_password" autocomplete="new-password" class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-3.5 outline-none focus:border-indigo-500" required>
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-2">Confirm New Password</label>
                <input type="password" name="confirm_password" autocomplete="new-password" class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-3.5 outline-none focus:border-indigo-500" required>
            </div>
            <button type="submit" class="w-full bg-gray-800 hover:bg-gray-700 border border-gray-700 rounded-xl py-3.5 font-semibold transition">
                <i class="fa-solid fa-key mr-2"></i> Change Password
            </button>
        </form>
    </section>

    <!-- CUSTOMER SERVICE / SUPPORT -->
    <section class="bg-gray-900 border border-gray-800 rounded-2xl p-5 mb-6">
        <div class="flex items-start gap-4 mb-4">
            <div class="w-12 h-12 rounded-xl bg-blue-950 text-blue-400 flex items-center justify-center shrink-0">
                <i class="fa-solid fa-headset text-lg"></i>
            </div>
            <div>
                <p class="text-blue-400 text-xs font-medium">HELP & SUPPORT</p>
                <h2 class="text-xl font-bold mt-1">Customer Service</h2>
                <p class="text-sm text-gray-500 mt-1">Facing deposit, withdrawal, or match issues? Chat with our support bot.</p>
            </div>
        </div>
        <a href="https://t.me/BattleArena_Care_bot" target="_blank" class="w-full flex items-center justify-center gap-2 bg-sky-600 hover:bg-sky-500 text-white rounded-xl py-3.5 font-semibold transition shadow-lg shadow-sky-600/20">
            <i class="fa-brands fa-telegram text-lg"></i> Open Telegram Support Bot
        </a>
    </section>

    <!-- LOGOUT -->
    <section>
        <a href="logout.php" class="w-full flex items-center justify-center gap-2 border border-red-900 text-red-400 hover:bg-red-950 rounded-xl py-3.5 font-semibold transition">
            <i class="fa-solid fa-right-from-bracket"></i> Logout
        </a>
    </section>

</main>

<!-- =====================================================
     BOTTOM NAVIGATION
===================================================== -->
<nav class="fixed bottom-0 left-0 right-0 z-50 bg-gray-900 border-t border-gray-800">
    <div class="max-w-5xl mx-auto grid grid-cols-4">
        <a href="index.php" class="py-3 text-center text-gray-500">
            <i class="fa-solid fa-house text-lg"></i>
            <p class="text-xs mt-1">Home</p>
        </a>
        <a href="my_tournaments.php" class="py-3 text-center text-gray-500">
            <i class="fa-solid fa-trophy text-lg"></i>
            <p class="text-xs mt-1">Tournaments</p>
        </a>
        <a href="wallet.php" class="py-3 text-center text-gray-500">
            <i class="fa-solid fa-wallet text-lg"></i>
            <p class="text-xs mt-1">Wallet</p>
        </a>
        <a href="profile.php" class="py-3 text-center text-indigo-400">
            <i class="fa-solid fa-user text-lg"></i>
            <p class="text-xs mt-1">Profile</p>
        </a>
    </div>
</nav>

<script>
// Disable right click
document.addEventListener("contextmenu", function(event) {
    event.preventDefault();
});

// Disable text selection
document.addEventListener("selectstart", function(event) {
    event.preventDefault();
});
</script>

</body>
</html>
