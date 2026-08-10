<?php
/**
 * edit_student.php
 * Student registration info update form and processor.
 */

// 1. Ensure user is logged in
require_once 'auth_check.php';
require_once 'db_connect.php';

$success_msg = '';
$error_msg = '';

// Parse and validate student ID
$student_id = filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT);
if (!$student_id) {
    header("Location: dashboard.php");
    exit();
}

// Fetch current student details
try {
    $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch();
    if (!$student) {
        header("Location: dashboard.php");
        exit();
    }
} catch (\PDOException $e) {
    die("Database error: " . htmlspecialchars($e->getMessage()));
}

// Handle POST request for editing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Protection
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        $error_msg = 'Invalid session token. Please refresh and try again.';
    } else {
        // Collect & Sanitize textual/date inputs
        $course_code        = trim($_POST['course_code'] ?? '');
        $batch_year         = trim($_POST['batch_year'] ?? '');
        $batch_number       = ''; // Batch number is no longer collected or used
        $is_nvq             = trim($_POST['is_nvq'] ?? '');
        $sequence_number    = trim($_POST['sequence_number'] ?? '');
        $registration_date  = trim($_POST['registration_date'] ?? '');
        $name               = trim($_POST['name'] ?? '');
        $address            = trim($_POST['address'] ?? '');
        $contact_no         = trim($_POST['contact_no'] ?? '');
        $nic                = trim($_POST['nic'] ?? '');
        $dob                = trim($_POST['dob'] ?? '');
        $gender             = trim($_POST['gender'] ?? '');
        $guardian_name      = trim($_POST['guardian_name'] ?? '');
        $guardian_details   = trim($_POST['guardian_details'] ?? '');

        // Build index_number based on course_code (omitting batch number)
        if ($course_code === 'IN' || $course_code === 'KIDS') {
            $is_nvq = null;
            $index_number = $course_code . '/' . $batch_year . '/' . $sequence_number;
        } else {
            $index_number = $course_code . '/' . $batch_year . '/' . $is_nvq . '/' . $sequence_number;
        }

        // Educational Qualifications (boolean checkboxes)
        $gce_al_science     = isset($_POST['gce_al_science']) ? 1 : 0;
        $gce_al_maths       = isset($_POST['gce_al_maths']) ? 1 : 0;
        $gce_al_commerce    = isset($_POST['gce_al_commerce']) ? 1 : 0;
        $gce_al_art         = isset($_POST['gce_al_art']) ? 1 : 0;
        $gce_al_tech        = isset($_POST['gce_al_tech']) ? 1 : 0;
        $gce_ol             = isset($_POST['gce_ol']) ? 1 : 0;
        $other_edu          = isset($_POST['other_edu']) ? 1 : 0;
        $kids_grade         = isset($_POST['kids_grade']) ? 1 : 0;

        // NVQ Courses (boolean checkboxes)
        $ict_tech           = isset($_POST['ict_tech']) ? 1 : 0;
        $computer_app_ast   = isset($_POST['computer_app_ast']) ? 1 : 0;
        $graphic_designer   = isset($_POST['graphic_designer']) ? 1 : 0;
        $pre_school         = isset($_POST['pre_school']) ? 1 : 0;

        // Non-NVQ Courses (boolean checkboxes)
        $non_nvq_app_ast    = isset($_POST['non_nvq_app_ast']) ? 1 : 0;
        $non_nvq_graphic    = isset($_POST['non_nvq_graphic']) ? 1 : 0;
        $hr                 = isset($_POST['hr']) ? 1 : 0;
        $english            = isset($_POST['english']) ? 1 : 0;
        $web_design         = isset($_POST['web_design']) ? 1 : 0;
        $beetaa_kids        = isset($_POST['beetaa_kids']) ? 1 : 0;
        $other_course       = isset($_POST['other_course']) ? 1 : 0;

        // Default registration date to today if empty
        if (empty($registration_date)) {
            $registration_date = date('Y-m-d');
        }

        // Validation checks
        if (empty($course_code) || empty($batch_year) || empty($sequence_number) || 
            ($course_code !== 'IN' && $course_code !== 'KIDS' && empty($is_nvq)) || 
            empty($registration_date) || empty($name) || empty($address) || 
            empty($contact_no) || empty($nic) || empty($dob) || empty($gender)) {
            $error_msg = 'Please fill out all required fields.';
        } elseif (!preg_match('/^[0-9]{12}$/', $nic)) {
            $error_msg = 'NIC must be exactly 12 digits.';
        } elseif (!preg_match('/^[0-9]{10}$/', $contact_no)) {
            $error_msg = 'Contact number must be exactly 10 digits.';
        }

        if ($error_msg === '') {
            // Process update
            try {
                $sql = "UPDATE students SET 
                            index_number = :index_number, 
                            course_code = :course_code, 
                            batch_year = :batch_year, 
                            batch_number = :batch_number, 
                            is_nvq = :is_nvq, 
                            sequence_number = :sequence_number,
                            registration_date = :registration_date, 
                            name = :name, 
                            address = :address, 
                            contact_no = :contact_no, 
                            nic = :nic, 
                            dob = :dob, 
                            gender = :gender, 
                            guardian_name = :guardian_name, 
                            guardian_details = :guardian_details,
                            gce_al_science = :gce_al_science, 
                            gce_al_maths = :gce_al_maths, 
                            gce_al_commerce = :gce_al_commerce, 
                            gce_al_art = :gce_al_art, 
                            gce_al_tech = :gce_al_tech, 
                            gce_ol = :gce_ol, 
                            other_edu = :other_edu, 
                            kids_grade = :kids_grade,
                            ict_tech = :ict_tech, 
                            computer_app_ast = :computer_app_ast, 
                            graphic_designer = :graphic_designer, 
                            pre_school = :pre_school,
                            non_nvq_app_ast = :non_nvq_app_ast, 
                            non_nvq_graphic = :non_nvq_graphic, 
                            hr = :hr, 
                            english = :english, 
                            web_design = :web_design, 
                            beetaa_kids = :beetaa_kids, 
                            other_course = :other_course
                        WHERE id = :id";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':index_number'       => $index_number,
                    ':course_code'        => $course_code,
                    ':batch_year'         => $batch_year,
                    ':batch_number'       => $batch_number,
                    ':is_nvq'             => !empty($is_nvq) ? $is_nvq : null,
                    ':sequence_number'    => !empty($sequence_number) ? (int)$sequence_number : null,
                    ':registration_date'  => $registration_date,
                    ':name'               => $name,
                    ':address'            => $address,
                    ':contact_no'         => $contact_no,
                    ':nic'                => $nic,
                    ':dob'                => $dob,
                    ':gender'             => $gender,
                    ':guardian_name'      => !empty($guardian_name) ? $guardian_name : null,
                    ':guardian_details'   => !empty($guardian_details) ? $guardian_details : null,
                    ':gce_al_science'     => $gce_al_science,
                    ':gce_al_maths'       => $gce_al_maths,
                    ':gce_al_commerce'    => $gce_al_commerce,
                    ':gce_al_art'         => $gce_al_art,
                    ':gce_al_tech'        => $gce_al_tech,
                    ':gce_ol'             => $gce_ol,
                    ':other_edu'          => $other_edu,
                    ':kids_grade'         => $kids_grade,
                    ':ict_tech'           => $ict_tech,
                    ':computer_app_ast'   => $computer_app_ast,
                    ':graphic_designer'   => $graphic_designer,
                    ':pre_school'         => $pre_school,
                    ':non_nvq_app_ast'    => $non_nvq_app_ast,
                    ':non_nvq_graphic'    => $non_nvq_graphic,
                    ':hr'                 => $hr,
                    ':english'            => $english,
                    ':web_design'         => $web_design,
                    ':beetaa_kids'        => $beetaa_kids,
                    ':other_course'       => $other_course,
                    ':id'                 => $student_id
                ]);

                header("Location: student_profile.php?id=$student_id&success=8");
                exit();
            } catch (\PDOException $e) {
                if (isset($e->errorInfo[1]) && $e->errorInfo[1] == 1062) {
                    $error_msg = '<div class="alert alert-danger">Error: This Index Number already exists in the system.</div>';
                } else {
                    $error_msg = '<div class="alert alert-danger">' . htmlspecialchars($e->getMessage()) . '</div>';
                }
            }
        }
    }
}

