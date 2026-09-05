<?php

require_once "../common/config.php";

// =====================================================
// AUTO-SETUP ADMIN TABLE & CREDENTIALS (Runs only once)
// =====================================================
try {
    // 1. Create the admins table if it doesn't exist
    $conn->query("CREATE TABLE IF NOT EXISTS admins (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL
    )");
    
    // 2. Check if an admin account already exists
    $check_admin = $conn->query("SELECT id FROM admins WHERE username = 'Rinku' LIMIT 1");
    if ($check_admin && $check_admin->num_rows === 0) {
        // 3. Create the secure hashed password for rinku701 and insert it
        $hashed_pw = password_hash("rinku701", PASSWORD_DEFAULT);
        $insert_admin = $conn->prepare("INSERT INTO admins (username, password) VALUES ('Rinku', ?)");
        $insert_admin->bind_param("s", $hashed_pw);
        $insert_admin->execute();
    }
} catch (Exception $e) {
    // Silently continue; the main login block will handle any hard database errors
}

// =====================================================
// IF ALREADY LOGGED IN AS ADMIN
// =====================================================

if (isset($_SESSION["admin_logged_in"]) && $_SESSION["admin_logged_in"] === true) {
    header("Location: dashboard.php");
    exit;
}

// =====================================================
// ADMIN LOGIN (Now secured via Database)
// =====================================================

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($username === "" || $password === "") {
        $error = "Please enter username and password.";
    } else {
        try {
            $stmt = $conn->prepare("SELECT id, username, password FROM admins WHERE username = ? LIMIT 1");
            
            if (!$stmt) {
                $error = "Database Error: " . $conn->error;
            } else {
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows === 1) {
                    $admin = $result->fetch_assoc();
                    
                    // Verify the entered password against the hashed password in the database
                    if (password_verify($password, $admin["password"])) {
                        
                        session_regenerate_id(true);
                        $_SESSION["admin_logged_in"] = true;
                        $_SESSION["admin_username"] = $admin["username"];

                        header("Location: dashboard.php");
                        exit;
                        
                    } else {
                        $error = "Invalid admin credentials.";
                    }
                } else {
                    $error = "Invalid admin credentials.";
                }
                $stmt->close();
            }
        } catch (Exception $e) {
            $error = "Database Error: " . $e->getMessage();
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - Battle Arena</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>

<body class="bg-gray-950 text-white min-h-screen flex items-center justify-center px-4">

<main class="w-full max-w-md">

    <!-- =================================================
         LOGO / TITLE
    ================================================== -->
    <div class="text-center mb-8">
        <div class="w-16 h-16 mx-auto rounded-2xl bg-indigo-600 flex items-center justify-center mb-4">
            <i class="fa-solid fa-shield-halved text-2xl"></i>
        </div>
        <h1 class="text-2xl font-bold">Battle Arena</h1>
        <p class="text-gray-500 text-sm mt-2">Owner / Admin Panel</p>
    </div>

    <!-- =================================================
         LOGIN CARD
    ================================================== -->
    <div class="bg-gray-900 border border-gray-800 rounded-2xl p-6 shadow-xl">

        <?php if ($error !== ""): ?>
            <div class="mb-5 bg-red-950/50 border border-red-800 text-red-300 rounded-xl p-4">
                <div class="flex items-start gap-3">
                    <i class="fa-solid fa-circle-exclamation mt-1"></i>
                    <span class="text-sm">
                        <?php echo htmlspecialchars($error); ?>
                    </span>
                </div>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-5">

            <!-- Username -->
            <div>
                <label class="block text-sm text-gray-300 mb-2">Admin Username</label>
                <div class="relative">
                    <i class="fa-solid fa-user-shield absolute left-4 top-1/2 -translate-y-1/2 text-gray-500"></i>
                    <input type="text" name="username" autocomplete="username" placeholder="Enter admin username" class="w-full bg-gray-800 border border-gray-700 rounded-xl py-3.5 pl-11 pr-4 outline-none focus:border-indigo-500" required>
                </div>
            </div>

            <!-- Password -->
            <div>
                <label class="block text-sm text-gray-300 mb-2">Password</label>
                <div class="relative">
                    <i class="fa-solid fa-lock absolute left-4 top-1/2 -translate-y-1/2 text-gray-500"></i>
                    <input type="password" name="password" autocomplete="current-password" placeholder="Enter admin password" class="w-full bg-gray-800 border border-gray-700 rounded-xl py-3.5 pl-11 pr-4 outline-none focus:border-indigo-500" required>
                </div>
            </div>

            <!-- Login -->
            <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-500 active:scale-[0.98] rounded-xl py-3.5 font-semibold transition">
                <i class="fa-solid fa-right-to-bracket mr-2"></i> Admin Login
            </button>

        </form>

        <!-- Player Login -->
        <div class="text-center border-t border-gray-800 mt-6 pt-5">
            <a href="../login.php" class="text-sm text-gray-500 hover:text-indigo-400">
                <i class="fa-solid fa-arrow-left mr-1"></i> Back to Player Login
            </a>
        </div>

    </div>

    <p class="text-center text-xs text-gray-600 mt-6">
        Authorized owner access only
    </p>

</main>

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
