# Beetaacom Student Management & Payment Tracking System

A secure, premium, and fully responsive Student Management System built using PHP, MySQL/TiDB, Bootstrap 5, and Vanilla JavaScript. Features automated composite Index Number generation, dynamic payment tracking, bulk academic grading, and advanced CSV export reporting.

---

## 🛠️ Technologies Used

### Backend Architecture
* **Core Language**: PHP 8.2 (Object-Oriented Programming, MVC-style modular structures, session-based routing).
* **Database Layer**: TiDB Serverless Cloud / MySQL.
* **Database Connector**: PHP Data Objects (PDO) with strictly parametrized SQL statements to prevent SQL Injection.
* **SSL Transport Security**: Enforced TLS/SSL database connections utilizing Render's native root CA trust bundle (`/etc/ssl/certs/ca-certificates.crt`).

### Frontend Design System
* **CSS Framework**: Bootstrap 5.3.2 (Layout grid, responsive helpers, card components, form controls).
* **Icons Library**: Bootstrap Icons.
* **Custom Styling**: Vanilla CSS3 custom variables (`:root` theme colors), premium dark-themed navigation header, glassmorphism card layouts, custom webkit scrollbar overrides, and smooth bezier transition animations (`transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1)`).

### Client-Side Scripts
* **Vanilla JavaScript ES6**: 
  * Real-time client-side Index Number generators.
  * Interactive page tab controllers.
  * Form input input-masking validation.
  * Live table search filtering.
  * Asynchronous AJAX requests.

### Infrastructure & Operations
* **Containerization**: Docker (PHP-Apache official image configuration).
* **DevOps Hosting**: Render Cloud Services web integration.
* **Version Control**: Git.
* **Local Server**: XAMPP Apache with MariaDB fallback compatibility logic.

---

## 📋 Complete Features List

### 1. User Authentication & Authorization System
* **File Directory**: `index.php`, `auth_check.php`, `logout.php`, `profile.php`
* **Role-Based Access Control (RBAC)**: Enforces access restrictions between `super_admin` and `staff` privilege layers.
* **Session Protection**: Implements session hijacking mitigation by forcing session ID regeneration on success.
* **Persistent Login**: Uses secure cryptographically generated remember-me cookies linked to database persistent tokens.
* **CSRF Protection**: Automatically validates authentication submissions with single-use session hashes.
* **User Settings Panel**: Safe profile update controller validating old passwords prior to matching new hashed inputs.

### 2. Main Dashboard & Analytics Panel
* **File Directory**: `dashboard.php`
* **Real-time Overview Analytics**: Aggregates Total Registered Students, Active Batches, Total Payments Collected, and Outstanding Balances directly from database stats.
* **Interactive Live Search Engine**: Real-time table filters to lookup records by Name, NIC, or Index Number instantly.
* **Course and Batch Filter Directories**: Sectioned navigation shortcuts to access student files filtered by batch year and stream.

### 3. Student Registration Suite
* **File Directory**: `add_student.php`
* **Live Index Number Builder**: Auto-generates registration index strings dynamically using: `[Course Code] / [Batch Year] / [Batch Number] / [NVQ Type Option] / [Sequence Number]` (e.g. `AP/26/004/3782` or `ICT/26/004/N/3782`).
* **Conditional Field Layouts**: Hides NVQ attributes automatically when non-NVQ courses (like `KIDS` or `IN`) are selected.
* **Regex Input Masking**: Enforces strict frontend/backend format verification ensuring 12-digit NIC structures and 10-digit mobile phone configurations.
* **Categorized Qualifications Checkboxes**: Saves student background profiles, NVQ programs, and Non-NVQ selections.

### 4. Comprehensive Student Profile Manager
* **File Directory**: `student_profile.php`
* **Tabbed Profile Navigation**: Sections profiles into Profile Overview, Academic Results, Payment Config, and Payment Ledger tabs.
* **Academic Results Manager**: Adds specific exam results (Mark, Exam Date, Status) and features instant delete queries for erroneous records.
* **Dynamic Payment Configurator**:
  * Enforces tuition fee structures based on plan categories.
  * Auto-calculates standard 10% payment discounts for full-payment setups.
  * Records base fees, final targets, and admission fee confirmations.
* **Installment Ledger Log**: Tracks historical installments with individual transaction records (receipt ID, amount paid, payment date).
* **Super-Admin Danger Zone**: Enables authorized admins to delete specific receipts, reset payment structures, or completely wipe student profiles from the database.

### 5. Bulk Academic Grading Panel
* **File Directory**: `bulk_grading.php`
* **Batch Loading**: Selects all enrolled students within a specific batch stream (e.g., Year, Batch, Course Code).
* **Bulk Exam Input**: Assigns exam marks, exam date, and status grades (Pass/Fail/Pending) to all students in a single transaction.
* **Status Notifications**: Dynamic colors representing successful grading operations.

### 6. CSV Batch Export Engine
* **File Directory**: `export_batch.php`
* **TiDB-Optimized Queries**: Formulates GROUP BY queries compatible with strict database modes (`ONLY_FULL_GROUP_BY`).
* **Formatted Balance Sheets**: Exports contact numbers, NICs, plan types, tuition fees, final totals, admission status, total payments, and outstanding balances.
* **Excel Data Safeguards**: Prefixes text strings (like Contact No and NIC) with a tab (`\t`) to preserve leading zeros and formatting in Microsoft Excel.
* **Aggregated Exam Summary**: Concatenates student test results into a single column using optimized SQL aggregates.

### 7. Core Database & Connector
* **File Directory**: `db_connect.php`, `schema.sql`, `registration_db.sql`
* **Environment Fallback Parser**: Automatically parses local `.env` values or reads system environment variables directly on cloud hosts.
* **SSL Certificate Verification**: Enforces SSL connection parameters for secure TiDB cloud servers.
* **Local Development Compatibility**: Auto-detects local host setups (Windows) to skip path verification and prevent connection crashes.
