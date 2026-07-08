<?php
require_once 'auth_check.php';
require_once 'db_connect.php';

$success_msg = '';
$error_msg = '';

$user_id = $_SESSION['user_id'];

// Fetch current username
try {
    $stmt = $pdo->prepare("SELECT username FROM users WHERE user_id = :user_id");
    $stmt->execute([':user_id' => $user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        die("User not found.");
    }
    $current_username = $user['username'];
} catch (\PDOException $e) {
    die("Database error: " . htmlspecialchars($e->getMessage()));
}

// POST processing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Handle Username Update
    if ($action === 'update_username') {
        $new_username = trim($_POST['username'] ?? '');

        if ($new_username === '') {
            $error_msg = "Username cannot be empty.";
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE users SET username = :username WHERE user_id = :user_id");
                $stmt->execute([
                    ':username' => $new_username,
                    ':user_id'  => $user_id
                ]);
                $_SESSION['username'] = $new_username;
                $current_username = $new_username;
                $success_msg = "Username updated successfully.";
            } catch (\PDOException $e) {
                // Check if UNIQUE constraint failed (error code 23000 or SQLSTATE[23000])
                if ($e->getCode() == 23000 || strpos($e->getMessage(), '1062') !== false) {
                    $error_msg = "Username is already taken by another staff member.";
                } else {
                    $error_msg = "Failed to update username: " . htmlspecialchars($e->getMessage());
                }
            }
        }
    }

    // Handle Password Update
    if ($action === 'update_password') {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $error_msg = "All password fields are required.";
        } elseif ($new_password !== $confirm_password) {
            $error_msg = "New password and confirmation password do not match.";
        } else {
            try {
                // Fetch current hash
                $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE user_id = :user_id");
                $stmt->execute([':user_id' => $user_id]);
                $db_user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($db_user && password_verify($current_password, $db_user['password_hash'])) {
                    // Update to new hash
                    $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET password_hash = :password WHERE user_id = :user_id");
                    $stmt->execute([
                        ':password' => $new_hash,
                        ':user_id'  => $user_id
                    ]);
                    $success_msg = "Password updated successfully.";
                } else {
                    $error_msg = "Current password is incorrect.";
                }
            } catch (\PDOException $e) {
                $error_msg = "Failed to update password: " . htmlspecialchars($e->getMessage());
            }
        }
    }
}

// Generate CSRF token if not set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$is_htmx = isset($_SERVER['HTTP_HX_REQUEST']);
if (!$is_htmx):
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Student Management System</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <!-- HTMX -->
    <script src="https://unpkg.com/htmx.org@1.9.10"></script>
    <style>
        /* Define theme variables */
        :root {
            --bg-main: #f4f7fb;
            --bg-surface: #ffffff;
            --bg-sidebar: #ebf0f7;
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --accent-color: #6366f1;
            --border-color: #e2e8f0;
            --border-radius: 12px;
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03);
        }

        /* HTMX Transitions & Spinner Styles */
        .htmx-swapping { opacity: 0; transition: opacity 200ms ease-out; }
        #global-spinner { position: fixed; top: 20px; right: 20px; z-index: 9999; display: none; }
        .htmx-request#global-spinner { display: inline-block; }
        .htmx-request #global-spinner { display: inline-block; }

        body {
            background-color: var(--bg-main);
            color: var(--text-primary);
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
            overflow-x: hidden;
            position: relative;
            padding-bottom: 3rem;
            line-height: 1.6;
        }

        h1, h2, h3, h4, h5, h6 {
            color: var(--text-primary);
            margin-top: 0;
            font-weight: 700;
        }

        /* Navigation Bar Styling */
        .navbar-custom {
            background-color: var(--bg-surface);
            border-bottom: 1px solid var(--border-color);
            box-shadow: var(--shadow-md);
            padding: 10px 20px;
        }
        .navbar-brand {
            font-weight: 700;
            letter-spacing: -0.5px;
            color: var(--text-primary) !important;
        }
        .nav-link {
            color: var(--text-secondary) !important;
            font-weight: 500;
            transition: color 0.2s ease;
        }
        .nav-link:hover, .nav-link.active {
            color: var(--accent-color) !important;
        }

        /* Card panels */
        .profile-card {
            background-color: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow-md);
            margin-top: 20px;
        }

        .card-header-custom {
            background-color: rgba(0, 0, 0, 0.01);
            border-bottom: 1px solid var(--border-color);
            padding: 1.5rem 2rem;
        }

        .form-label {
            font-size: 0.85rem;
            font-weight: 500;
            color: var(--text-secondary);
            margin-bottom: 0.4rem;
        }

        .form-label.required::after {
            content: ' *';
            color: #ef4444;
        }

        .form-control {
            background-color: var(--bg-main);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            border-radius: 8px;
            padding: 0.6rem 0.85rem;
            transition: all 0.2s ease;
        }

        .form-control:focus {
            background-color: var(--bg-surface);
            border-color: var(--accent-color);
            color: var(--text-primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2);
            outline: none;
        }

        /* Buttons */
        .btn-accent {
            background-color: var(--accent-color);
            color: #ffffff;
            border: none;
            border-radius: 8px;
            padding: 0.75rem 2rem;
            font-weight: bold;
            cursor: pointer;
            transition: background-color 0.2s ease;
        }
        .btn-accent:hover {
            background-color: #4f46e5;
        }

        .alert-custom-success {
            background-color: #d1fae5;
            border: 1px solid rgba(16, 185, 129, 0.25);
            color: #065f46;
            border-radius: 8px;
        }
        .alert-custom-error {
            background-color: #fee2e2;
            border: 1px solid rgba(239, 68, 68, 0.25);
            color: #ef4444;
            border-radius: 8px;
        }
    </style>
