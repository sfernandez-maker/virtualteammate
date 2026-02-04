/**
 * VA Personal Workday Tracker Shortcode
 * Usage: [va_workday_tracker]
 * 
 * Displays only the logged-in VA's own WorkdayTracker reports and End of Day reports
 */

add_shortcode('va_workday_tracker', 'va_sc_personal_workday_tracker');
function va_sc_personal_workday_tracker($atts) {
    // Check if user is logged in
    if (!is_user_logged_in()) {
        return '<p>Please login to view your workday tracker.</p>';
    }
    
    $va_id = get_current_user_id();
    $va_user = get_userdata($va_id);
    
    // Get VA's WorkdayTracker Report ID from user meta
    $report_id = get_user_meta($va_id, 'workday_report_id', true);
    
    // Get VA's email for End of Day report lookup
    $va_email = $va_user->user_email;
    
    // Get VA's profile data
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
    
    // Start output buffering
    ob_start();
    ?>
    
    <style>
      .va-workday-tracker-container {
        font-family: Arial, sans-serif;
        padding: 20px;
        background: #f4f4f4;
        min-height: 100vh;
      }
      
      .va-dashboard-header {
        text-align: center;
        margin-bottom: 30px;
        padding: 30px 20px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 12px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.1);
      }
      
      .va-dashboard-header h2 {
        margin: 0 0 10px 0;
        font-size: 2rem;
      }
      
      .va-dashboard-header p {
        margin: 0;
        opacity: 0.95;
        font-size: 1.1rem;
      }

      .va-profile-container {
        max-width: 500px;
        margin: 0 auto;
      }

      .va-profile-card {
        background: #fff;
        border-radius: 12px;
        padding: 40px 30px;
        text-align: center;
        box-shadow: 0 4px 20px rgba(0,0,0,0.12);
        transition: transform 0.3s ease, box-shadow 0.3s ease;
      }
      
      .va-profile-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 30px rgba(0,0,0,0.18);
      }

      .va-profile-card img {
        width: 150px;
        height: 150px;
        border-radius: 50%;
        object-fit: cover;
        margin-bottom: 20px;
        border: 4px solid #e8e8ff;
        box-shadow: 0 4px 10px rgba(0,0,0,0.1);
      }

      .va-profile-card h2 {
        font-size: 1.5rem;
        margin-bottom: 10px;
        color: #333;
        font-weight: 600;
      }

      .va-profile-card p {
        font-size: 1rem;
        color: #666;
        margin-bottom: 30px;
        line-height: 1.6;
      }

      .va-button-group {
        display: flex;
        flex-direction: column;
        gap: 12px;
      }

      .va-profile-card button {
        background: #007bff;
        color: #fff;
        border: none;
        padding: 14px 24px;
        border-radius: 8px;
        cursor: pointer;
        font-size: 1rem;
        font-weight: 500;
        width: 100%;
        transition: all 0.3s ease;
        box-shadow: 0 2px 8px rgba(0,123,255,0.3);
      }

      .va-profile-card button:hover:not(:disabled) {
        background: #0056b3;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,123,255,0.4);
      }
      
      .va-profile-card button:disabled {
        background: #6c757d;
        cursor: not-allowed;
        opacity: 0.6;
        box-shadow: none;
      }
      
      .va-profile-card button:disabled:hover {
        transform: none;
      }
      
      /* Modal Styles */
      .va-eod-modal {
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
      
      .va-eod-modal-content {
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
      
      .va-eod-modal-header {
        padding: 20px 25px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 12px 12px 0 0;
        display: flex;
        justify-content: space-between;
        align-items: center;
      }
      
      .va-eod-modal-header h2 {
        margin: 0;
        font-size: 1.4rem;
        font-weight: 600;
      }
      
      .va-eod-modal-close {
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
      
      .va-eod-modal-close:hover {
        background: rgba(255,255,255,0.2);
      }
      
      .va-eod-modal-body {
        padding: 25px;
        overflow-y: auto;
        flex: 1;
      }
      
      .va-eod-loading {
        text-align: center;
        padding: 40px;
        color: #666;
      }
      
      .va-eod-spinner {
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
      
      .va-eod-report-card {
        background: #f8f9fa;
        border-left: 4px solid #667eea;
        padding: 20px;
        margin-bottom: 15px;
        border-radius: 8px;
      }
      
      .va-eod-report-date {
        font-size: 0.85rem;
        color: #666;
        margin-bottom: 15px;
        font-weight: 500;
      }
      
      .va-eod-report-section {
        margin-bottom: 15px;
      }
      
      .va-eod-report-section:last-child {
        margin-bottom: 0;
      }
      
      .va-eod-report-label {
        font-weight: 600;
        color: #333;
        margin-bottom: 8px;
        font-size: 0.95rem;
        display: block;
      }
      
      .va-eod-report-value {
        color: #555;
        line-height: 1.6;
        white-space: pre-wrap;
        word-wrap: break-word;
      }
      
      .va-eod-no-data {
        text-align: center;
        padding: 40px 20px;
        color: #666;
      }
      
      .va-eod-no-data-icon {
        font-size: 3rem;
        margin-bottom: 15px;
        opacity: 0.5;
      }
      
      .va-eod-error {
        background: #fee;
        border-left: 4px solid #e53e3e;
        padding: 15px 20px;
        border-radius: 8px;
        color: #c53030;
      }
      
      .va-no-report-notice {
        background: #fff3cd;
        border-left: 4px solid #ffc107;
        padding: 15px 20px;
        border-radius: 8px;
        color: #856404;
        margin-bottom: 15px;
        text-align: left;
      }
      
      @media (max-width: 768px) {
        .va-dashboard-header h2 {
          font-size: 1.5rem;
        }
        
        .va-profile-card {
          padding: 30px 20px;
        }
        
        .va-profile-card img {
          width: 120px;
          height: 120px;
        }
      }
    </style>

    <div class="va-workday-tracker-container">
        <div class="va-dashboard-header">
            <h2>📊 My Workday Tracker</h2>
            <p>Track your daily progress and reports</p>
        </div>
        
        <div class="va-profile-container">
            <div class="va-profile-card">
                <img src="<?php echo esc_url($avatar_url); ?>" 
                     alt="<?php echo esc_attr($va_name); ?>">
                <h2><?php echo esc_html($va_name); ?></h2>
                <p><?php echo esc_html($description); ?></p>

                <div class="va-button-group">
                    <?php if ($report_id): ?>
                        <button onclick="openVAWorkdayReport('<?php echo esc_js($report_id); ?>')">
                            📊 View My Workday Tracker
                        </button>
                    <?php else: ?>
                        <button disabled title="No workday tracker report ID set">
                            📊 View My Workday Tracker
                        </button>
                    <?php endif; ?>

                    <button onclick="openVAEndOfDayReport('<?php echo esc_js($va_email); ?>', '<?php echo esc_js($va_name); ?>')">
                        📄 View My End of Day Reports
                    </button>
                </div>
                
                <?php if (!$report_id): ?>
                    <div class="va-no-report-notice" style="margin-top: 20px;">
                        <strong>⚠️ Notice:</strong> Your WorkdayTracker report ID has not been set yet. 
                        Please contact your manager to set this up.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- End of Day Report Modal -->
    <div id="vaEodModal" class="va-eod-modal">
        <div class="va-eod-modal-content">
            <div class="va-eod-modal-header">
                <h2 id="vaEodModalTitle">📄 My End of Day Reports</h2>
                <button class="va-eod-modal-close" onclick="closeVAEodModal()">&times;</button>
            </div>
            <div class="va-eod-modal-body" id="vaEodModalBody">
                <div class="va-eod-loading">
                    <div class="va-eod-spinner"></div>
                    <p>Loading your reports...</p>
                </div>
            </div>
        </div>
    </div>

    <script>
      /**
       * Get the current weekday date (skip weekends)
       */
      function getVAWeekdayDate() {
        const date = new Date();
        const day = date.getDay();

        if (day === 6) {
          date.setDate(date.getDate() - 1);
        } else if (day === 0) {
          date.setDate(date.getDate() - 2);
        }

        return date.toISOString().split('T')[0];
      }

      /**
       * Open WorkdayTracker report with dynamic date
       */
      function openVAWorkdayReport(reportId) {
        const currentDate = getVAWeekdayDate();
        
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

      /**
       * Open End of Day Report Modal
       */
      async function openVAEndOfDayReport(vaEmail, vaName) {
        const modal = document.getElementById('vaEodModal');
        const modalTitle = document.getElementById('vaEodModalTitle');
        const modalBody = document.getElementById('vaEodModalBody');
        
        // Show modal with loading state
        modal.style.display = 'block';
        modalTitle.textContent = `📄 My End of Day Reports`;
        modalBody.innerHTML = `
          <div class="va-eod-loading">
            <div class="va-eod-spinner"></div>
            <p>Loading your reports...</p>
          </div>
        `;
        
        try {
          // Fetch data from Google Sheets
          const csvUrl = 'https://docs.google.com/spreadsheets/d/e/2PACX-1vRYd0DJbkV4bkPkCJ86my09kdSfj-WANlRj4dJh5cWCQEXWyq6uAB-gQjvnYbRwgtxCF94EG2crm1o-/pub?output=csv&timestamp=' + Date.now();
          
          const response = await fetch(csvUrl);
          
          if (!response.ok) {
            throw new Error('Failed to fetch spreadsheet data');
          }
          
          const csvText = await response.text();
          const rows = parseVACSV(csvText);
          
          if (rows.length < 2) {
            throw new Error('No data found in spreadsheet');
          }
          
          // Get headers and normalize them (trim whitespace)
          const headers = rows[0].map(h => h ? h.trim() : '');
          
          // Debug: log headers to console
          console.log('Spreadsheet headers:', headers);
          
          // Find matching reports for this VA email
          const reports = [];
          for (let i = 1; i < rows.length; i++) {
            const row = rows[i];
            if (row.length < headers.length) continue;
            
            const rowData = {};
            headers.forEach((header, index) => {
              rowData[header] = row[index] ? row[index].trim() : '';
            });
            
            // Match email (case-insensitive)
            if (rowData['Email Address'] && 
                rowData['Email Address'].toLowerCase() === vaEmail.toLowerCase()) {
              reports.push(rowData);
            }
          }
          
          // Display results
          if (reports.length === 0) {
            modalBody.innerHTML = `
              <div class="va-eod-no-data">
                <div class="va-eod-no-data-icon">📭</div>
                <h3>No Reports Found</h3>
                <p>You haven't submitted any end of day reports yet.</p>
                <p style="font-size: 0.9rem; color: #999; margin-top: 10px;">
                  Start submitting daily reports to track your progress!
                </p>
              </div>
            `;
          } else {
            let html = '';
            
            // Show most recent reports first
            reports.reverse().forEach(report => {
              // Debug: log the report data
              console.log('Report data:', report);
              
              html += `
                <div class="va-eod-report-card">
                  <div class="va-eod-report-date">
                    📅 ${report['Timestamp'] || 'Date not available'}
                  </div>
                  
                  ${report['Best Work Achieved Today/ This week'] ? `
                    <div class="va-eod-report-section">
                      <span class="va-eod-report-label">✨ Best Work Achieved:</span>
                      <div class="va-eod-report-value">${escapeVAHtml(report['Best Work Achieved Today/ This week'])}</div>
                    </div>
                  ` : ''}
                  
                  ${report['Where I Need Help or Clarification'] ? `
                    <div class="va-eod-report-section">
                      <span class="va-eod-report-label">❓ Where I Need Help:</span>
                      <div class="va-eod-report-value">${escapeVAHtml(report['Where I Need Help or Clarification'])}</div>
                    </div>
                  ` : ''}
                  
                  ${report['Focus for Tomorrow or Next Week'] ? `
                    <div class="va-eod-report-section">
                      <span class="va-eod-report-label">🎯 Focus for Tomorrow:</span>
                      <div class="va-eod-report-value">${escapeVAHtml(report['Focus for Tomorrow or Next Week'])}</div>
                    </div>
                  ` : ''}
                  
                  ${(report['Pending / Waiting On'] || report['Pending / Waiting On '] || report[headers[headers.length - 1]]) ? `
                    <div class="va-eod-report-section">
                      <span class="va-eod-report-label">⏳ Pending / Waiting On:</span>
                      <div class="va-eod-report-value">${escapeVAHtml(report['Pending / Waiting On'] || report['Pending / Waiting On '] || report[headers[headers.length - 1]] || '')}</div>
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
            <div class="va-eod-error">
              <strong>⚠️ Error Loading Reports</strong>
              <p style="margin: 10px 0 0 0;">Unable to load your end of day reports. Please try again later.</p>
              <p style="margin: 5px 0 0 0; font-size: 0.9rem;">Error: ${error.message}</p>
            </div>
          `;
        }
      }

      /**
       * Close End of Day Report Modal
       */
      function closeVAEodModal() {
        document.getElementById('vaEodModal').style.display = 'none';
      }

      /**
       * Close modal when clicking outside
       */
      window.onclick = function(event) {
        const modal = document.getElementById('vaEodModal');
        if (event.target === modal) {
          closeVAEodModal();
        }
      }

      /**
       * Parse CSV text into array of arrays
       */
      function parseVACSV(text) {
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

      /**
       * Escape HTML to prevent XSS
       */
      function escapeVAHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
      }
    </script>
    
    <?php
    return ob_get_clean();
}