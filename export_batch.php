<?php
require_once 'auth_check.php';
require_once 'db_connect.php';

// Ensure the user is a super admin
if ($_SESSION['role'] !== 'super_admin') {
    http_response_code(403);
    die("Access Denied: Only super admins can export batch data.");
}

$batch_year = trim($_GET['batch_year'] ?? '');
if ($batch_year === '') {
    http_response_code(400);
    die("Bad Request: Batch year is required.");
}

try {
    // Prepare and execute the query
    $sql = "SELECT 
                s.name, 
                s.index_number, 
                s.nic, 
                s.contact_no, 
                s.course_code,
                IFNULL(p.final_total, 0.00) AS course_fee,
                IFNULL(
                    (
                        SELECT er.status 
                        FROM exam_results er 
                        WHERE er.student_id = s.id 
                        ORDER BY er.exam_date DESC, er.exam_id DESC 
                        LIMIT 1
                    ), 'Pending'
                ) AS recent_exam_status,
                IFNULL(
                    (
                        SELECT er.exam_name 
                        FROM exam_results er 
                        WHERE er.student_id = s.id 
                        ORDER BY er.exam_date DESC, er.exam_id DESC 
                        LIMIT 1
                    ), 'N/A'
                ) AS recent_exam_name
            FROM students s
            LEFT JOIN payment_plans p ON s.id = p.student_id
            WHERE s.batch_year = :batch_year
            ORDER BY s.index_number ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':batch_year' => $batch_year]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Set response headers to force download CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="batch_20' . $batch_year . '_report.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Output stream
    $output = fopen('php://output', 'w');

    // Write header columns
    fputcsv($output, ['Student Name', 'Index Number', 'NIC', 'Contact Number', 'Course Code', 'Course Fee (LKR)', 'Most Recent Exam', 'Exam Status']);

    // Write row lines
    foreach ($results as $row) {
        fputcsv($output, [
            $row['name'],
            $row['index_number'],
            $row['nic'],
            $row['contact_no'],
            $row['course_code'],
            number_format(floatval($row['course_fee']), 2, '.', ''),
            $row['recent_exam_name'],
            $row['recent_exam_status']
        ]);
    }

    fclose($output);
    exit();
} catch (\Exception $e) {
    http_response_code(500);
    die("Error generating CSV: " . $e->getMessage());
}
