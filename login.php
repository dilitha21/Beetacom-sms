<?php
/**
 * login.php
 * Secure login form and authentication using PDO.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

require_once 'db_connect.php';

// Check if remember_me cookie is set
if (isset($_COOKIE['remember_me'])) {
    $parts = explode(':', $_COOKIE['remember_me'], 2);
    if (count($parts) === 2) {
        $cookie_user_id = (int)$parts[0];
        $cookie_token = $parts[1];

        try {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE user_id = ?');
            $stmt->execute([$cookie_user_id]);
            $user = $stmt->fetch();

            if ($user && !empty($user['remember_token']) && hash_equals($user['remember_token'], hash('sha256', $cookie_token))) {
                // Set session variables
                $_SESSION['user_id']  = $user['user_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role']     = $user['role'];

                // Rotate remember token for security
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

                header("Location: dashboard.php");
                exit();
            }
        } catch (\PDOException $e) {
            // Ignore DB error
        }
    }
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Protection
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        $error = 'Invalid session token. Please refresh and try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $error = 'Please enter both username and password.';
        } else {
            try {
                $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
                $stmt->execute([$username]);
                $user = $stmt->fetch();

                if ($user && password_verify($password, $user['password_hash'])) {
                    // Regenerate session ID for security
                    session_regenerate_id(true);

                    // Set session variables
                    $_SESSION['user_id']  = $user['user_id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role']     = $user['role'];

                    // Process Remember Me
                    if (isset($_POST['remember_me'])) {
                        $token = bin2hex(random_bytes(32));
                        $token_hash = hash('sha256', $token);

                        $update_stmt = $pdo->prepare('UPDATE users SET remember_token = ? WHERE user_id = ?');
                        $update_stmt->execute([$token_hash, $user['user_id']]);

                        setcookie(
                            'remember_me',
                            $user['user_id'] . ':' . $token,
                            [
                                'expires' => time() + (30 * 24 * 60 * 60), // 30 days
                                'path' => '/',
                                'httponly' => true,
                                'samesite' => 'Lax'
                            ]
                        );
                    }

                    header("Location: dashboard.php");
                    exit();
                } else {
                    $error = 'Invalid username or password.';
                }
            } catch (\PDOException $e) {
                // Log actual error securely in production; show friendly message
                $error = 'An error occurred. Please try again.';
            }
        }
    }
}

// Generate new CSRF token for the session if it doesn't exist
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Student Registration System</title>
    <!-- Google Fonts: Plus Jakarta Sans for a premium feel -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        /* 1. Define your global color palette */
        :root {
            --bg-main: #f4f7fb;       /* Soft blue-gray background */
            --bg-surface: #ffffff;    /* Pure white for cards/panels */
            --text-primary: #1e293b;  /* Dark slate for high contrast */
            --text-secondary: #64748b;/* Muted slate for labels/metadata */
            --accent-color: #6366f1;   /* Vibrant Indigo */
            --border-color: #e2e8f0;   /* Subtle borders */
        }

        /* 2. Apply the baseline to the whole page */
        body {
            background-color: var(--bg-main);
            color: var(--text-primary);
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 20px;
            overflow-x: hidden;
            position: relative;
        }

        /* 3. Force headings to pop with dark slate */
        h1, h2, h3, h4, h5, h6 {
            color: var(--text-primary); 
            margin-top: 0;
            font-weight: 700;
        }

        /* 4. Fix vanishing text in input fields and forms */
        input, select, textarea {
            background-color: var(--bg-surface);
            color: var(--text-primary); /* Ensures typed text is visible */
            border: 1px solid var(--border-color);
            padding: 10px;
            border-radius: 4px;
        }

        .login-card {
            background-color: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 3rem 2.5rem;
            width: 100%;
            max-width: 460px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .login-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.08);
        }

        .form-label {
            font-weight: 500;
            font-size: 0.875rem;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        .input-group-text {
            background-color: var(--bg-main);
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
            border-top-left-radius: 8px;
            border-bottom-left-radius: 8px;
        }

        .form-control {
            background-color: var(--bg-surface);
            border: 1px solid var(--border-color);
            color: var(--text-primary) !important;
            border-top-right-radius: 8px;
            border-bottom-right-radius: 8px;
            padding: 0.75rem 1rem;
            transition: all 0.2s ease;
        }

        .form-control:focus {
            background-color: var(--bg-surface);
            border-color: var(--accent-color);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2);
            outline: none;
        }

        .form-control::placeholder {
            color: var(--text-secondary);
        }

        .btn-login {
            background-color: var(--accent-color);
            color: #ffffff; /* White text on Indigo works best */
            border: none;
            border-radius: 8px;
            padding: 0.75rem 1.5rem;
            font-weight: bold;
            width: 100%;
            cursor: pointer;
            transition: background-color 0.2s ease;
        }

        .btn-login:hover {
            background-color: #4f46e5;
        }

        .alert-custom {
            background-color: #fee2e2;
            border: 1px solid rgba(239, 68, 68, 0.25);
            color: #ef4444;
            border-radius: 8px;
            font-size: 0.875rem;
        }

        .text-muted {
            color: var(--text-secondary) !important;
        }

        /* Custom checkbox styles */
        .form-check-input:checked {
            background-color: var(--accent-color);
            border-color: var(--accent-color);
        }
    </style>
</head>
<body>

    <div class="login-card">
        <div class="text-center mb-4">
            <img src="logo.jpg" alt="BMCS Logo" style="max-height: 120px; max-width: 100%; height: auto; object-fit: contain;">
        </div>
        <h3 class="text-center mb-1 fw-bold">Welcome Back</h3>
        <p class="text-center text-muted mb-4 small">Please sign in to access your dashboard</p>

        <?php if (!empty($error)): ?>
            <div class="alert alert-custom d-flex align-items-center mb-4" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <div>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            </div>
        <?php endif; ?>

        <form action="login.php" method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

            <div class="mb-3">
                <label for="username" class="form-label">Username</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-person"></i></span>
                    <input type="text" class="form-control" id="username" name="username" placeholder="Enter username" required value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                </div>
            </div>

            <div class="mb-4">
                <label for="password" class="form-label">Password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-key"></i></span>
                    <input type="password" class="form-control" id="password" name="password" placeholder="Enter password" required>
                </div>
            </div>

            <div class="mb-4 d-flex align-items-center">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="remember_me" name="remember_me">
                    <label class="form-check-label text-muted small ms-1" for="remember_me">
                        Remember me
                    </label>
                </div>
            </div>

            <button type="submit" class="btn btn-login">
                Sign In
            </button>
        </form>
    </div>

    <!-- Bootstrap 5 Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
