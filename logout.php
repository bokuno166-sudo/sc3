<?php
require_once 'config/config.php';

// Log activity before destroying session
if (isLoggedIn()) {
    logActivity('logout');
}

// Clear all session data
$_SESSION = array();

// Destroy session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Destroy session
session_destroy();

// Redirect to login page
setFlashMessage('success', 'You have been logged out successfully.');
redirect('login.php');
?>
