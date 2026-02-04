/**
 * CSM Workday Tracker Dashboard Shortcode
 * Usage: [csm_workday_tracker]
 * 
 * Displays VAs grouped by company (from HubSpot) with collapsible dropdowns
 * Shows WorkdayTracker reports and End of Day reports for each VA
 */

add_shortcode('csm_workday_tracker', 'va_sc_csm_workday_tracker');
function va_sc_csm_workday_tracker($atts) {
    // Check if user is logged in
    if (!is_user_logged_in()) {
        return '<p>Please login to view the CSM workday tracker dashboard.</p>';
    }
    
    $csm_id = get_current_user_id();
    
    // Check if user is a manager/CSM
    if (!va_is_manager($csm_id)) {
        return '<p>You do not have CSM/Manager privileges to view this dashboard.</p>';
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
    $HUBSPOT_TOKEN = 'pat-na1-d3f94fd4-15b0-4a78-9328-03ae98c33aa0';
    $COMPANY_CACHE_TTL = 12 * HOUR_IN_SECONDS;

    // =============================
    // HUBSPOT REQUEST HELPER
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
    // HUBSPOT COMPANY LOOKUP
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
            return '';
        }

        $contactBody = $contactResp['data'];
        if (empty($contactBody['results'][0]['id'])) {
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
    
    // Get manager's slug
    $manager_user = get_userdata($csm_id);
    $manager_slug = $manager_user->user_nicename;
    
    // Collect VAs organized by company
    $companies_with_vas = array();
    $all_users = get_users();
    
    foreach ($all_users as $user) {
        // Skip managers, VAs, and other non-client roles
        if (va_is_manager($user->ID) || in_array('um_ambassador', $user->roles)) {
            continue;
        }
        
        // Get client's selected VAs
        $selected_vas = va_get_selected_vas($user->ID);
        
        if (!empty($selected_vas)) {
            $client_has_manager_vas = false;
            $client_vas = array();
            
            foreach ($selected_vas as $va_id) {
                // Check if this VA belongs to the current manager
                $va_manager = va_get_static_manager($va_id);
                
                if ($va_manager === $manager_slug) {
                    $client_has_manager_vas = true;
                    $va_user = get_userdata($va_id);
                    
                    if ($va_user) {
                        // Get WorkdayTracker Report ID from user meta
                        $report_id = get_user_meta($va_id, 'workday_report_id', true);
                        
                        // Get VA's email for End of Day report lookup
                        $va_email = $va_user->user_email;
                        
                        // Get VA's profile post for additional data
                        $all_va_posts = get_posts(array(
                            'post_type' => 'vt-list-by-category',
                            'posts_per_page' => -1,
                            'post_status' => 'publish'
                        ));
                        
                        $va_name = $va_user->display_name ?: $va_user->user_login;
                        $description = 'Virtual Assistant';
                        $avatar_url = get_avatar_url($va_id, array('size' => 200));
                        
                        // Try to get better profile data from VA post
                        foreach ($all_va_posts as $post) {
                            $linked_user = va_get_va_user_id_from_post($post->ID);
                            
                            if ($linked_user === intval($va_id)) {
                                // Get profile picture
                                $profile_pic = get_field('profile_picture', $post->ID);
                                
                                if (!empty($profile_pic)) {
                                    if (is_array($profile_pic) && isset($profile_pic['url'])) {
                                        $avatar_url = $profile_pic['url'];
                                    } elseif (is_string($profile_pic) && !empty($profile_pic)) {
                                        $avatar_url = $profile_pic;
                                    } elseif (is_numeric($profile_pic)) {
                                        $avatar_url = wp_get_attachment_url($profile_pic);
                                    }
                                }
                                
                                // Get name and description
                                $va_name_field = get_field('name', $post->ID);
                                if ($va_name_field) {
                                    $va_name = $va_name_field;
                                }
                                
                                $department = get_field('department', $post->ID);
                                $summary = get_field('summary', $post->ID);
                                
                                if ($department) {
                                    $description = $department;
                                } elseif ($summary) {
                                    $description = $summary;
                                }
                                
                                break;
                            }
                        }
                        
                        $client_vas[] = array(
                            'user_id' => $va_id,
                            'name' => $va_name,
                            'description' => $description,
                            'image' => $avatar_url,
                            'report_id' => $report_id,
                            'email' => $va_email
                        );
                    }
                }
            }
            
            // Only add client if they have VAs from this manager
            if ($client_has_manager_vas && !empty($client_vas)) {
                // Get company name from HubSpot
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
                
                // Initialize company if not exists
                if (!isset($companies_with_vas[$company_name])) {
                    $companies_with_vas[$company_name] = array(
                        'vas' => array()
                    );
                }
                
                // Add VAs to company (merge with existing)
                $companies_with_vas[$company_name]['vas'] = array_merge(
                    $companies_with_vas[$company_name]['vas'],
                    $client_vas
                );
            }
        }
    }
    
    // Sort companies alphabetically by name
    ksort($companies_with_vas);
    
    // Start output buffering
    ob_start();
    ?>
    
  <style>
      .csm-workday-tracker-container {
        font-family: Arial, sans-serif;
        padding: 20px;
        background: #f4f4f4;
      }
      
      .csm-dashboard-header {
        text-align: center;
        margin-bottom: 30px;
        padding: 20px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 10px;
        display: flex;
        justify-content: space-between;
        align-items: center;
      }
      
      .csm-dashboard-header h2 {
        margin: 0 0 10px 0;
        font-size: 1.8rem;
        flex: 1;
      }
      
      .csm-dashboard-header p {
        margin: 0;
        opacity: 0.9;
        font-size: 1rem;
      }

      .csm-dashboard-title-section {
        flex: 1;
        text-align: center;
      }

      .csm-cache-btn {
        font-size: 12px;
        padding: 8px 16px;
        background: rgba(255,255,255,0.2);
        color: white;
        text-decoration: none;
        border-radius: 6px;
        border: 1px solid rgba(255,255,255,0.3);
        transition: all 0.2s;
      }

      .csm-cache-btn:hover {
        background: rgba(255,255,255,0.3);
        border-color: rgba(255,255,255,0.5);
      }

      .csm-company-section {
        background: white;
        border-radius: 12px;
        margin-bottom: 15px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        overflow: hidden;
        transition: box-shadow 0.3s ease;
        border: 2px solid #3919BA;
      }

      .csm-company-section:hover {
        box-shadow: 0 4px 15px rgba(0,0,0,0.15);
      }

      .csm-company-header {
        display: flex;
        align-items: center;
        padding: 20px 25px;
        cursor: pointer;
        background: #3919BA;
        color: white;
        transition: background 0.2s ease;
        user-select: none;
      }

      .csm-company-header:hover {
        background: #2d1494;
      }

      .csm-company-toggle {
        font-size: 1.2rem;
        margin-right: 15px;
        transition: transform 0.3s ease;
        font-weight: bold;
      }

      .csm-company-toggle.expanded {
        transform: rotate(90deg);
      }

      .csm-company-name {
        font-size: 1.3rem;
        font-weight: 600;
        margin: 0;
        flex-grow: 1;
      }

      .csm-company-va-count {
        background: rgba(255,255,255,0.2);
        color: white;
        padding: 5px 15px;
        border-radius: 20px;
        font-size: 0.9rem;
        font-weight: 500;
      }

      .csm-company-content {
        max-height: 0;
        overflow: hidden;
        transition: max-height 0.4s ease;
      }

      .csm-company-content.expanded {
        max-height: 1000px;
      }

      .csm-company-content-inner {
        padding: 25px;
        background: #f8f9fa;
      }

      .csm-workday-tracker-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 20px;
        max-height: 500px;
        overflow-y: auto;
        overflow-x: hidden;
        padding-right: 10px;
      }

      .csm-workday-tracker-grid::-webkit-scrollbar {
        width: 8px;
      }
      
      .csm-workday-tracker-grid::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 10px;
      }
      
      .csm-workday-tracker-grid::-webkit-scrollbar-thumb {
        background: #667eea;
        border-radius: 10px;
      }
      
      .csm-workday-tracker-grid::-webkit-scrollbar-thumb:hover {
        background: #764ba2;
      }

      @media (max-width: 768px) {
        .csm-workday-tracker-grid { 
          grid-template-columns: 1fr;
          max-height: 400px;
        }
        
        .csm-company-header {
          flex-wrap: wrap;
          padding: 15px 20px;
        }
        
        .csm-company-name {
          font-size: 1.1rem;
        }
        
        .csm-company-content-inner {
          padding: 20px;
        }

        .csm-dashboard-header {
          flex-direction: column;
          gap: 15px;
        }
      }

      .csm-profile-card {
        background: white;
        border-radius: 10px;
        padding: 20px;
        text-align: center;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        border: 2px solid transparent;
      }
      
      .csm-profile-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        border-color: #667eea;
      }

      .csm-profile-card img {
        width: 100px;
        height: 100px;
        border-radius: 50%;
        object-fit: cover;
        margin-bottom: 12px;
        border: 3px solid #e8e8ff;
      }

      .csm-profile-card h3 {
        font-size: 1.1rem;
        margin-bottom: 8px;
        color: #333;
      }

      .csm-profile-card p {
        font-size: 0.85rem;
        color: #555;
        margin-bottom: 15px;
        min-height: 35px;
      }

      .csm-profile-card button {
        background: #007bff;
        color: #fff;
        border: none;
        padding: 10px 16px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 0.9rem;
        margin: 5px 0;
        width: 100%;
        transition: background 0.2s ease;
      }

      .csm-profile-card button:hover:not(:disabled) {
        background: #005fcc;
      }
      
      .csm-profile-card button:disabled {
        background: #6c757d;
        cursor: not-allowed;
        opacity: 0.6;
      }
      
      .csm-eod-modal {
        display: none;
        position: fixed;
        z-index: 10000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        overflow: auto;
        background-color: rgba(0,0,0,0.6);
        animation: fadeIn 0.3s ease;
      }
      
      @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
      }
      
      .csm-eod-modal-content {
        background-color: #fff;
        margin: 5% auto;
        padding: 0;
        border-radius: 12px;
        width: 90%;
        max-width: 800px;
        box-shadow: 0 8px 24px rgba(0,0,0,0.3);
        animation: slideDown 0.3s ease;
        max-height: 85vh;
        overflow: hidden;
        display: flex;
        flex-direction: column;
      }
      
      @keyframes slideDown {
        from { 
          transform: translateY(-50px);
          opacity: 0;
        }
        to { 
          transform: translateY(0);
          opacity: 1;
        }
      }
      
      .csm-eod-modal-header {
        padding: 20px 25px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 12px 12px 0 0;
        display: flex;
        justify-content: space-between;
        align-items: center;
      }
      
      .csm-eod-modal-header h2 {
        margin: 0;
        font-size: 1.4rem;
        font-weight: 600;
      }
      
      .csm-eod-modal-close {
        color: white;
        font-size: 32px;
        font-weight: bold;
        cursor: pointer;
        background: none;
        border: none;
        padding: 0;
        width: 32px;
        height: 32px;
        line-height: 32px;
        text-align: center;
        border-radius: 50%;
        transition: background 0.2s ease;
      }
      
      .csm-eod-modal-close:hover {
        background: rgba(255,255,255,0.2);
      }
      
      .csm-eod-modal-body {
        padding: 25px;
        overflow-y: auto;
        flex: 1;
      }
      
      .csm-eod-loading {
        text-align: center;
        padding: 40px;
        color: #666;
      }
      
      .csm-eod-spinner {
        border: 4px solid #f3f3f3;
        border-top: 4px solid #667eea;
        border-radius: 50%;
        width: 40px;
        height: 40px;
        animation: spin 1s linear infinite;
        margin: 0 auto 20px;
      }
      
      @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
      }
      
      .csm-eod-report-card {
        background: #f8f9fa;
        border-left: 4px solid #667eea;
        padding: 20px;
        margin-bottom: 15px;
        border-radius: 8px;
      }
      
      .csm-eod-report-date {
        font-size: 0.85rem;
        color: #666;
        margin-bottom: 15px;
        font-weight: 500;
      }
      
      .csm-eod-report-section {
        margin-bottom: 15px;
      }
      
      .csm-eod-report-section:last-child {
        margin-bottom: 0;
      }
      
      .csm-eod-report-label {
        font-weight: 600;
        color: #333;
        margin-bottom: 8px;
        font-size: 0.95rem;
        display: block;
      }
      
      .csm-eod-report-value {
        color: #555;
        line-height: 1.6;
        white-space: pre-wrap;
        word-wrap: break-word;
      }
      
      .csm-eod-no-data {
        text-align: center;
        padding: 40px 20px;
        color: #666;
      }
      
      .csm-eod-no-data-icon {
        font-size: 3rem;
        margin-bottom: 15px;
        opacity: 0.5;
      }
      
      .csm-eod-error {
        background: #fee;
        border-left: 4px solid #e53e3e;
        padding: 15px 20px;
        border-radius: 8px;
        color: #c53030;
      }
      
      .csm-no-companies {
        text-align: center;
        padding: 60px 20px;
        background: white;
        border-radius: 10px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
      }
      
      .csm-no-companies-icon {
        font-size: 4rem;
        margin-bottom: 20px;
        opacity: 0.3;
      }
    </style>


    <div class="csm-workday-tracker-container">
        <div class="csm-dashboard-header">
            <div class="csm-dashboard-title-section">
                <h2>📊 CSM Workday Tracker Dashboard</h2>
                <p>Monitor VTs organized by company</p>
            </div>
            <?php if (current_user_can('administrator')): ?>
                <a href="?clear_hs_cache=1" class="csm-cache-btn">Clear HubSpot Cache</a>
            <?php endif; ?>
        </div>
        
        <?php if (empty($companies_with_vas)): ?>
            <div class="csm-no-companies">
                <div class="csm-no-companies-icon">🏢</div>
                <h3>No Companies Found</h3>
                <p>There are currently no companies with VAs assigned under your management.</p>
            </div>
        <?php else: ?>
            <?php foreach ($companies_with_vas as $company_name => $company_info): ?>
                <div class="csm-company-section">
                    <div class="csm-company-header" onclick="toggleCompanySection('<?php echo esc_attr(md5($company_name)); ?>')">
                        <span class="csm-company-toggle" id="toggle-<?php echo esc_attr(md5($company_name)); ?>">▶</span>
                        <h2 class="csm-company-name"><?php echo esc_html($company_name); ?></h2>
                        <span class="csm-company-va-count">
                            <?php echo count($company_info['vas']); ?> VA<?php echo count($company_info['vas']) !== 1 ? 's' : ''; ?>
                        </span>
                    </div>
                    
                    <div class="csm-company-content" id="content-<?php echo esc_attr(md5($company_name)); ?>">
                        <div class="csm-company-content-inner">
                            <div class="csm-workday-tracker-grid">
                                <?php foreach ($company_info['vas'] as $va): ?>
                                    <div class="csm-profile-card">
                                        <img src="<?php echo esc_url($va['image']); ?>" 
                                             alt="<?php echo esc_attr($va['name']); ?>">
                                        <h3><?php echo esc_html($va['name']); ?></h3>
                                        <p><?php echo esc_html($va['description']); ?></p>

                                        <?php if ($va['report_id']): ?>
                                            <button onclick="openCSMWorkdayReport('<?php echo esc_js($va['report_id']); ?>')">
                                                📊 View Workday Tracker
                                            </button>
                                        <?php else: ?>
                                            <button disabled title="No workday tracker report ID set">
                                                📊 View Workday Tracker
                                            </button>
                                        <?php endif; ?>

                                        <button onclick="openCSMEndOfDayReport('<?php echo esc_js($va['email']); ?>', '<?php echo esc_js($va['name']); ?>')">
                                            📄 View End of Day Report
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div id="csmEodModal" class="csm-eod-modal">
        <div class="csm-eod-modal-content">
            <div class="csm-eod-modal-header">
                <h2 id="csmEodModalTitle">📄 End of Day Report</h2>
                <button class="csm-eod-modal-close" onclick="closeCSMEodModal()">&times;</button>
            </div>
            <div class="csm-eod-modal-body" id="csmEodModalBody">
                <div class="csm-eod-loading">
                    <div class="csm-eod-spinner"></div>
                    <p>Loading report data...</p>
                </div>
            </div>
        </div>
    </div>

    <script>
      function toggleCompanySection(companyHash) {
        const content = document.getElementById('content-' + companyHash);
        const toggle = document.getElementById('toggle-' + companyHash);
        
        if (content.classList.contains('expanded')) {
          content.classList.remove('expanded');
          toggle.classList.remove('expanded');
        } else {
          content.classList.add('expanded');
          toggle.classList.add('expanded');
        }
      }

      function getCSMWeekdayDate() {
        const date = new Date();
        const day = date.getDay();

        if (day === 6) {
          date.setDate(date.getDate() - 1);
        } else if (day === 0) {
          date.setDate(date.getDate() - 2);
        }

        return date.toISOString().split('T')[0];
      }

      function openCSMWorkdayReport(reportId) {
        const currentDate = getCSMWeekdayDate();
        
        const options = encodeURIComponent(JSON.stringify({
          showExecutiveSummary: true,
          showTimeline: true,
          showOverallProductivityScore: true,
          detailedPerformanceScores: {
            showActiveTimeScore: true,
            showRecordingTimeScore: true,
            showTaskFocusScore: true
          },
          timeBreakdown: {
            showActiveTime: true,
            showIdleTime: true,
            showTotalRecorded: true,
            showActivePeriods: true
          },
          applicationUsage: {
            showTimeline: true,
            showUsageByPercentage: true
          },
          showIndividualWindowUsage: true,
          showActiveTasks: true
        }));

        const url = `https://workdaytracker.com/app/public-report/${reportId}/?options=${options}&date=${currentDate}`;
        
        window.open(url, '_blank');
      }

      async function openCSMEndOfDayReport(vaEmail, vaName) {
        const modal = document.getElementById('csmEodModal');
        const modalTitle = document.getElementById('csmEodModalTitle');
        const modalBody = document.getElementById('csmEodModalBody');
        
        modal.style.display = 'block';
        modalTitle.textContent = `📄 End of Day Report - ${vaName}`;
        modalBody.innerHTML = `
          <div class="csm-eod-loading">
            <div class="csm-eod-spinner"></div>
            <p>Loading report data...</p>
          </div>
        `;
        
        try {
          const csvUrl = 'https://docs.google.com/spreadsheets/d/e/2PACX-1vRYd0DJbkV4bkPkCJ86my09kdSfj-WANlRj4dJh5cWCQEXWyq6uAB-gQjvnYbRwgtxCF94EG2crm1o-/pub?output=csv&timestamp=' + Date.now();
          
          const response = await fetch(csvUrl);
          
          if (!response.ok) {
            throw new Error('Failed to fetch spreadsheet data');
          }
          
          const csvText = await response.text();
          const rows = parseCSMCSV(csvText);
          
          if (rows.length < 2) {
            throw new Error('No data found in spreadsheet');
          }
          
          const headers = rows[0].map(h => h ? h.trim() : '');
          
          const reports = [];
          for (let i = 1; i < rows.length; i++) {
            const row = rows[i];
            if (row.length < headers.length) continue;
            
            const rowData = {};
            headers.forEach((header, index) => {
              rowData[header] = row[index] ? row[index].trim() : '';
            });
            
            if (rowData['Email Address'] && 
                rowData['Email Address'].toLowerCase() === vaEmail.toLowerCase()) {
              reports.push(rowData);
            }
          }
          
          if (reports.length === 0) {
            modalBody.innerHTML = `
              <div class="csm-eod-no-data">
                <div class="csm-eod-no-data-icon">📭</div>
                <h3>No Reports Found</h3>
                <p>No end of day reports found for <strong>${vaEmail}</strong></p>
                <p style="font-size: 0.9rem; color: #999; margin-top: 10px;">
                  The VA may not have submitted any reports yet.
                </p>
              </div>
            `;
          } else {
            let html = '';
            
            reports.reverse().forEach(report => {
              html += `
                <div class="csm-eod-report-card">
                  <div class="csm-eod-report-date">
                    📅 ${report['Timestamp'] || 'Date not available'}
                  </div>
                  
                  ${report['Best Work Achieved Today/ This week'] ? `
                    <div class="csm-eod-report-section">
                      <span class="csm-eod-report-label">✨ Best Work Achieved:</span>
                      <div class="csm-eod-report-value">${escapeCSMHtml(report['Best Work Achieved Today/ This week'])}</div>
                    </div>
                  ` : ''}
                  
                  ${report['Where I Need Help or Clarification'] ? `
                    <div class="csm-eod-report-section">
                      <span class="csm-eod-report-label">❓ Where I Need Help:</span>
                      <div class="csm-eod-report-value">${escapeCSMHtml(report['Where I Need Help or Clarification'])}</div>
                    </div>
                  ` : ''}
                  
                  ${report['Focus for Tomorrow or Next Week'] ? `
                    <div class="csm-eod-report-section">
                      <span class="csm-eod-report-label">🎯 Focus for Tomorrow:</span>
                      <div class="csm-eod-report-value">${escapeCSMHtml(report['Focus for Tomorrow or Next Week'])}</div>
                    </div>
                  ` : ''}
                  
                  ${(report['Pending / Waiting On'] || report['Pending / Waiting On '] || report[headers[headers.length - 1]]) ? `
                    <div class="csm-eod-report-section">
                      <span class="csm-eod-report-label">⏳ Pending / Waiting On:</span>
                      <div class="csm-eod-report-value">${escapeCSMHtml(report['Pending / Waiting On'] || report['Pending / Waiting On '] || report[headers[headers.length - 1]] || '')}</div>
                    </div>
                  ` : ''}
                </div>
              `;
            });
            
            modalBody.innerHTML = html;
          }
          
        } catch (error) {
          console.error('Error fetching End of Day report:', error);
          modalBody.innerHTML = `
            <div class="csm-eod-error">
              <strong>⚠️ Error Loading Report</strong>
              <p style="margin: 10px 0 0 0;">Unable to load the end of day report. Please try again later.</p>
              <p style="margin: 5px 0 0 0; font-size: 0.9rem;">Error: ${error.message}</p>
            </div>
          `;
        }
      }

      function closeCSMEodModal() {
        document.getElementById('csmEodModal').style.display = 'none';
      }

      window.onclick = function(event) {
        const modal = document.getElementById('csmEodModal');
        if (event.target === modal) {
          closeCSMEodModal();
        }
      }

      function parseCSMCSV(text) {
        const lines = text.split('\n');
        const result = [];
        
        for (let line of lines) {
          if (!line.trim()) continue;
          
          const row = [];
          let current = '';
          let inQuotes = false;
          
          for (let i = 0; i < line.length; i++) {
            const char = line[i];
            
            if (char === '"') {
              if (inQuotes && line[i + 1] === '"') {
                current += '"';
                i++;
              } else {
                inQuotes = !inQuotes;
              }
            } else if (char === ',' && !inQuotes) {
              row.push(current);
              current = '';
            } else {
              current += char;
            }
          }
          
          row.push(current);
          result.push(row);
        }
        
        return result;
      }

      function escapeCSMHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
      }
    </script>
    
    <?php
    return ob_get_clean();
}