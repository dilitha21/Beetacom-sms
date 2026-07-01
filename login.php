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
            --bg-main: #121212;         /* Deep, dark gray (better than pure black) */
            --bg-surface: #1e1e1e;      /* Slightly lighter gray for cards/panels */
            --text-primary: #e0e0e0;    /* Off-white for high readability */
            --text-secondary: #a0a0a0;  /* Dimmer gray for less important text */
            --accent-color: #bb86fc;    /* A vibrant accent color for buttons/links */
            --border-color: #333333;    /* Subtle borders */
        }

        /* 2. Apply the baseline to the whole page */
        body {
            background-color: var(--bg-main);
            color: var(--text-primary);
            font-family: system-ui, -apple-system, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 20px;
            overflow-x: hidden;
            position: relative;
        }

        /* 3. Force headings to pop with pure white */
        h1, h2, h3, h4, h5, h6 {
            color: #ffffff; 
            margin-top: 0;
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
            border-radius: 8px;
            padding: 3rem 2.5rem;
            width: 100%;
            max-width: 460px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.4);
            transition: transform 0.2s ease;
        }

        .login-card:hover {
            transform: translateY(-2px);
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
            border-top-left-radius: 4px;
            border-bottom-left-radius: 4px;
        }

        .form-control {
            background-color: var(--bg-main);
            border: 1px solid var(--border-color);
            color: var(--text-primary) !important;
            border-top-right-radius: 4px;
            border-bottom-right-radius: 4px;
            padding: 0.75rem 1rem;
            transition: all 0.2s ease;
        }

        .form-control:focus {
            background-color: var(--bg-main);
            border-color: var(--accent-color);
            box-shadow: 0 0 0 3px rgba(187, 134, 252, 0.2);
            outline: none;
        }

        .form-control::placeholder {
            color: var(--text-secondary);
        }

        .btn-login {
            background-color: var(--accent-color);
            color: #000000; /* Dark text on a bright button works best */
            border: none;
            border-radius: 4px;
            padding: 0.75rem 1.5rem;
            font-weight: bold;
            width: 100%;
            cursor: pointer;
            transition: background-color 0.2s ease;
        }

        .btn-login:hover {
            background-color: #9965f4;
        }

        .alert-custom {
            background-color: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.25);
            color: #fca5a5;
            border-radius: 4px;
            font-size: 0.875rem;
        }

        .text-muted {
            color: var(--text-secondary) !important;
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

            <button type="submit" class="btn btn-login">
                Sign In
            </button>
        </form>
    </div>

    <!-- Bootstrap 5 Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
