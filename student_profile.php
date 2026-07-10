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

$success_param = filter_var($_GET['success'] ?? 0, FILTER_VALIDATE_INT);
$error_param = $_GET['error'] ?? '';

$student = null;
$plan = null;
$receipts = [];
$error_msg = '';
$success_msg = '';

if ($success_param === 1) {
    $success_msg = "Payment plan set up successfully!";
} elseif ($success_param === 2) {
    $success_msg = "Payment recorded successfully!";
} elseif ($success_param === 3) {
    $success_msg = "Payment receipt record deleted successfully.";
} elseif ($success_param === 4) {
    $success_msg = "Payment plan reset successfully.";
}

if ($error_param !== '') {
    $error_msg = htmlspecialchars($error_param);
}

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
            $admission_paid = isset($_POST['admission_paid']) ? 1 : 0;
            $payment_date = trim($_POST['payment_date'] ?? date('Y-m-d'));
            $receipt_id = trim($_POST['receipt_id'] ?? '');

            if ($base_fee <= 0 || !in_array($plan_type, ['full', 'installment'])) {
                $error_msg = "Invalid course fee or payment type selection.";
            } elseif ($receipt_id === '') {
                $error_msg = "Receipt Number is required for the initial payment.";
            } else {
                // PHP Backend recalculations:
                if ($plan_type === 'full') {
                    $final_total = $base_fee * 0.95;
                    $amount_paid = $final_total;
                } else {
                    $final_total = $base_fee;
                    $amount_paid = $base_fee / 6;
                }

                try {
                    $pdo->beginTransaction();

                    // Insert payment plan
                    $stmt = $pdo->prepare("INSERT INTO payment_plans (student_id, plan_type, base_fee, final_total, admission_paid) VALUES (:student_id, :plan_type, :base_fee, :final_total, :admission_paid)");
                    $stmt->execute([
                        ':student_id'     => $student_id,
                        ':plan_type'      => $plan_type,
                        ':base_fee'       => $base_fee,
                        ':final_total'    => $final_total,
                        ':admission_paid' => $admission_paid
                    ]);

                    // Insert initial payment record
                    $stmt = $pdo->prepare("INSERT INTO payment_records (receipt_id, student_id, amount_paid, payment_date) VALUES (:receipt_id, :student_id, :amount_paid, :payment_date)");
                    $stmt->execute([
                        ':receipt_id'  => $receipt_id,
                        ':student_id'  => $student_id,
                        ':amount_paid' => $amount_paid,
                        ':payment_date'=> $payment_date
                    ]);

                    $pdo->commit();
                    header("Location: student_profile.php?id=" . $student_id . "&success=1");
                    exit;
                } catch (\Exception $e) {
                    $pdo->rollBack();
                    if ($e->getCode() == 23000 || strpos($e->getMessage(), '1062') !== false) {
                        $error_msg = "Error: Receipt Number already exists in the system.";
                    } else {
                        $error_msg = "Payment plan setup failed: " . htmlspecialchars($e->getMessage());
                    }
                }
            }
        } elseif ($action === 'record_payment') {
            $payment_date = trim($_POST['payment_date'] ?? date('Y-m-d'));
            $receipt_id = trim($_POST['receipt_id'] ?? '');
            $admission_paid = isset($_POST['admission_paid']) ? 1 : 0;

            if ($receipt_id === '') {
                $error_msg = "Receipt Number is required.";
            } else {
                try {
                    $pdo->beginTransaction();

                    // Fetch active plan
                    $stmt = $pdo->prepare("SELECT * FROM payment_plans WHERE student_id = :id");
                    $stmt->execute([':id' => $student_id]);
                    $current_plan = $stmt->fetch();

                    if ($current_plan) {
                        // Calculate total paid so far to check balance
                        $stmt = $pdo->prepare("SELECT SUM(amount_paid) FROM payment_records WHERE student_id = :id");
                        $stmt->execute([':id' => $student_id]);
                        $total_paid = floatval($stmt->fetchColumn());

                        $base_fee = floatval($current_plan['base_fee']);
                        $final_total = floatval($current_plan['final_total']);
                        
                        // Remaining balance
                        $remaining = $final_total - $total_paid;
                        
                        if ($remaining <= 0.01) {
                            $error_msg = "The course fee is already fully paid.";
                            $pdo->rollBack();
                        } else {
                            // Calculate amount paid (installments are 1/6th of base fee, capped at remaining)
                            $amount_paid = $base_fee / 6;
                            if ($amount_paid > $remaining) {
                                $amount_paid = $remaining;
                            }

                            // Update admission_paid in payment_plans
                            $stmt = $pdo->prepare("UPDATE payment_plans SET admission_paid = :admission_paid WHERE student_id = :student_id");
                            $stmt->execute([
                                ':admission_paid' => $admission_paid,
                                ':student_id'     => $student_id
                            ]);

                            // Insert transaction into payment_records
                            $stmt = $pdo->prepare("INSERT INTO payment_records (receipt_id, student_id, amount_paid, payment_date) VALUES (:receipt_id, :student_id, :amount_paid, :payment_date)");
                            $stmt->execute([
                                ':receipt_id'  => $receipt_id,
                                ':student_id'  => $student_id,
                                ':amount_paid' => $amount_paid,
                                ':payment_date'=> $payment_date
                            ]);

                            $pdo->commit();
                            header("Location: student_profile.php?id=" . $student_id . "&success=2");
                            exit;
                        }
                    } else {
                        $pdo->rollBack();
                        $error_msg = "No active payment plan found to record payment against.";
                    }
                } catch (\Exception $e) {
                    $pdo->rollBack();
                    if ($e->getCode() == 23000 || strpos($e->getMessage(), '1062') !== false) {
                        $error_msg = "Error: Receipt Number already exists in the system.";
                    } else {
                        $error_msg = "Failed to record payment: " . htmlspecialchars($e->getMessage());
                    }
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
            $receipt_id = trim($_POST['receipt_id'] ?? '');
            if ($receipt_id !== '') {
                try {
                    $pdo->beginTransaction();

                    // Fetch receipt amount
                    $stmt = $pdo->prepare("SELECT amount_paid FROM payment_records WHERE receipt_id = :receipt_id AND student_id = :student_id");
                    $stmt->execute([':receipt_id' => $receipt_id, ':student_id' => $student_id]);
                    $amt = floatval($stmt->fetchColumn());

                    // Delete receipt
                    $stmt = $pdo->prepare("DELETE FROM payment_records WHERE receipt_id = :receipt_id AND student_id = :student_id");
                    $stmt->execute([
                        ':receipt_id' => $receipt_id,
                        ':student_id' => $student_id
                    ]);

                    $pdo->commit();
                    header("Location: student_profile.php?id=" . $student_id . "&success=3");
                    exit;
                } catch (\Exception $e) {
                    $pdo->rollBack();
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
                header("Location: student_profile.php?id=" . $student_id . "&success=4");
                exit;
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
        $stmt = $pdo->prepare("SELECT * FROM payment_records WHERE student_id = :id ORDER BY payment_date ASC, receipt_id ASC");
        $stmt->execute([':id' => $student_id]);
        $receipts = $stmt->fetchAll();

        // Fetch exam results
        $stmt = $pdo->prepare("SELECT * FROM exam_results WHERE student_id = :id ORDER BY exam_date DESC, exam_id DESC");
        $stmt->execute([':id' => $student_id]);
        $exams = $stmt->fetchAll();
    } catch (\PDOException $e) {
        $error_msg = "Failed to retrieve payment/exam records: " . htmlspecialchars($e->getMessage());
    }
}

// Generate CSRF token if not set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$page_title = 'Student Profile Details - Student Registration System';
ob_start();
?>
    <style>
        /* Profile detailed review styles */
        .profile-card {
            background-color: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-md);
            overflow: hidden;
        }
        .profile-header {
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.05), rgba(99, 102, 241, 0.02));
            border-bottom: 1px solid var(--border-color);
            padding: 2.5rem;
        }
        .profile-section-title {
            font-size: 1rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--accent-color);
            border-bottom: 1px dashed var(--border-color);
            padding-bottom: 0.5rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .profile-label {
            font-size: 0.8rem;
            color: var(--text-secondary);
            font-weight: 600;
            margin-bottom: 0.25rem;
            text-transform: uppercase;
            letter-spacing: 0.25px;
        }
        .profile-value {
            font-size: 0.95rem;
            color: var(--text-primary);
            font-weight: 500;
            margin-bottom: 1.25rem;
        }
        /* Badges */
            margin-bottom: 0.5rem;
        }

        .badge-historical {
            background-color: var(--bg-sidebar);
            color: #d97706;
            border: 1px solid var(--border-color);
            padding: 0.25em 0.75em;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        .badge-active {
            background-color: #d1fae5;
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.25);
            padding: 0.25em 0.75em;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        /* Buttons */
        .btn-accent {
            background-color: var(--accent-color);
            color: #ffffff;
            border: none;
            border-radius: 8px;
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
            background-color: #4f46e5;
            color: #ffffff;
        }

        .btn-muted-outline {
            background-color: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
            border-radius: 8px;
            padding: 0.75rem 1.5rem;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s ease;
        }
        .btn-muted-outline:hover {
            background-color: var(--bg-sidebar);
            color: var(--text-primary);
        }

        /* Custom Premium Tab Styles */
        .nav-tabs {
            border-bottom: 2px solid var(--border-color);
        }
        .nav-tabs .nav-link {
            color: var(--text-secondary);
            border: none;
            border-bottom: 3px solid transparent;
            padding: 1rem 1.5rem;
            font-weight: 600;
            transition: all 0.2s ease;
            background: transparent;
        }
        .nav-tabs .nav-link:hover {
            color: var(--accent-color);
            border-bottom-color: var(--border-color);
        }
        .nav-tabs .nav-link.active {
            color: var(--accent-color) !important;
            background-color: transparent !important;
            border: none !important;
            border-bottom: 3px solid var(--accent-color) !important;
        }
        .tab-pane-container {
            animation: fadeIn 0.25s ease-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(4px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .text-muted {
            color: var(--text-secondary) !important;
        }

        .form-select option {
            background-color: var(--bg-surface);
            color: var(--text-primary);
        }
<?php
$extra_css = ob_get_clean();
include 'header.php';
?>

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
                        <a href="dashboard.php" class="btn btn-accent"><i class="bi bi-arrow-left"></i> Return to Dashboard</a>
                    </div>
                <?php else: ?>

                    <?php if (!empty($success_msg)): ?>
                        <div class="alert alert-success d-flex align-items-center mb-4" role="alert" style="background-color: #d1fae5; border: 1px solid rgba(16, 185, 129, 0.25); color: #065f46; border-radius: 8px;">
                            <i class="bi bi-check-circle-fill me-2 text-success"></i>
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
                                    Official Index: <span class="fw-bold text-primary"><?php echo htmlspecialchars($student['index_number']); ?></span>
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

                        <!-- Tab Navigation Bar -->
                        <div class="border-bottom px-4 pt-2 bg-white">
                            <ul class="nav nav-tabs border-bottom-0" id="profileTabs" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="registration-tab" data-bs-toggle="tab" data-bs-target="#registration-pane" type="button" role="tab" aria-controls="registration-pane" aria-selected="true">
                                        <i class="bi bi-person-bounding-box me-1"></i>Registration Info
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="payment-tab" data-bs-toggle="tab" data-bs-target="#payment-pane" type="button" role="tab" aria-controls="payment-pane" aria-selected="false">
                                        <i class="bi bi-credit-card-2-front me-1"></i>Payment Status
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="exam-tab" data-bs-toggle="tab" data-bs-target="#exam-pane" type="button" role="tab" aria-controls="exam-pane" aria-selected="false">
                                        <i class="bi bi-journal-check me-1"></i>Exam Records
                                    </button>
                                </li>
                            </ul>
                        </div>

                        <!-- Tab Content Wrapper -->
                        <div class="tab-content" id="profileTabsContent">
                            
                            <!-- TAB 1: REGISTRATION INFO -->
                            <div class="tab-pane fade show active tab-pane-container p-4 p-md-5" id="registration-pane" role="tabpanel" aria-labelledby="registration-tab" tabindex="0">
                            
                            <!-- SECTION 1: REGISTRATION META -->
                            <div class="profile-section-title">
                                <i class="bi bi-file-earmark-text"></i> Registration Parameters
                            </div>
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="profile-label">Course Code</div>
                                    <div class="profile-value"><?php echo htmlspecialchars($student['course_code']); ?></div>
                                </div>
                                <div class="col-md-3">
                                    <div class="profile-label">Batch Year</div>
                                    <div class="profile-value">20<?php echo htmlspecialchars($student['batch_year']); ?></div>
                                </div>
                                <div class="col-md-3">
                                    <div class="profile-label">Batch Number</div>
                                    <div class="profile-value"><?php echo htmlspecialchars($student['batch_number'] ?? ''); ?></div>
                                </div>
                                <div class="col-md-3">
                                    <div class="profile-label">NVQ Type</div>
                                    <div class="profile-value">
                                        <?php echo !empty($student['is_nvq']) ? htmlspecialchars($student['is_nvq']) . ' (NVQ)' : 'Non-NVQ'; ?>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="profile-label">Index No</div>
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

                        </div> <!-- Close TAB 1 registration-pane -->

                        <!-- TAB 2: PAYMENT STATUS -->
                        <div class="tab-pane fade tab-pane-container p-4 p-md-5" id="payment-pane" role="tabpanel" aria-labelledby="payment-tab" tabindex="0">
                            
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4 border-bottom border-secondary border-opacity-10 pb-3">
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
                                    <h5 class="fw-bold mb-4 text-dark"><i class="bi bi-gear-fill me-1 text-primary"></i>Configure New Payment Plan</h5>
                                    
                                    <form action="student_profile.php?id=<?php echo $student_id; ?>" method="POST" class="needs-validation" novalidate>
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="action" value="setup_plan">

                                        <div class="row g-3 mb-3 align-items-center">
                                            <div class="col-md-4">
                                                <label for="base_fee" class="form-label required">Course Fee (LKR)</label>
                                                <input type="number" class="form-control w-100" id="base_fee" name="base_fee" required min="1" step="0.01" placeholder="e.g. 100000">
                                            </div>
                                            <div class="col-md-4">
                                                <label for="plan_type" class="form-label required">Payment Type</label>
                                                <select class="form-select w-100" id="plan_type" name="plan_type" required>
                                                    <option value="" disabled selected>Select option</option>
                                                    <option value="full">Full Payment (5% Discount)</option>
                                                    <option value="installment">6-Month Installment</option>
                                                </select>
                                            </div>
                                            <div class="col-md-4 d-flex align-items-center pt-4">
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" id="admission_paid" name="admission_paid" value="1">
                                                    <label class="form-check-label fw-semibold text-dark" for="admission_paid">Admission Fee Paid</label>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="row g-3 mb-4">
                                            <div class="col-md-4">
                                                <label for="setup_receipt_id" class="form-label required">Receipt Number</label>
                                                <input type="text" class="form-control w-100" id="setup_receipt_id" name="receipt_id" required placeholder="e.g. R-1002">
                                            </div>
                                            <div class="col-md-4">
                                                <label for="setup_payment_date" class="form-label required">Payment Date</label>
                                                <input type="date" class="form-control w-100" id="setup_payment_date" name="payment_date" required value="<?php echo date('Y-m-d'); ?>">
                                            </div>
                                            <div class="col-md-4">
                                                <label for="amount_due_today" class="form-label">Amount Due Today (LKR)</label>
                                                <input type="text" class="form-control w-100 fw-bold text-dark" id="amount_due_today" name="amount_due_today" readonly value="0.00" style="background-color: #e9ecef;">
                                            </div>
                                        </div>

                                        <!-- Live Dynamic Calculation Preview -->
                                        <div id="calculation_preview" class="mb-4"></div>

                                        <div class="d-flex justify-content-end gap-3">
                                            <button type="button" class="btn btn-muted-outline" onclick="hidePaymentForm()">Cancel</button>
                                            <button type="submit" class="btn btn-accent">Confirm & Process Plan</button>
                                        </div>
                                    </form>
                                </div>

                            <?php else: ?>
                                
                                <!-- PAYMENT PLAN IS ACTIVE -->
                                <?php
                                    $plan_type = $plan['plan_type'];
                                    $base_fee = floatval($plan['base_fee']);
                                    $final_total = floatval($plan['final_total']);
                                    $admission_paid = intval($plan['admission_paid']);
                                    
                                    // Calculate total paid so far
                                    $total_paid = 0.00;
                                    foreach ($receipts as $r) {
                                        $total_paid += floatval($r['amount_paid']);
                                    }
                                    
                                    // Balance
                                    $balance = max($final_total - $total_paid, 0.00);
                                    $is_fully_paid = ($balance <= 0.01);
                                ?>

                                <!-- Summary Cards -->
                                <div class="row g-3 mb-4">
                                    <div class="col">
                                        <div class="p-3 rounded border border-secondary border-opacity-10 text-center h-100" style="background-color: var(--bg-main);">
                                            <div class="profile-label text-muted small">Base Fee</div>
                                            <div class="fs-6 fw-bold text-dark mt-1">
                                                LKR <?php echo number_format($base_fee, 2); ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col">
                                        <div class="p-3 rounded border border-secondary border-opacity-10 text-center h-100" style="background-color: var(--bg-main);">
                                            <div class="profile-label text-muted small">Plan Type</div>
                                            <div class="fs-6 fw-bold text-dark mt-1">
                                                <?php echo ($plan_type === 'full') ? 'Full (5% Disc)' : 'Installment'; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col">
                                        <div class="p-3 rounded border border-secondary border-opacity-10 text-center h-100" style="background-color: var(--bg-main);">
                                            <div class="profile-label text-muted small">Final Total</div>
                                            <div class="fs-6 fw-bold text-dark mt-1">
                                                LKR <?php echo number_format($final_total, 2); ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col">
                                        <div class="p-3 rounded border border-secondary border-opacity-10 text-center h-100" style="background-color: var(--bg-main);">
                                            <div class="profile-label text-muted small">Total Paid</div>
                                            <div class="fs-6 fw-bold text-success mt-1">
                                                LKR <?php echo number_format($total_paid, 2); ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col">
                                        <div class="p-3 rounded border border-secondary border-opacity-10 text-center h-100" style="background-color: var(--bg-main);">
                                            <div class="profile-label text-muted small">Admission Fee</div>
                                            <div class="mt-1">
                                                <?php if ($admission_paid): ?>
                                                    <span class="badge bg-success-subtle text-success border border-success border-opacity-25 px-2.5 py-1.5 rounded">Settled</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger-subtle text-danger border border-danger border-opacity-25 px-2.5 py-1.5 rounded">Pending</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="row g-4 mb-4">
                                    <div class="col-md-6">
                                        <div class="p-4 rounded border border-secondary border-opacity-10 h-100" style="background-color: var(--bg-main);">
                                            <h5 class="fw-bold mb-3 text-dark"><i class="bi bi-clock-history me-1 text-primary"></i>Installment Timeline</h5>
                                            
                                            <div class="list-group list-group-flush bg-transparent">
                                                <?php if ($plan_type === 'full'): ?>
                                                    <div class="list-group-item bg-transparent text-dark px-0 border-0 d-flex justify-content-between align-items-center">
                                                        <span><i class="bi bi-check-circle-fill text-success me-2"></i>Full Course Payment</span>
                                                        <span class="badge bg-success-subtle text-success border border-success border-opacity-25 px-3 py-2 rounded">
                                                            Paid LKR <?php echo number_format($final_total, 2); ?>
                                                        </span>
                                                    </div>
                                                <?php else: ?>
                                                    <?php
                                                     $p_index = 1;
                                                     foreach ($receipts as $r):
                                                     ?>
                                                        <div class="list-group-item bg-transparent text-dark px-0 border-secondary border-opacity-10 d-flex justify-content-between align-items-center flex-wrap gap-2 py-3">
                                                            <span>
                                                                <i class="bi bi-check-circle-fill text-success me-2"></i>
                                                                Payment #<?php echo $p_index++; ?>: <strong>LKR <?php echo number_format(floatval($r['amount_paid']), 2); ?></strong>
                                                            </span>
                                                            <span class="badge bg-success-subtle text-success border border-success border-opacity-25 px-2 py-1.5 rounded small">
                                                                Paid on <?php echo htmlspecialchars($r['payment_date']); ?>
                                                            </span>
                                                        </div>
                                                     <?php endforeach; ?>
                                                     <?php if (empty($receipts)): ?>
                                                         <div class="text-muted small py-2">No payments recorded yet.</div>
                                                     <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <div class="p-4 rounded border border-secondary border-opacity-10 h-100 d-flex flex-column justify-content-between" style="background-color: var(--bg-main);">
                                            <div>
                                                <h5 class="fw-bold mb-3 text-dark"><i class="bi bi-info-circle me-1 text-primary"></i>Payment Overview</h5>
                                                
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

                                            <?php if (!$is_fully_paid): ?>
                                                <!-- MAKE PAYMENT TRIGGER & FORM -->
                                                <div>
                                                    <button class="btn btn-accent btn-sm w-100 py-2 mb-3" type="button" id="make-payment-btn" onclick="showRecordPaymentForm()">
                                                        <i class="bi bi-cash-coin me-1"></i>Make Payment
                                                    </button>

                                                    <div class="border-top border-secondary border-opacity-10 pt-3" id="record-payment-form" style="display: none;">
                                                        <h6 class="fw-bold text-dark mb-3">Record Payment Transaction</h6>
                                                        
                                                        <form action="student_profile.php?id=<?php echo $student_id; ?>" method="POST" class="needs-validation" novalidate>
                                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                            <input type="hidden" name="action" value="record_payment">

                                                            <div class="mb-3">
                                                                <label for="receipt_id_inst" class="form-label required small">Receipt Number</label>
                                                                <input type="text" class="form-control form-control-sm w-100" id="receipt_id_inst" name="receipt_id" required placeholder="e.g. R-1003">
                                                            </div>

                                                            <div class="mb-3 d-flex align-items-center">
                                                                <div class="form-check form-switch">
                                                                    <input class="form-check-input" type="checkbox" id="admission_paid_inst" name="admission_paid" value="1" <?php echo $admission_paid ? 'checked' : ''; ?>>
                                                                    <label class="form-check-label fw-semibold text-dark" for="admission_paid_inst">Admission Fee Paid</label>
                                                                </div>
                                                            </div>

                                                            <div class="row g-2 mb-3">
                                                                <div class="col-sm-6">
                                                                    <label for="amount_due_today_inst" class="form-label small">Amount Due Today (LKR)</label>
                                                                    <?php
                                                                    $inst_due = $base_fee / 6;
                                                                    if ($inst_due > $balance) {
                                                                        $inst_due = $balance;
                                                                    }
                                                                    ?>
                                                                    <input type="text" class="form-control form-control-sm w-100 fw-bold text-dark" id="amount_due_today_inst" readonly value="<?php echo number_format($inst_due, 2); ?>">
                                                                </div>
                                                                <div class="col-sm-6">
                                                                    <label for="record_payment_date" class="form-label required small">Payment Date</label>
                                                                    <input type="date" class="form-control form-control-sm w-100" id="record_payment_date" name="payment_date" required value="<?php echo date('Y-m-d'); ?>">
                                                                </div>
                                                            </div>

                                                            <div class="d-flex gap-2">
                                                                <button type="button" class="btn btn-outline-secondary btn-sm w-50" onclick="hideRecordPaymentForm()">Cancel</button>
                                                                <button type="submit" class="btn btn-accent btn-sm w-50">
                                                                    <i class="bi bi-save me-1"></i>Record Payment
                                                                </button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- RECEIPTS TABLE -->
                                <div class="mt-4">
                                    <h5 class="fw-bold mb-3 text-dark"><i class="bi bi-receipt-cutoff me-1 text-primary"></i>Payment Receipt Ledger</h5>
                                    <div class="table-responsive">
                                        <table class="table table-striped table-hover border border-secondary border-opacity-10" style="border-radius: 4px; overflow: hidden;">
                                            <thead>
                                                <tr>
                                                    <th>Receipt ID</th>
                                                    <th>Payment Description</th>
                                                    <th>Amount Paid</th>
                                                    <th>Payment Date</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php 
                                                $p_index = 1;
                                                foreach ($receipts as $r): 
                                                ?>
                                                    <tr>
                                                        <td>REC-<?php echo str_pad($r['receipt_id'], 5, '0', STR_PAD_LEFT); ?></td>
                                                        <td>
                                                            <?php echo ($plan_type === 'full') ? 'Full Payment' : 'Payment #' . $p_index++; ?>
                                                        </td>
                                                        <td class="fw-bold text-success">LKR <?php echo number_format(floatval($r['amount_paid']), 2); ?></td>
                                                        <td><?php echo htmlspecialchars($r['payment_date']); ?></td>
                                                        <td>
                                                            <form action="student_profile.php?id=<?php echo $student_id; ?>" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this payment receipt? This will revert the payment status.');">
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
                                    </div> <!-- Close table-responsive -->
                                </div> <!-- Close mt-4 receipts container -->

                            <?php endif; ?>

                        </div> <!-- Close TAB 2 payment-pane -->

                        <!-- TAB 3: EXAM RECORDS -->
                        <div class="tab-pane fade tab-pane-container p-4 p-md-5" id="exam-pane" role="tabpanel" aria-labelledby="exam-tab" tabindex="0">
                            <h4 class="mb-4 fw-bold"><i class="bi bi-journal-check me-2 text-primary"></i>Exam Records & Grades</h4>
                            
                            <?php if (empty($exams)): ?>
                                <div class="alert alert-info text-center py-4 border border-secondary border-opacity-10" style="background-color: var(--bg-main); border-radius: 8px;">
                                    <i class="bi bi-exclamation-circle fs-3 text-secondary d-block mb-2"></i>
                                    <span class="text-secondary">No exam records found for this student.</span>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover border border-secondary border-opacity-10" style="border-radius: 4px; overflow: hidden;">
                                        <thead>
                                            <tr>
                                                <th>Exam Name</th>
                                                <th>Course Code</th>
                                                <th>Exam Date</th>
                                                <th>Status</th>
                                                <th>Mark</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($exams as $e): ?>
                                                <tr>
                                                    <td class="fw-semibold text-dark"><?php echo htmlspecialchars($e['exam_name']); ?></td>
                                                    <td><span class="badge bg-secondary-subtle text-secondary px-2.5 py-1.5 rounded"><?php echo htmlspecialchars($e['course_code']); ?></span></td>
                                                    <td><?php echo htmlspecialchars($e['exam_date']); ?></td>
                                                    <td>
                                                        <?php if ($e['status'] === 'Pass'): ?>
                                                            <span class="badge bg-success-subtle text-success border border-success border-opacity-25 px-2.5 py-1.5 rounded">Pass</span>
                                                        <?php elseif ($e['status'] === 'Fail'): ?>
                                                            <span class="badge bg-danger-subtle text-danger border border-danger border-opacity-25 px-2.5 py-1.5 rounded">Fail</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-warning-subtle text-warning border border-warning border-opacity-25 px-2.5 py-1.5 rounded">Pending</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="fw-bold text-dark">
                                                        <?php echo ($e['mark'] !== null) ? htmlspecialchars(number_format($e['mark'], 2)) : '-'; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div> <!-- Close TAB 3 exam-pane -->

                    </div> <!-- Close tab-content -->
                        
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
                                <a href="dashboard.php" class="btn btn-muted-outline">
                                    <i class="bi bi-speedometer2"></i> Return to Dashboard
                                </a>
                                <a href="add_student.php" class="btn btn-accent">
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
            document.getElementById('setup_receipt_id').value = '';
            document.getElementById('amount_due_today').value = '0.00';
            document.getElementById('admission_paid').checked = false;
        };

        const showRecordPaymentForm = () => {
            document.getElementById('make-payment-btn').style.display = 'none';
            document.getElementById('record-payment-form').style.display = 'block';
        };

        const hideRecordPaymentForm = () => {
            document.getElementById('record-payment-form').style.display = 'none';
            document.getElementById('make-payment-btn').style.display = 'block';
            document.getElementById('receipt_id_inst').value = '';
        };

        const baseFeeInput = document.getElementById('base_fee');
        const planTypeSelect = document.getElementById('plan_type');
        const amountDueTodayInput = document.getElementById('amount_due_today');
        const calculationPreview = document.getElementById('calculation_preview');

        const calculatePreview = () => {
            const baseFee = parseFloat(baseFeeInput.value) || 0;
            const planType = planTypeSelect.value;

            if (baseFee <= 0 || !planType) {
                amountDueTodayInput.value = '0.00';
                calculationPreview.innerHTML = '';
                return;
            }

            if (planType === 'full') {
                const finalTotal = baseFee * 0.95;
                amountDueTodayInput.value = finalTotal.toFixed(2);

                calculationPreview.innerHTML = `
                    <div class="p-3 rounded border border-success border-opacity-25" style="background-color: #d1fae5; color: #065f46;">
                        <strong>Full Course Payment (5% Discount):</strong><br>
                        Base Course Fee: LKR ${baseFee.toLocaleString('en-US', {minimumFractionDigits: 2})}<br>
                        5% Discount: - LKR ${(baseFee * 0.05).toLocaleString('en-US', {minimumFractionDigits: 2})}<br>
                        <span class="fs-5 fw-bold text-dark">Final Total: LKR ${finalTotal.toLocaleString('en-US', {minimumFractionDigits: 2})}</span>
                    </div>
                `;
            } else if (planType === 'installment') {
                const installment = baseFee / 6;
                amountDueTodayInput.value = installment.toFixed(2);

                calculationPreview.innerHTML = `
                    <div class="p-3 rounded border border-info border-opacity-25" style="background-color: #e0f2fe; color: #0369a1;">
                        <strong>6-Month Installment Plan:</strong><br>
                        Total Course Fee: LKR ${baseFee.toLocaleString('en-US', {minimumFractionDigits: 2})}<br>
                        Amount Due (1st Installment): <span class="fs-5 fw-bold text-dark">LKR ${installment.toLocaleString('en-US', {minimumFractionDigits: 2})}</span>
                    </div>
                `;
            }
        };

        if (baseFeeInput) {
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
    </main>

<?php include 'footer.php'; ?>