</head>
<body hx-indicator="#global-spinner">

    <!-- Global Loading Spinner -->
    <div id="global-spinner" class="htmx-indicator spinner-border text-primary" role="status">
        <span class="visually-hidden">Loading...</span>
    </div>

    <!-- Nav Bar -->
    <nav class="navbar navbar-expand-lg navbar-custom sticky-top mb-4">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2" href="dashboard.php" hx-get="dashboard.php" hx-target="#main-content" hx-push-url="true" hx-swap="innerHTML transition:true">
                <img src="logo.jpg" alt="BMCS Logo" style="height: 38px; width: auto; object-fit: contain;">
                <span>Beetacom</span>
            </a>
            <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php" hx-get="dashboard.php" hx-target="#main-content" hx-push-url="true" hx-swap="innerHTML transition:true"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="add_student.php" hx-get="add_student.php" hx-target="#main-content" hx-push-url="true" hx-swap="innerHTML transition:true"><i class="bi bi-person-plus me-1"></i>Register</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="bulk_grading.php" hx-get="bulk_grading.php" hx-target="#main-content" hx-push-url="true" hx-swap="innerHTML transition:true"><i class="bi bi-journal-plus me-1"></i>Grades</a>
                    </li>
                </ul>
                <div class="d-flex align-items-center gap-3">
                    <a href="profile.php" class="nav-link active small" hx-get="profile.php" hx-target="#main-content" hx-push-url="true" hx-swap="innerHTML transition:true"><i class="bi bi-gear-fill me-1"></i>My Profile</a>
                    <a href="logout.php" class="btn btn-outline-danger btn-sm rounded-pill px-3">
                        <i class="bi bi-box-arrow-right me-1"></i>Logout
                    </a>
                </div>
            </div>
        </div>
    </nav>
<?php endif; ?>

    <main id="main-content">

    <!-- Main Container -->
    <div class="container pb-5">
        <div class="row justify-content-center">
            <div class="col-lg-8">

                <?php if ($success_msg !== ''): ?>
                    <div class="alert alert-custom-success d-flex align-items-center mb-4" role="alert">
                        <i class="bi bi-check-circle-fill me-2 text-success"></i>
                        <div><?php echo $success_msg; ?></div>
                    </div>
                <?php endif; ?>

                <?php if ($error_msg !== ''): ?>
                    <div class="alert alert-custom-error d-flex align-items-center mb-4" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <div><?php echo $error_msg; ?></div>
                    </div>
                <?php endif; ?>

                <!-- Card 1: Update Account Details -->
                <div class="profile-card mb-4">
                    <div class="card-header-custom">
                        <h4 class="mb-1 fw-bold"><i class="bi bi-person-vcard me-2 text-primary"></i>Update Account Details</h4>
                        <p class="text-muted mb-0 small">Change your username. Note that usernames must be unique.</p>
                    </div>
                    <div class="p-4">
                        <form action="profile.php" method="POST" class="needs-validation" novalidate>
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="action" value="update_username">
                            <div class="mb-3">
                                <label for="username" class="form-label required">Username</label>
                                <input type="text" class="form-control" id="username" name="username" required value="<?php echo htmlspecialchars($current_username); ?>">
                            </div>
                            <div class="text-end">
                                <button type="submit" class="btn btn-accent">Save Changes</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Card 2: Change Password -->
                <div class="profile-card">
                    <div class="card-header-custom">
                        <h4 class="mb-1 fw-bold"><i class="bi bi-shield-lock me-2 text-primary"></i>Change Password</h4>
                        <p class="text-muted mb-0 small">Ensure your account is using a long, random password to stay secure.</p>
                    </div>
                    <div class="p-4">
                        <form action="profile.php" method="POST" class="needs-validation" novalidate>
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="action" value="update_password">
                            <div class="mb-3">
                                <label for="current_password" class="form-label required">Current Password</label>
                                <input type="password" class="form-control" id="current_password" name="current_password" required>
                            </div>
                            <div class="mb-3">
                                <label for="new_password" class="form-label required">New Password</label>
                                <input type="password" class="form-control" id="new_password" name="new_password" required minlength="6">
                            </div>
                            <div class="mb-3">
                                <label for="confirm_password" class="form-label required">Confirm New Password</label>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="6">
                            </div>
                            <div class="text-end">
                                <button type="submit" class="btn btn-accent">Update Password</button>
                            </div>
                        </form>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- Bootstrap 5 Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Bootstrap Validation
        (function () {
            'use strict'
            var forms = document.querySelectorAll('.needs-validation')
            Array.prototype.slice.call(forms)
                .forEach(function (form) {
                    form.addEventListener('submit', function (event) {
                        if (!form.checkValidity()) {
                            event.preventDefault()
                            event.stopPropagation()
                        }
                        form.classList.add('was-validated')
                    }, false)
                })
        })()
    </script>
    </main>

<?php if (!$is_htmx): ?>
</body>
</html>
<?php endif; ?>
