<?php
// 1. Resume the current session
session_start();

// 2. Clear all the session variables
$_SESSION = array();

// 3. Destroy the session completely
session_destroy();

// 4. Redirect the user back to the main login page
header("Location: index.php");
exit;
?>