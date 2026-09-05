<?php

require_once "../common/config.php";


// =====================================================
// CHECK ADMIN LOGIN
// =====================================================

if (
    isset($_SESSION["admin_logged_in"]) &&
    $_SESSION["admin_logged_in"] === true
) {

    header("Location: dashboard.php");
    exit;

}


// =====================================================
// NOT LOGGED IN
// =====================================================

header("Location: login.php");
exit;

?>
