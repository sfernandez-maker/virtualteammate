add_shortcode('my_payslip', 'display_user_payslip');
function display_user_payslip() {
    // Check if user is logged in
    if (!is_user_logged_in()) {
        return '<p>Please login to view your payslip.</p>';
    }
    
    $current_user = wp_get_current_user();
    $user_email = sanitize_email($current_user->user_email);
    
    // Use the published CSV link
    // IMPORTANT: Add a timestamp to prevent caching
    $csv_url = "https://docs.google.com/spreadsheets/d/e/2PACX-1vTE4PXuJwIQff08Wl16hpKxPIbqUB3QA5yCF8IAyoG5_lf7pYoQWEFpO842E6iskZ3ACZj7BurC8bwa/pub?output=csv&timestamp=" . time();
    
    // Try to fetch the CSV with error handling
    $response = wp_remote_get($csv_url, array(
        'timeout' => 15,
        'sslverify' => true
    ));
    
    // Check for errors
    if (is_wp_error($response)) {
        error_log('Payslip fetch error: ' . $response->get_error_message());
        return '<p>Unable to load payslip data. Please try again later.</p>';
    }
    
    $body = wp_remote_retrieve_body($response);
    
    if (empty($body)) {
        return '<p>No payslip data available. Please make sure the Google Sheet is published to the web.</p>';
    }
    
    // Parse CSV data
    $lines = explode("\n", $body);
    $rows = array_map('str_getcsv', $lines);
    
    // Remove empty rows
    $rows = array_filter($rows, function($row) {
        return !empty(array_filter($row));
    });
    
    if (empty($rows)) {
        return '<p>No payslip data found in the sheet.</p>';
    }
    
    // Get header row and trim all whitespace
    $header = array_shift($rows);
    $header = array_map('trim', $header);
    
    // DEBUG MODE - Enable this to see what's happening
    $debug_mode = false; // Set to true if you need to troubleshoot
    
    if ($debug_mode) {
        $debug_info = '<div style="background:#f0f0f0; padding:15px; margin:20px 0; border-radius:5px;">';
        $debug_info .= '<h4>Debug Information:</h4>';
        $debug_info .= '<p><strong>Your email:</strong> ' . esc_html($user_email) . '</p>';
        $debug_info .= '<p><strong>Headers found:</strong> ' . esc_html(implode(' | ', $header)) . '</p>';
        $debug_info .= '<p><strong>Total rows in sheet:</strong> ' . count($rows) . '</p>';
        
        // Check if email column exists
        $email_col_index = array_search('VT Email', $header);
        $debug_info .= '<p><strong>VT Email column found:</strong> ' . ($email_col_index !== false ? 'Yes (column ' . $email_col_index . ')' : 'NO - THIS IS THE PROBLEM!') . '</p>';
        
        // Show first few emails in the sheet
        $debug_info .= '<p><strong>Sample emails from sheet:</strong><br>';
        $sample_count = 0;
        foreach ($rows as $row) {
            if ($sample_count >= 5) break;
            if (count($row) > $email_col_index && $email_col_index !== false) {
                $debug_info .= '- ' . esc_html(trim($row[$email_col_index])) . '<br>';
                $sample_count++;
            }
        }
        $debug_info .= '</p></div>';
    }
    
    // Find matching payslips for this user
    $payslips = array();
    foreach ($rows as $row) {
        // Skip if row doesn't have enough columns
        if (count($row) < count($header)) continue;
        
        // Trim all values in the row
        $row = array_map('trim', $row);
        $row_assoc = array_combine($header, $row);
        
        // Match by "VT Email" column (case-insensitive)
        if (isset($row_assoc['VT Email']) && 
            strtolower(trim($row_assoc['VT Email'])) === strtolower($user_email)) {
            $payslips[] = $row_assoc;
        }
    }
    
    // Show debug info if enabled
    if ($debug_mode) {
        $output = $debug_info;
        $output .= '<p><strong>Payslips found:</strong> ' . count($payslips) . '</p>';
        
        if (!empty($payslips)) {
            $output .= '<pre style="background:#e8f5e9; padding:10px; overflow:auto;">';
            $output .= esc_html(print_r($payslips, true));
            $output .= '</pre>';
        }
    } else {
        $output = '';
    }
    
    // No payslips found for this user
    if (empty($payslips)) {
        return $output . '<div class="payslip-no-data">
            <p>No payslips found for your account.</p>
            <p style="font-size: 12px; color: #666;">Email on file: ' . esc_html($user_email) . '</p>
            <p style="font-size: 12px; color: #999;">If you believe this is an error, please contact HR.</p>
        </div>';
    }
    
    // Build HTML table
    ob_start();
    ?>
    <style>
        .payslip-container {
            max-width: 1200px;
            margin: 20px auto;
            padding: 20px;
        }
        .payslip-header {
            margin-bottom: 20px;
        }
        .payslip-header h3 {
            color: #333;
            margin-bottom: 5px;
        }
        .payslip-info {
            font-size: 14px;
            color: #666;
        }
        .payslip-table-wrapper {
            overflow-x: auto;
            margin: 20px 0;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .payslip-table {
            width: 100%;
            border-collapse: collapse;
        }
        .payslip-table th {
            background: #4E46DC;
            color: white;
            padding: 14px 12px;
            text-align: left;
            font-weight: 600;
            font-size: 13px;
            white-space: nowrap;
        }
        .payslip-table td {
            padding: 12px;
            border-bottom: 1px solid #eee;
            font-size: 14px;
        }
        .payslip-table tbody tr:hover {
            background: #f8f9fa;
        }
        .payslip-table tbody tr:last-child td {
            border-bottom: none;
        }
        .payslip-no-data {
            text-align: center;
            padding: 40px 20px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        /* Highlight important columns */
        .payslip-table td.total-pay {
            font-weight: 700;
            color: #28a745;
        }
        
        @media (max-width: 768px) {
            .payslip-table {
                font-size: 12px;
            }
            .payslip-table th,
            .payslip-table td {
                padding: 8px 6px;
            }
        }
    </style>
    
    <div class="payslip-container">
        <div class="payslip-header">
            <h3>📄 Your Payslips</h3>
            <div class="payslip-info">
                Showing <?php echo count($payslips); ?> payslip(s) for: <strong><?php echo esc_html($user_email); ?></strong>
            </div>
        </div>
        
        <div class="payslip-table-wrapper">
            <table class="payslip-table">
                <thead>
                    <tr>
                        <?php foreach ($header as $col): ?>
                            <?php if ($col !== ''): // Skip empty columns ?>
                                <th><?php echo esc_html($col); ?></th>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payslips as $payslip): ?>
                        <tr>
                            <?php foreach ($header as $col): ?>
                                <?php if ($col !== ''): ?>
                                    <td <?php echo ($col === 'Total Pay') ? 'class="total-pay"' : ''; ?>>
                                        <?php echo esc_html($payslip[$col] ?? ''); ?>
                                    </td>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    
    return ob_get_clean();
}