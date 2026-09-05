<?php

require_once "../common/config.php";

// Destroy admin session
unset($_SESSION["admin_logged_in"]);
unset($_SESSION["admin_username"]);

// Redirect to admin login
header("Location: login.php");
exit;

?>