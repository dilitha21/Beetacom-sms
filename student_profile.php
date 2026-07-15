<?php
/**
 * student_profile.php
 * Displays student profile details, manages payment plans, receipts, and exam records.
 */

// 1. Ensure user is logged in
require_once 'auth_check.php';
require_once 'db_connect.php';

// 2. Parse and validate student ID
$student_id = filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT);
if (!$student_id) {
    header("Location: dashboard.php");
    exit();
}

$success_param = filter_var($_GET['success'] ?? 0, FILTER_VALIDATE_INT);
$error_param = $_GET['error'] ?? '';

$student = null;
$plan = null;
$receipts = [];
$exams = [];
$error_msg = '';
$success_msg = '';

if ($success_param === 1) {
    $success_msg = "Payment plan configured successfully!";
} elseif ($success_param === 2) {
    $success_msg = "Payment recorded successfully!";
} elseif ($success_param === 3) {
    $success_msg = "Payment record deleted successfully.";
} elseif ($success_param === 4) {
    $success_msg = "Payment plan reset successfully.";
}

if ($error_param !== '') {
    $error_msg = htmlspecialchars($error_param);
}

// 3. Handle POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // CSRF Protection
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        $error_msg = 'Invalid session token. Please try again.';
    } else {
        $action = $_POST['action'];

        try {
            $pdo->beginTransaction();

            if ($action === 'reset_plan') {
                // Delete receipts and plans for this student
                $pdo->prepare("DELETE FROM payment_records WHERE student_id = ?")->execute([$student_id]);
                $pdo->prepare("DELETE FROM payment_plans WHERE student_id = ?")->execute([$student_id]);
                $pdo->commit();
                header("Location: student_profile.php?id=$student_id&success=4");
                exit();
            }

            elseif ($action === 'pay_later') {
                // Set plan type to pending
                $stmt = $pdo->prepare("INSERT INTO payment_plans (student_id, plan_type, base_fee, final_total, discount_percentage, remaining_balance, admission_paid) VALUES (?, 'pending', 0.00, 0.00, 0.00, 0.00, 0)");
                $stmt->execute([$student_id]);
                $pdo->commit();
                header("Location: student_profile.php?id=$student_id&success=1");
                exit();
            }

            elseif ($action === 'setup_plan') {
                $base_fee = floatval($_POST['base_fee'] ?? 0);
                $plan_type = trim($_POST['plan_type'] ?? '');
                $setup_receipt_id = trim($_POST['setup_receipt_id'] ?? '');
                $admission_paid = isset($_POST['admission_paid']) ? 1 : 0;

                if ($base_fee <= 0 || !in_array($plan_type, ['full', 'installment'])) {
                    throw new Exception("Invalid base fee or plan type configured.");
                }

                $discount = 0.00;
                $final_total = $base_fee;

                if ($plan_type === 'full') {
                    $discount = 5.00; // 5% discount
                    $final_total = $base_fee * 0.95;
                }

                // Insert the new plan
                $stmt = $pdo->prepare("INSERT INTO payment_plans (student_id, plan_type, base_fee, final_total, discount_percentage, remaining_balance, admission_paid) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$student_id, $plan_type, $base_fee, $final_total, $discount, $final_total, $admission_paid]);

                // Record initial payment transaction if receipt ID is provided
                if ($setup_receipt_id !== '') {
                    $first_pay_amount = ($plan_type === 'full') ? $final_total : ($base_fee / 6);
                    
                    $pay_stmt = $pdo->prepare("INSERT INTO payment_records (receipt_id, student_id, amount_paid, payment_date, installment_number) VALUES (?, ?, ?, ?, ?)");
                    $pay_stmt->execute([$setup_receipt_id, $student_id, $first_pay_amount, date('Y-m-d'), ($plan_type === 'full') ? null : 1]);

                    // Update remaining balance
                    $new_balance = max($final_total - $first_pay_amount, 0.00);
                    $up_stmt = $pdo->prepare("UPDATE payment_plans SET remaining_balance = ? WHERE student_id = ?");
                    $up_stmt->execute([$new_balance, $student_id]);
                }

                $pdo->commit();
                header("Location: student_profile.php?id=$student_id&success=1");
                exit();
            }

            elseif ($action === 'record_payment') {
                $receipt_id = trim($_POST['receipt_id'] ?? '');
                $amount_paid = floatval($_POST['amount_paid'] ?? 0);
                $payment_date = trim($_POST['payment_date'] ?? date('Y-m-d'));
                $installment_number = isset($_POST['installment_number']) ? intval($_POST['installment_number']) : null;

                if (empty($receipt_id) || $amount_paid <= 0) {
                    throw new Exception("Please specify a valid receipt ID and payment amount.");
                }

                // Check active plan
                $stmt = $pdo->prepare("SELECT * FROM payment_plans WHERE student_id = ?");
                $stmt->execute([$student_id]);
                $current_plan = $stmt->fetch();

                if (!$current_plan) {
                    throw new Exception("No active payment plan exists for this student.");
                }

                // Check if receipt already exists globally
                $chk_stmt = $pdo->prepare("SELECT COUNT(*) FROM payment_records WHERE receipt_id = ?");
                $chk_stmt->execute([$receipt_id]);
                if ($chk_stmt->fetchColumn() > 0) {
                    throw new Exception("This Receipt ID already exists in the database.");
                }

                // Insert payment record
                $stmt = $pdo->prepare("INSERT INTO payment_records (receipt_id, student_id, amount_paid, payment_date, installment_number) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$receipt_id, $student_id, $amount_paid, $payment_date, $installment_number]);

                // Recalculate remaining balance
                $rec_stmt = $pdo->prepare("SELECT SUM(amount_paid) FROM payment_records WHERE student_id = ?");
                $rec_stmt->execute([$student_id]);
                $total_paid_so_far = floatval($rec_stmt->fetchColumn());

                $new_balance = max($current_plan['final_total'] - $total_paid_so_far, 0.00);
                $up_stmt = $pdo->prepare("UPDATE payment_plans SET remaining_balance = ? WHERE student_id = ?");
                $up_stmt->execute([$new_balance, $student_id]);

                $pdo->commit();
                header("Location: student_profile.php?id=$student_id&success=2");
                exit();
            }

            elseif ($action === 'delete_receipt') {
                $receipt_id = trim($_POST['receipt_id'] ?? '');

                if (empty($receipt_id)) {
                    throw new Exception("Invalid receipt parameters.");
                }

                // Delete the record
                $stmt = $pdo->prepare("DELETE FROM payment_records WHERE receipt_id = ? AND student_id = ?");
                $stmt->execute([$receipt_id, $student_id]);

                // Recalculate remaining balance
                $stmt = $pdo->prepare("SELECT * FROM payment_plans WHERE student_id = ?");
                $stmt->execute([$student_id]);
                $current_plan = $stmt->fetch();

                if ($current_plan) {
                    $rec_stmt = $pdo->prepare("SELECT SUM(amount_paid) FROM payment_records WHERE student_id = ?");
                    $rec_stmt->execute([$student_id]);
                    $total_paid_so_far = floatval($rec_stmt->fetchColumn());

                    $new_balance = max($current_plan['final_total'] - $total_paid_so_far, 0.00);
                    $up_stmt = $pdo->prepare("UPDATE payment_plans SET remaining_balance = ? WHERE student_id = ?");
                    $up_stmt->execute([$new_balance, $student_id]);
                }

                $pdo->commit();
                header("Location: student_profile.php?id=$student_id&success=3");
                exit();
            }

            elseif ($action === 'delete_profile') {
                // Delete student records from all related tables
                $pdo->prepare("DELETE FROM exam_results WHERE student_id = ?")->execute([$student_id]);
                $pdo->prepare("DELETE FROM payment_records WHERE student_id = ?")->execute([$student_id]);
                $pdo->prepare("DELETE FROM payment_plans WHERE student_id = ?")->execute([$student_id]);
                $pdo->prepare("DELETE FROM students WHERE id = ?")->execute([$student_id]);

                $pdo->commit();
                header("Location: dashboard.php?success=3");
                exit();
            }

        } catch (Exception $e) {
            $pdo->rollBack();
            $error_msg = "Operation Failed: " . htmlspecialchars($e->getMessage());
        }
    }
}

// 4. Fetch Student Details
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
    } else {
        // Fetch active payment plan
        $stmt = $pdo->prepare("SELECT * FROM payment_plans WHERE student_id = :id");
        $stmt->execute([':id' => $student_id]);
        $plan = $stmt->fetch();

        // Fetch payment receipts
        $stmt = $pdo->prepare("SELECT * FROM payment_records WHERE student_id = :id ORDER BY payment_date ASC, receipt_id ASC");
        $stmt->execute([':id' => $student_id]);
        $receipts = $stmt->fetchAll();

        // Fetch exam results
        $stmt = $pdo->prepare("SELECT * FROM exam_results WHERE student_id = :id ORDER BY exam_date DESC, exam_id DESC");
        $stmt->execute([':id' => $student_id]);
        $exams = $stmt->fetchAll();
    }
} catch (\PDOException $e) {
    $error_msg = "Database query failed: " . htmlspecialchars($e->getMessage());
}

