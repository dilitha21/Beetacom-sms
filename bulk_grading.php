<?php
require_once 'auth_check.php';
require_once 'db_connect.php';

$success_msg = '';
$error_msg = '';

$course_code = trim($_GET['course_code'] ?? '');
$batch_year = trim($_GET['batch_year'] ?? '');
$batch_number = trim($_GET['batch_number'] ?? '');
$exam_name = trim($_GET['exam_name'] ?? '');
$exam_date = trim($_GET['exam_date'] ?? date('Y-m-d'));

$students = [];

// Load students matching the batch, course and batch number
if ($course_code !== '' && $batch_year !== '' && $batch_number !== '') {
    try {
        $stmt = $pdo->prepare("SELECT id, name, index_number FROM students WHERE course_code = :course_code AND batch_year = :batch_year AND batch_number = :batch_number ORDER BY index_number ASC");
        $stmt->execute([
            ':course_code' => $course_code,
            ':batch_year'  => $batch_year,
            ':batch_number' => $batch_number
        ]);
        $students = $stmt->fetchAll();
    } catch (\PDOException $e) {
        $error_msg = "Failed to load students: " . htmlspecialchars($e->getMessage());
    }
}

// POST processing: Insert results inside database transaction
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_bulk_grades') {
    $post_course_code = trim($_POST['course_code'] ?? '');
    $post_exam_name = trim($_POST['exam_name'] ?? '');
    $post_exam_date = trim($_POST['exam_date'] ?? '');
    $student_ids = $_POST['student_ids'] ?? [];

    if ($post_course_code === '' || $post_exam_name === '' || $post_exam_date === '' || empty($student_ids)) {
        $error_msg = "Please ensure all exam fields are entered and student records are loaded.";
    } else {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("INSERT INTO exam_results (student_id, course_code, exam_name, exam_date, status, mark) VALUES (:student_id, :course_code, :exam_name, :exam_date, :status, :mark)");

            foreach ($student_ids as $sid) {
                $status = $_POST['status'][$sid] ?? 'Pending';
                $mark = $_POST['mark'][$sid] ?? '';

                $mark_val = ($mark !== '' && $mark !== null) ? floatval($mark) : null;

                $stmt->execute([
                    ':student_id'  => $sid,
                    ':course_code' => $post_course_code,
                    ':exam_name'   => $post_exam_name,
                    ':exam_date'   => $post_exam_date,
                    ':status'      => $status,
                    ':mark'        => $mark_val
                ]);
            }

            $pdo->commit();
            $success_msg = "Successfully recorded exam results for " . count($student_ids) . " students.";
            // Clear student list after success
            $students = [];
            $course_code = '';
            $batch_year = '';
            $batch_number = '';
            $exam_name = '';
        } catch (\Exception $e) {
            $pdo->rollBack();
            $error_msg = "Failed to record exam grades: " . htmlspecialchars($e->getMessage());
        }
    }
}

