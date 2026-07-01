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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Student Registration System</title>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        /* 1. Define your global color palette */
        :root {
            --bg-main: #121212;         /* Deep, dark gray (better than pure black) */
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

        /* 4. Fix vanishing text in input fields and forms */
        input, select, textarea {
            background-color: var(--bg-surface);
            color: var(--text-primary); /* Ensures typed text is visible */
            border: 1px solid var(--border-color);
            padding: 10px;
            border-radius: 4px;
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

        .dashboard-container {
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Search Section */
        .search-card {
            background-color: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 2rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.4);
            margin-bottom: 2rem;
        }

        .search-control {
            background-color: var(--bg-main);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            border-radius: 4px 0 0 4px;
            padding: 0.8rem 1.2rem;
            font-size: 1.05rem;
            transition: all 0.2s ease;
        }
        .search-control:focus {
            background-color: var(--bg-main);
            border-color: var(--accent-color);
            color: var(--text-primary);
            box-shadow: 0 0 0 3px rgba(187, 134, 252, 0.2);
            outline: none;
        }
        .search-control::placeholder {
            color: var(--text-secondary);
        }

        .btn-search {
            background-color: var(--accent-color);
            color: #000000;
            border: none;
            font-weight: bold;
            padding: 0.8rem 2rem;
            border-radius: 0 4px 4px 0;
            cursor: pointer;
            transition: background-color 0.2s ease;
        }
        .btn-search:hover {
            background-color: #9965f4;
        }

        .btn-clear-search {
            background-color: var(--bg-surface);
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
            border-radius: 4px;
            padding: 0.8rem 1.2rem;
            transition: all 0.2s ease;
        }
        .btn-clear-search:hover {
            background-color: var(--border-color);
            color: #ffffff;
        }

        /* Data Card */
        .data-card {
            background-color: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.4);
        }

        .data-card-header {
            background-color: rgba(255, 255, 255, 0.02);
            border-bottom: 1px solid var(--border-color);
            padding: 1.25rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
        }

        /* Table Styling */
        .table-custom {
            margin-bottom: 0;
            color: var(--text-primary);
        }
        .table-custom th {
            background-color: var(--bg-main);
            border-bottom: 1px solid var(--border-color);
            color: var(--text-secondary);
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 700;
            padding: 1.25rem 1.5rem;
        }
        .table-custom td {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
            font-size: 0.95rem;
        }
        .table-striped tbody tr:nth-of-type(odd) {
            background-color: rgba(255, 255, 255, 0.01);
        }
        .table-custom tbody tr {
            transition: background-color 0.2s ease;
        }
        .table-custom tbody tr:hover {
            background-color: rgba(187, 134, 252, 0.05) !important;
        }

        /* Badges */
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
            background-color: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
            border-radius: 4px;
        }

        /* High-contrast dark mode overrides */
        .text-muted {
            color: var(--text-secondary) !important;
        }
        .text-secondary {
            color: var(--text-secondary) !important;
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
                        <a class="nav-link active" href="dashboard.php"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a>
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

    <!-- Dashboard Panel -->
    <div class="container dashboard-container">
        
        <?php if (!empty($error_msg)): ?>
            <div class="alert alert-custom-error d-flex align-items-center mb-4" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <div><?php echo $error_msg; ?></div>
            </div>
        <?php endif; ?>

        <!-- Search Bar Section -->
        <div class="search-card">
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

    <!-- Bootstrap 5 Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
