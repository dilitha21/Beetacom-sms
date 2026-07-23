<?php
/**
 * add_student.php
 * Student registration form and processor.
 */

// 1. Ensure user is logged in
require_once 'auth_check.php';
require_once 'db_connect.php';

$success_msg = '';
$error_msg = '';

// Handle POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Protection
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        $error_msg = 'Invalid session token. Please refresh and try again.';
    } else {
        // Collect & Sanitize textual/date inputs
        $course_code        = trim($_POST['course_code'] ?? '');
        $batch_year         = trim($_POST['batch_year'] ?? '');
        $batch_number       = trim($_POST['batch_number'] ?? '');
        $is_nvq             = trim($_POST['is_nvq'] ?? '');
        $sequence_number    = trim($_POST['sequence_number'] ?? '');
        $is_historical      = isset($_POST['is_historical']) ? 1 : 0;
        $registration_date  = trim($_POST['registration_date'] ?? '');
        $name               = trim($_POST['name'] ?? '');
        $address            = trim($_POST['address'] ?? '');
        $contact_no         = trim($_POST['contact_no'] ?? '');
        $nic                = trim($_POST['nic'] ?? '');
        $dob                = trim($_POST['dob'] ?? '');
        $gender             = trim($_POST['gender'] ?? '');
        $guardian_name      = trim($_POST['guardian_name'] ?? '');
        $guardian_details   = trim($_POST['guardian_details'] ?? '');
        $added_by           = $_SESSION['user_id'] ?? null;

        // Build index_number based on course_code
        if ($course_code === 'IN') {
            $is_nvq = null;
            $index_number = $course_code . '/' . $batch_year . '/' . $batch_number . '/' . $sequence_number;
        } else {
            $index_number = $course_code . '/' . $batch_year . '/' . $batch_number . '/' . $is_nvq . '/' . $sequence_number;
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

        // Default registration date to today if not historical (for backend fallback security)
        if (!$is_historical) {
            $registration_date = date('Y-m-d');
        }

        // Validation checks
        if (empty($course_code) || empty($batch_year) || empty($batch_number) || empty($sequence_number) || ($course_code !== 'IN' && empty($is_nvq)) || 
            empty($registration_date) || empty($name) || empty($address) || 
            empty($contact_no) || empty($nic) || empty($dob) || empty($gender)) {
            $error_msg = 'Please fill out all required fields.';
        } elseif (!preg_match('/^[0-9]{12}$/', $nic)) {
            $error_msg = 'NIC must be exactly 12 digits.';
        } elseif (!preg_match('/^[0-9]{10}$/', $contact_no)) {
            $error_msg = 'Contact number must be exactly 10 digits.';
        }

        if ($error_msg === '') {
            // Process insertion
            try {
                $sql = "INSERT INTO students (
                            index_number, course_code, batch_year, batch_number, is_nvq, sequence_number,
                            is_historical, registration_date, name, address, contact_no, nic, dob, gender, 
                            guardian_name, guardian_details, added_by,
                            gce_al_science, gce_al_maths, gce_al_commerce, gce_al_art, gce_al_tech, gce_ol, other_edu, kids_grade,
                            ict_tech, computer_app_ast, graphic_designer, pre_school,
                            non_nvq_app_ast, non_nvq_graphic, hr, english, web_design, beetaa_kids, other_course
                        ) VALUES (
                            :index_number, :course_code, :batch_year, :batch_number, :is_nvq, :sequence_number,
                            :is_historical, :registration_date, :name, :address, :contact_no, :nic, :dob, :gender, 
                            :guardian_name, :guardian_details, :added_by,
                            :gce_al_science, :gce_al_maths, :gce_al_commerce, :gce_al_art, :gce_al_tech, :gce_ol, :other_edu, :kids_grade,
                            :ict_tech, :computer_app_ast, :graphic_designer, :pre_school,
                            :non_nvq_app_ast, :non_nvq_graphic, :hr, :english, :web_design, :beetaa_kids, :other_course
                        )";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':index_number'       => $index_number,
                    ':course_code'        => $course_code,
                    ':batch_year'         => $batch_year,
                    ':batch_number'       => $batch_number,
                    ':is_nvq'             => !empty($is_nvq) ? $is_nvq : null,
                    ':sequence_number'    => !empty($sequence_number) ? (int)$sequence_number : null,
                    ':is_historical'      => $is_historical,
                    ':registration_date'  => $registration_date,
                    ':name'               => $name,
                    ':address'            => $address,
                    ':contact_no'         => $contact_no,
                    ':nic'                => $nic,
                    ':dob'                => $dob,
                    ':gender'             => $gender,
                    ':guardian_name'      => !empty($guardian_name) ? $guardian_name : null,
                    ':guardian_details'   => !empty($guardian_details) ? $guardian_details : null,
                    ':added_by'           => $added_by,
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
                    ':other_course'       => $other_course
                ]);

                $student_id = $pdo->lastInsertId();
                header("Location: student_profile.php?id=$student_id&success=1");
                exit();
            } catch (\PDOException $e) {
                // Check if UNIQUE constraint was violated (MySQL error code 1062)
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

$page_title = 'Register Student - Student Registration System';
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

        .form-control[readonly] {
            background-color: var(--bg-sidebar);
            color: var(--text-secondary) !important;
            border-color: var(--border-color);
            cursor: not-allowed;
            opacity: 0.6;
        }

        .form-check-input {
            background-color: #ffffff;
            border: 2px solid #94a3b8;
            width: 1.25em;
            height: 1.25em;
            margin-top: 0.15em;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .form-check-input:hover {
            border-color: var(--accent-color);
        }
        .form-check-input:checked {
            background-color: var(--accent-color);
            border-color: var(--accent-color);
            box-shadow: 0 0 8px rgba(99, 102, 241, 0.4);
        }
        .form-check-input:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2);
        }
        .form-check-label {
            font-size: 0.9rem;
            color: var(--text-primary);
            cursor: pointer;
        }

        .form-switch .form-check-input {
            width: 2.25em !important;
            height: 1.25em !important;
            border-radius: 2em !important;
            background-position: left center;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='-4 -4 8 8'%3e%3ccircle r='3' fill='%2394a3b8'/%3e%3c/svg%3e") !important;
        }
        .form-switch .form-check-input:checked {
            background-position: right center;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='-4 -4 8 8'%3e%3ccircle r='3' fill='%23fff'/%3e%3c/svg%3e") !important;
        }

        /* Action Buttons */
        .btn-submit {
            background-color: var(--accent-color) !important;
            color: #ffffff !important;
            border: none !important;
            border-radius: 8px !important;
            padding: 0.75rem 2rem !important;
            font-weight: bold !important;
            cursor: pointer !important;
            transition: background-color 0.2s ease !important;
        }
        .btn-submit:hover {
            background-color: #4f46e5 !important;
            color: #ffffff !important;
        }

        .btn-reset {
            background-color: var(--bg-surface) !important;
            border: 1px solid var(--border-color) !important;
            color: var(--text-primary) !important;
            font-weight: 500 !important;
            padding: 0.75rem 2rem !important;
            border-radius: 8px !important;
            transition: all 0.2s ease !important;
        }
        .btn-reset:hover {
            background-color: var(--bg-sidebar) !important;
            color: var(--text-primary) !important;
        }

        /* Course category wrapper cards */
        .category-box {
            background-color: var(--bg-main);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1.25rem;
            height: 100%;
        }

        /* Alert stylings */
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
<?php
$extra_css = ob_get_clean();
include 'header.php';
?>

    <!-- Main Container -->
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-xl-10">

                <!-- Alert Messages -->
                <div id="alert-container">
                    <?php if (!empty($success_msg)): ?>
                        <div class="alert alert-custom-success d-flex align-items-center mb-4" role="alert">
                            <i class="bi bi-check-circle-fill me-2"></i>
                            <div><?php echo htmlspecialchars($success_msg); ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($error_msg)): ?>
                        <?php if (strpos($error_msg, 'alert-danger') !== false): ?>
                            <?php echo $error_msg; ?>
                        <?php else: ?>
                            <div class="alert alert-custom-error d-flex align-items-center mb-4" role="alert">
                                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                <div><?php echo htmlspecialchars($error_msg); ?></div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- Registration Card -->
                <div class="form-card">
                    <form action="add_student.php" method="POST" id="studentForm" class="needs-validation" novalidate>
                        <div class="card-header-custom d-flex justify-content-between align-items-center flex-wrap gap-3">
                            <div>
                                <h4 class="mb-1 fw-bold"><i class="bi bi-person-bounding-box me-2 text-primary"></i>Student Registration</h4>
                                <p class="text-muted mb-0 small">Enter student academic and personal profiles below</p>
                            </div>
                            
                            <!-- Toggle & Date Container -->
                            <div class="d-flex align-items-center gap-3">
                                <!-- Registration Date -->
                                <div class="d-flex align-items-center gap-2 px-3 py-2 rounded-3" style="background-color: var(--bg-main); border: 1px solid var(--border-color);">
                                    <label for="registration_date" class="form-label required mb-0 small fw-bold text-dark" style="white-space: nowrap;">Reg. Date:</label>
                                    <input type="date" class="form-control form-control-sm border-0 bg-transparent p-0 text-dark" id="registration_date" name="registration_date" required value="<?php echo isset($_POST['registration_date']) ? htmlspecialchars($_POST['registration_date']) : ''; ?>" style="box-shadow: none; outline: none; width: 130px;">
                                </div>
                                
                                <!-- Historical Record Toggle -->
                                <div class="form-check form-switch px-4 py-2 rounded-3 d-flex align-items-center gap-2 mb-0" style="background-color: var(--bg-main); border: 1px solid var(--border-color);">
                                    <input class="form-check-input ms-0" type="checkbox" id="is_historical" name="is_historical_check" <?php echo (isset($_POST['is_historical_check']) || (isset($_POST['is_historical']) && $_POST['is_historical'] == 1)) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="is_historical">Historical Paper Record</label>
                                </div>
                            </div>
                        </div>

                        <div class="card-body p-4 p-md-5">
                            
                            <!-- Hidden Fields -->
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <!-- Mirror historical status for POST -->
                            <input type="hidden" name="is_historical" id="is_historical_hidden" value="<?php echo (isset($_POST['is_historical_check']) || (isset($_POST['is_historical']) && $_POST['is_historical'] == 1)) ? '1' : '0'; ?>">

                            <!-- SECTION 1: REGISTRATION DETAILS -->
                            <div class="form-section-title">
                                <i class="bi bi-file-earmark-text"></i> Registration Information
                            </div>
                            
                            <!-- Index Number Builder Grid -->
                            <div class="row g-3 mb-4">
                                <div class="col-md-5">
                                    <label for="course_code" class="form-label required">Course Code</label>
                                    <select class="form-select" id="course_code" name="course_code" required>
                                        <option value="" disabled selected>Select Course</option>
                                        <option value="IN" <?php echo (isset($_POST['course_code']) && $_POST['course_code'] === 'IN') ? 'selected' : ''; ?>>IN - Individual</option>
                                        <option value="AP" <?php echo (isset($_POST['course_code']) && $_POST['course_code'] === 'AP') ? 'selected' : ''; ?>>AP - Application Programming</option>
                                        <option value="CGD" <?php echo (isset($_POST['course_code']) && $_POST['course_code'] === 'CGD') ? 'selected' : ''; ?>>CGD - Computer Graphic Designer</option>
                                        <option value="PRE" <?php echo (isset($_POST['course_code']) && $_POST['course_code'] === 'PRE') ? 'selected' : ''; ?>>PRE - Pre School Teacher Training</option>
                                        <option value="ICT" <?php echo (isset($_POST['course_code']) && $_POST['course_code'] === 'ICT') ? 'selected' : ''; ?>>ICT - ICT Technician</option>
                                    </select>
                                </div>
                                <div class="col-md-1">
                                    <label for="batch_year" class="form-label required">Year</label>
                                    <input type="text" name="batch_year" id="batch_year" maxlength="2" pattern="\d{2}" placeholder="26" required class="form-control" value="<?php echo isset($_POST['batch_year']) ? htmlspecialchars($_POST['batch_year']) : ''; ?>">
                                </div>
                                <div class="col-md-2">
                                    <label for="batch_number" class="form-label required">Batch</label>
                                    <input type="text" name="batch_number" id="batch_number" maxlength="3" pattern="\d{3}" placeholder="004" required class="form-control" value="<?php echo isset($_POST['batch_number']) ? htmlspecialchars($_POST['batch_number']) : ''; ?>">
                                </div>
                                <div class="col-md-2" id="nvq_type_container">
                                    <label for="is_nvq" class="form-label required">Type</label>
                                    <select class="form-select" id="is_nvq" name="is_nvq" required>
                                        <option value="" disabled selected>Type</option>
                                        <option value="N" <?php echo (isset($_POST['is_nvq']) && $_POST['is_nvq'] === 'N') ? 'selected' : ''; ?>>N (NVQ)</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label for="sequence_number" class="form-label required">Index No</label>
                                    <input type="number" class="form-control" id="sequence_number" name="sequence_number" required placeholder="3782" min="1" value="<?php echo isset($_POST['sequence_number']) ? htmlspecialchars($_POST['sequence_number']) : ''; ?>">
                                </div>
                            </div>

                            <!-- Generated Index Number Preview -->
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label for="index_number" class="form-label fw-bold">Generated Index Number Preview</label>
                                    <input type="text" class="form-control fw-bold text-primary" id="index_number" name="index_number" readonly placeholder="Fill inputs to generate..." value="<?php echo isset($index_number) ? htmlspecialchars($index_number) : ''; ?>">
                                </div>
                            </div>

                            <!-- SECTION 2: PERSONAL DETAILS -->
                            <div class="form-section-title">
                                <i class="bi bi-person"></i> Personal Profile
                            </div>
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label for="name" class="form-label required">Full Name</label>
                                    <input type="text" class="form-control" id="name" name="name" required placeholder="e.g. John Doe" value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>">
                                </div>
                                <div class="col-md-3">
                                    <label for="nic" class="form-label required">NIC Number</label>
                                    <input type="text" class="form-control" id="nic" name="nic" required placeholder="12-digit number" pattern="[0-9]{12}" maxlength="12" value="<?php echo isset($_POST['nic']) ? htmlspecialchars($_POST['nic']) : ''; ?>">
                                    <div class="invalid-feedback">Must be exactly 12 digits.</div>
                                </div>
                                <div class="col-md-3">
                                    <label for="contact_no" class="form-label required">Contact Number</label>
                                    <input type="text" class="form-control" id="contact_no" name="contact_no" required placeholder="10-digit number" pattern="[0-9]{10}" maxlength="10" value="<?php echo isset($_POST['contact_no']) ? htmlspecialchars($_POST['contact_no']) : ''; ?>">
                                    <div class="invalid-feedback">Must be exactly 10 digits.</div>
                                </div>
                                <div class="col-md-3">
                                    <label for="dob" class="form-label required">Date of Birth</label>
                                    <input type="date" class="form-control" id="dob" name="dob" required value="<?php echo isset($_POST['dob']) ? htmlspecialchars($_POST['dob']) : ''; ?>">
                                </div>
                                <div class="col-md-3">
                                    <label for="gender" class="form-label required">Gender</label>
                                    <select class="form-select" id="gender" name="gender" required>
                                        <option value="" disabled selected>Select</option>
                                        <option value="Male" <?php echo (isset($_POST['gender']) && $_POST['gender'] === 'Male') ? 'selected' : ''; ?>>Male</option>
                                        <option value="Female" <?php echo (isset($_POST['gender']) && $_POST['gender'] === 'Female') ? 'selected' : ''; ?>>Female</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="address" class="form-label required">Home Address</label>
                                    <input type="text" class="form-control" id="address" name="address" required placeholder="Full street address" value="<?php echo isset($_POST['address']) ? htmlspecialchars($_POST['address']) : ''; ?>">
                                </div>
                            </div>

                            <!-- SECTION 3: GUARDIAN DETAILS -->
                            <div class="form-section-title">
                                <i class="bi bi-people"></i> Guardian Details
                            </div>
                            <div class="row g-3 mb-4">
                                <div class="col-md-4">
                                    <label for="guardian_name" class="form-label">Guardian Name</label>
                                    <input type="text" class="form-control" id="guardian_name" name="guardian_name" placeholder="Name of parent/guardian" value="<?php echo isset($_POST['guardian_name']) ? htmlspecialchars($_POST['guardian_name']) : ''; ?>">
                                </div>
                                <div class="col-md-8">
                                    <label for="guardian_details" class="form-label">Guardian Contact Info / Details</label>
                                    <input type="text" class="form-control" id="guardian_details" name="guardian_details" placeholder="Contact number, occupation, relationship, etc." value="<?php echo isset($_POST['guardian_details']) ? htmlspecialchars($_POST['guardian_details']) : ''; ?>">
                                </div>
                            </div>

                            <!-- SECTION 4: QUALIFICATIONS & COURSES -->
                            <div class="form-section-title">
                                <i class="bi bi-tags"></i> Qualifications & Courses
                            </div>
                            <div class="row g-3 mb-5">
                                <!-- Educational Qualifications -->
                                <div class="col-lg-4 col-md-6">
                                    <div class="category-box">
                                        <h6 class="fw-bold mb-3 text-secondary border-bottom border-secondary border-opacity-25 pb-2">
                                            <i class="bi bi-book me-1 text-primary"></i>Education
                                        </h6>
                                        <div class="d-flex flex-column gap-2">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="gce_al_science" name="gce_al_science" <?php echo isset($_POST['gce_al_science']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="gce_al_science">GCE A/L Science</label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="gce_al_maths" name="gce_al_maths" <?php echo isset($_POST['gce_al_maths']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="gce_al_maths">GCE A/L Maths</label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="gce_al_commerce" name="gce_al_commerce" <?php echo isset($_POST['gce_al_commerce']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="gce_al_commerce">GCE A/L Commerce</label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="gce_al_art" name="gce_al_art" <?php echo isset($_POST['gce_al_art']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="gce_al_art">GCE A/L Art</label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="gce_al_tech" name="gce_al_tech" <?php echo isset($_POST['gce_al_tech']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="gce_al_tech">GCE A/L Tech</label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="gce_ol" name="gce_ol" <?php echo isset($_POST['gce_ol']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="gce_ol">GCE O/L</label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="kids_grade" name="kids_grade" <?php echo isset($_POST['kids_grade']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="kids_grade">Kids Grade</label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="other_edu" name="other_edu" <?php echo isset($_POST['other_edu']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="other_edu">Other Education</label>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- NVQ Courses -->
                                <div class="col-lg-4 col-md-6">
                                    <div class="category-box">
                                        <h6 class="fw-bold mb-3 text-secondary border-bottom border-secondary border-opacity-25 pb-2">
                                            <i class="bi bi-award me-1 text-primary"></i>NVQ Courses
                                        </h6>
                                        <div class="d-flex flex-column gap-2">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="ict_tech" name="ict_tech" <?php echo isset($_POST['ict_tech']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="ict_tech">ICT Technician</label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="computer_app_ast" name="computer_app_ast" <?php echo isset($_POST['computer_app_ast']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="computer_app_ast">Computer App. Assistant</label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="graphic_designer" name="graphic_designer" <?php echo isset($_POST['graphic_designer']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="graphic_designer">Graphic Designer</label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="pre_school" name="pre_school" <?php echo isset($_POST['pre_school']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="pre_school">Pre School</label>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Non NVQ Courses -->
                                <div class="col-lg-4 col-md-12">
                                    <div class="category-box">
                                        <h6 class="fw-bold mb-3 text-secondary border-bottom border-secondary border-opacity-25 pb-2">
                                            <i class="bi bi-box me-1 text-primary"></i>Non-NVQ Courses
                                        </h6>
                                        <div class="d-flex flex-column gap-2">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="non_nvq_app_ast" name="non_nvq_app_ast" <?php echo isset($_POST['non_nvq_app_ast']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="non_nvq_app_ast">App. Assistant (Non-NVQ)</label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="non_nvq_graphic" name="non_nvq_graphic" <?php echo isset($_POST['non_nvq_graphic']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="non_nvq_graphic">Graphic Design (Non-NVQ)</label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="hr" name="hr" <?php echo isset($_POST['hr']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="hr">Human Resources (HR)</label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="english" name="english" <?php echo isset($_POST['english']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="english">English Language</label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="web_design" name="web_design" <?php echo isset($_POST['web_design']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="web_design">Web Designing</label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="beetaa_kids" name="beetaa_kids" <?php echo isset($_POST['beetaa_kids']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="beetaa_kids">Beetaa Kids</label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="other_course" name="other_course" <?php echo isset($_POST['other_course']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="other_course">Other Courses</label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Buttons -->
                            <div class="d-flex justify-content-end gap-3 flex-wrap">
                                <button type="reset" class="btn btn-reset" id="resetBtn">
                                    <i class="bi bi-arrow-counterclockwise me-1"></i>Reset Form
                                </button>
                                <button type="submit" class="btn btn-submit">
                                    <i class="bi bi-save me-1"></i>Register Student
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- Bootstrap 5 Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Vanilla Javascript Logic -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const isHistoricalCheckbox = document.getElementById('is_historical');
            const isHistoricalHiddenInput = document.getElementById('is_historical_hidden');
            const registrationDateInput = document.getElementById('registration_date');
            
            // Function to set registration date to today's date (local timezone)
            const setRegistrationDateToToday = () => {
                const now = new Date();
                const year = now.getFullYear();
                const month = String(now.getMonth() + 1).padStart(2, '0');
                const day = String(now.getDate()).padStart(2, '0');
                registrationDateInput.value = `${year}-${month}-${day}`;
            };

            // Setup state based on the checkbox status
            const updateDatePickerState = () => {
                if (isHistoricalCheckbox.checked) {
                    registrationDateInput.removeAttribute('readonly');
                    isHistoricalHiddenInput.value = '1';
                } else {
                    registrationDateInput.setAttribute('readonly', true);
                    isHistoricalHiddenInput.value = '0';
                    setRegistrationDateToToday();
                }
            };

            // Initialize on page load (keep user input if form submitted with errors, otherwise default to today)
            if (!registrationDateInput.value) {
                setRegistrationDateToToday();
            }
            updateDatePickerState();

            // Handle toggle changes
            isHistoricalCheckbox.addEventListener('change', updateDatePickerState);

            // Elements for Index Number Builder
            const courseCodeSelect = document.getElementById('course_code');
            const batchYearInput = document.getElementById('batch_year');
            const batchNumberInput = document.getElementById('batch_number');
            const isNvqSelect = document.getElementById('is_nvq');
            const sequenceNumberInput = document.getElementById('sequence_number');
            const indexNumberInput = document.getElementById('index_number');
            const nvqTypeContainer = document.getElementById('nvq_type_container');

            // Live Index Number Generator
            const generateIndexNumber = () => {
                const courseCode = courseCodeSelect.value || '';
                const batchYear = batchYearInput.value || '';
                const batchNumber = batchNumberInput.value || '';
                const isNvq = isNvqSelect.value || '';
                const sequenceNumber = sequenceNumberInput.value || '';

                // If critical parameters are empty, leave preview blank
                if (!courseCode || !batchYear || !batchNumber || !sequenceNumber || (courseCode !== 'IN' && !isNvq)) {
                    indexNumberInput.value = '';
                    return;
                }

                if (courseCode === 'IN') {
                    indexNumberInput.value = `${courseCode}/${batchYear}/${batchNumber}/${sequenceNumber}`;
                } else {
                    indexNumberInput.value = `${courseCode}/${batchYear}/${batchNumber}/${isNvq}/${sequenceNumber}`;
                }
            };

            const handleCourseCodeChange = () => {
                const courseCode = courseCodeSelect.value || '';

                if (courseCode === 'IN') {
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
            [courseCodeSelect, batchYearInput, batchNumberInput, isNvqSelect, sequenceNumberInput].forEach(elem => {
                if (elem) {
                    elem.addEventListener('input', generateIndexNumber);
                    elem.addEventListener('change', generateIndexNumber);
                }
            });

            courseCodeSelect.addEventListener('change', handleCourseCodeChange);

            // Initialize
            handleCourseCodeChange();

            // Handle manual reset button
            document.getElementById('resetBtn').addEventListener('click', function(e) {
                // Let native reset run first, then apply delay to reset dates properly
                setTimeout(() => {
                    isHistoricalCheckbox.checked = false;
                    updateDatePickerState();
                    handleCourseCodeChange();
                }, 10);
            });

            // Form validation highlight trigger
            const form = document.getElementById('studentForm');
            form.addEventListener('submit', function(event) {
                if (!form.checkValidity()) {
                    event.preventDefault();
                    event.stopPropagation();
                }
                form.classList.add('was-validated');
            }, false);
        });
    </script>

<?php include 'footer.php'; ?>
