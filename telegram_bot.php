<?php
// Prevent PHP notices/errors from corrupting the HTTP response
error_reporting(0);
ini_set('display_errors', '0');

require_once "Common/config.php";

$bot_token = "8612246633:AAGlgk1e7YK6Fj-cvb6doRJqxch7dbiyuLI";
$api_url = "https://api.telegram.org/bot" . $bot_token . "/";
$admin_id = "8254005139"; // Your Admin ID

$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (!$update) {
    http_response_code(200);
    echo "OK";
    exit;
}

$chat_id = $update["message"]["chat"]["id"] ?? null;
$text = trim($update["message"]["text"] ?? "");
$username = $update["message"]["from"]["username"] ?? "User";
$reply_to = $update["message"]["reply_to_message"]["text"] ?? "";

if ($chat_id && !empty($text)) {

    // 1. ADMIN DIRECT REPLY FEATURE
    // If you (Admin) swipe and reply to an alert, parse the ID and forward to the player
    if ((string)$chat_id === (string)$admin_id && !empty($reply_to)) {
        if (preg_match('/ID:\s*<code>(\d+)<\/code>/', $reply_to, $matches)) {
            $player_chat_id = $matches[1];
            
            $player_reply = "💬 <b>Support Team Reply:</b>\n\n" . htmlspecialchars($text);
            $send_data = [
                'chat_id' => $player_chat_id,
                'text' => $player_reply,
                'parse_mode' => 'HTML'
            ];
            
            $ch = curl_init($api_url . "sendMessage");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($send_data)); 
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_exec($ch);
            curl_close($ch);

            // Confirm to admin
            $confirm_data = [
                'chat_id' => $admin_id,
                'text' => "✅ <b>Reply sent to player successfully.</b>",
                'parse_mode' => 'HTML'
            ];
            $ch2 = curl_init($api_url . "sendMessage");
            curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch2, CURLOPT_POSTFIELDS, http_build_query($confirm_data)); 
            curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false); 
            curl_setopt($ch2, CURLOPT_TIMEOUT, 5);
            curl_exec($ch2);
            curl_close($ch2);

            http_response_code(200);
            echo "OK";
            exit;
        }
    }

    // 2. STANDARD PLAYER COMMANDS
    if ($text === "/start") {
        $reply = "Welcome to Battle Arena Support! 👋\n\nHow can we help you today? Choose an option or type your query:\n1️⃣ /deposit - Deposit not credited / UPI issues\n2️⃣ /withdrawal - Winning amount / payout delay\n3️⃣ /cheating - Report a hacker or team-up";
    } 
    elseif ($text === "/deposit") {
        $reply = "To check your deposit:\nMake sure you paid via UPI and MacroDroid logged it. If it's pending, please send your <b>Transaction UTR Number</b> and your <b>Registered Mobile Number</b>, and our team will verify it within 10 minutes.";
    } 
    elseif ($text === "/withdrawal") {
        $reply = "Withdrawals are processed automatically to your saved UPI ID. If your winning amount is delayed, ensure your UPI ID is updated correctly in your profile.";
    } 
    elseif ($text === "/cheating") {
        $reply = "To report cheating, please provide the match ID, opponent's username, and screen recording proof. Direct bans apply to verified rule-breakers.";
    } 
    else {
        // Log to database
        if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
            $stmt = $conn->prepare("INSERT INTO support_tickets (telegram_id, issue_type, message) VALUES (?, 'General', ?)");
            if ($stmt) {
                $stmt->bind_param("ss", $chat_id, $text);
                $stmt->execute();
                $stmt->close();
            }
        }

        $reply = "Thank you, @$username. Your message has been forwarded to Battle Arena support. We will get back to you shortly.";
        
        // Dispatch alert to Admin
        $admin_text = "🚨 <b>New Support Ticket</b>\n\n<b>From:</b> @$username (ID: <code>$chat_id</code>)\n<b>Message:</b>\n" . htmlspecialchars($text);
        
        $admin_data = [
            'chat_id' => $admin_id,
            'text' => $admin_text,
            'parse_mode' => 'HTML'
        ];
        
        $ch_admin = curl_init($api_url . "sendMessage");
        curl_setopt($ch_admin, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch_admin, CURLOPT_POSTFIELDS, http_build_query($admin_data)); 
        curl_setopt($ch_admin, CURLOPT_SSL_VERIFYPEER, false); 
        curl_setopt($ch_admin, CURLOPT_TIMEOUT, 5);
        curl_exec($ch_admin);
        curl_close($ch_admin);
    }

    $send_data = [
        'chat_id' => $chat_id,
        'text' => $reply,
        'parse_mode' => 'HTML'
    ];
    
    $ch = curl_init($api_url . "sendMessage");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($send_data)); 
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_exec($ch);
    curl_close($ch);
}

http_response_code(200);
echo "OK";
exit;
?>
