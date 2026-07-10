<?php
/**
 * dashboard.php
 * Main landing page and student search panel.
 */

// 1. Ensure user is logged in
require_once 'auth_check.php';
require_once 'db_connect.php';

$search_query = trim($_GET['search'] ?? '');
$students = [];
$error_msg = '';

try {
    if ($search_query !== '') {
        // Query to match search query in name, nic, or registration number
        $sql = "SELECT id, index_number, name, nic, contact_no, registration_date, is_historical 
                FROM students 
                WHERE name LIKE :search_name 
                   OR nic LIKE :search_nic 
                   OR index_number LIKE :search_reg 
                ORDER BY registration_date DESC, id DESC 
                LIMIT 50";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':search_name' => '%' . $search_query . '%',
            ':search_nic'  => '%' . $search_query . '%',
            ':search_reg'  => '%' . $search_query . '%',
        ]);
    } else {
        // Default: fetch the latest 50 records
        $sql = "SELECT id, index_number, name, nic, contact_no, registration_date, is_historical 
                FROM students 
                ORDER BY registration_date DESC, id DESC 
                LIMIT 50";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
    }
    $students = $stmt->fetchAll();
} catch (\PDOException $e) {
    $error_msg = 'Database error: ' . htmlspecialchars($e->getMessage());
}