// Generate CSRF token if not set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$page_title = 'Student Profile Details - Student Registration System';
ob_start();
?>
    <style>
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
        .badge-tag-qual {
            display: inline-block;
            background-color: #f1f5f9;
            color: #475569;
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0.35rem 0.75rem;
            border-radius: 50px;
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
        }
        .badge-tag {
            display: inline-block;
            background-color: #e0e7ff;
            color: #4338ca;
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0.35rem 0.75rem;
            border-radius: 50px;
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
        }
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
            font-weight: bold;
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
        .tab-pane-container {
            animation: fadeIn 0.25s ease-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(4px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
<?php
$extra_css = ob_get_clean();
include 'header.php';
?>

    <!-- Main Container -->
    <div class="container pb-5">
        <div class="row justify-content-center">
            <div class="col-xl-10">

                <!-- Action Alerts -->
                <div id="alert-container">
                    <?php if (!empty($success_msg)): ?>
                        <div class="alert alert-success d-flex align-items-center mb-4" role="alert" style="background-color: #d1fae5; border: 1px solid rgba(16, 185, 129, 0.25); color: #065f46; border-radius: 8px;">
                            <i class="bi bi-check-circle-fill me-2"></i>
                            <div><?php echo htmlspecialchars($success_msg); ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($error_msg)): ?>
                        <div class="alert alert-danger d-flex align-items-center mb-4" role="alert" style="background-color: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.3); color: #fca5a5; border-radius: 4px;">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <div><?php echo $error_msg; ?></div>
                        </div>
                        <div class="text-center mb-4">
                            <a href="dashboard.php" class="btn btn-accent"><i class="bi bi-arrow-left"></i> Return to Dashboard</a>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($student && empty($error_msg)): ?>
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
                                <div class="row mb-3">
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
                                        <div class="profile-value"><?php echo !empty($student['is_nvq']) ? htmlspecialchars($student['is_nvq']) . ' (NVQ)' : 'Non-NVQ'; ?></div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-3">
                                        <div class="profile-label">Registration Date</div>
                                        <div class="profile-value"><?php echo htmlspecialchars($student['registration_date']); ?></div>
                                    </div>
                                </div>

                                <!-- SECTION 2: PERSONAL IDENTITY -->
                                <div class="profile-section-title mt-4">
                                    <i class="bi bi-person-badge"></i> Personal Credentials
                                </div>
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="profile-label">NIC / National ID</div>
                                        <div class="profile-value"><?php echo htmlspecialchars($student['nic']); ?></div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="profile-label">Date of Birth</div>
                                        <div class="profile-value"><?php echo htmlspecialchars($student['dob']); ?></div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="profile-label">Gender</div>
                                        <div class="profile-value"><?php echo htmlspecialchars($student['gender']); ?></div>
                                    </div>
                                </div>

                                <!-- SECTION 3: COMMUNICATIONS -->
                                <div class="profile-section-title mt-4">
                                    <i class="bi bi-geo-alt"></i> Communication Channels
                                </div>
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="profile-label">Contact Number</div>
                                        <div class="profile-value"><?php echo htmlspecialchars($student['contact_no']); ?></div>
                                    </div>
                                    <div class="col-md-8">
                                        <div class="profile-label">Residential Address</div>
                                        <div class="profile-value"><?php echo nl2br(htmlspecialchars($student['address'])); ?></div>
                                    </div>
                                </div>

                                <!-- SECTION 4: GUARDIAN INFO -->
                                <div class="profile-section-title mt-4">
                                    <i class="bi bi-shield-check"></i> Guardian Context
                                </div>
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="profile-label">Guardian Name</div>
                                        <div class="profile-value">
                                            <?php echo !empty($student['guardian_name']) ? htmlspecialchars($student['guardian_name']) : '<span class="text-muted small">Not Provided</span>'; ?>
                                        </div>
                                    </div>
                                    <div class="col-md-8">
                                        <div class="profile-label">Emergency Details</div>
                                        <div class="profile-value">
                                            <?php echo !empty($student['guardian_details']) ? htmlspecialchars($student['guardian_details']) : '<span class="text-muted small">Not Provided</span>'; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- SECTION 5: PRIOR EDUCATION -->
                                <div class="profile-section-title mt-4">
                                    <i class="bi bi-mortarboard-fill"></i> Academic Qualifications
                                </div>
                                <div class="mb-3">
                                    <?php
                                    $quals = [];
                                    if ($student['gce_ol']) $quals[] = 'G.C.E. O/L';
                                    if ($student['gce_al_science']) $quals[] = 'G.C.E. A/L - Science';
                                    if ($student['gce_al_maths']) $quals[] = 'G.C.E. A/L - Mathematics';
                                    if ($student['gce_al_commerce']) $quals[] = 'G.C.E. A/L - Commerce';
                                    if ($student['gce_al_art']) $quals[] = 'G.C.E. A/L - Arts';
                                    if ($student['gce_al_tech']) $quals[] = 'G.C.E. A/L - Technology';
                                    if ($student['kids_grade']) $quals[] = 'Kids School Grade';
                                    if ($student['other_edu']) $quals[] = 'Other Qualifications';

                                    if (empty($quals)):
                                        echo '<span class="text-muted small">No prior qualifications listed.</span>';
                                    else:
                                        foreach ($quals as $qual):
                                            echo '<span class="badge-tag-qual"><i class="bi bi-check2-circle me-1"></i>' . htmlspecialchars($qual) . '</span>';
                                        endforeach;
                                    endif;
                                    ?>
                                </div>

                                <!-- SECTION 6: ENROLLED COURSES -->
                                <div class="profile-section-title mt-4">
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
                                
                                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4 border-bottom pb-3">
                                    <h4 class="mb-0 fw-bold"><i class="bi bi-credit-card-2-front me-2 text-primary"></i>Payment System & Tracking</h4>
                                    <?php if ($plan && $plan['plan_type'] !== 'pending'): ?>
                                        <form action="student_profile.php?id=<?php echo $student_id; ?>" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to reset this payment plan? This will delete all registered payments/receipts.');">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                            <input type="hidden" name="action" value="reset_plan">
                                            <button type="submit" class="btn btn-outline-warning btn-sm">
                                                <i class="bi bi-arrow-counterclockwise me-1"></i>Reset Plan
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>

                                <!-- NO PLAN SET UP OR PENDING -->
                                <?php if (!$plan || $plan['plan_type'] === 'pending'): ?>
                                    <div class="alert alert-info py-4 border border-secondary border-opacity-10" style="background-color: var(--bg-main);">
                                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                                            <div>
                                                <h5 class="fw-bold mb-1"><i class="bi bi-exclamation-circle me-1"></i>No Active Payment Plan</h5>
                                                <p class="mb-0 text-muted small">Please configure a payment structure (Full or Installment) for this student.</p>
                                            </div>
                                            <div id="no-plan-buttons">
                                                <button type="button" class="btn btn-accent" onclick="showPaymentForm()">
                                                    <i class="bi bi-cash-coin me-1"></i>Configure Plan
                                                </button>
                                                <?php if (!$plan): ?>
                                                    <form action="student_profile.php?id=<?php echo $student_id; ?>" method="POST" class="d-inline">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                        <input type="hidden" name="action" value="pay_later">
                                                        <button type="submit" class="btn btn-muted-outline">Pay Later</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Plan Configuration Form -->
                                    <div id="pay-now-form" style="display: none;" class="mt-4 card p-4 border">
                                        <h5 class="fw-bold mb-3 text-dark"><i class="bi bi-gear-fill me-1 text-primary"></i>Configure New Payment Plan</h5>
                                        
                                        <form action="student_profile.php?id=<?php echo $student_id; ?>" method="POST" class="needs-validation" novalidate>
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                            <input type="hidden" name="action" value="setup_plan">

                                            <div class="row mb-3">
                                                <div class="col-md-6 mb-3">
                                                    <label for="base_fee" class="form-label fw-bold">Base Fee (LKR)</label>
                                                    <input type="number" step="0.01" min="1" class="form-control" id="base_fee" name="base_fee" required>
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label for="plan_type" class="form-label fw-bold">Payment Plan Type</label>
                                                    <select class="form-select" id="plan_type" name="plan_type" required>
                                                        <option value="">-- Select Plan Type --</option>
                                                        <option value="full">Full Payment (5% Discount)</option>
                                                        <option value="installment">Installment Plan (6 Months)</option>
                                                    </select>
                                                </div>
                                            </div>

                                            <div class="mb-3" id="calculation_preview"></div>

                                            <div class="card p-3 mb-3 bg-light border">
                                                <h6 class="fw-bold mb-3"><i class="bi bi-cash me-1 text-primary"></i>First Transaction / Receipt (Optional)</h6>
                                                <div class="row">
                                                    <div class="col-md-6 mb-3">
                                                        <label for="setup_receipt_id" class="form-label fw-bold">Receipt ID</label>
                                                        <input type="text" class="form-control" id="setup_receipt_id" name="setup_receipt_id" placeholder="e.g. REC-10254">
                                                    </div>
                                                    <div class="col-md-6 mb-3 d-flex align-items-end">
                                                        <div class="form-check mb-2">
                                                            <input class="form-check-input" type="checkbox" id="admission_paid" name="admission_paid" value="1">
                                                            <label class="form-check-label fw-semibold" for="admission_paid">Admission Fee Paid</label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="d-flex gap-3">
                                                <button type="submit" class="btn btn-accent">Save Connection Plan</button>
                                                <button type="button" class="btn btn-muted-outline" onclick="hidePaymentForm()">Cancel</button>
                                            </div>
                                        </form>
                                    </div>

                                <!-- PLAN CONFIGURED (FULL OR INSTALLMENTS) -->
                                <?php else: 
                                    $plan_type = $plan['plan_type'];
                                    $base_fee = floatval($plan['base_fee']);
                                    $final_total = floatval($plan['final_total']);
                                    $admission_paid = intval($plan['admission_paid']);
                                    
                                    $total_paid = 0.00;
                                    foreach ($receipts as $r) {
                                        $total_paid += floatval($r['amount_paid']);
                                    }
                                    
                                    $balance = max($final_total - $total_paid, 0.00);
                                    $is_fully_paid = ($balance <= 0.01);
                                ?>

                                    <!-- Plan Summary Cards -->
                                    <div class="row mb-4">
                                        <div class="col-md-4 mb-3">
                                            <div class="card p-3 border-left-primary bg-light">
                                                <div class="profile-label">Plan Type</div>
                                                <div class="fs-5 fw-bold text-primary text-uppercase">
                                                    <?php echo ($plan_type === 'full') ? 'Full Payment' : 'Installment Plan'; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <div class="card p-3 bg-light">
                                                <div class="profile-label">Total Fee (Final)</div>
                                                <div class="fs-5 fw-bold text-dark">
                                                    LKR <?php echo number_format($final_total, 2); ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <div class="card p-3 bg-light">
                                                <div class="profile-label">Admission Status</div>
                                                <div>
                                                    <?php if ($admission_paid): ?>
                                                        <span class="badge bg-success-subtle text-success border border-success border-opacity-25 px-2.5 py-1.5 rounded"><i class="bi bi-check2-circle me-1"></i>Paid</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger-subtle text-danger border border-danger border-opacity-25 px-2.5 py-1.5 rounded"><i class="bi bi-x-circle me-1"></i>Unpaid</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Paid & Balance indicators -->
                                    <div class="row mb-4">
                                        <div class="col-md-6 mb-3">
                                            <div class="card p-3 bg-light">
                                                <div class="profile-label">Total Paid So Far</div>
                                                <div class="fs-4 fw-bold text-success">
                                                    LKR <?php echo number_format($total_paid, 2); ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <div class="card p-3 bg-light">
                                                <div class="profile-label">Remaining Balance</div>
                                                <div class="fs-4 fw-bold <?php echo $is_fully_paid ? 'text-muted' : 'text-danger'; ?>">
                                                    LKR <?php echo number_format($balance, 2); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Installments Breakdown for Installment Plan -->
                                    <?php if ($plan_type === 'installment'): ?>
                                        <div class="card p-4 mb-4 border">
                                            <h5 class="fw-bold mb-3"><i class="bi bi-clock-history me-1 text-primary"></i>6-Month Installment Schedule</h5>
                                            <div class="row">
                                                <?php
                                                $monthly_due = $base_fee / 6;
                                                for ($i = 1; $i <= 6; $i++):
                                                    $is_paid = false;
                                                    foreach ($receipts as $r) {
                                                        if (intval($r['installment_number']) === $i) {
                                                            $is_paid = true;
                                                            break;
                                                        }
                                                    }
                                                ?>
                                                    <div class="col-md-4 col-sm-6 mb-3">
                                                        <div class="p-3 border rounded <?php echo $is_paid ? 'bg-success bg-opacity-10 border-success border-opacity-25' : 'bg-light'; ?>">
                                                            <div class="fw-bold">Month <?php echo $i; ?></div>
                                                            <div class="text-muted small">Due: LKR <?php echo number_format($monthly_due, 2); ?></div>
                                                            <div class="mt-2">
                                                                <?php if ($is_paid): ?>
                                                                    <span class="badge bg-success-subtle text-success px-2 py-1"><i class="bi bi-check-circle me-1"></i>Settled</span>
                                                                <?php else: ?>
                                                                    <span class="badge bg-warning-subtle text-warning px-2 py-1"><i class="bi bi-exclamation-circle me-1"></i>Pending</span>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php endfor; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Payment Form Action -->
                                    <?php if (!$is_fully_paid): ?>
                                        <div class="mb-4">
                                            <button type="button" class="btn btn-accent" id="make-payment-btn" onclick="showRecordPaymentForm()">
                                                <i class="bi bi-cash me-1"></i>Record New Payment
                                            </button>

                                            <div id="record-payment-form" style="display: none;" class="card p-4 border mt-3">
                                                <h6 class="fw-bold mb-3"><i class="bi bi-plus-circle me-1 text-primary"></i>Record Payment Transaction</h6>
                                                <form action="student_profile.php?id=<?php echo $student_id; ?>" method="POST" class="needs-validation" novalidate>
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                    <input type="hidden" name="action" value="record_payment">

                                                    <div class="row mb-3">
                                                        <div class="col-md-6 mb-3">
                                                            <label for="receipt_id" class="form-label fw-bold">Receipt ID / Invoice Number</label>
                                                            <input type="text" class="form-control" id="receipt_id" name="receipt_id" required placeholder="e.g. REC-5421">
                                                        </div>
                                                        <div class="col-md-6 mb-3">
                                                            <label for="amount_paid" class="form-label fw-bold">Amount Paid (LKR)</label>
                                                            <input type="number" step="0.01" min="0.01" max="<?php echo $balance; ?>" class="form-control" id="amount_paid" name="amount_paid" required value="<?php echo ($plan_type === 'installment') ? number_format($base_fee / 6, 2, '.', '') : number_format($balance, 2, '.', ''); ?>">
                                                        </div>
                                                    </div>

                                                    <div class="row mb-3">
                                                        <div class="col-md-6 mb-3">
                                                            <label for="payment_date" class="form-label fw-bold">Payment Date</label>
                                                            <input type="date" class="form-control" id="payment_date" name="payment_date" value="<?php echo date('Y-m-d'); ?>" required>
                                                        </div>
                                                        <?php if ($plan_type === 'installment'): ?>
                                                            <div class="col-md-6 mb-3">
                                                                <label for="installment_number" class="form-label fw-bold">Associate to Installment Month</label>
                                                                <select class="form-select" id="installment_number" name="installment_number" required>
                                                                    <option value="">-- Choose Installment --</option>
                                                                    <?php
                                                                    for ($i = 1; $i <= 6; $i++) {
                                                                        $is_paid = false;
                                                                        foreach ($receipts as $r) {
                                                                            if (intval($r['installment_number']) === $i) {
                                                                                $is_paid = true;
                                                                                break;
                                                                            }
                                                                        }
                                                                        if (!$is_paid) {
                                                                            echo "<option value='$i'>Month $i (LKR " . number_format($base_fee / 6, 2) . ")</option>";
                                                                        }
                                                                    }
                                                                    ?>
                                                                </select>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>

                                                    <div class="d-flex gap-2">
                                                        <button type="submit" class="btn btn-accent">Submit Payment</button>
                                                        <button type="button" class="btn btn-muted-outline" onclick="hideRecordPaymentForm()">Cancel</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Payment Receipts Table -->
                                    <div class="card p-4 border">
                                        <h5 class="fw-bold mb-3"><i class="bi bi-list-check me-1 text-primary"></i>Transaction History</h5>
                                        <?php if (empty($receipts)): ?>
                                            <div class="text-muted small">No payment transactions recorded yet.</div>
                                        <?php else: ?>
                                            <div class="table-responsive">
                                                <table class="table table-striped table-hover mb-0">
                                                    <thead>
                                                        <tr>
                                                            <th>Receipt ID</th>
                                                            <th>Description</th>
                                                            <th>Amount</th>
                                                            <th>Date</th>
                                                            <th>Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($receipts as $r): ?>
                                                            <tr>
                                                                <td class="fw-bold"><?php echo htmlspecialchars($r['receipt_id']); ?></td>
                                                                <td>
                                                                    <?php echo ($plan_type === 'full') ? 'Full Fee Settled' : 'Installment Month ' . $r['installment_number']; ?>
                                                                </td>
                                                                <td class="fw-bold text-success">LKR <?php echo number_format(floatval($r['amount_paid']), 2); ?></td>
                                                                <td><?php echo htmlspecialchars($r['payment_date']); ?></td>
                                                                <td>
                                                                    <form action="student_profile.php?id=<?php echo $student_id; ?>" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this payment record? This will adjust the remaining balance.');">
                                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                                        <input type="hidden" name="action" value="delete_receipt">
                                                                        <input type="hidden" name="receipt_id" value="<?php echo htmlspecialchars($r['receipt_id']); ?>">
                                                                        <button type="submit" class="btn btn-outline-danger btn-sm">
                                                                            <i class="bi bi-trash"></i> Delete
                                                                        </button>
                                                                    </form>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                <?php endif; ?>

                            </div> <!-- Close TAB 2 payment-pane -->

                            <!-- TAB 3: EXAM RECORDS -->
                            <div class="tab-pane fade tab-pane-container p-4 p-md-5" id="exam-pane" role="tabpanel" aria-labelledby="exam-tab" tabindex="0">
                                <h4 class="mb-4 fw-bold"><i class="bi bi-journal-check me-2 text-primary"></i>Exam Records & Grades</h4>
                                
                                <?php if (empty($exams)): ?>
                                    <div class="alert alert-info text-center py-4 border border-secondary border-opacity-10" style="background-color: var(--bg-main);">
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
            document.getElementById('admission_paid').checked = false;
        };

        const showRecordPaymentForm = () => {
            document.getElementById('make-payment-btn').style.display = 'none';
            document.getElementById('record-payment-form').style.display = 'block';
        };

        const hideRecordPaymentForm = () => {
            document.getElementById('record-payment-form').style.display = 'none';
            document.getElementById('make-payment-btn').style.display = 'block';
        };

        const baseFeeInput = document.getElementById('base_fee');
        const planTypeSelect = document.getElementById('plan_type');
        const calculationPreview = document.getElementById('calculation_preview');

        const calculatePreview = () => {
            const baseFee = parseFloat(baseFeeInput.value) || 0;
            const planType = planTypeSelect.value;

            if (baseFee <= 0 || !planType) {
                calculationPreview.innerHTML = '';
                return;
            }

            if (planType === 'full') {
                const finalTotal = baseFee * 0.95;
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
