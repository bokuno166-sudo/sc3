<?php
/**
 * Saint Claire Hospital Management System
 * Main Entry Point
 */

require_once 'config/config.php';

// Redirect to login or dashboard based on session
if (isLoggedIn()) {
    redirect('dashboard.php');
} else {
    redirect('login.php');
}
?>
