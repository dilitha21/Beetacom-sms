<?php
/**
 * header.php
 * Global header file containing Bootstrap 5 styles, Google Fonts, and the main navbar.
 * HTMX and loading indicator have been removed to return to standard MPA architecture.
 */
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title ?? 'Student Management System'; ?></title>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    
    <style>
        /* 1. Define global color palette */
        :root {
            --bg-main: #f4f7fb;       /* Soft blue-gray background */
            --bg-surface: #ffffff;    /* Pure white for cards/panels */
            --bg-sidebar: #ebf0f7;    /* Slightly darker for sidebar contrast */
            --text-primary: #1e293b;  /* Dark slate for high contrast */
            --text-secondary: #64748b;/* Muted slate for labels/metadata */
            --accent-color: #6366f1;   /* Vibrant Indigo */
            --border-color: #e2e8f0;   /* Subtle borders */
            --border-radius: 12px;
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03);
            --shadow-hover: 0 10px 15px -3px rgba(0,0,0,0.08);
        }

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
        }

        /* Force headings to pop with dark slate */
        h1, h2, h3, h4, h5, h6 {
            color: var(--text-primary); 
            margin-top: 0;
            font-weight: 700;
        }

        /* Fix vanishing text in input fields and forms */
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

        .btn-accent {
            background-color: var(--accent-color) !important;
            color: #ffffff !important;
            border: none !important;
            border-radius: 8px !important;
            font-weight: bold !important;
            transition: background-color 0.2s ease !important;
        }
        .btn-accent:hover {
            background-color: #4f46e5 !important;
            color: #ffffff !important;
        }

        .btn-muted-outline {
            background-color: transparent !important;
            border: 1px solid var(--border-color) !important;
            color: var(--text-secondary) !important;
            border-radius: 8px !important;
            transition: all 0.2s ease !important;
        }
        .btn-muted-outline:hover {
            background-color: var(--bg-sidebar) !important;
            color: var(--text-primary) !important;
        }

        .text-muted {
            color: var(--text-secondary) !important;
        }
        .text-secondary {
            color: var(--text-secondary) !important;
        }
    </style>
    <?php if (isset($extra_css)) echo $extra_css; ?>
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
                        <a class="nav-link <?php echo ($current_page === 'dashboard.php') ? 'active' : ''; ?>" href="dashboard.php"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo ($current_page === 'add_student.php') ? 'active' : ''; ?>" href="add_student.php"><i class="bi bi-person-plus me-1"></i>Register</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo ($current_page === 'bulk_grading.php') ? 'active' : ''; ?>" href="bulk_grading.php"><i class="bi bi-journal-plus me-1"></i>Grades</a>
                    </li>
                </ul>
                <div class="d-flex align-items-center gap-3">
                    <a href="profile.php" class="nav-link small <?php echo ($current_page === 'profile.php') ? 'active' : ''; ?>"><i class="bi bi-gear-fill me-1"></i>My Profile</a>
                    <a href="logout.php" class="btn btn-outline-danger btn-sm rounded-pill px-3">
                        <i class="bi bi-box-arrow-right me-1"></i>Logout
                    </a>
                </div>
            </div>
        </div>
    </nav>
    <main id="main-content">
