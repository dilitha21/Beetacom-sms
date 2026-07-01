# Student Management & Payment Tracking System (Beetacom)

A secure web-based Student Management System built using PHP, MySQL (PDO), Bootstrap 5, and Vanilla JavaScript. Features automated composite Index Number generation, dynamic payment installments division, receipt ledger tracking, and profile modification capabilities.

## Features
- **Secure Authentication**: Staff and Super Admin accounts with cryptographically hashed passwords.
- **Index Number Builder**: Conditional dynamic registration formatting based on course codes (NVQ vs. Non-NVQ).
- **Payment tracking**: Supports full payments (with 10% discount auto-calculations) and 6-month installment tracking with individual status toggles.
- **Mistake Rollback**: Fully customizable option to delete erroneous payment receipts, reset configured payment structures, or completely wipe student profiles.
- **Search Panel**: High-contrast, easy-to-use search dashboard filtering students by name, NIC, or index number.

## Installation & Setup

1. **Move Codebase to Server**:
   - Clone or copy this repository into your XAMPP/WAMP local directory (typically `C:\xampp\htdocs\Beetacom`).

2. **Database Configuration**:
   - Start Apache and MySQL via your control panel.
   - Access `phpMyAdmin` (typically `http://localhost/phpmyadmin`).
   - Create a database named `registration_db`.
   - Import the **[schema.sql](schema.sql)** file to set up all tables (`users`, `students`, `payment_plans`, `payment_records`) and seed default admin accounts.

3. **Database Connection**:
   - Update connection parameters inside **[db_connect.php](db_connect.php)** if your local root MySQL database user uses a custom password (default is empty password `""` on `127.0.0.1`).

4. **Default Credentials**:
   - **URL**: `http://localhost/Beetacom/login.php`
   - **Username**: `Beetacomsuperadmin`
   - **Password**: `Beetaacommri1971`