$page_title = 'Dashboard - Student Registration System';
ob_start();
?>
    <style>
        .dashboard-container {
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Search Section */
        .search-card {
            background-color: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            padding: 2rem;
            box-shadow: var(--shadow-md);
            margin-bottom: 2rem;
        }

        .search-control {
            background-color: var(--bg-main);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            border-radius: 8px 0 0 8px;
            padding: 0.8rem 1.2rem;
            font-size: 1.05rem;
            transition: all 0.2s ease;
        }
        .search-control:focus {
            background-color: var(--bg-surface);
            border-color: var(--accent-color);
            color: var(--text-primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
        }
        .search-control::placeholder {
            color: var(--text-secondary);
        }

        .btn-search {
            background-color: var(--accent-color);
            color: white;
            border: none;
            border-radius: 0 8px 8px 0;
            padding: 0 1.8rem;
            font-weight: 600;
            transition: background-color 0.2s ease;
        }
        .btn-search:hover {
            background-color: #4f46e5;
            color: white;
        }

        .btn-clear-search {
            background-color: transparent;
            color: var(--text-secondary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 0.8rem 1.2rem;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        .btn-clear-search:hover {
            background-color: var(--border-color);
            color: var(--text-primary);
        }

        /* Table Card & General Table */
        .table-card {
            background-color: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            padding: 2rem;
            box-shadow: var(--shadow-md);
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
            padding: 1.2rem 1rem;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.95rem;
        }
        .table-custom tr:hover td {
            background-color: rgba(99, 102, 241, 0.02) !important;
        }

        /* Empty state */
        .empty-state {
            padding: 5rem 2rem;
            text-align: center;
        }
        .empty-icon {
            font-size: 3rem;
            color: var(--text-secondary);
            margin-bottom: 1.5rem;
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

    <!-- Dashboard Panel -->
    <div class="container dashboard-container">
        
        <?php if (!empty($error_msg)): ?>
            <div class="alert alert-custom-error d-flex align-items-center mb-4" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <div><?php echo $error_msg; ?></div>
            </div>
        <?php endif; ?>

        <!-- Search & Export Grid Section -->
        <div class="row g-4 mb-4">
            <div class="<?php echo ($_SESSION['role'] === 'super_admin') ? 'col-lg-8' : 'col-12'; ?>">
                <!-- Search Bar Section -->
                <div class="search-card h-100 mb-0">
                    <h5 class="mb-3 fw-semibold"><i class="bi bi-search me-2 text-primary"></i>Find Student Records</h5>
                    <form action="dashboard.php" method="GET">
                        <div class="row g-2">
                            <div class="col">
                                <div class="input-group">
                                    <input type="text" name="search" class="form-control search-control" placeholder="Search by Student Name, NIC, or Registration Number..." value="<?php echo htmlspecialchars($search_query); ?>" autocomplete="off">
                                    <button class="btn btn-search" type="submit">
                                        <i class="bi bi-search me-2"></i>Search
                                    </button>
                                </div>
                            </div>
                            <?php if ($search_query !== ''): ?>
                                <div class="col-auto">
                                    <a href="dashboard.php" class="btn btn-clear-search d-flex align-items-center gap-1">
                                        <i class="bi bi-x-circle"></i> Clear
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
            
            <?php if ($_SESSION['role'] === 'super_admin'): ?>
                <div class="col-lg-4">
                    <!-- Export Batch Data Section -->
                    <div class="search-card h-100 mb-0">
                        <h5 class="mb-3 fw-semibold"><i class="bi bi-file-earmark-spreadsheet me-2 text-primary"></i>Export Batch Data</h5>
                        <form action="export_batch.php" method="GET">
                            <div class="d-flex gap-2 align-items-end">
                                <div class="flex-grow-1">
                                    <label for="export_batch_year" class="form-label mb-1 small text-muted">Batch Year</label>
                                    <select class="form-select w-100" id="export_batch_year" name="batch_year" required>
                                        <option value="" disabled selected>Select Year</option>
                                        <?php
                                        $current_short = (int)date('y');
                                        $start_year = 24;
                                        $end_year = max($current_short + 10, 30);
                                        for ($y = $start_year; $y <= $end_year; $y++) {
                                            $padded_y = str_pad($y, 2, '0', STR_PAD_LEFT);
                                            echo "<option value=\"$padded_y\">20$padded_y</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                                <button class="btn btn-accent px-3 py-2 d-flex align-items-center gap-1" style="height: 40px;" type="submit">
                                    <i class="bi bi-download"></i> Export
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Student Data Table Section -->
        <div class="data-card">
            <div class="data-card-header">
                <div>
                    <h5 class="mb-0 fw-bold"><i class="bi bi-list-columns-reverse me-2 text-primary"></i>Registered Students</h5>
                    <p class="text-muted mb-0 small">
                        <?php if ($search_query !== ''): ?>
                            Showing search results matching "<strong><?php echo htmlspecialchars($search_query); ?></strong>"
                        <?php else: ?>
                            Showing latest 50 registrations
                        <?php endif; ?>
                    </p>
                </div>
                <div>
                    <span class="badge bg-secondary px-3 py-2 fs-6 rounded-pill">
                        Total Found: <?php echo count($students); ?>
                    </span>
                </div>
            </div>
            
            <div class="table-responsive">
                <?php if (count($students) > 0): ?>
                    <table class="table table-striped table-custom table-hover">
                        <thead>
                            <tr>
                                <th>Index Number</th>
                                <th>Student Name</th>
                                <th>NIC Number</th>
                                <th>Contact Number</th>
                                <th>Registration Date</th>
                                <th>Record Type</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $student): ?>
                                <tr>
                                    <td>
                                        <a href="student_profile.php?id=<?php echo htmlspecialchars($student['id']); ?>" class="text-decoration-none text-primary fw-normal">
                                            <?php echo htmlspecialchars($student['index_number']); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <a href="student_profile.php?id=<?php echo htmlspecialchars($student['id']); ?>" class="text-decoration-none text-dark">
                                            <?php echo htmlspecialchars($student['name']); ?>
                                        </a>
                                    </td>
                                    <td class="text-dark"><?php echo htmlspecialchars($student['nic']); ?></td>
                                    <td>
                                        <a href="tel:<?php echo htmlspecialchars($student['contact_no']); ?>" class="text-decoration-none text-dark">
                                            <i class="bi bi-telephone me-1 small text-dark"></i><?php echo htmlspecialchars($student['contact_no']); ?>
                                        </a>
                                    </td>
                                    <td class="text-dark">
                                        <i class="bi bi-calendar3 me-1 small text-dark"></i><?php echo htmlspecialchars($student['registration_date']); ?>
                                    </td>
                                    <td class="text-dark">
                                        <?php if ($student['is_historical']): ?>
                                            <i class="bi bi-file-earmark-lock-fill me-1 text-dark"></i>Historical
                                        <?php else: ?>
                                            <i class="bi bi-cloud-check-fill me-1 text-dark"></i>Active
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-icon">
                            <i class="bi bi-folder-x"></i>
                        </div>
                        <h5>No student records found</h5>
                        <p class="text-muted small">We couldn't find any students matching your search criteria.</p>
                        <a href="add_student.php" class="btn btn-outline-primary btn-sm mt-2 rounded-pill px-4">
                            <i class="bi bi-plus-lg me-1"></i>Register New Student
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
    </main>

<?php include 'footer.php'; ?>