// Generate CSRF token if not set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Set form values on initial load or from $_POST on submit failure
$f_course_code = htmlspecialchars($_POST['course_code'] ?? $student['course_code'] ?? '');
$f_batch_year = htmlspecialchars($_POST['batch_year'] ?? $student['batch_year'] ?? '');
$f_is_nvq = htmlspecialchars($_POST['is_nvq'] ?? $student['is_nvq'] ?? '');
$f_sequence_number = htmlspecialchars($_POST['sequence_number'] ?? $student['sequence_number'] ?? '');
$f_registration_date = htmlspecialchars($_POST['registration_date'] ?? $student['registration_date'] ?? '');
$f_name = htmlspecialchars($_POST['name'] ?? $student['name'] ?? '');
$f_address = htmlspecialchars($_POST['address'] ?? $student['address'] ?? '');
$f_contact_no = htmlspecialchars($_POST['contact_no'] ?? $student['contact_no'] ?? '');
$f_nic = htmlspecialchars($_POST['nic'] ?? $student['nic'] ?? '');
$f_dob = htmlspecialchars($_POST['dob'] ?? $student['dob'] ?? '');
$f_gender = htmlspecialchars($_POST['gender'] ?? $student['gender'] ?? '');
$f_guardian_name = htmlspecialchars($_POST['guardian_name'] ?? $student['guardian_name'] ?? '');
$f_guardian_details = htmlspecialchars($_POST['guardian_details'] ?? $student['guardian_details'] ?? '');

