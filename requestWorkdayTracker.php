/**
 * Client Workday Report Request System with Status Tracking
 * Add this to your functions.php or as a separate plugin file
 */

/* ========== AJAX HANDLER FOR REQUESTING WORKDAY REPORT ========== */
add_action('wp_ajax_va_request_workday_report', 'va_ajax_request_workday_report');
function va_ajax_request_workday_report() {
    check_ajax_referer('va_workday_nonce', 'nonce');
    
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'not_logged_in'));
    }
    
    $client_id = get_current_user_id();
    $va_ids = isset($_POST['va_ids']) ? $_POST['va_ids'] : array();
    $date_from = isset($_POST['date_from']) ? sanitize_text_field($_POST['date_from']) : '';
    $date_to = isset($_POST['date_to']) ? sanitize_text_field($_POST['date_to']) : '';
    $notes = isset($_POST['notes']) ? sanitize_textarea_field($_POST['notes']) : '';
    
    if (!is_array($va_ids)) {
        $va_ids = array($va_ids);
    }
    
    $va_ids = array_map('intval', array_filter($va_ids));
    
    if (empty($va_ids)) {
        wp_send_json_error(array('message' => 'Please select at least one VA'));
    }
    
    if (empty($date_from) || empty($date_to)) {
        wp_send_json_error(array('message' => 'Please provide date range'));
    }
    
    $client_user = get_userdata($client_id);
    $client_name = $client_user->display_name ?: $client_user->user_login;
    
    $successful_requests = 0;
    $manager_notifications = array();
    
    foreach ($va_ids as $va_id) {
        $va_user = get_userdata($va_id);
        if (!$va_user) continue;
        
        $va_name = $va_user->display_name ?: $va_user->user_login;
        
        // Get VA's manager
        $manager_name = va_get_static_manager($va_id);
        
        if (!$manager_name) continue;
        
        $manager_user_id = va_get_manager_user_id($manager_name);
        
        if (!$manager_user_id) continue;
        
        // Create report request record
        $request_data = array(
            'client_id' => $client_id,
            'client_name' => $client_name,
            'va_id' => $va_id,
            'va_name' => $va_name,
            'date_from' => $date_from,
            'date_to' => $date_to,
            'notes' => $notes,
            'status' => 'pending',
            'requested_date' => current_time('mysql'),
            'manager_id' => $manager_user_id,
            'report_file' => '' // Will be populated when manager uploads report
        );
        
        // Store the request using post meta for persistence
        $request_id = wp_insert_post(array(
            'post_type' => 'workday_request',
            'post_status' => 'publish',
            'post_title' => "Workday Report - {$va_name} - {$date_from} to {$date_to}",
            'post_author' => $client_id,
            'meta_input' => $request_data
        ));
        
        if ($request_id) {
            // Prepare manager notification
            if (!isset($manager_notifications[$manager_user_id])) {
                $manager_notifications[$manager_user_id] = array(
                    'vas' => array(),
                    'manager_name' => $manager_name
                );
            }
            
            $manager_notifications[$manager_user_id]['vas'][] = $va_name;
            $successful_requests++;
        }
    }
    
    // Send notifications to managers
    foreach ($manager_notifications as $manager_id => $data) {
        $vas_list = implode(', ', $data['vas']);
        $notification_message = "📊 Workday Report Request from {$client_name} for: {$vas_list} (Period: {$date_from} to {$date_to})";
        
        va_add_notification(
            $manager_id, 
            $notification_message,
            array(
                'type' => 'workday_report_request',
                'client_id' => $client_id,
                'date_from' => $date_from,
                'date_to' => $date_to
            )
        );
        
        // Send email to manager
        $manager_email = va_get_manager_email($data['manager_name']);
        if ($manager_email) {
            $email_subject = "Workday Report Request from {$client_name}";
            $email_body = va_build_workday_request_email($client_id, $data['vas'], $date_from, $date_to, $notes);
            wp_mail($manager_email, $email_subject, $email_body, array('Content-Type: text/html; charset=UTF-8'));
        }
    }
    
    // Notify client
    va_add_notification(
        $client_id,
        "✓ Workday report request sent successfully for {$successful_requests} VA(s)",
        array('type' => 'workday_report_sent')
    );
    
    wp_send_json_success(array(
        'message' => "Report request sent successfully for {$successful_requests} VA(s)",
        'count' => $successful_requests
    ));
}

