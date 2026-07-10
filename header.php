<?php
/**
 * header.php
 * Global header file containing Bootstrap 5 and HTMX CDNs.
 */
$is_htmx = isset($_SERVER['HTTP_HX_REQUEST']);
if (!$is_htmx):
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
    <!-- HTMX -->
    <script src="https://unpkg.com/htmx.org@1.9.10"></script>
    <style>
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
        }

        .btn-accent {
            background-color: var(--accent-color) !important;
            color: #ffffff !important;
            border: none !important;
            border-radius: 8px !important;
            font-weight: bold !important;
            transition: all 0.2s ease !important;
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
    </style>
</head>
<body hx-indicator="#global-spinner">

    <!-- Global Loading Spinner -->
    <div id="global-spinner" class="htmx-indicator spinner-border text-primary" role="status">
        <span class="visually-hidden">Loading...</span>
    </div>
<?php endif; ?>