$f_gce_al_science = isset($_POST['csrf_token']) ? isset($_POST['gce_al_science']) : ($student['gce_al_science'] ?? 0);
$f_gce_al_maths = isset($_POST['csrf_token']) ? isset($_POST['gce_al_maths']) : ($student['gce_al_maths'] ?? 0);
$f_gce_al_commerce = isset($_POST['csrf_token']) ? isset($_POST['gce_al_commerce']) : ($student['gce_al_commerce'] ?? 0);
$f_gce_al_art = isset($_POST['csrf_token']) ? isset($_POST['gce_al_art']) : ($student['gce_al_art'] ?? 0);
$f_gce_al_tech = isset($_POST['csrf_token']) ? isset($_POST['gce_al_tech']) : ($student['gce_al_tech'] ?? 0);
$f_gce_ol = isset($_POST['csrf_token']) ? isset($_POST['gce_ol']) : ($student['gce_ol'] ?? 0);
$f_other_edu = isset($_POST['csrf_token']) ? isset($_POST['other_edu']) : ($student['other_edu'] ?? 0);
$f_kids_grade = isset($_POST['csrf_token']) ? isset($_POST['kids_grade']) : ($student['kids_grade'] ?? 0);

$f_ict_tech = isset($_POST['csrf_token']) ? isset($_POST['ict_tech']) : ($student['ict_tech'] ?? 0);
$f_computer_app_ast = isset($_POST['csrf_token']) ? isset($_POST['computer_app_ast']) : ($student['computer_app_ast'] ?? 0);
$f_graphic_designer = isset($_POST['csrf_token']) ? isset($_POST['graphic_designer']) : ($student['graphic_designer'] ?? 0);
$f_pre_school = isset($_POST['csrf_token']) ? isset($_POST['pre_school']) : ($student['pre_school'] ?? 0);

