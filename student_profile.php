<?php
/**
 * student_profile.php
 * Displays student details, tracks payment plans with fixed fees, and logs exam records.
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
    $success_msg = "Installment payment recorded successfully!";
} elseif ($success_param === 3) {
    $success_msg = "Payment record deleted successfully.";
} elseif ($success_param === 4) {
    $success_msg = "Payment plan reset successfully.";
}

if ($error_param !== '') {
    $error_msg = htmlspecialchars($error_param);
}

// 3. PHP Backend Logic
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        $error_msg = 'Invalid session token. Please try again.';
    } else {
        $action = $_POST['action'];

        try {
            $pdo->beginTransaction();

            if ($action === 'setup_payment_plan') {
                $base_fee = floatval($_POST['base_fee'] ?? 0);
                $plan_type = trim($_POST['plan_type'] ?? '');
                $admission_paid = isset($_POST['admission_paid']) ? 1 : 0;
                $setup_receipt_id = trim($_POST['setup_receipt_id'] ?? '');

                if ($base_fee <= 0 || !in_array($plan_type, ['full', 'installment'])) {
                    throw new Exception("Please specify a valid base fee and payment plan type.");
                }
                if (empty($setup_receipt_id)) {
                    throw new Exception("Please specify the Receipt ID for the first payment.");
                }

                // Check if receipt ID already exists
                $chk_stmt = $pdo->prepare("SELECT COUNT(*) FROM payment_records WHERE receipt_id = ?");
                $chk_stmt->execute([$setup_receipt_id]);
                if ($chk_stmt->fetchColumn() > 0) {
                    throw new Exception("The Receipt ID '$setup_receipt_id' already exists in the database.");
                }

                // Calculate total and first payment amount
                if ($plan_type === 'full') {
                    $final_total = $base_fee * 0.95;
                    $amount_paid = $final_total;
                } else { // installment
                    $final_total = $base_fee;
                    $amount_paid = $base_fee / 6;
                }

                // Insert the new plan
                $ins_plan = $pdo->prepare("INSERT INTO payment_plans (student_id, plan_type, base_fee, final_total, admission_paid) VALUES (?, ?, ?, ?, ?)");
                $ins_plan->execute([$student_id, $plan_type, $base_fee, $final_total, $admission_paid]);

                // Insert the first payment record
                $ins_record = $pdo->prepare("INSERT INTO payment_records (receipt_id, student_id, amount_paid, payment_date) VALUES (?, ?, ?, ?)");
                $ins_record->execute([$setup_receipt_id, $student_id, $amount_paid, date('Y-m-d')]);

                $pdo->commit();
                header("Location: student_profile.php?id=$student_id&success=1");
                exit();
            }

            elseif ($action === 'record_installment') {
                $receipt_id = trim($_POST['receipt_id'] ?? '');

                if (empty($receipt_id)) {
                    throw new Exception("Please provide a Receipt ID.");
                }

                // Fetch plan
                $stmt = $pdo->prepare("SELECT * FROM payment_plans WHERE student_id = ?");
                $stmt->execute([$student_id]);
                $current_plan = $stmt->fetch();

                if (!$current_plan || $current_plan['plan_type'] !== 'installment') {
                    throw new Exception("No active installment plan found for this student.");
                }

                // Calculate total paid so far
                $rec_stmt = $pdo->prepare("SELECT SUM(amount_paid) FROM payment_records WHERE student_id = ?");
                $rec_stmt->execute([$student_id]);
                $total_paid = floatval($rec_stmt->fetchColumn());
                
                $final_total = floatval($current_plan['final_total']);
                $balance = max($final_total - $total_paid, 0.00);

                if ($balance <= 0.01) {
                    throw new Exception("The payment plan is already fully paid.");
                }

                // Check if receipt ID already exists
                $chk_stmt = $pdo->prepare("SELECT COUNT(*) FROM payment_records WHERE receipt_id = ?");
                $chk_stmt->execute([$receipt_id]);
                if ($chk_stmt->fetchColumn() > 0) {
                    throw new Exception("The Receipt ID '$receipt_id' already exists in the database.");
                }

                // Fixed installment is base_fee / 6, or remaining balance if less
                $installment_amount = floatval($current_plan['base_fee']) / 6;
                if ($balance < $installment_amount) {
                    $installment_amount = $balance;
                }

                // Record payment with auto-date (today)
                $ins_record = $pdo->prepare("INSERT INTO payment_records (receipt_id, student_id, amount_paid, payment_date) VALUES (?, ?, ?, ?)");
                $ins_record->execute([$receipt_id, $student_id, $installment_amount, date('Y-m-d')]);

                $pdo->commit();
                header("Location: student_profile.php?id=$student_id&success=2");
                exit();
            }

            elseif ($action === 'reset_plan') {
                $pdo->prepare("DELETE FROM payment_records WHERE student_id = ?")->execute([$student_id]);
                $pdo->prepare("DELETE FROM payment_plans WHERE student_id = ?")->execute([$student_id]);
                $pdo->commit();
                header("Location: student_profile.php?id=$student_id&success=4");
                exit();
            }

            elseif ($action === 'delete_receipt') {
                $receipt_id = trim($_POST['receipt_id'] ?? '');
                if ($receipt_id !== '') {
                    $stmt = $pdo->prepare("DELETE FROM payment_records WHERE receipt_id = ? AND student_id = ?");
                    $stmt->execute([$receipt_id, $student_id]);
                }
                $pdo->commit();
                header("Location: student_profile.php?id=$student_id&success=3");
                exit();
            }

            elseif ($action === 'delete_profile') {
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
            $error_msg = "Error: " . htmlspecialchars($e->getMessage());
        }
    }
}

// 4. Fetch Student and Related Records
try {
    $stmt = $pdo->prepare("SELECT s.*, u.username AS creator_username FROM students s LEFT JOIN users u ON s.added_by = u.user_id WHERE s.id = ?");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch();

    if (!$student) {
        $error_msg = "No student record found with ID " . htmlspecialchars($student_id) . ".";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM payment_plans WHERE student_id = ?");
        $stmt->execute([$student_id]);
        $plan = $stmt->fetch();

        $stmt = $pdo->prepare("SELECT * FROM payment_records WHERE student_id = ? ORDER BY payment_date ASC, receipt_id ASC");
        $stmt->execute([$student_id]);
        $receipts = $stmt->fetchAll();

        $stmt = $pdo->prepare("SELECT * FROM exam_results WHERE student_id = ? ORDER BY exam_date DESC, exam_id DESC");
        $stmt->execute([$student_id]);
        $exams = $stmt->fetchAll();
    }
} catch (\PDOException $e) {
    $error_msg = "Database query failed: " . htmlspecialchars($e->getMessage());
}

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

    <div class="container pb-5">
        <div class="row justify-content-center">
            <div class="col-xl-10">

                <!-- Alert Messages -->
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

                        <!-- Tab Header -->
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

                        <div class="tab-content" id="profileTabsContent">
                            
                            <!-- TAB 1: REGISTRATION INFO -->
                            <div class="tab-pane fade show active tab-pane-container p-4 p-md-5" id="registration-pane" role="tabpanel" aria-labelledby="registration-tab" tabindex="0">
                                
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

                                <div class="profile-section-title mt-4">
                                    <i class="bi bi-geo-alt"></i> Residential Address & Contacts
                                </div>
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="profile-label">Contact Number</div>
                                        <div class="profile-value"><?php echo htmlspecialchars($student['contact_no']); ?></div>
                                    </div>
                                    <div class="col-md-8">
                                        <div class="profile-label">Address</div>
                                        <div class="profile-value"><?php echo nl2br(htmlspecialchars($student['address'])); ?></div>
                                    </div>
                                </div>

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
                                        echo '<span class="text-muted small">No qualifications selected.</span>';
                                    else:
                                        foreach ($quals as $qual):
                                            echo '<span class="badge-tag-qual"><i class="bi bi-check2-circle me-1"></i>' . htmlspecialchars($qual) . '</span>';
                                        endforeach;
                                    endif;
                                    ?>
                                </div>

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

                            </div>

                            <!-- TAB 2: PAYMENT STATUS -->
                            <div class="tab-pane fade tab-pane-container p-4 p-md-5" id="payment-pane" role="tabpanel" aria-labelledby="payment-tab" tabindex="0">
                                
                                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4 border-bottom pb-3">
                                    <h4 class="mb-0 fw-bold"><i class="bi bi-credit-card-2-front me-2 text-primary"></i>Fixed Course Payment System</h4>
                                    <?php if ($plan && $plan['plan_type'] !== 'pending'): ?>
                                        <form action="student_profile.php?id=<?php echo $student_id; ?>" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to reset this payment plan? This will clear all recorded payments.');">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                            <input type="hidden" name="action" value="reset_plan">
                                            <button type="submit" class="btn btn-outline-warning btn-sm">
                                                <i class="bi bi-arrow-counterclockwise me-1"></i>Reset Plan & Start Over
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>

                                <?php
                                $total_paid = 0.00;
                                foreach ($receipts as $r) {
                                    $total_paid += floatval($r['amount_paid']);
                                }

                                $final_total = $plan ? floatval($plan['final_total']) : 0.00;
                                $balance = max($final_total - $total_paid, 0.00);
                                $is_fully_paid = ($plan && $plan['plan_type'] !== 'pending' && $balance <= 0.01);
                                ?>

                                <!-- 1. Summary Card -->
                                <?php if ($plan && $plan['plan_type'] !== 'pending'): ?>
                                    <div class="card p-4 bg-light border mb-4">
                                        <h5 class="fw-bold mb-3"><i class="bi bi-info-circle text-primary me-1"></i>Payment Summary Card</h5>
                                        <div class="row">
                                            <div class="col-md-4 mb-3">
                                                <div class="profile-label">Base Fee</div>
                                                <div class="fs-5 fw-bold text-dark">LKR <?php echo number_format(floatval($plan['base_fee']), 2); ?></div>
                                            </div>
                                            <div class="col-md-4 mb-3">
                                                <div class="profile-label">Plan Type</div>
                                                <div class="fs-5 fw-bold text-primary text-uppercase"><?php echo htmlspecialchars($plan['plan_type']); ?></div>
                                            </div>
                                            <div class="col-md-4 mb-3">
                                                <div class="profile-label">Final Total</div>
                                                <div class="fs-5 fw-bold text-dark">LKR <?php echo number_format($final_total, 2); ?></div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-4 mb-3">
                                                <div class="profile-label">Total Paid</div>
                                                <div class="fs-5 fw-bold text-success">LKR <?php echo number_format($total_paid, 2); ?></div>
                                            </div>
                                            <div class="col-md-4 mb-3">
                                                <div class="profile-label">Remaining Balance Needed</div>
                                                <div class="fs-5 fw-bold <?php echo $is_fully_paid ? 'text-muted' : 'text-danger'; ?>">LKR <?php echo number_format($balance, 2); ?></div>
                                            </div>
                                            <div class="col-md-4 mb-3">
                                                <div class="profile-label">Admission Fee Status</div>
                                                <div>
                                                    <?php if ($plan['admission_paid']): ?>
                                                        <span class="badge bg-success-subtle text-success border border-success border-opacity-25 px-2.5 py-1.5 rounded"><i class="bi bi-check2-circle me-1"></i>Settled (Paid)</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger-subtle text-danger border border-danger border-opacity-25 px-2.5 py-1.5 rounded"><i class="bi bi-x-circle me-1"></i>Pending</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- 2. SETUP PLAN FORM (If no plan is configured yet) -->
                                <?php if (!$plan || $plan['plan_type'] === 'pending'): ?>
                                    <div class="card p-4 border bg-white mb-4">
                                        <h5 class="fw-bold mb-3 text-dark"><i class="bi bi-gear-fill me-1 text-primary"></i>Setup Course Payment Plan</h5>
                                        
                                        <form action="student_profile.php?id=<?php echo $student_id; ?>" method="POST" class="needs-validation" novalidate>
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                            <input type="hidden" name="action" value="setup_payment_plan">

                                            <!-- Admission Toggle (Required for first payment setup) -->
                                            <div class="form-check form-switch mb-3">
                                                <input class="form-check-input" type="checkbox" role="switch" id="admission_paid" name="admission_paid" value="1" checked>
                                                <label class="form-check-label fw-bold" for="admission_paid">Admission Fee Paid</label>
                                            </div>

                                            <div class="row mb-3">
                                                <div class="col-md-6 mb-3">
                                                    <label for="base_fee" class="form-label fw-bold">Base Fee (LKR)</label>
                                                    <input type="number" step="0.01" min="1" class="form-control" id="base_fee" name="base_fee" required placeholder="e.g. 60000">
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label for="plan_type" class="form-label fw-bold">Payment Type</label>
                                                    <select class="form-select" id="plan_type" name="plan_type" required>
                                                        <option value="">-- Select Payment Type --</option>
                                                        <option value="full">Full Payment (5% Discount)</option>
                                                        <option value="installment">6-Month Installment</option>
                                                    </select>
                                                </div>
                                            </div>

                                            <div class="row mb-3">
                                                <div class="col-md-6 mb-3">
                                                    <label for="setup_receipt_id" class="form-label fw-bold">Receipt ID (First Payment)</label>
                                                    <input type="text" class="form-control" id="setup_receipt_id" name="setup_receipt_id" required placeholder="e.g. REC-12345">
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label for="amount_due_today" class="form-label fw-bold">Amount Due Right Now (LKR)</label>
                                                    <input type="text" class="form-control fw-bold text-success" id="amount_due_today" readonly value="0.00">
                                                </div>
                                            </div>

                                            <button type="submit" class="btn btn-accent">Configure Plan & Record First Payment</button>
                                        </form>
                                    </div>

                                <!-- 3. SUBSEQUENT INSTALLMENTS FORM (If plan is installment and not fully paid) -->
                                <?php elseif ($plan['plan_type'] === 'installment' && !$is_fully_paid): 
                                    $fixed_amount = floatval($plan['base_fee']) / 6;
                                    if ($balance < $fixed_amount) {
                                        $fixed_amount = $balance;
                                    }
                                ?>
                                    <div class="card p-4 border bg-white mb-4">
                                        <h5 class="fw-bold mb-2 text-dark"><i class="bi bi-cash-coin me-1 text-success"></i>Record Installment Payment</h5>
                                        <p class="text-muted small mb-3">
                                            Fixed amount to pay: <span class="fw-bold text-success">LKR <?php echo number_format($fixed_amount, 2); ?></span>. Date is automatically set to today.
                                        </p>
                                        
                                        <form action="student_profile.php?id=<?php echo $student_id; ?>" method="POST" class="row align-items-end needs-validation" novalidate>
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                            <input type="hidden" name="action" value="record_installment">

                                            <div class="col-md-8 mb-3">
                                                <label for="receipt_id" class="form-label fw-bold">Receipt ID (Manual)</label>
                                                <input type="text" class="form-control" id="receipt_id" name="receipt_id" required placeholder="Enter Receipt ID / Invoice Number">
                                            </div>
                                            <div class="col-md-4 mb-3">
                                                <button type="submit" class="btn btn-accent w-100 py-2">
                                                    <i class="bi bi-check2-circle me-1"></i>Record Installment
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                <?php endif; ?>

                                <!-- 4. Transaction History -->
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
                                                        <th>Amount Paid</th>
                                                        <th>Payment Date</th>
                                                        <th>Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($receipts as $r): ?>
                                                        <tr>
                                                            <td class="fw-bold"><?php echo htmlspecialchars($r['receipt_id']); ?></td>
                                                            <td class="fw-bold text-success">LKR <?php echo number_format(floatval($r['amount_paid']), 2); ?></td>
                                                            <td><?php echo htmlspecialchars($r['payment_date']); ?></td>
                                                            <td>
                                                                <form action="student_profile.php?id=<?php echo $student_id; ?>" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this payment record?');">
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

                            </div>

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
                            </div>

                        </div>

                        <!-- Footer Actions -->
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
    
    <!-- Setup Calculations JS -->
    <script>
        const baseFeeInput = document.getElementById('base_fee');
        const planTypeSelect = document.getElementById('plan_type');
        const amountDueInput = document.getElementById('amount_due_today');

        const updateDueAmount = () => {
            if (!baseFeeInput || !planTypeSelect) return;
            const baseFee = parseFloat(baseFeeInput.value) || 0;
            const planType = planTypeSelect.value;
            let due = 0.00;

            if (baseFee > 0 && planType !== '') {
                if (planType === 'full') {
                    due = baseFee * 0.95; // 5% Discount
                } else if (planType === 'installment') {
                    due = baseFee / 6; // 1st installment
                }
            }
            amountDueInput.value = due.toFixed(2);
        };

        if (baseFeeInput) {
            baseFeeInput.addEventListener('input', updateDueAmount);
        }
        if (planTypeSelect) {
            planTypeSelect.addEventListener('change', updateDueAmount);
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
