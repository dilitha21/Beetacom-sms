<?php
/**
 * logout.php
 * Destroys the user session and redirects to login.php.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Clear remember token in database
if (isset($_SESSION['user_id'])) {
    require_once 'db_connect.php';
    try {
        $stmt = $pdo->prepare('UPDATE users SET remember_token = NULL WHERE user_id = ?');
        $stmt->execute([$_SESSION['user_id']]);
    } catch (\PDOException $e) {
        // Ignore DB error
    }
}

// Clear remember_me cookie
if (isset($_COOKIE['remember_me'])) {
    setcookie('remember_me', '', time() - 3600, '/');
}

// Clear all session variables
$_SESSION = [];

// If it's desired to kill the session, also delete the session cookie.
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// Redirect to login page
header("Location: login.php");
exit();