/* ========== REGISTER CUSTOM POST TYPE FOR WORKDAY REQUESTS ========== */
add_action('init', 'va_register_workday_request_post_type');
function va_register_workday_request_post_type() {
    register_post_type('workday_request', array(
        'labels' => array(
            'name' => 'Workday Requests',
            'singular_name' => 'Workday Request'
        ),
        'public' => false,
        'show_ui' => false,
        'capability_type' => 'post',
        'supports' => array('title', 'custom-fields')
    ));
}

/* ========== GET CLIENT'S WORKDAY REQUESTS ========== */
function va_get_client_workday_requests($client_id, $limit = 20) {
    $args = array(
        'post_type' => 'workday_request',
        'post_status' => 'publish',
        'author' => $client_id,
        'posts_per_page' => $limit,
        'orderby' => 'date',
        'order' => 'DESC'
    );
    
    $requests = get_posts($args);
    $formatted_requests = array();
    
    foreach ($requests as $request) {
        $meta = get_post_meta($request->ID);
        $formatted_requests[] = array(
            'id' => $request->ID,
            'va_name' => isset($meta['va_name'][0]) ? $meta['va_name'][0] : 'Unknown',
            'date_from' => isset($meta['date_from'][0]) ? $meta['date_from'][0] : '',
            'date_to' => isset($meta['date_to'][0]) ? $meta['date_to'][0] : '',
            'status' => isset($meta['status'][0]) ? $meta['status'][0] : 'pending',
            'requested_date' => $request->post_date,
            'notes' => isset($meta['notes'][0]) ? $meta['notes'][0] : '',
            'report_file' => isset($meta['report_file'][0]) ? $meta['report_file'][0] : '',
            'completed_date' => isset($meta['completed_date'][0]) ? $meta['completed_date'][0] : ''
        );
    }
    
    return $formatted_requests;
}

/* ========== AJAX: LOAD WORKDAY REQUESTS ========== */
add_action('wp_ajax_va_load_workday_requests', 'va_ajax_load_workday_requests');
function va_ajax_load_workday_requests() {
    check_ajax_referer('va_workday_nonce', 'nonce');
    
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Not logged in'));
    }
    
    $client_id = get_current_user_id();
    $requests = va_get_client_workday_requests($client_id);
    
    wp_send_json_success(array('requests' => $requests));
}

/**
 * Build workday report request email for manager
 */
function va_build_workday_request_email($client_id, $va_names, $date_from, $date_to, $notes) {
    $client = get_userdata($client_id);
    $client_name = $client ? ($client->display_name ?: $client->user_login) : 'A client';
    $site_name = get_bloginfo('name');
    
    $vas_list = is_array($va_names) ? implode('<br>• ', $va_names) : $va_names;
    
    $body = "<html><body style='font-family: Arial, sans-serif; line-height: 1.6;'>
        <div style='max-width: 600px; margin: 0 auto; padding: 20px; background: #f9f9f9; border-radius: 10px;'>
            <h2 style='color: #2271b1; margin-top: 0;'>📊 Workday Report Request</h2>
            
            <div style='background: white; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                <p>Hi,</p>
                
                <p><strong>" . esc_html($client_name) . "</strong> has requested a workday report for the following VA(s):</p>
                
                <div style='background: #e8f4f8; padding: 15px; border-left: 4px solid #2271b1; margin: 15px 0;'>
                    <strong>📋 Request Details:</strong><br><br>
                    <strong>Client:</strong> " . esc_html($client_name) . "<br>
                    <strong>Email:</strong> " . esc_html($client->user_email) . "<br><br>
                    <strong>VA(s):</strong><br>
                    • " . $vas_list . "<br><br>
                    <strong>Report Period:</strong><br>
                    From: " . esc_html($date_from) . "<br>
                    To: " . esc_html($date_to) . "<br><br>
                    " . ($notes ? "<strong>Additional Notes:</strong><br>" . nl2br(esc_html($notes)) : "") . "
                </div>
                
                <p>Please prepare and submit the workday report for the requested period.</p>
                
                <p style='margin-top: 30px;'>
                    <a href='" . esc_url(home_url('/manager-dashboard/')) . "' 
                       style='background: #2271b1; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; display: inline-block;'>
                        View Dashboard
                    </a>
                </p>
            </div>
            
            <p style='color: #666; font-size: 12px; margin-top: 20px;'>
                — " . esc_html($site_name) . "<br>
                Requested on: " . date('F j, Y g:i A') . "
            </p>
        </div>
    </body></html>";
    
    return $body;
}

