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

$page_title = 'My Profile - Student Management System';
ob_start();
?>
    <style>
        .profile-card {
            background-color: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-md);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .card-header-custom {
            background-color: rgba(0, 0, 0, 0.01);
            border-bottom: 1px solid var(--border-color);
            padding: 1.5rem 2rem;
        }

        .form-label.required::after {
            content: ' *';
            color: #ef4444;
        }

        .form-control, .form-select {
            background-color: var(--bg-main);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            border-radius: 8px;
            padding: 0.6rem 0.85rem;
            transition: all 0.2s ease;
        }

        .form-control:focus, .form-select:focus {
            background-color: var(--bg-surface);
            border-color: var(--accent-color);
            color: var(--text-primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2);
            outline: none;
        }
    </style>
<?php
$extra_css = ob_get_clean();
include 'header.php';
?>

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

<?php include 'footer.php'; ?>
