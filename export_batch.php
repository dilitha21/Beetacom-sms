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
    // 3. TiDB Compatible SQL Query to list individual payment records per student
    $sql = "SELECT 
                s.id AS student_id,
                s.index_number, 
                s.name, 
                s.nic, 
                s.contact_no,
                s.cert_completion_issued,
                s.english_cert_issued,
                p.plan_type, 
                COALESCE(p.base_fee, 0.00) AS base_fee, 
                COALESCE(p.final_total, 0.00) AS final_total, 
                COALESCE(p.admission_paid, 0) AS admission_paid,
                -- Subquery for cumulative total paid by student
                (SELECT COALESCE(SUM(amount_paid), 0.00) FROM payment_records WHERE student_id = s.id) AS total_paid,
                -- Individual payment record fields
                pr.receipt_id,
                pr.payment_date,
                pr.amount_paid AS installment_amount,
                -- Subquery for aggregated exam records
                (SELECT COALESCE(
                    GROUP_CONCAT(
                        CONCAT(er.exam_name, ': ', er.status, ' (', COALESCE(er.mark, 'N/A'), ')')
                        ORDER BY er.exam_date DESC, er.exam_id DESC
                        SEPARATOR ' | '
                    ), 
                    'No Exam Records'
                 ) FROM exam_results er WHERE er.student_id = s.id
                ) AS exam_records
            FROM students s
            LEFT JOIN payment_plans p ON s.id = p.student_id
            LEFT JOIN payment_records pr ON s.id = pr.student_id
            WHERE s.batch_year = :batch_year
            ORDER BY s.index_number ASC, pr.payment_date ASC, pr.receipt_id ASC";

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
        'Receipt Number', 
        'Payment Date', 
        'Amount Paid (This Installment)', 
        'Certificate of Completion', 
        'English Course Certificate', 
        'All Exam Records'
    ]);

    // 6. Loop and format records
    $last_student_id = null;
    foreach ($results as $row) {
        $student_id = $row['student_id'];
        $is_repeated = ($student_id === $last_student_id);
        $last_student_id = $student_id;

        // Excel-safe string formatting to protect leading zeros and large numeric values
        $safe_receipt = ($row['receipt_id'] !== null) ? "\t" . $row['receipt_id'] : 'N/A';
        $payment_date = ($row['payment_date'] !== null) ? "\t" . $row['payment_date'] : 'N/A';
        $installment_amount = ($row['installment_amount'] !== null) ? number_format(floatval($row['installment_amount']), 2, '.', '') : '0.00';

        if ($is_repeated) {
            // Write row with empty non-payment fields
            fputcsv($output, [
                '', // Index Number
                '', // Name
                '', // NIC
                '', // Contact Number
                '', // Admission Paid
                '', // Plan Type
                '', // Base Fee
                '', // Final Total Expected
                '', // Total Paid So Far
                '', // Remaining Balance
                '', // Payment Status
                $safe_receipt,
                $payment_date,
                $installment_amount,
                '', // Certificate of Completion
                '', // English Course Certificate
                ''  // All Exam Records
            ]);
        } else {
            // Transform admission_paid boolean
            $admission_text = ($row['admission_paid'] == 1) ? 'Yes' : 'No';

            // Calculate remaining balance dynamically
            $final_total = floatval($row['final_total']);
            $total_paid = floatval($row['total_paid']);
            $remaining_balance = max($final_total - $total_paid, 0.00);

            // Calculate payment status dynamically
            $payment_status = 'Pending';
            if ($row['plan_type'] !== null && $row['plan_type'] !== 'pending') {
                if ($total_paid >= $final_total) {
                    $payment_status = 'Completed';
                }
            }

            // Format Plan Type for readable text
            $plan_type = 'Pending';
            if ($row['plan_type'] === 'full') {
                $plan_type = 'Full Payment';
            } elseif ($row['plan_type'] === 'installment') {
                $plan_type = 'Installment';
            }

            $safe_nic = "\t" . $row['nic'];
            $safe_contact = "\t" . $row['contact_no'];

            // Certificate text
            $cert_completion_text = ($row['cert_completion_issued'] == 1) ? 'Yes' : 'No';
            $english_cert_text = ($row['english_cert_issued'] == 1) ? 'Yes' : 'No';

            // Write row with full details
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
                $payment_status,
                $safe_receipt,
                $payment_date,
                $installment_amount,
                $cert_completion_text,
                $english_cert_text,
                $row['exam_records']
            ]);
        }
    }

    fclose($output);
    exit();

} catch (\Exception $e) {
    http_response_code(500);
    die("Error generating CSV: " . htmlspecialchars($e->getMessage()));
}
