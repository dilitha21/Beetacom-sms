<?php
/**
 * db_connect.php
 * Secure database connection using PDO.
 */

$host = '127.0.0.1';
$db   = 'registration_db';
$user = 'root';
$pass = ''; // Default local MySQL (XAMPP/WAMP) password is empty
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    // In production, log the error and display a generic message.
    // For local development, displaying the error helps debugging.
    die("Database connection failed: " . htmlspecialchars($e->getMessage()));
}
