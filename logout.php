<?php
session_start();
session_unset();       // remove all session variables
session_destroy();     // destroy the session
setcookie(session_name(), '', time() - 3600, '/'); // clear session cookie

header('Location: login.php');
exit;
?>
