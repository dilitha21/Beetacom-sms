<?php
/**
 * auth_check.php
 * Include this file at the top of any page that requires authentication.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    // Prevent session fixation or caching issues by redirecting
    header("Location: login.php");
    exit();
}
