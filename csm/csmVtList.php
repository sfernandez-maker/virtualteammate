add_shortcode('manager_va_dashboard', function () {

    if (!is_user_logged_in()) {
        return '<p>Please login to view this dashboard.</p>';
    }

    $manager_id = get_current_user_id();
    
    // Check if user is a manager
    if (!va_is_manager($manager_id)) {
        return '<p>You do not have manager privileges.</p>';
    }

    // =============================
    // CACHE CLEARING (for admins)
    // =============================
    if (current_user_can('administrator') && isset($_GET['clear_hs_cache'])) {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_hs_company_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_hs_company_%'");
        echo '<div style="padding:10px;background:#d4edda;color:#155724;margin:10px 0;border-radius:4px;">HubSpot company cache cleared! <a href="' . remove_query_arg('clear_hs_cache') . '">Reload page</a></div>';
    }

    // =============================
    // HUBSPOT CONFIG
    // =============================
    // In wp-config.php (near the top, after the opening php tag) put "define('HUBSPOT_PRIVATE_TOKEN', 'your_actual_token_here');
    $HUBSPOT_TOKEN = defined('HUBSPOT_PRIVATE_TOKEN') ? HUBSPOT_PRIVATE_TOKEN : '';
    $COMPANY_CACHE_TTL = 12 * HOUR_IN_SECONDS;

    // =============================
    // HUBSPOT REQUEST HELPER (same as invoice viewer)
    // =============================
    $hubspot_request = function (string $url, array $body = null) use ($HUBSPOT_TOKEN) {
        $args = [
            'headers' => [
                'Authorization' => 'Bearer ' . $HUBSPOT_TOKEN,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'timeout'     => 15,
            'redirection' => 0,
        ];

        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
            $response = wp_remote_post($url, $args);
        } else {
            $response = wp_remote_get($url, $args);
        }

        if (is_wp_error($response)) {
            return ['ok' => false, 'error' => $response->get_error_message(), 'data' => null, 'code' => 0];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw  = wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);

        if ($code < 200 || $code >= 300) {
            $msg = 'HubSpot request failed.';
            if (is_array($data) && !empty($data['message'])) {
                $msg .= ' ' . $data['message'];
            }
            return ['ok' => false, 'error' => $msg, 'data' => $data, 'code' => $code];
        }

        return ['ok' => true, 'error' => null, 'data' => $data, 'code' => $code];
    };

    // =============================
    // HUBSPOT COMPANY LOOKUP (same logic as invoice viewer)
    // =============================
    $get_company_from_hubspot = function($email, $user_id = 0) use ($hubspot_request, $COMPANY_CACHE_TTL) {
        if (empty($email)) {
            return '';
        }

        // Normalize email
        $email = strtolower(trim($email));

        // Check cache first
        $cache_key = 'hs_company_v2_' . md5($email);
        $cached = get_transient($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }

        // Search for contact by email with associations
        $contactSearchBody = [
            "filterGroups" => [[
                "filters" => [[
                    "propertyName" => "email",
                    "operator"     => "EQ",
                    "value"        => $email,
                ]]
            ]],
            "properties" => ["email", "firstname", "lastname", "company"],
            "associations" => ["companies"],
            "limit" => 1,
        ];

        $contactResp = $hubspot_request('https://api.hubapi.com/crm/v3/objects/contacts/search', $contactSearchBody);

        if (!$contactResp['ok']) {
            // Don't cache errors
            return '';
        }

        $contactBody = $contactResp['data'];
        if (empty($contactBody['results'][0]['id'])) {
            // Cache empty result for 30 minutes
            set_transient($cache_key, '', 30 * MINUTE_IN_SECONDS);
            return '';
        }

        $contact = $contactBody['results'][0];
        $contactId = $contact['id'];
        $companyName = '';
        $companyId = '';

        // Try to get company name from contact properties
        if (!empty($contact['properties']['company'])) {
            $companyName = trim($contact['properties']['company']);
        }

        // Try to get company ID from associations in search response
        if (!empty($contact['associations']['companies']['results'][0]['id'])) {
            $companyId = $contact['associations']['companies']['results'][0]['id'];
        }

        // Fallback: use v4 associations API if not found
        if (empty($companyId)) {
            $assocResp = $hubspot_request("https://api.hubapi.com/crm/v4/objects/contacts/{$contactId}/associations/companies");
            
            if ($assocResp['ok'] && !empty($assocResp['data']['results'][0]['toObjectId'])) {
                $companyId = $assocResp['data']['results'][0]['toObjectId'];
            }
        }

        // If still no company name but we have ID, fetch company details
        if (!empty($companyId) && empty($companyName)) {
            $companyResp = $hubspot_request("https://api.hubapi.com/crm/v3/objects/companies/{$companyId}?properties=name");
            if ($companyResp['ok'] && !empty($companyResp['data']['properties']['name'])) {
                $companyName = trim($companyResp['data']['properties']['name']);
            }
        }

        // Cache the result
        if (!empty($companyName)) {
            set_transient($cache_key, $companyName, $COMPANY_CACHE_TTL);
        } else {
            set_transient($cache_key, '', 30 * MINUTE_IN_SECONDS);
        }
        
        return $companyName;
    };

    // Get manager's name/slug
    $manager_user = get_userdata($manager_id);
    $manager_slug = $manager_user->user_nicename;
    
    // Build company => clients => VAs mapping (ONLY for VAs under this manager)
    $companies_data = array();
    
    // Get all users in the system
    $all_users = get_users();
    
    foreach ($all_users as $user) {
        // Skip managers, VAs, and other non-client roles
        if (va_is_manager($user->ID) || in_array('um_ambassador', $user->roles)) {
            continue;
        }
        
        // Get their selected VAs first to see if they have any
        $selected_vas = get_user_meta($user->ID, 'selected_vas', true);
        
        // If empty, try alternative meta keys
        if (empty($selected_vas)) {
            $selected_vas = get_user_meta($user->ID, '_selected_vas', true);
        }
        if (empty($selected_vas)) {
            $selected_vas = get_user_meta($user->ID, '_va_selected', true);
        }
        if (empty($selected_vas)) {
            $selected_vas = get_user_meta($user->ID, 'va_selected', true);
        }
        
        // Ensure it's an array
        if (!is_array($selected_vas)) {
            $selected_vas = !empty($selected_vas) ? array($selected_vas) : array();
        }
        
        if (!empty($selected_vas)) {
            $manager_vas = array(); // VAs that belong to THIS manager
            
            foreach ($selected_vas as $va_id) {
                // Check if this VA belongs to the current manager
                $va_manager = va_get_static_manager($va_id);
                
                if ($va_manager === $manager_slug) {
                    // This VA belongs to this manager!
                    $va_user = get_userdata($va_id);
                    if ($va_user) {
                        // Get manager approval status
                        $manager_status = get_user_meta($user->ID, '_manager_approval_' . $va_id, true);
                        
                        // Get VA acceptance status
                        $va_status = get_user_meta($user->ID, '_va_status_' . $va_id, true);
                        
                        $va_name = $va_user->display_name ?: $va_user->user_login;
                        
                        $manager_vas[$va_id] = array(
                            'name' => ucwords(strtolower($va_name)),
                            'email' => $va_user->user_email,
                            'manager_status' => $manager_status ?: 'pending',
                            'va_status' => $va_status ?: 'pending'
                        );
                    }
                }
            }
            
            // Only add client if they have VAs under this manager
            if (!empty($manager_vas)) {
                // Get company name from HubSpot using user's email (same as invoice viewer)
                $company_name = $get_company_from_hubspot($user->user_email, $user->ID);
                
                // Fallback to WordPress meta if HubSpot doesn't return anything
                if (empty($company_name)) {
                    $company_name = get_user_meta($user->ID, 'company', true);
                }
                if (empty($company_name)) {
                    $company_name = get_user_meta($user->ID, 'billing_company', true);
                }
                if (empty($company_name)) {
                    $company_name = get_user_meta($user->ID, 'company_name', true);
                }
                
                // If still no company, use client name as fallback
                if (empty($company_name)) {
                    $company_name = ($user->display_name ?: $user->user_login);
                }
                
                $client_name = $user->display_name ?: $user->user_login;
                
                // Initialize company if not exists
                if (!isset($companies_data[$company_name])) {
                    $companies_data[$company_name] = array(
                        'clients' => array()
                    );
                }
                
                // Add client to company
                $companies_data[$company_name]['clients'][$user->ID] = array(
                    'name' => ucwords(strtolower($client_name)),
                    'email' => $user->user_email,
                    'vas' => $manager_vas
                );
            }
        }
    }
    
    // Sort companies alphabetically
    ksort($companies_data);
    
    $select_nonce = wp_create_nonce('va_select_nonce');

    ob_start();
    ?>
    
    <div class="manager-va-dashboard">
        
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h3 style="margin: 0;">Virtual Teammate Management</h3>
            <?php if (current_user_can('administrator')): ?>
                <a href="?clear_hs_cache=1" style="font-size: 12px; padding: 6px 12px; background: #666; color: white; text-decoration: none; border-radius: 4px;">Clear HubSpot Cache</a>
            <?php endif; ?>
        </div>
        
        <?php if (empty($companies_data)): ?>
            <p style="padding: 20px; text-align: center; color: #666;">No companies with VAs found yet.</p>
        <?php else: ?>
        
        <div class="company-list">
            <?php foreach ($companies_data as $company_name => $company_info): ?>
            <div class="company-item" data-company-name="<?php echo esc_attr($company_name); ?>">
                <div class="company-header" onclick="toggleCompany('<?php echo esc_attr(md5($company_name)); ?>')">
                    <div>
                        <strong><?php echo esc_html($company_name); ?></strong>
                        <?php 
                        $total_vas = 0;
                        foreach ($company_info['clients'] as $client) {
                            $total_vas += count($client['vas']);
                        }
                        ?>
                        <span class="va-count">(<?php echo $total_vas; ?> VA<?php echo $total_vas !== 1 ? 's' : ''; ?> • <?php echo count($company_info['clients']); ?> Contact<?php echo count($company_info['clients']) !== 1 ? 's' : ''; ?>)</span>
                    </div>
                    <span class="toggle-icon">▼</span>
                </div>
                
                <div class="company-container" id="company-container-<?php echo esc_attr(md5($company_name)); ?>" style="display: none;">
                    <?php foreach ($company_info['clients'] as $client_id => $client_info): ?>
                    <div class="client-section" data-client-id="<?php echo esc_attr($client_id); ?>">
                        <div class="client-name-header">
                            <strong>Contact:</strong> <?php echo esc_html($client_info['name']); ?>
                            <span class="client-email">(<?php echo esc_html($client_info['email']); ?>)</span>
                        </div>
                        
                        <div class="va-list">
                            <?php foreach ($client_info['vas'] as $va_id => $va_info): ?>
                            <div class="va-item" data-va-id="<?php echo esc_attr($va_id); ?>">
                                <div class="va-item-header">
                                    <label>
                                        <input type="checkbox" 
                                               class="va-checkbox" 
                                               value="<?php echo esc_attr($va_id); ?>" 
                                               data-client="<?php echo esc_attr($client_id); ?>"
                                               data-company="<?php echo esc_attr(md5($company_name)); ?>">
                                        <span class="va-name"><?php echo esc_html($va_info['name']); ?></span>
                                    </label>
                                    
                                    <div class="va-status-badges">
                                        <?php if ($va_info['manager_status'] === 'approved'): ?>
                                            <span class="status-badge approved">✓ Approved</span>
                                        <?php elseif ($va_info['manager_status'] === 'declined'): ?>
                                            <span class="status-badge declined">✗ Declined</span>
                                        <?php else: ?>
                                            <span class="status-badge pending">⏳ Pending</span>
                                        <?php endif; ?>
                                        
                                        <?php if ($va_info['va_status'] === 'accepted'): ?>
                                            <span class="status-badge va-accepted">VA Accepted</span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if ($va_info['manager_status'] === 'pending'): ?>
                                    <div class="approval-buttons">
                                        <button class="btn-approve" 
                                                onclick="approveVA('<?php echo esc_attr($client_id); ?>', '<?php echo esc_attr($va_id); ?>')"
                                                title="Approve this VA selection">
                                            ✓ Approve
                                        </button>
                                        <button class="btn-decline-approval" 
                                                onclick="declineVA('<?php echo esc_attr($client_id); ?>', '<?php echo esc_attr($va_id); ?>')"
                                                title="Decline this VA selection">
                                            ✗ Decline
                                        </button>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="actions">
                            <button class="remove-va-btn" 
                                    onclick="removeSelectedVAs('<?php echo esc_attr($client_id); ?>', '<?php echo esc_attr(md5($company_name)); ?>')" 
                                    disabled>
                                Remove Selected VA(s)
                            </button>
                            <span class="selected-count" id="selected-count-<?php echo esc_attr($client_id); ?>">
                                0 selected
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <?php endif; ?>
        
        <div id="result-message" style="margin-top: 20px; padding: 10px; display: none;"></div>
        
    </div>

    <!-- Confirmation Modal -->
    <div id="confirmModal" class="modal-overlay" style="display: none;">
        <div class="modal-content">
            <h3 id="modalTitle">Confirm Action</h3>
            <p id="modalMessage">Are you sure you want to proceed?</p>
            <div class="modal-buttons">
                <button id="modalConfirm" class="btn-confirm">Confirm</button>
                <button id="modalCancel" class="btn-cancel">Cancel</button>
            </div>
        </div>
    </div>

    <style>
        .manager-va-dashboard {
            max-width: 900px;
            margin: 0 auto;
            padding: 20px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            background: #f9f9f9;
        }
        
        .company-list {
            max-height: 600px;
            overflow-y: auto;
            padding-right: 6px;
        }

        .company-list::-webkit-scrollbar {
            width: 6px;
        }

        .company-list::-webkit-scrollbar-thumb {
            background: rgba(0,0,0,0.2);
            border-radius: 4px;
        }

        .company-list::-webkit-scrollbar-track {
            background: transparent;
        }
        
        .company-item {
            margin-bottom: 15px;
            border: 2px solid #3919BA;
            border-radius: 8px;
            overflow: hidden;
            background: white;
        }
        
        .company-header {
            padding: 18px;
            background: #3919BA;
            color: white;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: background 0.3s;
            font-size: 16px;
        }
        
        .company-header:hover {
            background: #2d1494;
        }
        
        .va-count {
            font-size: 0.85em;
            opacity: 0.9;
            margin-left: 10px;
            font-weight: normal;
        }
        
        .toggle-icon {
            font-size: 0.8em;
            transition: transform 0.3s;
        }
        
        .company-item.expanded .toggle-icon {
            transform: rotate(180deg);
        }
        
        .company-container {
            padding: 20px;
            background: #f8f9fa;
        }
        
        .client-section {
            margin-bottom: 20px;
            padding: 15px;
            background: white;
            border-radius: 6px;
            border: 1px solid #e0e0e0;
        }
        
        .client-section:last-child {
            margin-bottom: 0;
        }
        
        .client-name-header {
            padding: 10px 0;
            margin-bottom: 15px;
            border-bottom: 2px solid #e0e0e0;
            font-size: 14px;
        }
        
        .client-email {
            color: #666;
            font-weight: normal;
            font-size: 0.9em;
            margin-left: 8px;
        }
        
        .va-list {
            margin-bottom: 15px;
        }
        
        .va-item {
            padding: 15px;
            border-bottom: 1px solid #f0f0f0;
            transition: background 0.2s;
        }
        
        .va-item:hover {
            background: #f8f9fa;
        }
        
        .va-item:last-child {
            border-bottom: none;
        }
        
        .va-item-header {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .va-item-header > label {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
        }
        
        .va-checkbox {
            margin-right: 8px;
        }
        
        .va-name {
            flex: 1;
            font-weight: 500;
        }
        
        .va-status-badges {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-left: 30px;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-badge.pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-badge.approved {
            background: #d4edda;
            color: #155724;
        }
        
        .status-badge.declined {
            background: #f8d7da;
            color: #721c24;
        }
        
        .status-badge.va-accepted {
            background: #cfe2ff;
            color: #084298;
        }
        
        .approval-buttons {
            display: flex;
            gap: 8px;
            margin-left: 30px;
        }
        
        .btn-approve {
            padding: 6px 12px;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            transition: background 0.2s;
        }
        
        .btn-approve:hover {
            background: #218838;
        }
        
        .btn-decline-approval {
            padding: 6px 12px;
            background: #ffc107;
            color: #000;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            transition: background 0.2s;
        }
        
        .btn-decline-approval:hover {
            background: #e0a800;
        }
        
        .actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 15px;
            border-top: 1px solid #eee;
            margin-top: 10px;
        }
        
        .remove-va-btn {
            padding: 8px 16px;
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            transition: background 0.3s;
        }
        
        .remove-va-btn:hover:not(:disabled) {
            background: #c82333;
        }
        
        .remove-va-btn:disabled {
            background: #6c757d;
            cursor: not-allowed;
        }
        
        .selected-count {
            font-size: 0.9em;
            color: #666;
        }
        
        #result-message {
            display: none;
        }
        
        #result-message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
            border-radius: 4px;
        }
        
        #result-message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
            border-radius: 4px;
        }
        
        .va-item.removing {
            opacity: 0.5;
        }
        
        .va-item.removing .va-name {
            text-decoration: line-through;
        }

        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 10000;
            animation: fadeIn 0.2s;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
            max-width: 500px;
            width: 90%;
            animation: slideIn 0.3s;
        }

        @keyframes slideIn {
            from { 
                transform: translateY(-30px);
                opacity: 0;
            }
            to { 
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-content h3 {
            margin: 0 0 15px 0;
            color: #333;
            font-size: 20px;
        }

        .modal-content p {
            margin: 0 0 25px 0;
            color: #666;
            font-size: 15px;
            line-height: 1.5;
        }

        .modal-buttons {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        .btn-confirm, .btn-cancel {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.2s;
        }

        .btn-confirm {
            background: #28a745;
            color: white;
        }

        .btn-confirm:hover {
            background: #218838;
        }

        .btn-confirm.decline-action {
            background: #ffc107;
            color: #000;
        }

        .btn-confirm.decline-action:hover {
            background: #e0a800;
        }

        .btn-confirm.remove-action {
            background: #dc3545;
            color: white;
        }

        .btn-confirm.remove-action:hover {
            background: #c82333;
        }

        .btn-cancel {
            background: #6c757d;
            color: white;
        }

        .btn-cancel:hover {
            background: #5a6268;
        }
    </style>

    <script>
        var ajaxurl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        var selectNonce = <?php echo wp_json_encode($select_nonce); ?>;
        
        // Modal functionality
        function showModal(title, message, onConfirm, actionType = 'approve') {
            const modal = document.getElementById('confirmModal');
            const modalTitle = document.getElementById('modalTitle');
            const modalMessage = document.getElementById('modalMessage');
            const confirmBtn = document.getElementById('modalConfirm');
            const cancelBtn = document.getElementById('modalCancel');
            
            modalTitle.textContent = title;
            modalMessage.textContent = message;
            
            // Reset button classes
            confirmBtn.className = 'btn-confirm';
            
            // Add specific class based on action type
            if (actionType === 'decline') {
                confirmBtn.classList.add('decline-action');
            } else if (actionType === 'remove') {
                confirmBtn.classList.add('remove-action');
            }
            
            modal.style.display = 'flex';
            
            // Remove old event listeners by cloning
            const newConfirmBtn = confirmBtn.cloneNode(true);
            confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);
            
            const newCancelBtn = cancelBtn.cloneNode(true);
            cancelBtn.parentNode.replaceChild(newCancelBtn, cancelBtn);
            
            // Add new event listeners
            document.getElementById('modalConfirm').addEventListener('click', function() {
                modal.style.display = 'none';
                if (onConfirm) onConfirm();
            });
            
            document.getElementById('modalCancel').addEventListener('click', function() {
                modal.style.display = 'none';
            });
            
            // Close on overlay click
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    modal.style.display = 'none';
                }
            });
        }
        
        function toggleCompany(companyHash) {
            const container = document.getElementById('company-container-' + companyHash);
            const companyItem = container.closest('.company-item');
            
            if (container.style.display === 'none') {
                container.style.display = 'block';
                companyItem.classList.add('expanded');
            } else {
                container.style.display = 'none';
                companyItem.classList.remove('expanded');
            }
        }
        
        // Update checkbox selections
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.va-checkbox').forEach(function(checkbox) {
                checkbox.addEventListener('change', function() {
                    const clientId = this.getAttribute('data-client');
                    updateSelectedCount(clientId);
                });
            });
        });
        
        function updateSelectedCount(clientId) {
            const checkboxes = document.querySelectorAll('.va-checkbox[data-client="' + clientId + '"]');
            const selectedCount = document.getElementById('selected-count-' + clientId);
            const removeBtn = document.querySelector('[data-client-id="' + clientId + '"] .remove-va-btn');
            
            let count = 0;
            checkboxes.forEach(function(cb) {
                if (cb.checked) count++;
            });
            
            selectedCount.textContent = count + ' selected';
            removeBtn.disabled = count === 0;
        }
        
        function showMessage(message, type) {
            const resultMessage = document.getElementById('result-message');
            resultMessage.className = type;
            resultMessage.innerHTML = message;
            resultMessage.style.display = 'block';
            
            setTimeout(function() {
                resultMessage.style.display = 'none';
            }, 5000);
        }
        
        function approveVA(clientId, vaId) {
            showModal(
                'Approve VT',
                'Do you want to approve this VT selection?',
                function() {
                    // Get VA name for better feedback
                    const vaItem = document.querySelector('.va-item[data-va-id="' + vaId + '"]');
                    const vaName = vaItem ? vaItem.querySelector('.va-name').textContent : 'VA';
                    
                    jQuery.post(ajaxurl, {
                        action: 'va_manager_approve_va',
                        client_id: clientId,
                        va_user_id: vaId,
                        nonce: selectNonce
                    }, function(res) {
                        if (res.success) {
                            showMessage(vaName + ' approved successfully!', 'success');
                            
                            // Update UI without refresh
                            const statusBadge = vaItem.querySelector('.status-badge.pending');
                            if (statusBadge) {
                                statusBadge.className = 'status-badge approved';
                                statusBadge.textContent = '✓ Approved';
                            }
                            
                            // Remove approval buttons
                            const approvalButtons = vaItem.querySelector('.approval-buttons');
                            if (approvalButtons) {
                                approvalButtons.style.transition = 'opacity 0.3s';
                                approvalButtons.style.opacity = '0';
                                setTimeout(function() {
                                    approvalButtons.remove();
                                }, 300);
                            }
                        } else {
                            showMessage(res.data.message || 'Failed to approve VA', 'error');
                        }
                    }).fail(function() {
                        showMessage('Request failed. Please try again.', 'error');
                    });
                },
                'approve'
            );
        }
        
        function declineVA(clientId, vaId) {
            showModal(
                'Decline VT',
                'Do you want to decline this VT selection?',
                function() {
                    // Get VA name for better feedback
                    const vaItem = document.querySelector('.va-item[data-va-id="' + vaId + '"]');
                    const vaName = vaItem ? vaItem.querySelector('.va-name').textContent : 'VA';
                    
                    jQuery.post(ajaxurl, {
                        action: 'va_manager_decline_va',
                        client_id: clientId,
                        va_user_id: vaId,
                        nonce: selectNonce
                    }, function(res) {
                        if (res.success) {
                            showMessage(vaName + ' selection declined.', 'success');
                            
                            // Update UI without refresh
                            const statusBadge = vaItem.querySelector('.status-badge.pending');
                            if (statusBadge) {
                                statusBadge.className = 'status-badge declined';
                                statusBadge.textContent = '✗ Declined';
                            }
                            
                            // Remove approval buttons
                            const approvalButtons = vaItem.querySelector('.approval-buttons');
                            if (approvalButtons) {
                                approvalButtons.style.transition = 'opacity 0.3s';
                                approvalButtons.style.opacity = '0';
                                setTimeout(function() {
                                    approvalButtons.remove();
                                }, 300);
                            }
                        } else {
                            showMessage(res.data.message || 'Failed to decline VA', 'error');
                        }
                    }).fail(function() {
                        showMessage('Request failed. Please try again.', 'error');
                    });
                },
                'decline'
            );
        }
        
        function removeSelectedVAs(clientId, companyHash) {
            const checkboxes = document.querySelectorAll('.va-checkbox[data-client="' + clientId + '"]:checked');
            const clientSection = document.querySelector('[data-client-id="' + clientId + '"]');
            const resultMessage = document.getElementById('result-message');
            
            if (checkboxes.length === 0) {
                resultMessage.className = 'error';
                resultMessage.innerHTML = 'Please select at least one VA to remove.';
                resultMessage.style.display = 'block';
                setTimeout(function() {
                    resultMessage.style.display = 'none';
                }, 5000);
                return;
            }
            
            const count = checkboxes.length;
            const vaNames = [];
            checkboxes.forEach(function(cb) {
                const vaItem = cb.closest('.va-item');
                const vaName = vaItem.querySelector('.va-name').textContent;
                vaNames.push(vaName);
            });
            
            const vaList = vaNames.length <= 3 ? vaNames.join(', ') : count + ' VAs';
            
            showModal(
                'Remove VT' + (count > 1 ? 's' : ''),
                'Are you sure you want to remove ' + vaList + '? This will:\n\n• Remove the VT from the client\'s selected list\n• End their active conversation',
                function() {
                    const vaIds = [];
                    checkboxes.forEach(function(cb) {
                        vaIds.push(parseInt(cb.value));
                        const vaItem = cb.closest('.va-item');
                        if (vaItem) {
                            vaItem.classList.add('removing');
                            cb.disabled = true;
                        }
                    });
                    
                    let processed = 0;
                    let errors = 0;
                    
                    vaIds.forEach(function(vaId) {
                        jQuery.post(ajaxurl, {
                            action: 'va_manager_remove_va',
                            client_id: clientId,
                            va_user_id: vaId,
                            nonce: selectNonce
                        }, function(res) {
                            processed++;
                            
                            if (res.success) {
                                const vaItem = document.querySelector('.va-item[data-va-id="' + vaId + '"]');
                                if (vaItem) {
                                    vaItem.style.transition = 'opacity 0.3s';
                                    vaItem.style.opacity = '0';
                                    setTimeout(function() {
                                        vaItem.remove();
                                        checkIfClientEmpty(clientId, companyHash);
                                    }, 300);
                                }
                            } else {
                                errors++;
                                const vaItem = document.querySelector('.va-item[data-va-id="' + vaId + '"]');
                                if (vaItem) {
                                    vaItem.classList.remove('removing');
                                    vaItem.querySelector('.va-checkbox').disabled = false;
                                }
                            }
                            
                            if (processed === vaIds.length) {
                                if (errors === 0) {
                                    resultMessage.className = 'success';
                                    resultMessage.innerHTML = vaIds.length + ' VA(s) removed successfully.';
                                } else {
                                    resultMessage.className = 'error';
                                    resultMessage.innerHTML = 'Removed ' + (vaIds.length - errors) + ' VA(s), but ' + errors + ' failed.';
                                }
                                resultMessage.style.display = 'block';
                                updateSelectedCount(clientId);
                                
                                setTimeout(function() {
                                    resultMessage.style.display = 'none';
                                }, 5000);
                            }
                        }).fail(function() {
                            errors++;
                            processed++;
                            
                            if (processed === vaIds.length) {
                                resultMessage.className = 'error';
                                resultMessage.innerHTML = 'Error removing VAs. Please try again.';
                                resultMessage.style.display = 'block';
                                
                                setTimeout(function() {
                                    resultMessage.style.display = 'none';
                                }, 5000);
                            }
                        });
                    });
                },
                'remove'
            );
        }
        
        function checkIfClientEmpty(clientId, companyHash) {
            const clientSection = document.querySelector('[data-client-id="' + clientId + '"]');
            const vaItems = clientSection.querySelectorAll('.va-item');
            
            if (vaItems.length === 0) {
                clientSection.style.transition = 'opacity 0.3s';
                clientSection.style.opacity = '0';
                setTimeout(function() {
                    clientSection.remove();
                    updateCompanyCount(companyHash);
                }, 300);
            }
        }
        
        function updateCompanyCount(companyHash) {
            const companyContainer = document.getElementById('company-container-' + companyHash);
            const companyItem = companyContainer.closest('.company-item');
            const clientSections = companyContainer.querySelectorAll('.client-section');
            
            if (clientSections.length === 0) {
                companyItem.style.transition = 'opacity 0.3s';
                companyItem.style.opacity = '0';
                setTimeout(function() {
                    companyItem.remove();
                }, 300);
            } else {
                // Update VA count
                let totalVAs = 0;
                clientSections.forEach(function(section) {
                    totalVAs += section.querySelectorAll('.va-item').length;
                });
                
                const vaCount = companyItem.querySelector('.va-count');
                if (vaCount) {
                    vaCount.textContent = '(' + totalVAs + ' VA' + (totalVAs !== 1 ? 's' : '') + ' • ' + clientSections.length + ' Contact' + (clientSections.length !== 1 ? 's' : '') + ')';
                }
            }
        }
    </script>
    
    <?php

    return ob_get_clean();

});

