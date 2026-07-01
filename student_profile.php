<?php
/**
 * student_profile.php
 * Displays student profile details and manages payments (Full / Installments).
 */

// 1. Ensure user is logged in
require_once 'auth_check.php';
require_once 'db_connect.php';

$student_id = filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT);
if (!$student_id) {
    header("Location: dashboard.php");
    exit;
}

$student = null;
$plan = null;
$receipts = [];
$error_msg = '';
$success_msg = '';

// 2. Fetch Student Profile Data
try {
    $sql = "SELECT s.*, u.username AS creator_username 
            FROM students s 
            LEFT JOIN users u ON s.added_by = u.user_id 
            WHERE s.id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $student_id]);
    $student = $stmt->fetch();

    if (!$student) {
        $error_msg = "No student record found with ID " . htmlspecialchars($student_id) . ".";
    }
} catch (\PDOException $e) {
    $error_msg = "Database query failed: " . htmlspecialchars($e->getMessage());
}

// 3. Handle POST actions if student exists
if ($student && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // CSRF check
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        $error_msg = 'Invalid session token. Please try again.';
    } else {
        if ($action === 'pay_later') {
            try {
                // Set plan type as 'pending'
                $pdo->prepare("DELETE FROM payment_plans WHERE student_id = :id")->execute([':id' => $student_id]);
                
                $stmt = $pdo->prepare("INSERT INTO payment_plans (student_id, plan_type, base_fee, final_total) VALUES (:student_id, 'pending', 0.00, 0.00)");
                $stmt->execute([':student_id' => $student_id]);
                header("Location: dashboard.php");
                exit;
            } catch (\PDOException $e) {
                $error_msg = "Failed to register Pay Later: " . htmlspecialchars($e->getMessage());
            }
        } elseif ($action === 'setup_plan') {
            $base_fee = floatval($_POST['base_fee'] ?? 0);
            $plan_type = $_POST['plan_type'] ?? '';
            $payment_date = trim($_POST['payment_date'] ?? date('Y-m-d'));

            if ($base_fee <= 0 || !in_array($plan_type, ['full', 'installment'])) {
                $error_msg = "Invalid course fee or payment type selection.";
            } else {
                $final_total = $base_fee;
                $amount_paid = 0.00;

                if ($plan_type === 'full') {
                    $final_total = $base_fee * 0.90; // 10% discount for full payments
                    $amount_paid = $final_total;
                } else {
                    $amount_paid = $base_fee / 6; // First installment share
                }

                try {
                    $pdo->beginTransaction();

                    // Remove any existing pending plans
                    $pdo->prepare("DELETE FROM payment_plans WHERE student_id = :id")->execute([':id' => $student_id]);

                    // Insert payment plan
                    $stmt = $pdo->prepare("INSERT INTO payment_plans (student_id, plan_type, base_fee, final_total) VALUES (:student_id, :plan_type, :base_fee, :final_total)");
                    $stmt->execute([
                        ':student_id' => $student_id,
                        ':plan_type'   => $plan_type,
                        ':base_fee'    => $base_fee,
                        ':final_total' => $final_total
                    ]);

                    // Insert initial receipt payment record
                    $stmt = $pdo->prepare("INSERT INTO payment_records (student_id, amount_paid, payment_date, installment_number) VALUES (:student_id, :amount_paid, :payment_date, 1)");
                    $stmt->execute([
                        ':student_id' => $student_id,
                        ':amount_paid' => $amount_paid,
                        ':payment_date' => $payment_date
                    ]);

                    $pdo->commit();
                    $success_msg = "Payment plan set up successfully!";
                } catch (\Exception $e) {
                    $pdo->rollBack();
                    $error_msg = "Payment plan setup failed: " . htmlspecialchars($e->getMessage());
                }
            }
        } elseif ($action === 'record_payment') {
            $amount_paid = floatval($_POST['amount_paid'] ?? 0);
            $payment_date = trim($_POST['payment_date'] ?? date('Y-m-d'));

            // Find current payment records
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM payment_records WHERE student_id = :id");
            $stmt->execute([':id' => $student_id]);
            $current_count = intval($stmt->fetchColumn());
            $next_installment = $current_count + 1;

            if ($amount_paid <= 0) {
                $error_msg = "Invalid payment amount.";
            } elseif ($next_installment > 6) {
                $error_msg = "All 6 installments are already recorded.";
            } else {
                try {
                    $stmt = $pdo->prepare("INSERT INTO payment_records (student_id, amount_paid, payment_date, installment_number) VALUES (:student_id, :amount_paid, :payment_date, :installment_number)");
                    $stmt->execute([
                        ':student_id'         => $student_id,
                        ':amount_paid'        => $amount_paid,
                        ':payment_date'       => $payment_date,
                        ':installment_number' => $next_installment
                    ]);
                    $success_msg = "Installment payment recorded successfully!";
                } catch (\PDOException $e) {
                    $error_msg = "Failed to record payment: " . htmlspecialchars($e->getMessage());
                }
            }
        } elseif ($action === 'delete_profile') {
            try {
                $stmt = $pdo->prepare("DELETE FROM students WHERE id = :id");
                $stmt->execute([':id' => $student_id]);
                header("Location: dashboard.php");
                exit;
            } catch (\PDOException $e) {
                $error_msg = "Failed to delete student profile: " . htmlspecialchars($e->getMessage());
            }
        } elseif ($action === 'delete_receipt') {
            $receipt_id = filter_var($_POST['receipt_id'] ?? 0, FILTER_VALIDATE_INT);
            if ($receipt_id) {
                try {
                    $stmt = $pdo->prepare("DELETE FROM payment_records WHERE receipt_id = :receipt_id AND student_id = :student_id");
                    $stmt->execute([
                        ':receipt_id' => $receipt_id,
                        ':student_id' => $student_id
                    ]);
                    $success_msg = "Payment receipt record deleted successfully.";
                } catch (\PDOException $e) {
                    $error_msg = "Failed to delete payment receipt: " . htmlspecialchars($e->getMessage());
                }
            }
        } elseif ($action === 'reset_plan') {
            try {
                $pdo->beginTransaction();
                // Delete all receipts
                $pdo->prepare("DELETE FROM payment_records WHERE student_id = :id")->execute([':id' => $student_id]);
                // Delete payment plan
                $pdo->prepare("DELETE FROM payment_plans WHERE student_id = :id")->execute([':id' => $student_id]);
                $pdo->commit();
                $success_msg = "Payment plan reset successfully.";
                $plan = null;
                $receipts = [];
            } catch (\Exception $e) {
                $pdo->rollBack();
                $error_msg = "Failed to reset payment plan: " . htmlspecialchars($e->getMessage());
            }
        }
    }
}

