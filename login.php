<?php

require_once "common/config.php";

$message = "";
$message_type = "";

$active_tab = "login";

// =====================================================
// LOGIN
// =====================================================

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["login"])) {

    $active_tab = "login";

    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($username === "" || $password === "") {

        $message = "Please enter username and password.";
        $message_type = "error";

    } else {

        $stmt = $conn->prepare(
            "SELECT id, username, password
             FROM users
             WHERE username = ?
             LIMIT 1"
        );

        if (!$stmt) {
            $message = "Database Error: " . $conn->error;
            $message_type = "error";
        } else {
            $stmt->bind_param("s", $username);
            $stmt->execute();

            $result = $stmt->get_result();

            if ($result->num_rows === 1) {

                $user = $result->fetch_assoc();

                if (password_verify($password, $user["password"])) {

                    session_regenerate_id(true);

                    $_SESSION["user_id"] = $user["id"];
                    $_SESSION["username"] = $user["username"];

                    header("Location: index.php");
                    exit;

                } else {

                    $message = "Invalid username or password.";
                    $message_type = "error";
                }

            } else {

                $message = "Invalid username or password.";
                $message_type = "error";
            }

            $stmt->close();
        }
    }
}


// =====================================================
// SIGN UP
// =====================================================

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["signup"])) {

    $active_tab = "signup";

    $fullname = trim($_POST["signup_fullname"] ?? "");
    $username = trim($_POST["signup_username"] ?? "");
    $mobile   = trim($_POST["signup_mobile"] ?? "");
    $email    = trim($_POST["signup_email"] ?? "");
    $password = $_POST["signup_password"] ?? "";

    if ($fullname === "" || $username === "" || $mobile === "" || $email === "" || $password === "") {

        $message = "Please fill in all signup fields.";
        $message_type = "error";

    } elseif (!preg_match('/^[0-9]{10}$/', $mobile)) {

        $message = "Mobile number must be exactly 10 digits.";
        $message_type = "error";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $message = "Please enter a valid email address.";
        $message_type = "error";

    } elseif (strlen($username) < 3) {

        $message = "Username must be at least 3 characters.";
        $message_type = "error";

    } elseif (strlen($password) < 6) {

        $message = "Password must be at least 6 characters.";
        $message_type = "error";

    } else {

        // 1. Check if username already exists
        $check_user = $conn->prepare(
            "SELECT id
             FROM users
             WHERE username = ?
             LIMIT 1"
        );
        
        if (!$check_user) {
            $message = "Database Error: " . $conn->error;
            $message_type = "error";
        } else {
            $check_user->bind_param("s", $username);
            $check_user->execute();
            $result_user = $check_user->get_result();

            if ($result_user->num_rows > 0) {
                $message = "Username already exists.";
                $message_type = "error";
                $check_user->close();
            } else {
                $check_user->close();

                // 2. Check if mobile or email already exists
                $check_contact = $conn->prepare(
                    "SELECT id
                     FROM users
                     WHERE email = ? OR mobile = ?
                     LIMIT 1"
                );
                
                if (!$check_contact) {
                    $message = "Database Error: " . $conn->error;
                    $message_type = "error";
                } else {
                    $check_contact->bind_param("ss", $email, $mobile);
                    $check_contact->execute();
                    $result_contact = $check_contact->get_result();

                    if ($result_contact->num_rows > 0) {
                        $message = "Account already exists with this mobile number or email.";
                        $message_type = "error";
                        $check_contact->close();
                    } else {
                        $check_contact->close();

                        // 3. Create Account
                        $hashed_password = password_hash(
                            $password,
                            PASSWORD_DEFAULT
                        );

                        $insert = $conn->prepare(
                            "INSERT INTO users
                            (full_name, username, mobile, email, password, wallet_balance)
                            VALUES (?, ?, ?, ?, ?, 0.00)"
                        );
                        
                        if (!$insert) {
                            $message = "Database Error: " . $conn->error;
                            $message_type = "error";
                        } else {
                            $insert->bind_param(
                                "sssss",
                                $fullname,
                                $username,
                                $mobile,
                                $email,
                                $hashed_password
                            );

                            if ($insert->execute()) {

                                $message = "Account created successfully. You can now login.";
                                $message_type = "success";

                                $active_tab = "login";

                            } else {

                                $message = "SQL Error: " . $insert->error;
                                $message_type = "error";
                            }

                            $insert->close();
                        }
                    }
                }
            }
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Battle Arena - Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>
<body class="bg-gray-950 text-white min-h-screen flex items-center justify-center px-4">

<div class="w-full max-w-md my-8">

    <!-- Logo -->
    <div class="text-center mb-8">
        <div class="w-20 h-20 mx-auto mb-4 rounded-3xl bg-indigo-600 flex items-center justify-center shadow-lg shadow-indigo-900/30">
            <i class="fa-solid fa-trophy text-3xl"></i>
        </div>
        <h1 class="text-3xl font-bold">Battle Arena</h1>
        <p class="text-gray-400 mt-2">Enter the competition</p>
    </div>

    <!-- Main Card -->
    <div class="bg-gray-900 border border-gray-800 rounded-3xl p-5 shadow-xl">

        <!-- Tabs -->
        <div class="grid grid-cols-2 bg-gray-800 rounded-xl p-1 mb-6">
            <button type="button" onclick="showTab('login')" id="loginTab" class="py-3 rounded-lg font-semibold transition">Login</button>
            <button type="button" onclick="showTab('signup')" id="signupTab" class="py-3 rounded-lg font-semibold transition">Sign Up</button>
        </div>

        <!-- Message -->
        <?php if ($message !== ""): ?>
            <div class="mb-5 rounded-xl p-4 <?= $message_type === "success" ? "bg-green-950/50 border border-green-800 text-green-300" : "bg-red-950/50 border border-red-800 text-red-300"; ?>">
                <div class="flex items-start gap-3">
                    <i class="fa-solid <?= $message_type === "success" ? "fa-circle-check" : "fa-circle-exclamation"; ?> mt-1"></i>
                    <span><?= htmlspecialchars($message); ?></span>
                </div>
            </div>
        <?php endif; ?>

        <!-- LOGIN FORM -->
        <form method="POST" id="loginForm" class="space-y-5">
            <input type="hidden" name="login" value="1">
            <div>
                <label class="block text-sm text-gray-300 mb-2">Username</label>
                <div class="relative">
                    <i class="fa-solid fa-user absolute left-4 top-1/2 -translate-y-1/2 text-gray-500"></i>
                    <input type="text" name="username" autocomplete="username" placeholder="Enter username" class="w-full bg-gray-800 border border-gray-700 rounded-xl py-3.5 pl-11 pr-4 outline-none focus:border-indigo-500" required>
                </div>
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-2">Password</label>
                <div class="relative">
                    <i class="fa-solid fa-lock absolute left-4 top-1/2 -translate-y-1/2 text-gray-500"></i>
                    <input type="password" name="password" autocomplete="current-password" placeholder="Enter password" class="w-full bg-gray-800 border border-gray-700 rounded-xl py-3.5 pl-11 pr-4 outline-none focus:border-indigo-500" required>
                </div>
            </div>
            <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-500 rounded-xl py-3.5 font-semibold transition">
                <i class="fa-solid fa-right-to-bracket mr-2"></i>Login
            </button>
        </form>

        <!-- SIGNUP FORM -->
        <form method="POST" id="signupForm" class="space-y-5 hidden">
            <input type="hidden" name="signup" value="1">
            <div>
                <label class="block text-sm text-gray-300 mb-2">Full Name</label>
                <input type="text" name="signup_fullname" placeholder="Enter your full name" class="w-full bg-gray-800 border border-gray-700 rounded-xl py-3.5 px-4 outline-none focus:border-indigo-500" required>
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-2">Username</label>
                <input type="text" name="signup_username" placeholder="Choose a username" class="w-full bg-gray-800 border border-gray-700 rounded-xl py-3.5 px-4 outline-none focus:border-indigo-500" required>
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-2">Mobile Number</label>
                <input type="tel" name="signup_mobile" placeholder="10-digit mobile number" class="w-full bg-gray-800 border border-gray-700 rounded-xl py-3.5 px-4 outline-none focus:border-indigo-500" required>
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-2">Email</label>
                <input type="email" name="signup_email" placeholder="Enter your email" class="w-full bg-gray-800 border border-gray-700 rounded-xl py-3.5 px-4 outline-none focus:border-indigo-500" required>
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-2">Password</label>
                <input type="password" name="signup_password" placeholder="Create a password" class="w-full bg-gray-800 border border-gray-700 rounded-xl py-3.5 px-4 outline-none focus:border-indigo-500" required>
            </div>
            <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-500 rounded-xl py-3.5 font-semibold transition">
                <i class="fa-solid fa-user-plus mr-2"></i>Create Account
            </button>
        </form>

    </div>

    <p class="text-center text-gray-600 text-xs mt-6">Battle Arena Tournament Platform</p>

</div>

<script>
function showTab(tab) {
    const loginForm = document.getElementById("loginForm");
    const signupForm = document.getElementById("signupForm");
    const loginTab = document.getElementById("loginTab");
    const signupTab = document.getElementById("signupTab");

    if (tab === "login") {
        loginForm.classList.remove("hidden");
        signupForm.classList.add("hidden");
        loginTab.classList.add("bg-indigo-600", "text-white");
        signupTab.classList.remove("bg-indigo-600", "text-white");
    } else {
        signupForm.classList.remove("hidden");
        loginForm.classList.add("hidden");
        signupTab.classList.add("bg-indigo-600", "text-white");
        loginTab.classList.remove("bg-indigo-600", "text-white");
    }
}

showTab("<?= $active_tab; ?>");
</script>

</body>
</html>