/* ========== CLIENT WORKDAY REPORT REQUEST SHORTCODE ========== */
add_shortcode('workday_report_request', 'va_sc_workday_report_request');
function va_sc_workday_report_request($atts) {
    if (!is_user_logged_in()) {
        return '<p>Please login to request workday reports.</p>';
    }
    
    $client_id = get_current_user_id();
    
    // Get client's selected VAs
    $selected_vas = va_get_selected_vas($client_id);
    $available_vas = array();
    
    foreach ($selected_vas as $va_id) {
        $va_user = get_userdata($va_id);
        if ($va_user) {
            $available_vas[] = array(
                'id' => $va_id,
                'name' => $va_user->display_name ?: $va_user->user_login
            );
        }
    }
    
    $nonce = wp_create_nonce('va_workday_nonce');
    
    ob_start();
    ?>
    
    <style>
    .workday-request-container {
        max-width: 1200px;
        margin: 0 auto;
    }
    
    .workday-request-btn {
        background: #2271b1;
        color: white;
        padding: 14px 28px;
        border: none;
        border-radius: 8px;
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
        transition: background 0.3s ease;
        box-shadow: 0 2px 8px rgba(34, 113, 177, 0.3);
        margin-bottom: 30px;
    }
    
    .workday-request-btn:hover {
        background: #1a5a8a;
    }
    
    /* Status Table Styles */
    .workday-status-section {
        margin-top: 40px;
    }
    
    .workday-status-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }
    
    .workday-status-header h3 {
        margin: 0;
        font-size: 22px;
        color: #333;
    }
    
    .refresh-requests-btn {
        background: #f0f0f0;
        color: #333;
        padding: 10px 20px;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-size: 14px;
        transition: background 0.2s;
    }
    
    .refresh-requests-btn:hover {
        background: #e0e0e0;
    }
    
    .workday-requests-table {
        width: 100%;
        background: white;
        border-radius: 10px;
        overflow: hidden;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
    }
    
    .workday-requests-table table {
        width: 100%;
        border-collapse: collapse;
    }
    
    .workday-requests-table th {
        background: #2271b1;
        color: white;
        padding: 16px;
        text-align: left;
        font-weight: 600;
        font-size: 14px;
    }
    
    .workday-requests-table td {
        padding: 16px;
        border-bottom: 1px solid #e5e7eb;
        font-size: 14px;
        color: #333;
    }
    
    .workday-requests-table tr:last-child td {
        border-bottom: none;
    }
    
    .workday-requests-table tr:hover {
        background: #f9fafb;
    }
    
    .status-badge {
        display: inline-block;
        padding: 6px 14px;
        border-radius: 20px;
        font-size: 13px;
        font-weight: 600;
        text-transform: capitalize;
    }
    
    .status-badge.pending {
        background: #fff3cd;
        color: #856404;
    }
    
    .status-badge.completed {
        background: #d4edda;
        color: #155724;
    }
    
    .status-badge.processing {
        background: #d1ecf1;
        color: #0c5460;
    }
    
    .view-report-btn {
        background: #28a745;
        color: white;
        padding: 8px 16px;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-size: 13px;
        font-weight: 600;
        transition: background 0.2s;
        text-decoration: none;
        display: inline-block;
    }
    
    .view-report-btn:hover {
        background: #218838;
    }
    
    .no-action {
        color: #999;
        font-style: italic;
        font-size: 13px;
    }
    
    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: #666;
    }
    
    .empty-state-icon {
        font-size: 48px;
        margin-bottom: 16px;
    }
    
    .empty-state h4 {
        margin: 0 0 10px 0;
        font-size: 18px;
        color: #333;
    }
    
    .empty-state p {
        margin: 0;
        font-size: 14px;
    }
    
    .loading-spinner {
        text-align: center;
        padding: 40px;
        color: #666;
    }
    
    /* Modal Styles */
    .workday-modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.6);
        z-index: 99999;
        align-items: center;
        justify-content: center;
    }
    
    .workday-modal.active {
        display: flex;
    }
    
    .workday-modal-content {
        background: white;
        border-radius: 16px;
        padding: 35px;
        max-width: 550px;
        width: 90%;
        max-height: 85vh;
        overflow-y: auto;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
        animation: slideIn 0.3s ease;
    }
    
    @keyframes slideIn {
        from {
            transform: translateY(-50px);
            opacity: 0;
        }
        to {
            transform: translateY(0);
            opacity: 1;
        }
    }
    
    .workday-modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 25px;
        padding-bottom: 15px;
        border-bottom: 2px solid #e5e7eb;
    }
    
    .workday-modal-header h3 {
        margin: 0;
        font-size: 24px;
        color: #2271b1;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .workday-modal-close {
        background: none;
        border: none;
        font-size: 32px;
        cursor: pointer;
        color: #999;
        padding: 0;
        width: 35px;
        height: 35px;
        line-height: 1;
        transition: color 0.2s;
    }
    
    .workday-modal-close:hover {
        color: #333;
    }
    
    .form-group {
        margin-bottom: 22px;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        color: #333;
        font-size: 15px;
    }
    
    .form-group input[type="date"],
    .form-group textarea {
        width: 100%;
        padding: 12px;
        border: 1px solid #ddd;
        border-radius: 8px;
        font-size: 15px;
        box-sizing: border-box;
        transition: border-color 0.2s;
    }
    
    .form-group input[type="date"]:focus,
    .form-group textarea:focus {
        outline: none;
        border-color: #2271b1;
    }
    
    .form-group textarea {
        resize: vertical;
        min-height: 80px;
    }
    
    .date-range-group {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 15px;
    }
    
    .va-selection-label {
        font-weight: 600;
        margin-bottom: 12px;
        color: #333;
        font-size: 15px;
    }
    
    .va-checkbox-list {
        max-height: 250px;
        overflow-y: auto;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        padding: 10px;
        background: #fafbfc;
    }
    
    .va-checkbox-item {
        display: flex;
        align-items: center;
        padding: 12px;
        margin-bottom: 8px;
        background: white;
        border-radius: 6px;
        cursor: pointer;
        transition: background 0.2s;
        border: 1px solid #e5e7eb;
    }
    
    .va-checkbox-item:hover {
        background: #f0f4ff;
        border-color: #2271b1;
    }
    
    .va-checkbox-item input[type="checkbox"] {
        margin-right: 12px;
        width: 18px;
        height: 18px;
        cursor: pointer;
    }
    
    .va-checkbox-item label {
        cursor: pointer;
        flex: 1;
        margin: 0;
        font-size: 15px;
        color: #333;
    }
    
    .selection-info {
        font-size: 13px;
        color: #666;
        margin-top: 8px;
        font-style: italic;
    }
    
    .modal-actions {
        display: flex;
        gap: 12px;
        margin-top: 25px;
        padding-top: 20px;
        border-top: 1px solid #e5e7eb;
    }
    
    .btn-submit-request {
        flex: 1;
        padding: 14px;
        background: #28a745;
        color: white;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        font-weight: 600;
        font-size: 16px;
        transition: background 0.3s ease;
    }
    
    .btn-submit-request:hover {
        background: #218838;
    }
    
    .btn-submit-request:disabled {
        background: #ccc;
        cursor: not-allowed;
    }
    
    .btn-cancel-request {
        flex: 1;
        padding: 14px;
        background: #6c757d;
        color: white;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        font-weight: 600;
        font-size: 16px;
        transition: background 0.3s ease;
    }
    
    .btn-cancel-request:hover {
        background: #5a6268;
    }
    
    .success-toast {
        position: fixed;
        bottom: 30px;
        right: 30px;
        background: #28a745;
        color: white;
        padding: 16px 24px;
        border-radius: 8px;
        font-size: 15px;
        font-weight: 600;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        opacity: 0;
        transform: translateY(20px);
        transition: opacity 0.3s ease, transform 0.3s ease;
        z-index: 999999;
    }
    
    .success-toast.show {
        opacity: 1;
        transform: translateY(0);
    }
    
    @media (max-width: 768px) {
        .workday-modal-content {
            padding: 25px;
        }
        
        .date-range-group {
            grid-template-columns: 1fr;
        }
        
        .workday-requests-table {
            overflow-x: auto;
        }
        
        .workday-status-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 15px;
        }
    }
    </style>
    
    <div class="workday-request-container">
        <div class="workday-request-wrap">
            <button class="workday-request-btn" id="open-workday-modal">
                📊 Request Workday Report
            </button>
        </div>
        
        <!-- Status Table Section -->
        <div class="workday-status-section">
            <div class="workday-status-header">
                <h3>📋 My Report Requests</h3>
                <button class="refresh-requests-btn" id="refresh-requests">
                    🔄 Refresh
                </button>
            </div>
            
            <div class="workday-requests-table" id="requests-table-container">
                <div class="loading-spinner">
                    <p>Loading requests...</p>
                </div>
            </div>
        </div>
    </div>
    
    <div class="workday-modal" id="workday-modal">
        <div class="workday-modal-content">
            <div class="workday-modal-header">
                <h3>📊 Request Workday Report</h3>
                <button class="workday-modal-close" id="close-workday-modal">&times;</button>
            </div>
            
            <form id="workday-request-form">
                <div class="form-group">
                    <div class="va-selection-label">Select VA(s) *</div>
                    <?php if (empty($available_vas)): ?>
                        <div style="padding: 20px; text-align: center; color: #666; background: #f8f9fa; border-radius: 8px;">
                            <p style="margin: 0;">You haven't selected any VAs yet.</p>
                            <p style="margin: 10px 0 0 0; font-size: 14px;">Please select VAs from your dashboard first.</p>
                        </div>
                    <?php else: ?>
                        <div class="va-checkbox-list">
                            <?php foreach ($available_vas as $va): ?>
                                <div class="va-checkbox-item">
                                    <input type="checkbox" 
                                           id="va-workday-<?php echo $va['id']; ?>" 
                                           name="va_ids[]" 
                                           value="<?php echo $va['id']; ?>" 
                                           class="workday-va-checkbox">
                                    <label for="va-workday-<?php echo $va['id']; ?>">
                                        <?php echo esc_html($va['name']); ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="selection-info">
                            <span id="workday-selected-count">0</span> VA(s) selected
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label>Report Period *</label>
                    <div class="date-range-group">
                        <div>
                            <input type="date" 
                                   id="workday-date-from" 
                                   name="date_from" 
                                   required
                                   max="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div>
                            <input type="date" 
                                   id="workday-date-to" 
                                   name="date_to" 
                                   required
                                   max="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="workday-notes">Additional Notes (Optional)</label>
                    <textarea id="workday-notes" 
                              name="notes" 
                              placeholder="Add any specific instructions or details..."
                              maxlength="500"></textarea>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn-cancel-request" id="cancel-workday-request">
                        Cancel
                    </button>
                    <button type="submit" class="btn-submit-request" id="submit-workday-request" disabled>
                        Send Request
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <div class="success-toast" id="workday-toast"></div>
    
    <script>
    jQuery(document).ready(function($) {
        var ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
        var nonce = '<?php echo $nonce; ?>';
        
        // Load requests on page load
        loadWorkdayRequests();
        
        // Open modal
        $('#open-workday-modal').on('click', function() {
            $('#workday-modal').addClass('active');
        });
        
        // Close modal
        $('#close-workday-modal, #cancel-workday-request').on('click', function() {
            closeModal();
        });
        
        // Close on outside click
        $('#workday-modal').on('click', function(e) {
            if ($(e.target).is('#workday-modal')) {
                closeModal();
            }
        });
        
        function closeModal() {
            $('#workday-modal').removeClass('active');
            $('#workday-request-form')[0].reset();
            $('.workday-va-checkbox').prop('checked', false);
            updateSelectedCount();
        }
        
        // Update selected count
        function updateSelectedCount() {
            var count = $('.workday-va-checkbox:checked').length;
            $('#workday-selected-count').text(count);
            
            var dateFrom = $('#workday-date-from').val();
            var dateTo = $('#workday-date-to').val();
            
            // Enable submit button if at least 1 VA and both dates are selected
            $('#submit-workday-request').prop('disabled', !(count > 0 && dateFrom && dateTo));
        }
        
        // Listen to checkbox changes
        $('.workday-va-checkbox').on('change', updateSelectedCount);
        
        // Listen to date changes
        $('#workday-date-from, #workday-date-to').on('change', updateSelectedCount);
        
        // Refresh requests
        $('#refresh-requests').on('click', function() {
            loadWorkdayRequests();
        });
        
        // Load workday requests
        function loadWorkdayRequests() {
            var container = $('#requests-table-container');
            container.html('<div class="loading-spinner"><p>Loading requests...</p></div>');
            
            $.post(ajaxurl, {
                action: 'va_load_workday_requests',
                nonce: nonce
            }, function(res) {
                if (res.success && res.data.requests) {
                    renderRequestsTable(res.data.requests);
                } else {
                    container.html('<div class="empty-state"><div class="empty-state-icon">📋</div><h4>No Requests Yet</h4><p>You haven\'t made any workday report requests yet.</p></div>');
                }
            }).fail(function() {
                container.html('<div class="empty-state"><div class="empty-state-icon">⚠️</div><h4>Error Loading Requests</h4><p>Please try again later.</p></div>');
            });
        }
        
        // Render requests table
        function renderRequestsTable(requests) {
            var container = $('#requests-table-container');
            
            if (requests.length === 0) {
                container.html('<div class="empty-state"><div class="empty-state-icon">📋</div><h4>No Requests Yet</h4><p>You haven\'t made any workday report requests yet.</p></div>');
                return;
            }
            
            var html = '<table><thead><tr>';
            html += '<th>VA Name</th>';
            html += '<th>Report Period</th>';
            html += '<th>Requested Date</th>';
            html += '<th>Status</th>';
            html += '<th>Action</th>';
            html += '</tr></thead><tbody>';
            
            requests.forEach(function(req) {
                var statusClass = req.status.toLowerCase();
                var actionHtml = '';
                
                if (req.status === 'completed' && req.report_file) {
                    actionHtml = '<a href="' + req.report_file + '" class="view-report-btn" target="_blank">📄 View Report</a>';
                } else if (req.status === 'pending') {
                    actionHtml = '<span class="no-action">Waiting for manager</span>';
                } else if (req.status === 'processing') {
                    actionHtml = '<span class="no-action">Being prepared...</span>';
                } else {
                    actionHtml = '<span class="no-action">—</span>';
                }
                
                var requestedDate = new Date(req.requested_date).toLocaleDateString('en-US', {
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric'
                });
                
                html += '<tr>';
                html += '<td><strong>' + escapeHtml(req.va_name) + '</strong></td>';
                html += '<td>' + req.date_from + ' to ' + req.date_to + '</td>';
                html += '<td>' + requestedDate + '</td>';
                html += '<td><span class="status-badge ' + statusClass + '">' + req.status + '</span></td>';
                html += '<td>' + actionHtml + '</td>';
                html += '</tr>';
            });
            
            html += '</tbody></table>';
            container.html(html);
        }
        
        // Escape HTML
        function escapeHtml(text) {
            var map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, function(m) { return map[m]; });
        }
        
        // Show toast notification
        function showToast(message, type) {
            var toast = $('#workday-toast');
            toast.css('background', type === 'success' ? '#28a745' : '#dc3545');
            toast.text(message).addClass('show');
            setTimeout(function() {
                toast.removeClass('show');
            }, 4000);
        }
        
        // Submit form
        $('#workday-request-form').on('submit', function(e) {
            e.preventDefault();
            
            var selectedVAs = [];
            $('.workday-va-checkbox:checked').each(function() {
                selectedVAs.push($(this).val());
            });
            
            if (selectedVAs.length === 0) {
                showToast('Please select at least one VA', 'error');
                return;
            }
            
            var dateFrom = $('#workday-date-from').val();
            var dateTo = $('#workday-date-to').val();
            
            if (!dateFrom || !dateTo) {
                showToast('Please select report period', 'error');
                return;
            }
            
            var notes = $('#workday-notes').val();
            var submitBtn = $('#submit-workday-request');
            
            submitBtn.prop('disabled', true).text('Sending...');
            
            $.post(ajaxurl, {
                action: 'va_request_workday_report',
                va_ids: selectedVAs,
                date_from: dateFrom,
                date_to: dateTo,
                notes: notes,
                nonce: nonce
            }, function(res) {
                if (res.success) {
                    showToast(res.data.message, 'success');
                    closeModal();
                    // Reload the requests table
                    setTimeout(function() {
                        loadWorkdayRequests();
                    }, 500);
                } else {
                    showToast(res.data.message || 'Failed to send request', 'error');
                    submitBtn.prop('disabled', false).text('Send Request');
                }
            }).fail(function() {
                showToast('Request failed. Please try again.', 'error');
                submitBtn.prop('disabled', false).text('Send Request');
            });
        });
    });
    </script>
    
    <?php
    return ob_get_clean();
}