// 4. Fetch Active Payment Details & Receipts
if ($student && empty($error_msg)) {
    try {
        // Fetch current plan
        $stmt = $pdo->prepare("SELECT * FROM payment_plans WHERE student_id = :id");
        $stmt->execute([':id' => $student_id]);
        $plan = $stmt->fetch();

        // Fetch receipts
        $stmt = $pdo->prepare("SELECT * FROM payment_records WHERE student_id = :id ORDER BY installment_number ASC");
        $stmt->execute([':id' => $student_id]);
        $receipts = $stmt->fetchAll();
    } catch (\PDOException $e) {
        $error_msg = "Failed to retrieve payment records: " . htmlspecialchars($e->getMessage());
    }
}

// Generate CSRF token if not set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Profile Details - Student Management System</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        /* 1. Define your global color palette */
        :root {
            --bg-main: #121212;         /* Deep, dark gray */
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
            margin: 0;
            padding: 20px;
            overflow-x: hidden;
            position: relative;
            padding-bottom: 3rem;
        }

        /* 3. Force headings to pop with pure white */
        h1, h2, h3, h4, h5, h6 {
            color: #ffffff; 
            margin-top: 0;
        }

        /* 4. Inputs and dropdowns */
        input, select, textarea {
            background-color: var(--bg-main) !important;
            color: var(--text-primary) !important;
            border: 1px solid var(--border-color) !important;
            padding: 10px;
            border-radius: 4px;
        }
        input:focus, select:focus {
            border-color: var(--accent-color) !important;
            box-shadow: 0 0 0 3px rgba(187, 134, 252, 0.2) !important;
            outline: none !important;
        }

        /* Navigation Bar Styling */
        .navbar-custom {
            background-color: var(--bg-surface);
            border-bottom: 1px solid var(--border-color);
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.2);
            padding: 10px 20px;
        }
        .navbar-brand {
            font-weight: 700;
            letter-spacing: -0.5px;
            color: #ffffff !important;
        }
        .nav-link {
            color: var(--text-secondary) !important;
            font-weight: 500;
            transition: color 0.2s ease;
        }
        .nav-link:hover, .nav-link.active {
            color: var(--accent-color) !important;
        }

        /* Profile Card & Details Layout */
        .profile-card {
            background-color: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.4);
            margin-top: 20px;
        }

        .profile-header {
            background-color: rgba(255, 255, 255, 0.02);
            border-bottom: 1px solid var(--border-color);
            padding: 2rem;
        }

        .profile-section-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--accent-color);
            border-bottom: 1px dashed var(--border-color);
            padding-bottom: 0.5rem;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .profile-label {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.25rem;
        }

        .profile-value {
            font-size: 1rem;
            color: var(--text-primary);
            margin-bottom: 1.25rem;
        }

        /* Badges */
        .badge-tag {
            background-color: rgba(187, 134, 252, 0.12);
            color: var(--accent-color);
            border: 1px solid rgba(187, 134, 252, 0.25);
            padding: 0.4em 0.8em;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 500;
            display: inline-block;
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
        }

        .badge-tag-qual {
            background-color: rgba(16, 185, 129, 0.12);
            color: #a7f3d0;
            border: 1px solid rgba(16, 185, 129, 0.25);
            padding: 0.4em 0.8em;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 500;
            display: inline-block;
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
        }

        .badge-historical {
            background-color: var(--bg-main);
            color: #f59e0b;
            border: 1px solid var(--border-color);
            padding: 0.25em 0.75em;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        .badge-active {
            background-color: rgba(16, 185, 129, 0.15);
            color: #a7f3d0;
            border: 1px solid rgba(16, 185, 129, 0.3);
            padding: 0.25em 0.75em;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        /* Buttons */
        .btn-accent {
            background-color: var(--accent-color);
            color: #000000;
            border: none;
            border-radius: 4px;
            padding: 0.75rem 1.5rem;
            font-weight: bold;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            transition: background-color 0.2s ease;
        }
        .btn-accent:hover {
            background-color: #9965f4;
            color: #000000;
        }

        .btn-muted-outline {
            background-color: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
            border-radius: 4px;
            padding: 0.75rem 1.5rem;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s ease;
        }
        .btn-muted-outline:hover {
            background-color: var(--border-color);
            color: #ffffff;
        }

        .text-muted {
            color: var(--text-secondary) !important;
        }

        .form-select option {
            background-color: var(--bg-surface);
            color: var(--text-primary);
        }
    </style>
</head>
<body>

    <!-- Nav Bar -->
    <nav class="navbar navbar-expand-lg navbar-custom sticky-top mb-4">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2" href="dashboard.php">
                <img src="logo.jpg" alt="BMCS Logo" style="height: 38px; width: auto; object-fit: contain;">
                <span>Beetacom</span>
            </a>
            <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="add_student.php"><i class="bi bi-person-plus me-1"></i>Add Student</a>
                    </li>
                </ul>
                <div class="d-flex align-items-center gap-3">
                    <span class="text-light small">
                        <i class="bi bi-person-circle me-1 text-primary"></i><?php echo htmlspecialchars($_SESSION['username']); ?> 
                        <span class="badge bg-secondary ms-1"><?php echo htmlspecialchars($_SESSION['role']); ?></span>
                    </span>
                    <a href="logout.php" class="btn btn-outline-danger btn-sm rounded-pill px-3">
                        <i class="bi bi-box-arrow-right me-1"></i>Logout
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Container -->
    <div class="container pb-5">
        <div class="row justify-content-center">
            <div class="col-xl-9">

                <?php if (!empty($error_msg)): ?>
                    <div class="alert alert-danger d-flex align-items-center mb-4" role="alert" style="background-color: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.3); color: #fca5a5; border-radius: 4px;">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <div><?php echo $error_msg; ?></div>
                    </div>
                    <div class="text-center">
                        <a href="dashboard.php" class="btn-accent"><i class="bi bi-arrow-left"></i> Return to Dashboard</a>
                    </div>
                <?php else: ?>

                    <?php if (!empty($success_msg)): ?>
                        <div class="alert alert-success d-flex align-items-center mb-4" role="alert" style="background-color: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.3); color: #a7f3d0; border-radius: 4px;">
                            <i class="bi bi-check-circle-fill me-2"></i>
                            <div><?php echo htmlspecialchars($success_msg); ?></div>
                        </div>
                    <?php endif; ?>

                    <!-- Profile Detailed Review Card -->
                    <div class="profile-card mb-4">
                        
                        <!-- Header Banner -->
                        <div class="profile-header d-flex justify-content-between align-items-center flex-wrap gap-3">
                            <div>
                                <h3 class="mb-1 fw-bold"><?php echo htmlspecialchars($student['name']); ?></h3>
                                <p class="text-muted mb-0 small">
                                    Official Index: <span class="fw-bold text-white"><?php echo htmlspecialchars($student['index_number']); ?></span>
                                </p>
                            </div>
                            <div>
                                <?php if ($student['is_historical']): ?>
                                    <span class="badge-historical"><i class="bi bi-file-earmark-lock-fill me-1"></i>Historical Paper Record</span>
                                <?php else: ?>
                                    <span class="badge-active"><i class="bi bi-cloud-check-fill me-1"></i>Live Digital Entry</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Card Body Details -->
                        <div class="p-4 p-md-5">
                            
                            <!-- SECTION 1: REGISTRATION META -->
                            <div class="profile-section-title">
                                <i class="bi bi-file-earmark-text"></i> Registration Parameters
                            </div>
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="profile-label">Course Code</div>
                                    <div class="profile-value"><?php echo htmlspecialchars($student['course_code']); ?></div>
                                </div>
                                <div class="col-md-4">
                                    <div class="profile-label">Batch Year</div>
                                    <div class="profile-value">20<?php echo htmlspecialchars($student['batch_year']); ?></div>
                                </div>
                                <div class="col-md-4">
                                    <div class="profile-label">NVQ Type</div>
                                    <div class="profile-value">
                                        <?php echo !empty($student['is_nvq']) ? htmlspecialchars($student['is_nvq']) . ' (NVQ)' : 'Non-NVQ'; ?>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="profile-label">Sequence Number</div>
                                    <div class="profile-value"><?php echo htmlspecialchars($student['sequence_number']); ?></div>
                                </div>
                                <div class="col-md-4">
                                    <div class="profile-label">Registration Date</div>
                                    <div class="profile-value"><?php echo htmlspecialchars($student['registration_date']); ?></div>
                                </div>
                                <div class="col-md-4">
                                    <div class="profile-label">Registered By</div>
                                    <div class="profile-value">
                                        <i class="bi bi-person-fill text-muted me-1"></i><?php echo htmlspecialchars($student['creator_username'] ?? 'System / Unknown'); ?>
                                    </div>
                                </div>
                            </div>

                            <!-- SECTION 2: PERSONAL PROFILE -->
                            <div class="profile-section-title mt-2">
                                <i class="bi bi-person"></i> Personal Profile
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="profile-label">Full Name</div>
                                    <div class="profile-value"><?php echo htmlspecialchars($student['name']); ?></div>
                                </div>
                                <div class="col-md-3">
                                    <div class="profile-label">NIC Number</div>
                                    <div class="profile-value"><?php echo htmlspecialchars($student['nic']); ?></div>
                                </div>
                                <div class="col-md-3">
                                    <div class="profile-label">Contact Number</div>
                                    <div class="profile-value"><?php echo htmlspecialchars($student['contact_no']); ?></div>
                                </div>
                                <div class="col-md-3">
                                    <div class="profile-label">Date of Birth</div>
                                    <div class="profile-value"><?php echo htmlspecialchars($student['dob']); ?></div>
                                </div>
                                <div class="col-md-3">
                                    <div class="profile-label">Gender</div>
                                    <div class="profile-value"><?php echo htmlspecialchars($student['gender']); ?></div>
                                </div>
                                <div class="col-md-6">
                                    <div class="profile-label">Home Address</div>
                                    <div class="profile-value"><?php echo htmlspecialchars($student['address']); ?></div>
                                </div>
                            </div>

                            <!-- SECTION 3: GUARDIAN DETAILS -->
                            <div class="profile-section-title mt-2">
                                <i class="bi bi-people"></i> Guardian Details
                            </div>
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="profile-label">Guardian Name</div>
                                    <div class="profile-value">
                                        <?php echo !empty($student['guardian_name']) ? htmlspecialchars($student['guardian_name']) : '<span class="text-muted small">Not Provided</span>'; ?>
                                    </div>
                                </div>
                                <div class="col-md-8">
                                    <div class="profile-label">Contact & Info Details</div>
                                    <div class="profile-value">
                                        <?php echo !empty($student['guardian_details']) ? htmlspecialchars($student['guardian_details']) : '<span class="text-muted small">Not Provided</span>'; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- SECTION 4: EDUCATIONAL QUALIFICATIONS -->
                            <div class="profile-section-title mt-2">
                                <i class="bi bi-mortarboard"></i> Educational Qualifications
                            </div>
                            <div class="mb-4">
                                <?php
                                $quals = [];
                                if ($student['gce_ol']) $quals[] = 'G.C.E. O/L';
                                if ($student['gce_al_science']) $quals[] = 'G.C.E. A/L - Science';
                                if ($student['gce_al_maths']) $quals[] = 'G.C.E. A/L - Mathematics';
                                if ($student['gce_al_commerce']) $quals[] = 'G.C.E. A/L - Commerce';
                                if ($student['gce_al_art']) $quals[] = 'G.C.E. A/L - Arts';
                                if ($student['gce_al_tech']) $quals[] = 'G.C.E. A/L - Technology';
                                if ($student['kids_grade']) $quals[] = 'Kids School Grade';
                                if ($student['other_edu']) $quals[] = 'Other Academic Qualifications';

                                if (empty($quals)):
                                    echo '<span class="text-muted small">No prior qualifications selected.</span>';
                                else:
                                    foreach ($quals as $qual):
                                        echo '<span class="badge-tag-qual"><i class="bi bi-check2-circle me-1"></i>' . htmlspecialchars($qual) . '</span>';
                                    endforeach;
                                endif;
                                ?>
                            </div>

                            <!-- SECTION 5: ENROLLED COURSES -->
                            <div class="profile-section-title mt-2">
                                <i class="bi bi-journal-bookmark-fill"></i> Enrolled Courses
                            </div>
                            <div class="mb-2">
                                <?php
                                $courses = [];
                                if ($student['ict_tech']) $courses[] = 'ICT Technician (NVQ)';
                                if ($student['computer_app_ast']) $courses[] = 'Computer Application Assistant (NVQ)';
                                if ($student['graphic_designer']) $courses[] = 'Graphic Designer (NVQ)';
                                if ($student['pre_school']) $courses[] = 'Pre-School Teacher Training (NVQ)';
                                if ($student['non_nvq_app_ast']) $courses[] = 'App Assistant (Non-NVQ)';
                                if ($student['non_nvq_graphic']) $courses[] = 'Graphic Design (Non-NVQ)';
                                if ($student['hr']) $courses[] = 'Human Resources Management';
                                if ($student['english']) $courses[] = 'English Language Training';
                                if ($student['web_design']) $courses[] = 'Web Designing';
                                if ($student['beetaa_kids']) $courses[] = 'Beetaa Kids Course';
                                if ($student['other_course']) $courses[] = 'Other Special Course';

                                if (empty($courses)):
                                    echo '<span class="text-muted small">No courses enrolled.</span>';
                                else:
                                    foreach ($courses as $course):
                                        echo '<span class="badge-tag"><i class="bi bi-book-half me-1"></i>' . htmlspecialchars($course) . '</span>';
                                    endforeach;
                                endif;
                                ?>
                            </div>

                        </div>
                    </div>

                    <!-- PAYMENT SECTION -->
                    <div class="profile-card mb-5">
                        <div class="profile-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <h4 class="mb-0 fw-bold"><i class="bi bi-credit-card-2-front me-2 text-primary"></i>Payment System & Tracking</h4>
                            <?php if ($plan && $plan['plan_type'] !== 'pending'): ?>
                                <form action="student_profile.php?id=<?php echo $student_id; ?>" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to reset this payment plan? This will delete all registered payments/receipts and return the status to Pending.');">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="action" value="reset_plan">
                                    <button type="submit" class="btn btn-outline-warning btn-sm">
                                        <i class="bi bi-arrow-counterclockwise me-1"></i>Reset Payment Plan
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <div class="p-4 p-md-5">
                            
                            <?php if (!$plan || $plan['plan_type'] === 'pending'): ?>
                                
                                <!-- NO PLAN SET UP OR PENDING -->
                                <div class="text-center py-4" id="no-plan-buttons">
                                    <p class="text-muted mb-4">No active payment plan is configured for this student.</p>
                                    <button class="btn btn-accent px-5 py-3 me-3" onclick="showPaymentForm()">
                                        <i class="bi bi-cash-coin"></i> Pay Now (Setup Plan)
                                    </button>
                                    
                                    <form action="student_profile.php?id=<?php echo $student_id; ?>" method="POST" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="action" value="pay_later">
                                        <button type="submit" class="btn btn-muted-outline px-5 py-3">
                                            <i class="bi bi-clock-history"></i> Pay Later
                                        </button>
                                    </form>
                                </div>

                                <!-- PAY NOW SETUP FORM (Hidden by default, shown via JS) -->
                                <div id="pay-now-form" style="display: none;">
                                    <h5 class="fw-bold mb-4 text-white"><i class="bi bi-gear-fill me-1 text-primary"></i>Configure New Payment Plan</h5>
                                    
                                    <form action="student_profile.php?id=<?php echo $student_id; ?>" method="POST" class="needs-validation" novalidate>
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="action" value="setup_plan">

                                        <div class="row g-3 mb-4">
                                            <div class="col-md-4">
                                                <label for="base_fee" class="form-label required">Course Fee (LKR)</label>
                                                <input type="number" class="form-control w-100" id="base_fee" name="base_fee" required min="1" step="0.01" placeholder="e.g. 100000">
                                            </div>
                                            <div class="col-md-4">
                                                <label for="plan_type" class="form-label required">Payment Type</label>
                                                <select class="form-select w-100" id="plan_type" name="plan_type" required>
                                                    <option value="" disabled selected>Select option</option>
                                                    <option value="full">Full Payment (10% Discount)</option>
                                                    <option value="installment">6-Month Installments</option>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <label for="payment_date" class="form-label required">Payment Date</label>
                                                <input type="date" class="form-control w-100" id="payment_date" name="payment_date" required value="<?php echo date('Y-m-d'); ?>">
                                            </div>
                                        </div>

                                        <!-- Live Dynamic Calculation Preview -->
                                        <div id="calculation_preview" class="mb-4"></div>

                                        <div class="d-flex justify-content-end gap-3">
                                            <button type="button" class="btn btn-muted-outline" onclick="hidePaymentForm()">Cancel</button>
                                            <button type="submit" class="btn btn-accent">Confirm & Process Payment</button>
                                        </div>
                                    </form>
                                </div>

                            <?php else: ?>
                                
                                <!-- PAYMENT PLAN IS ACTIVE -->
                                <?php
                                    $plan_type = $plan['plan_type'];
                                    $base_fee = floatval($plan['base_fee']);
                                    $final_total = floatval($plan['final_total']);
                                    
                                    // Calculate total paid so far
                                    $total_paid = 0.00;
                                    foreach ($receipts as $r) {
                                        $total_paid += floatval($r['amount_paid']);
                                    }
                                    
                                    // Balance
                                    $balance = max($final_total - $total_paid, 0.00);
                                    $is_fully_paid = ($balance <= 0.01);
                                ?>

                                <div class="row g-4 mb-4">
                                    <div class="col-md-3">
                                        <div class="p-3 rounded bg-dark border border-secondary border-opacity-25 text-center">
                                            <div class="profile-label text-muted">Plan Type</div>
                                            <div class="fs-5 fw-bold text-white mt-1">
                                                <?php echo ($plan_type === 'full') ? 'Full Payment' : 'Installments'; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="p-3 rounded bg-dark border border-secondary border-opacity-25 text-center">
                                            <div class="profile-label text-muted">Course Fee</div>
                                            <div class="fs-5 fw-bold text-white mt-1">
                                                LKR <?php echo number_format($base_fee, 2); ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="p-3 rounded bg-dark border border-secondary border-opacity-25 text-center">
                                            <div class="profile-label text-muted">Final Total</div>
                                            <div class="fs-5 fw-bold text-white mt-1">
                                                LKR <?php echo number_format($final_total, 2); ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="p-3 rounded bg-dark border border-secondary border-opacity-25 text-center">
                                            <div class="profile-label text-muted">Paid So Far</div>
                                            <div class="fs-5 fw-bold text-success mt-1">
                                                LKR <?php echo number_format($total_paid, 2); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="row g-4 mb-4">
                                    <div class="col-md-6">
                                        <div class="p-4 rounded bg-dark border border-secondary border-opacity-25 h-100">
                                            <h5 class="fw-bold mb-3 text-white"><i class="bi bi-clock-history me-1 text-primary"></i>Installment Timeline</h5>
                                            
                                            <div class="list-group list-group-flush bg-transparent">
                                                <?php if ($plan_type === 'full'): ?>
                                                    <div class="list-group-item bg-transparent text-white px-0 border-0 d-flex justify-content-between align-items-center">
                                                        <span><i class="bi bi-check-circle-fill text-success me-2"></i>Full Course Payment</span>
                                                        <span class="badge bg-success-subtle text-success border border-success border-opacity-25 px-3 py-2 rounded">
                                                            Paid LKR <?php echo number_format($final_total, 2); ?>
                                                        </span>
                                                    </div>
                                                <?php else: ?>
                                                    <?php
                                                    $share = $base_fee / 6;
                                                    for ($i = 1; $i <= 6; $i++):
                                                        // Check if this installment number is recorded as paid
                                                        $paid_record = null;
                                                        foreach ($receipts as $r) {
                                                            if (intval($r['installment_number']) === $i) {
                                                                $paid_record = $r;
                                                                break;
                                                            }
                                                        }
                                                    ?>
                                                        <div class="list-group-item bg-transparent text-white px-0 border-secondary border-opacity-25 d-flex justify-content-between align-items-center flex-wrap gap-2 py-3">
                                                            <span>
                                                                <i class="bi <?php echo $paid_record ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger'; ?> me-2"></i>
                                                                Installment <?php echo $i; ?>: <strong>LKR <?php echo number_format($share, 2); ?></strong>
                                                            </span>
                                                            <?php if ($paid_record): ?>
                                                                <span class="badge bg-success-subtle text-success border border-success border-opacity-25 px-2 py-1.5 rounded small">
                                                                    Paid LKR <?php echo number_format(floatval($paid_record['amount_paid']), 2); ?> on <?php echo htmlspecialchars($paid_record['payment_date']); ?>
                                                                </span>
                                                            <?php else: ?>
                                                                <span class="badge bg-danger-subtle text-danger border border-danger border-opacity-25 px-2 py-1.5 rounded small">
                                                                    Pending / Unpaid
                                                                </span>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endfor; ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <div class="p-4 rounded bg-dark border border-secondary border-opacity-25 h-100 d-flex flex-column justify-content-between">
                                            <div>
                                                <h5 class="fw-bold mb-3 text-white"><i class="bi bi-info-circle me-1 text-primary"></i>Payment Overview</h5>
                                                
                                                <div class="mb-4">
                                                    <div class="profile-label text-muted">Remaining Balance</div>
                                                    <div class="fs-3 fw-bold <?php echo $is_fully_paid ? 'text-success' : 'text-danger'; ?>">
                                                        LKR <?php echo number_format($balance, 2); ?>
                                                    </div>
                                                    <p class="text-muted mt-2 small">
                                                        <?php if ($is_fully_paid): ?>
                                                            <i class="bi bi-patch-check-fill text-success me-1"></i>This student has completed all course payments.
                                                        <?php else: ?>
                                                            <i class="bi bi-exclamation-circle-fill text-warning me-1"></i>Outstanding balance requires custom installment record collection.
                                                        <?php endif; ?>
                                                    </p>
                                                </div>
                                            </div>

                                            <?php if (!$is_fully_paid && $plan_type === 'installment'): ?>
                                                <!-- RECORD NEXT INSTALLMENT FORM -->
                                                <div class="border-top border-secondary border-opacity-25 pt-3">
                                                    <h6 class="fw-bold text-white mb-3">Record Next Installment Payment</h6>
                                                    
                                                    <form action="student_profile.php?id=<?php echo $student_id; ?>" method="POST" class="needs-validation" novalidate>
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                        <input type="hidden" name="action" value="record_payment">

                                                        <div class="row g-2 mb-3">
                                                            <div class="col-sm-6">
                                                                <label for="amount_paid" class="form-label required small">Amount Paid</label>
                                                                <input type="number" class="form-control form-control-sm w-100" id="amount_paid" name="amount_paid" required min="1" step="0.01" value="<?php echo round($base_fee / 6, 2); ?>">
                                                            </div>
                                                            <div class="col-sm-6">
                                                                <label for="record_payment_date" class="form-label required small">Payment Date</label>
                                                                <input type="date" class="form-control form-control-sm w-100" id="record_payment_date" name="payment_date" required value="<?php echo date('Y-m-d'); ?>">
                                                            </div>
                                                        </div>

                                                        <button type="submit" class="btn btn-accent btn-sm w-100 py-2">
                                                            <i class="bi bi-save me-1"></i>Record Installment <?php echo count($receipts) + 1; ?>
                                                        </button>
                                                    </form>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- RECEIPTS TABLE -->
                                <div class="mt-4">
                                    <h5 class="fw-bold mb-3 text-white"><i class="bi bi-receipt-cutoff me-1 text-primary"></i>Payment Receipt Ledger</h5>
                                    <div class="table-responsive">
                                        <table class="table table-dark table-striped table-hover border border-secondary border-opacity-25" style="border-radius: 4px; overflow: hidden;">
                                            <thead>
                                                <tr>
                                                    <th>Receipt ID</th>
                                                    <th>Installment No</th>
                                                    <th>Amount Paid</th>
                                                    <th>Payment Date</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($receipts as $r): ?>
                                                    <tr>
                                                        <td>REC-<?php echo str_pad($r['receipt_id'], 5, '0', STR_PAD_LEFT); ?></td>
                                                        <td>
                                                            <?php echo ($plan_type === 'full') ? 'Full Fee' : 'Installment ' . $r['installment_number']; ?>
                                                        </td>
                                                        <td class="fw-bold text-success">LKR <?php echo number_format(floatval($r['amount_paid']), 2); ?></td>
                                                        <td><?php echo htmlspecialchars($r['payment_date']); ?></td>
                                                        <td>
                                                            <form action="student_profile.php?id=<?php echo $student_id; ?>" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this payment receipt? This will revert the installment payment status.');">
                                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                                <input type="hidden" name="action" value="delete_receipt">
                                                                <input type="hidden" name="receipt_id" value="<?php echo $r['receipt_id']; ?>">
                                                                <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-2" style="font-size: 0.75rem;">
                                                                    <i class="bi bi-trash"></i> Delete
                                                                </button>
                                                            </form>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                            <?php endif; ?>

                        </div>
                        
                        <!-- Back Dashboard actions -->
                        <div class="profile-header border-top d-flex justify-content-between align-items-center flex-wrap gap-3">
                            <div>
                                <form action="student_profile.php?id=<?php echo $student_id; ?>" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to permanently delete this student profile? This will remove all payment details and transaction histories.');">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="action" value="delete_profile">
                                    <button type="submit" class="btn btn-outline-danger">
                                        <i class="bi bi-trash-fill me-1"></i>Delete Student Profile
                                    </button>
                                </form>
                            </div>
                            <div class="d-flex gap-3">
                                <a href="dashboard.php" class="btn-muted-outline">
                                    <i class="bi bi-speedometer2"></i> Return to Dashboard
                                </a>
                                <a href="add_student.php" class="btn-accent">
                                    <i class="bi bi-person-plus"></i> Add New Student
                                </a>
                            </div>
                        </div>
                    </div>

                <?php endif; ?>

            </div>
        </div>
    </div>

    <!-- Bootstrap 5 Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Client Side Calculations for Setup -->
    <script>
        const showPaymentForm = () => {
            document.getElementById('no-plan-buttons').style.display = 'none';
            document.getElementById('pay-now-form').style.display = 'block';
        };

        const hidePaymentForm = () => {
            document.getElementById('pay-now-form').style.display = 'none';
            document.getElementById('no-plan-buttons').style.display = 'block';
            document.getElementById('calculation_preview').innerHTML = '';
            document.getElementById('base_fee').value = '';
            document.getElementById('plan_type').value = '';
        };

        const baseFeeInput = document.getElementById('base_fee');
        const planTypeSelect = document.getElementById('plan_type');
        const calculationPreview = document.getElementById('calculation_preview');

        const calculatePreview = () => {
            const baseFee = parseFloat(baseFeeInput.value) || 0;
            const planType = planTypeSelect.value;
            calculationPreview.innerHTML = '';

            if (baseFee <= 0 || !planType) return;

            if (planType === 'full') {
                const finalTotal = baseFee * 0.90; // 10% discount
                calculationPreview.innerHTML = `
                    <div class="p-3 rounded border border-success border-opacity-25 bg-success bg-opacity-10 text-success-emphasis" style="background-color: rgba(25, 135, 84, 0.1) !important; color: #a3cfbb !important;">
                        <strong>Full Course Payment Calculation:</strong><br>
                        Base Course Fee: LKR ${baseFee.toLocaleString('en-US', {minimumFractionDigits: 2})}<br>
                        10% Discount: - LKR ${(baseFee * 0.10).toLocaleString('en-US', {minimumFractionDigits: 2})}<br>
                        <span class="fs-5 fw-bold text-white">Final Discounted Total: LKR ${finalTotal.toLocaleString('en-US', {minimumFractionDigits: 2})}</span>
                    </div>
                `;
            } else if (planType === 'installment') {
                const monthly = baseFee / 6;
                let listHtml = `
                    <div class="p-3 rounded border border-info border-opacity-25 bg-info bg-opacity-10 text-info-emphasis" style="background-color: rgba(13, 202, 240, 0.1) !important; color: #9eeaf9 !important;">
                        <strong>6-Month Installment Division Plan:</strong>
                        <ol class="mt-2 mb-0">
                `;
                for (let i = 1; i <= 6; i++) {
                    listHtml += `<li class="text-white">Installment ${i}: <span class="fw-bold">LKR ${monthly.toLocaleString('en-US', {minimumFractionDigits: 2})}</span></li>`;
                }
                listHtml += `
                        </ol>
                    </div>
                `;
                calculationPreview.innerHTML = listHtml;
            }
        };

        if (baseFeeInput && planTypeSelect) {
            baseFeeInput.addEventListener('input', calculatePreview);
            planTypeSelect.addEventListener('change', calculatePreview);
        }

        // Form validation
        (() => {
            'use strict'
            const forms = document.querySelectorAll('.needs-validation')
            Array.from(forms).forEach(form => {
                form.addEventListener('submit', event => {
                    if (!form.checkValidity()) {
                        event.preventDefault()
                        event.stopPropagation()
                    }
                    form.classList.add('was-validated')
                }, false)
            })
        })()
    </script>
</body>
</html>
