<?php
/**
 * export_batch.php
 * Generates a detailed CSV report for a selected batch year.
 * Features: TiDB-optimized grouping, dynamic payment status calculation, aggregated exam lists, and Excel-safe formatting.
 */

require_once 'auth_check.php';
require_once 'db_connect.php';

// 1. Ensure user has permission
if ($_SESSION['role'] !== 'super_admin') {
    http_response_code(403);
    die("Access Denied: Only super admins can export batch data.");
}

// 2. Parse batch year parameter
$batch_year = trim($_GET['batch_year'] ?? '');
if ($batch_year === '') {
    http_response_code(400);
    die("Bad Request: Batch year is required.");
}

try {
    // 3. TiDB Compatible Group-By SQL Query
    $sql = "SELECT 
                s.index_number, 
                s.name, 
                s.nic, 
                s.contact_no,
                p.plan_type, 
                COALESCE(p.base_fee, 0.00) AS base_fee, 
                COALESCE(p.final_total, 0.00) AS final_total, 
                COALESCE(p.admission_paid, 0) AS admission_paid,
                COALESCE(SUM(pr.amount_paid), 0.00) AS total_paid,
                CASE 
                    WHEN p.plan_type IS NULL OR p.plan_type = 'pending' THEN 'Pending'
                    WHEN COALESCE(SUM(pr.amount_paid), 0.00) >= p.final_total THEN 'Completed' 
                    ELSE 'Pending' 
                END AS payment_status,
                COALESCE(
                    GROUP_CONCAT(
                        CONCAT(er.exam_name, ': ', er.status, ' (', COALESCE(er.mark, 'N/A'), ')')
                        ORDER BY er.exam_date DESC, er.exam_id DESC
                        SEPARATOR ' | '
                    ), 
                    'No Exam Records'
                ) AS exam_records
            FROM students s
            LEFT JOIN payment_plans p ON s.id = p.student_id
            LEFT JOIN payment_records pr ON s.id = pr.student_id
            LEFT JOIN exam_results er ON s.id = er.student_id
            WHERE s.batch_year = :batch_year
            GROUP BY 
                s.id, 
                s.index_number, 
                s.name, 
                s.nic, 
                s.contact_no, 
                p.plan_type, 
                p.base_fee, 
                p.final_total, 
                p.admission_paid
            ORDER BY s.index_number ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':batch_year' => $batch_year]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Set Headers to trigger download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="batch_report_20' . $batch_year . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    // 5. Open output stream
    $output = fopen('php://output', 'w');

    // Write BOM for proper Excel UTF-8 display
    fwrite($output, "\xEF\xBB\xBF");

    // Write headers matching specification
    fputcsv($output, [
        'Index Number', 
        'Name', 
        'NIC', 
        'Contact Number', 
        'Admission Paid', 
        'Plan Type', 
        'Base Fee', 
        'Final Total Expected', 
        'Total Paid So Far', 
        'Remaining Balance', 
        'Payment Status', 
        'All Exam Records'
    ]);

    // 6. Loop and format records
    foreach ($results as $row) {
        // Transform admission_paid boolean
        $admission_text = ($row['admission_paid'] == 1) ? 'Yes' : 'No';

        // Calculate remaining balance dynamically
        $final_total = floatval($row['final_total']);
        $total_paid = floatval($row['total_paid']);
        $remaining_balance = max($final_total - $total_paid, 0.00);

        // Format Plan Type for readable text
        $plan_type = 'Pending';
        if ($row['plan_type'] === 'full') {
            $plan_type = 'Full Payment';
        } elseif ($row['plan_type'] === 'installment') {
            $plan_type = 'Installment';
        }

        // Excel-safe string formatting to protect leading zeros and large numeric values
        $safe_nic = "\t" . $row['nic'];
        $safe_contact = "\t" . $row['contact_no'];

        // Write row
        fputcsv($output, [
            $row['index_number'],
            $row['name'],
            $safe_nic,
            $safe_contact,
            $admission_text,
            $plan_type,
            number_format(floatval($row['base_fee']), 2, '.', ''),
            number_format($final_total, 2, '.', ''),
            number_format($total_paid, 2, '.', ''),
            number_format($remaining_balance, 2, '.', ''),
            $row['payment_status'],
            $row['exam_records']
        ]);
    }

    fclose($output);
    exit();

} catch (\Exception $e) {
    http_response_code(500);
    die("Error generating CSV: " . htmlspecialchars($e->getMessage()));
}