// Generate CSRF token if not set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$page_title = 'Bulk Grading System - Student Management System';
ob_start();
?>
    <style>
        .grading-card {
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

        .table-custom {
            margin-bottom: 0;
            vertical-align: middle;
        }
        .table-custom th {
            background-color: var(--bg-main) !important;
            color: var(--text-secondary);
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.8rem;
            letter-spacing: 0.5px;
            padding: 1rem;
            border-bottom: 1px solid var(--border-color) !important;
        }
        .table-custom td {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.95rem;
        }
    </style>
<?php
$extra_css = ob_get_clean();
include 'header.php';
?>

    <!-- Main Container -->
    <div class="container pb-5">
        <div class="row justify-content-center">
            <div class="col-lg-10">

                <?php if ($success_msg !== ''): ?>
                    <div class="alert alert-custom-success d-flex align-items-center mb-4" role="alert">
                        <i class="bi bi-check-circle-fill me-2"></i>
                        <div><?php echo $success_msg; ?></div>
                    </div>
                <?php endif; ?>

                <?php if ($error_msg !== ''): ?>
                    <div class="alert alert-custom-error d-flex align-items-center mb-4" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <div><?php echo $error_msg; ?></div>
                    </div>
                <?php endif; ?>

                <!-- Top batch parameters filter form -->
                <div class="grading-card">
                    <div class="card-header-custom">
                        <h4 class="mb-1 fw-bold"><i class="bi bi-search me-2 text-primary"></i>1. Select Student Batch & Exam Details</h4>
                        <p class="text-muted mb-0 small">Load a student batch by Course and Batch Year, and define the exam metadata.</p>
                    </div>
                    <div class="p-4">
                        <form action="bulk_grading.php" method="GET" class="needs-validation" novalidate>
                            <div class="row g-3">
                                <div class="col-md-2">
                                    <label for="course_code" class="form-label required">Course Code</label>
                                    <select class="form-select w-100" id="course_code" name="course_code" required>
                                        <option value="" disabled selected>Select Course</option>
                                        <option value="IN" <?php echo ($course_code === 'IN') ? 'selected' : ''; ?>>IN - Individual</option>
                                        <option value="AP" <?php echo ($course_code === 'AP') ? 'selected' : ''; ?>>AP - Application Programming</option>
                                        <option value="CGD" <?php echo ($course_code === 'CGD') ? 'selected' : ''; ?>>CGD - Computer Graphic Designer</option>
                                        <option value="PRE" <?php echo ($course_code === 'PRE') ? 'selected' : ''; ?>>PRE - Pre School Teacher Training</option>
                                        <option value="ICT" <?php echo ($course_code === 'ICT') ? 'selected' : ''; ?>>ICT - ICT Technician</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label for="batch_year" class="form-label required">Batch Year</label>
                                    <input type="text" class="form-control w-100" id="batch_year" name="batch_year" maxlength="2" pattern="\d{2}" placeholder="e.g. 26" required value="<?php echo htmlspecialchars($batch_year); ?>">
                                </div>
                                <div class="col-md-2">
                                    <label for="batch_number" class="form-label required">Batch No</label>
                                    <input type="text" class="form-control w-100" id="batch_number" name="batch_number" maxlength="3" pattern="\d{3}" placeholder="e.g. 004" required value="<?php echo htmlspecialchars($batch_number); ?>">
                                </div>
                                <div class="col-md-3">
                                    <label for="exam_name" class="form-label required">Exam Name</label>
                                    <input type="text" class="form-control w-100" id="exam_name" name="exam_name" required placeholder="e.g. Final Practical" value="<?php echo htmlspecialchars($exam_name); ?>">
                                </div>
                                <div class="col-md-3">
                                    <label for="exam_date" class="form-label required">Exam Date</label>
                                    <input type="date" class="form-control w-100" id="exam_date" name="exam_date" required value="<?php echo htmlspecialchars($exam_date); ?>">
                                </div>
                            </div>
                            <div class="mt-3 text-end">
                                <button type="submit" class="btn btn-accent px-4 py-2">
                                    <i class="bi bi-people-fill me-1"></i>Load Students
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Bulk Grading List Form -->
                <?php if ($_SERVER['REQUEST_METHOD'] === 'GET' && $course_code !== '' && $batch_year !== '' && $batch_number !== ''): ?>
                    <div class="grading-card mt-4">
                        <div class="card-header-custom d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div>
                                <h4 class="mb-1 fw-bold"><i class="bi bi-card-checklist me-2 text-primary"></i>2. Input Grades for Batch</h4>
                                <p class="text-muted mb-0 small">Course: <strong><?php echo htmlspecialchars($course_code); ?></strong> | Batch Year: <strong>20<?php echo htmlspecialchars($batch_year); ?></strong> | Batch No: <strong><?php echo htmlspecialchars($batch_number); ?></strong></p>
                            </div>
                            <span class="badge bg-primary px-3 py-2 rounded"><?php echo count($students); ?> Students Found</span>
                        </div>
                        <div class="p-0">
                            <?php if (empty($students)): ?>
                                <div class="p-5 text-center">
                                    <i class="bi bi-people fs-1 text-secondary mb-3 d-block"></i>
                                    <h5 class="fw-bold">No students registered in this batch.</h5>
                                    <p class="text-muted small mb-0">Select another batch criteria or add students first.</p>
                                </div>
                            <?php else: ?>
                                <form action="bulk_grading.php" method="POST" class="needs-validation" novalidate>
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="action" value="submit_bulk_grades">
                                    
                                    <!-- Pass filters as parameters to POST -->
                                    <input type="hidden" name="course_code" value="<?php echo htmlspecialchars($course_code); ?>">
                                    <input type="hidden" name="batch_year" value="<?php echo htmlspecialchars($batch_year); ?>">
                                    <input type="hidden" name="batch_number" value="<?php echo htmlspecialchars($batch_number); ?>">
                                    <input type="hidden" name="exam_name" value="<?php echo htmlspecialchars($exam_name); ?>">
                                    <input type="hidden" name="exam_date" value="<?php echo htmlspecialchars($exam_date); ?>">

                                    <div class="table-responsive">
                                        <table class="table table-custom table-striped table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Index Number</th>
                                                    <th>Student Name</th>
                                                    <th style="width: 200px;">Status</th>
                                                    <th style="width: 150px;">Mark (Optional)</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($students as $s): ?>
                                                    <tr>
                                                        <td class="fw-semibold text-primary">
                                                            <input type="hidden" name="student_ids[]" value="<?php echo $s['id']; ?>">
                                                            <?php echo htmlspecialchars($s['index_number']); ?>
                                                        </td>
                                                        <td class="text-dark"><?php echo htmlspecialchars($s['name']); ?></td>
                                                        <td>
                                                            <select class="form-select form-select-sm w-100" name="status[<?php echo $s['id']; ?>]" required>
                                                                <option value="Pass">Pass</option>
                                                                <option value="Fail">Fail</option>
                                                                <option value="Pending" selected>Pending</option>
                                                            </select>
                                                        </td>
                                                        <td>
                                                            <input type="number" class="form-control form-control-sm w-100" name="mark[<?php echo $s['id']; ?>]" min="0" max="100" step="0.01" placeholder="e.g. 85.50">
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="p-4 border-top border-secondary border-opacity-10 text-end">
                                        <button type="submit" class="btn btn-accent px-5 py-2.5">
                                            <i class="bi bi-save-fill me-1"></i>Submit Batch Results
                                        </button>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

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
