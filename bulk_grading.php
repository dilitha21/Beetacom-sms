<?php
require_once 'auth_check.php';
require_once 'db_connect.php';

$success_msg = '';
$error_msg = '';

$course_code = trim($_GET['course_code'] ?? '');
$batch_year = trim($_GET['batch_year'] ?? '');
$exam_name = trim($_GET['exam_name'] ?? '');
$exam_date = trim($_GET['exam_date'] ?? date('Y-m-d'));

$students = [];

// Load students matching the batch and course
if ($course_code !== '' && $batch_year !== '') {
    try {
        $stmt = $pdo->prepare("SELECT id, name, index_number FROM students WHERE course_code = :course_code AND batch_year = :batch_year ORDER BY index_number ASC");
        $stmt->execute([
            ':course_code' => $course_code,
            ':batch_year'  => $batch_year
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

$is_htmx = isset($_SERVER['HTTP_HX_REQUEST']);
if (!$is_htmx):
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Grading System - Student Management System</title>
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

        input, select, textarea {
            background-color: var(--bg-surface);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
            padding: 10px;
            border-radius: 8px;
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
        .grading-card {
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

        .btn-muted-outline {
            background-color: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
            border-radius: 8px;
            padding: 0.75rem 2rem;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        .btn-muted-outline:hover {
            background-color: var(--bg-sidebar);
            color: var(--text-primary);
        }

        .table-custom {
            margin-bottom: 0;
            color: var(--text-primary);
        }
        .table-custom th {
            background-color: var(--bg-sidebar);
            border-bottom: 1px solid var(--border-color);
            color: var(--text-secondary);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 600;
            padding: 1rem 1.25rem;
        }
        .table-custom td {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
            font-size: 0.95rem;
        }

        .alert-custom-success {
            background-color: #d1fae5;
            border: 1px solid rgba(16, 185, 129, 0.25);
            color: #10b981;
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
                        <a class="nav-link active" href="bulk_grading.php" hx-get="bulk_grading.php" hx-target="#main-content" hx-push-url="true" hx-swap="innerHTML transition:true"><i class="bi bi-journal-plus me-1"></i>Grades</a>
                    </li>
                </ul>
                <div class="d-flex align-items-center gap-3">
                    <a href="profile.php" class="nav-link small" hx-get="profile.php" hx-target="#main-content" hx-push-url="true" hx-swap="innerHTML transition:true"><i class="bi bi-gear-fill me-1"></i>My Profile</a>
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
                                <div class="col-md-3">
                                    <label for="course_code" class="form-label required">Course Code</label>
                                    <select class="form-select w-100" id="course_code" name="course_code" required>
                                        <option value="" disabled selected>Select Course</option>
                                        <option value="IN" <?php echo ($course_code === 'IN') ? 'selected' : ''; ?>>IN (Individual)</option>
                                        <option value="AP" <?php echo ($course_code === 'AP') ? 'selected' : ''; ?>>AP (App Assistant)</option>
                                        <option value="CGD" <?php echo ($course_code === 'CGD') ? 'selected' : ''; ?>>CGD (Graphic Design)</option>
                                        <option value="PRE" <?php echo ($course_code === 'PRE') ? 'selected' : ''; ?>>PRE (Pre-School)</option>
                                        <option value="ICT" <?php echo ($course_code === 'ICT') ? 'selected' : ''; ?>>ICT (ICT Technician)</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label for="batch_year" class="form-label required">Batch Year</label>
                                    <select class="form-select w-100" id="batch_year" name="batch_year" required>
                                        <option value="" disabled selected>Select Year</option>
                                        <?php
                                        $current_short = (int)date('y');
                                        $start_year = 24;
                                        $end_year = max($current_short + 10, 30);
                                        for ($y = $start_year; $y <= $end_year; $y++) {
                                            $padded_y = str_pad($y, 2, '0', STR_PAD_LEFT);
                                            $selected = ($batch_year === $padded_y) ? 'selected' : '';
                                            echo "<option value=\"$padded_y\" $selected>$padded_y</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
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
                <?php if ($_SERVER['REQUEST_METHOD'] === 'GET' && $course_code !== '' && $batch_year !== ''): ?>
                    <div class="grading-card mt-4">
                        <div class="card-header-custom d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div>
                                <h4 class="mb-1 fw-bold"><i class="bi bi-card-checklist me-2 text-primary"></i>2. Input Grades for Batch</h4>
                                <p class="text-muted mb-0 small">Course: <strong><?php echo htmlspecialchars($course_code); ?></strong> | Batch Year: <strong>20<?php echo htmlspecialchars($batch_year); ?></strong></p>
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

<?php if (!$is_htmx): ?>
</body>
</html>
<?php endif; ?>
