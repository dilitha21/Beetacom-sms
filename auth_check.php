<?php
/**
 * auth_check.php
 * Include this file at the top of any page that requires authentication.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    // Check if remember_me cookie is set
    if (isset($_COOKIE['remember_me'])) {
        $parts = explode(':', $_COOKIE['remember_me'], 2);
        if (count($parts) === 2) {
            $cookie_user_id = (int)$parts[0];
            $cookie_token = $parts[1];

            require_once 'db_connect.php';
            try {
                $stmt = $pdo->prepare('SELECT * FROM users WHERE user_id = ?');
                $stmt->execute([$cookie_user_id]);
                $user = $stmt->fetch();

                if ($user && !empty($user['remember_token']) && hash_equals($user['remember_token'], hash('sha256', $cookie_token))) {
                    // Set session variables
                    $_SESSION['user_id']  = $user['user_id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role']     = $user['role'];

                    // Rotate the remember token for security
                    $new_token = bin2hex(random_bytes(32));
                    $new_hash = hash('sha256', $new_token);

                    $update_stmt = $pdo->prepare('UPDATE users SET remember_token = ? WHERE user_id = ?');
                    $update_stmt->execute([$new_hash, $user['user_id']]);

                    setcookie(
                        'remember_me',
                        $user['user_id'] . ':' . $new_token,
                        [
                            'expires' => time() + (30 * 24 * 60 * 60), // 30 days
                            'path' => '/',
                            'httponly' => true,
                            'samesite' => 'Lax'
                        ]
                    );

                    // User successfully authenticated via cookie, continue execution
                    return;
                }
            } catch (\PDOException $e) {
                // Ignore DB error, fallback to redirect
            }
        }
    }

    // Prevent session fixation or caching issues by redirecting
    header("Location: login.php");
    exit();
}