$f_non_nvq_app_ast = isset($_POST['csrf_token']) ? isset($_POST['non_nvq_app_ast']) : ($student['non_nvq_app_ast'] ?? 0);
$f_non_nvq_graphic = isset($_POST['csrf_token']) ? isset($_POST['non_nvq_graphic']) : ($student['non_nvq_graphic'] ?? 0);
$f_hr = isset($_POST['csrf_token']) ? isset($_POST['hr']) : ($student['hr'] ?? 0);
$f_english = isset($_POST['csrf_token']) ? isset($_POST['english']) : ($student['english'] ?? 0);
$f_web_design = isset($_POST['csrf_token']) ? isset($_POST['web_design']) : ($student['web_design'] ?? 0);
$f_beetaa_kids = isset($_POST['csrf_token']) ? isset($_POST['beetaa_kids']) : ($student['beetaa_kids'] ?? 0);
$f_other_course = isset($_POST['csrf_token']) ? isset($_POST['other_course']) : ($student['other_course'] ?? 0);

$page_title = 'Update Student - Student Registration System';
ob_start();
?>
    <style>
        .form-card {
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

        .form-section-title {
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

        .form-label {
            font-size: 0.85rem;
            font-weight: 500;
            color: var(--text-muted);
            margin-bottom: 0.4rem;
        }

        .form-label.required::after {
            content: " *";
            color: var(--accent-red);
        }

        .form-control, .form-select {
            padding: 0.6rem 0.9rem;
            font-size: 0.9rem;
            border-radius: 6px;
            border: 1px solid var(--border-color);
            background-color: var(--bg-surface);
            color: var(--text-main);
            transition: all 0.25s ease-in-out;
        }

        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: var(--accent-color);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
            background-color: var(--bg-surface);
            color: var(--text-main);
        }

        .form-control::placeholder {
            color: #94a3b8;
            opacity: 0.8;
        }

        /* Customize checkboxes styles */
        .form-check-input {
            width: 1.15em;
            height: 1.15em;
            margin-top: 0.15em;
            background-color: var(--bg-surface);
            border: 1px solid var(--border-color);
        }

        .form-check-input:checked {
            background-color: var(--accent-color);
            border-color: var(--accent-color);
        }

        .form-check-label {
            font-size: 0.88rem;
            color: var(--text-main);
            user-select: none;
            cursor: pointer;
        }

        .btn-accent {
            background-color: var(--accent-color);
            color: #ffffff;
            border: none;
            padding: 0.65rem 1.5rem;
            font-weight: 500;
            border-radius: 6px;
            transition: all 0.2s ease;
        }

        .btn-accent:hover {
            background-color: var(--accent-color-hover);
            color: #ffffff;
            transform: translateY(-1px);
        }

        .btn-muted-outline {
            border: 1px solid var(--border-color);
            background-color: transparent;
            color: var(--text-muted);
            padding: 0.65rem 1.5rem;
            font-weight: 500;
            border-radius: 6px;
            transition: all 0.2s ease;
        }

        .btn-muted-outline:hover {
            background-color: rgba(0, 0, 0, 0.02);
            color: var(--text-main);
        }
    </style>
<?php
$extra_css = ob_get_clean();
include 'header.php';
?>

    <div class="container py-4">
        <!-- Back Navigation link -->
        <div class="mb-3">
            <a href="student_profile.php?id=<?php echo $student_id; ?>" class="text-decoration-none text-muted small">
                <i class="bi bi-arrow-left me-1"></i>Back to Student Profile
            </a>
        </div>

        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
            <h2 class="mb-0 fw-bold text-dark"><i class="bi bi-pencil-square me-2 text-primary"></i>Update Student Registration Info</h2>
        </div>

        <?php if ($error_msg !== ''): ?>
            <div class="mb-4">
                <?php echo $error_msg; ?>
            </div>
        <?php endif; ?>

        <div class="form-card">
            <div class="card-header-custom">
                <h5 class="mb-1 fw-bold text-dark">Registration Form</h5>
                <p class="text-muted mb-0 small">Update the registration details, educational qualifications, or courses for <strong><?php echo htmlspecialchars($f_name); ?></strong>.</p>
            </div>
            <div class="p-4 p-md-5">
                <form action="edit_student.php?id=<?php echo $student_id; ?>" method="POST" id="studentForm" class="needs-validation" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                    <!-- SECTION 1: REGISTRATION DETAILS -->
                    <div class="form-section-title">
                        <i class="bi bi-file-earmark-text"></i> Registration Information
                    </div>
                    
                    <!-- Index Number Builder Grid -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label for="course_code" class="form-label required">Course Code</label>
                            <select class="form-select" id="course_code" name="course_code" required>
                                <option value="" disabled>Select Course</option>
                                <option value="IN" <?php echo ($f_course_code === 'IN') ? 'selected' : ''; ?>>IN - Individual</option>
                                <option value="KIDS" <?php echo ($f_course_code === 'KIDS') ? 'selected' : ''; ?>>KIDS - KIDS Course</option>
                                <option value="AP" <?php echo ($f_course_code === 'AP') ? 'selected' : ''; ?>>AP - Computer Application Assistant (LV3)</option>
                                <option value="CGD" <?php echo ($f_course_code === 'CGD') ? 'selected' : ''; ?>>CGD - Computer Graphic Designer</option>
                                <option value="PRE" <?php echo ($f_course_code === 'PRE') ? 'selected' : ''; ?>>PRE - Pre School Teacher Training</option>
                                <option value="ICT" <?php echo ($f_course_code === 'ICT') ? 'selected' : ''; ?>>ICT - ICT Technician (LV4)</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="batch_year" class="form-label required">Year</label>
                            <input type="text" name="batch_year" id="batch_year" maxlength="2" pattern="\d{2}" placeholder="26" required class="form-control" value="<?php echo $f_batch_year; ?>">
                        </div>
                        <div class="col-md-2" id="nvq_type_container">
                            <label for="is_nvq" class="form-label required">Type</label>
                            <select class="form-select" id="is_nvq" name="is_nvq" required>
                                <option value="" disabled <?php echo ($f_is_nvq === '') ? 'selected' : ''; ?>>Type</option>
                                <option value="N" <?php echo ($f_is_nvq === 'N') ? 'selected' : ''; ?>>N (NVQ)</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="sequence_number" class="form-label required">Index No</label>
                            <input type="number" class="form-control" id="sequence_number" name="sequence_number" required placeholder="3782" min="1" value="<?php echo $f_sequence_number; ?>">
                        </div>
                    </div>

                    <!-- Generated Index Number Preview -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label for="index_number" class="form-label fw-bold">Generated Index Number Preview</label>
                            <input type="text" class="form-control fw-bold text-primary" id="index_number" name="index_number" readonly placeholder="Fill inputs to generate..." value="<?php echo htmlspecialchars($student['index_number']); ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="registration_date" class="form-label required">Registration Date</label>
                            <input type="date" name="registration_date" id="registration_date" required class="form-control" value="<?php echo $f_registration_date; ?>">
                        </div>
                    </div>

                    <!-- SECTION 2: PERSONAL DETAILS -->
                    <div class="form-section-title">
                        <i class="bi bi-person-badge"></i> Personal Details
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6 mb-3">
                            <label for="name" class="form-label required">Full Name</label>
                            <input type="text" name="name" id="name" required placeholder="Enter student's full name" class="form-control" value="<?php echo $f_name; ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="address" class="form-label required">Address</label>
                            <input type="text" name="address" id="address" required placeholder="Home / Mailing Address" class="form-control" value="<?php echo $f_address; ?>">
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-4 mb-3">
                            <label for="contact_no" class="form-label required">Contact Number</label>
                            <input type="text" name="contact_no" id="contact_no" required placeholder="10-digit Mobile (e.g. 0771234567)" maxlength="10" pattern="\d{10}" class="form-control" value="<?php echo $f_contact_no; ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="nic" class="form-label required">NIC (National ID)</label>
                            <input type="text" name="nic" id="nic" required placeholder="12-digit NIC" maxlength="12" pattern="\d{12}" class="form-control" value="<?php echo $f_nic; ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="dob" class="form-label required">Date of Birth</label>
                            <input type="date" name="dob" id="dob" required class="form-control" value="<?php echo $f_dob; ?>">
                        </div>
                    </div>
                    <div class="row mb-4">
                        <div class="col-md-4 mb-3">
                            <label class="form-label required">Gender</label>
                            <div class="d-flex gap-4 mt-2">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="gender" id="gender_male" value="Male" required <?php echo ($f_gender === 'Male') ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="gender_male">Male</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="gender" id="gender_female" value="Female" required <?php echo ($f_gender === 'Female') ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="gender_female">Female</label>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="guardian_name" class="form-label">Guardian Name (Optional)</label>
                            <input type="text" name="guardian_name" id="guardian_name" placeholder="Parent / Guardian Name" class="form-control" value="<?php echo $f_guardian_name; ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="guardian_details" class="form-label">Guardian Details (Optional)</label>
                            <input type="text" name="guardian_details" id="guardian_details" placeholder="Guardian Phone / Contact" class="form-control" value="<?php echo $f_guardian_details; ?>">
                        </div>
                    </div>

                    <!-- SECTION 3: EDUCATIONAL QUALIFICATIONS -->
                    <div class="form-section-title">
                        <i class="bi bi-mortarboard"></i> Educational Qualifications
                    </div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="gce_ol" id="gce_ol" value="1" <?php echo $f_gce_ol ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="gce_ol">G.C.E. O/L</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="gce_al_science" id="gce_al_science" value="1" <?php echo $f_gce_al_science ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="gce_al_science">G.C.E. A/L - Science</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="gce_al_maths" id="gce_al_maths" value="1" <?php echo $f_gce_al_maths ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="gce_al_maths">G.C.E. A/L - Mathematics</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="gce_al_commerce" id="gce_al_commerce" value="1" <?php echo $f_gce_al_commerce ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="gce_al_commerce">G.C.E. A/L - Commerce</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="gce_al_art" id="gce_al_art" value="1" <?php echo $f_gce_al_art ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="gce_al_art">G.C.E. A/L - Arts</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="gce_al_tech" id="gce_al_tech" value="1" <?php echo $f_gce_al_tech ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="gce_al_tech">G.C.E. A/L - Technology</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="kids_grade" id="kids_grade" value="1" <?php echo $f_kids_grade ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="kids_grade">Kids School Grade</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="other_edu" id="other_edu" value="1" <?php echo $f_other_edu ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="other_edu">Other Qualifications</label>
                            </div>
                        </div>
                    </div>

                    <!-- SECTION 4: NVQ COURSES -->
                    <div class="form-section-title">
                        <i class="bi bi-award"></i> NVQ Course Enrolments
                    </div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6 col-lg-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="ict_tech" id="ict_tech" value="1" <?php echo $f_ict_tech ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="ict_tech">ICT Technician (NVQ)</label>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="computer_app_ast" id="computer_app_ast" value="1" <?php echo $f_computer_app_ast ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="computer_app_ast">Computer Application Assistant (NVQ)</label>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="graphic_designer" id="graphic_designer" value="1" <?php echo $f_graphic_designer ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="graphic_designer">Graphic Designer (NVQ)</label>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="pre_school" id="pre_school" value="1" <?php echo $f_pre_school ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="pre_school">Pre-School Teacher Training (NVQ)</label>
                            </div>
                        </div>
                    </div>

                    <!-- SECTION 5: NON-NVQ COURSES -->
                    <div class="form-section-title">
                        <i class="bi bi-book"></i> Non-NVQ Course Enrolments
                    </div>
                    <div class="row g-3 mb-5">
                        <div class="col-md-6 col-lg-4 col-xl-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="non_nvq_app_ast" id="non_nvq_app_ast" value="1" <?php echo $f_non_nvq_app_ast ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="non_nvq_app_ast">App Assistant (Non-NVQ)</label>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-4 col-xl-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="non_nvq_graphic" id="non_nvq_graphic" value="1" <?php echo $f_non_nvq_graphic ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="non_nvq_graphic">Graphic Design (Non-NVQ)</label>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-4 col-xl-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="hr" id="hr" value="1" <?php echo $f_hr ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="hr">Human Resources Management</label>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-4 col-xl-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="english" id="english" value="1" <?php echo $f_english ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="english">English Language Training</label>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-4 col-xl-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="web_design" id="web_design" value="1" <?php echo $f_web_design ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="web_design">Web Designing</label>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-4 col-xl-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="beetaa_kids" id="beetaa_kids" value="1" <?php echo $f_beetaa_kids ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="beetaa_kids">Beetaa Kids Course</label>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-4 col-xl-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="other_course" id="other_course" value="1" <?php echo $f_other_course ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="other_course">Other Special Course</label>
                            </div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="border-top pt-4 d-flex justify-content-end gap-3">
                        <a href="student_profile.php?id=<?php echo $student_id; ?>" class="btn btn-muted-outline">Cancel Changes</a>
                        <button type="submit" class="btn btn-accent px-4">Update Student Info</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5 Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            // Elements for Index Number Builder
            const courseCodeSelect = document.getElementById('course_code');
            const batchYearInput = document.getElementById('batch_year');
            const isNvqSelect = document.getElementById('is_nvq');
            const sequenceNumberInput = document.getElementById('sequence_number');
            const indexNumberInput = document.getElementById('index_number');
            const nvqTypeContainer = document.getElementById('nvq_type_container');

            // Live Index Number Generator
            const generateIndexNumber = () => {
                const courseCode = courseCodeSelect.value || '';
                const batchYear = batchYearInput.value || '';
                const isNvq = isNvqSelect.value || '';
                const sequenceNumber = sequenceNumberInput.value || '';

                // If critical parameters are empty, leave preview blank
                if (!courseCode || !batchYear || !sequenceNumber || (courseCode !== 'IN' && courseCode !== 'KIDS' && !isNvq)) {
                    indexNumberInput.value = '';
                    return;
                }

                if (courseCode === 'IN' || courseCode === 'KIDS') {
                    indexNumberInput.value = `${courseCode}/${batchYear}/${sequenceNumber}`;
                } else {
                    indexNumberInput.value = `${courseCode}/${batchYear}/${isNvq}/${sequenceNumber}`;
                }
            };

            const handleCourseCodeChange = () => {
                const courseCode = courseCodeSelect.value || '';

                if (courseCode === 'IN' || courseCode === 'KIDS') {
                    // Disable and reset is_nvq
                    isNvqSelect.disabled = true;
                    isNvqSelect.removeAttribute('required');
                    isNvqSelect.value = '';
                    nvqTypeContainer.style.display = 'none';
                } else {
                    // Enable is_nvq
                    isNvqSelect.disabled = false;
                    isNvqSelect.setAttribute('required', true);
                    nvqTypeContainer.style.display = '';
                }
                generateIndexNumber();
            };

            // Listen to inputs
            [courseCodeSelect, batchYearInput, isNvqSelect, sequenceNumberInput].forEach(elem => {
                if (elem) {
                    elem.addEventListener('input', generateIndexNumber);
                    elem.addEventListener('change', generateIndexNumber);
                }
            });

            courseCodeSelect.addEventListener('change', handleCourseCodeChange);

            // Initialize
            handleCourseCodeChange();
        });
    </script>

<?php include 'footer.php'; ?>
