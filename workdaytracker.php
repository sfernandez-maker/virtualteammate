/**
 * FINAL WORKDAY TRACKER (CSM approval based)
 * Shortcode: [workday_tracker_profiles]
 *
 * Logic:
 * - If manager/CSM approval NOT approved => hide that VT card; count it in "pending" note
 * - If approved => show card
 * - Workday button is clickable if VA has workday_report_id (no VT acceptance gate)
 * - Title "Your VT workday tracker" shows only if Workday is clickable
 */

add_action('init', function () {
    remove_shortcode('workday_tracker_profiles');
    add_shortcode('workday_tracker_profiles', 'va_sc_workday_tracker_profiles_csm');
});

function va_wdt_norm($v) { return strtolower(trim((string)$v)); }

function va_wdt_get_selected_vas_safe($client_id) {
    $client_id = (int)$client_id;

    if (function_exists('va_get_selected_vas')) {
        $arr = va_get_selected_vas($client_id);
        return is_array($arr) ? array_map('intval', $arr) : [];
    }

    // fallback if function isn't loaded
    $val = get_user_meta($client_id, 'selected_vas', true);
    if (empty($val)) return [];
    if (is_array($val)) return array_map('intval', $val);
    if (is_string($val)) {
        $arr = array_filter(array_map('trim', explode(',', $val)));
        return array_map('intval', $arr);
    }
    return [];
}

function va_wdt_is_manager_approved($client_id, $va_user_id) {
    $approval = get_user_meta((int)$client_id, '_manager_approval_' . (int)$va_user_id, true);
    return va_wdt_norm($approval) === 'approved';
}

function va_sc_workday_tracker_profiles_csm($atts) {
    if (!is_user_logged_in()) {
        return '<p>Please login to view workday tracker profiles.</p>';
    }

    $client_id = get_current_user_id();
    $selected_vas = va_wdt_get_selected_vas_safe($client_id);

    if (empty($selected_vas)) {
        return '<div style="text-align:center;padding:40px;">
            <p>You have not selected any VAs yet. Please select VAs to view their workday tracker profiles.</p>
        </div>';
    }

    // Fetch VA profile posts once
    $all_va_posts = get_posts([
        'post_type'      => 'vt-list-by-category',
        'posts_per_page' => -1,
        'post_status'    => 'publish'
    ]);

    $available_vas = [];
    $pending_manager_count = 0;

    foreach ($selected_vas as $va_user_id) {
        $va_user_id = (int)$va_user_id;
        $va_user = get_userdata($va_user_id);
        if (!$va_user) continue;

        // ✅ Hide cards until manager/CSM approved
        if (!va_wdt_is_manager_approved($client_id, $va_user_id)) {
            $pending_manager_count++;
            continue;
        }

        // ✅ WorkdayTracker Report ID (same as your old snippet)
        $report_id = get_user_meta($va_user_id, 'workday_report_id', true);

        $va_email = $va_user->user_email;
        $va_name = $va_user->display_name ?: $va_user->user_login;
        $description = 'Virtual Assistant';
        $avatar_url = get_avatar_url($va_user_id, ['size' => 200]);

        // Improve with VA post data (optional)
        if (function_exists('va_get_va_user_id_from_post')) {
            foreach ($all_va_posts as $post) {
                $linked_user = va_get_va_user_id_from_post($post->ID);
                if ((int)$linked_user === $va_user_id) {

                    if (function_exists('get_field')) {
                        $profile_pic = get_field('profile_picture', $post->ID);
                        if (!empty($profile_pic)) {
                            if (is_array($profile_pic) && isset($profile_pic['url'])) $avatar_url = $profile_pic['url'];
                            elseif (is_string($profile_pic) && $profile_pic) $avatar_url = $profile_pic;
                            elseif (is_numeric($profile_pic)) $avatar_url = wp_get_attachment_url($profile_pic);
                        }

                        $va_name_field = get_field('name', $post->ID);
                        if ($va_name_field) $va_name = $va_name_field;

                        $department = get_field('department', $post->ID);
                        $summary = get_field('summary', $post->ID);

                        if ($department) $description = $department;
                        elseif ($summary) $description = $summary;
                    }
                    break;
                }
            }
        }

        $available_vas[] = [
            'user_id' => $va_user_id,
            'name' => $va_name,
            'description' => $description,
            'image' => $avatar_url,
            'report_id' => $report_id,
            'email' => $va_email,
        ];
    }

    ob_start();
    ?>
    <style>
      .workday-tracker-container { font-family: Arial, sans-serif; padding: 20px; background: #f4f4f4; }
      .va-note { max-width: 1400px; margin: 0 auto 15px; background:#fff3cd; border-left:4px solid #ffc107; padding:12px 15px; border-radius:8px; color:#856404; }
      .workday-tracker-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; max-width: 1400px; margin: 0 auto; }
      @media (max-width: 1024px) { .workday-tracker-grid { grid-template-columns: repeat(2, 1fr); } }
      @media (max-width: 600px) { .workday-tracker-grid { grid-template-columns: repeat(1, 1fr); } }

      .profile-card { background:#fff; border-radius:10px; padding:20px; text-align:center; box-shadow:0 2px 10px rgba(0,0,0,0.1); transition:transform 0.2s ease; }
      .profile-card:hover { transform: translateY(-5px); box-shadow: 0 4px 15px rgba(0,0,0,0.15); }
      .profile-card img { width:110px; height:110px; border-radius:50%; object-fit:cover; margin-bottom:12px; border:3px solid #e8e8ff; }
      .profile-card h2 { font-size:1.2rem; margin-bottom:8px; color:#333; }
      .profile-card p { font-size:0.9rem; color:#555; margin-bottom:15px; min-height:40px; }
      .profile-card button { background:#007bff; color:#fff; border:none; padding:10px 18px; border-radius:6px; cursor:pointer; font-size:0.95rem; margin:5px 0; width:100%; transition: background 0.2s ease; }
      .profile-card button:hover:not(:disabled) { background:#005fcc; }
      .profile-card button:disabled { background:#6c757d; cursor:not-allowed; opacity:0.6; }
      .workday-title { margin:10px 0 8px; font-size:14px; font-weight:700; color:#333; }

      /* Modal Styles */
      .eod-modal { display:none; position:fixed; z-index:10000; left:0; top:0; width:100%; height:100%; overflow:auto; background-color: rgba(0,0,0,0.6); animation: fadeIn 0.3s ease; }
      @keyframes fadeIn { from { opacity:0; } to { opacity:1; } }
      .eod-modal-content { background:#fff; margin:5% auto; padding:0; border-radius:12px; width:90%; max-width:800px; box-shadow:0 8px 24px rgba(0,0,0,0.3); animation: slideDown 0.3s ease; max-height:85vh; overflow:hidden; display:flex; flex-direction:column; }
      @keyframes slideDown { from { transform: translateY(-50px); opacity:0; } to { transform: translateY(0); opacity:1; } }
      .eod-modal-header { padding:20px 25px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color:white; border-radius:12px 12px 0 0; display:flex; justify-content:space-between; align-items:center; }
      .eod-modal-header h2 { margin:0; font-size:1.4rem; font-weight:600; }
      .eod-modal-close { color:white; font-size:32px; font-weight:bold; cursor:pointer; background:none; border:none; padding:0; width:32px; height:32px; line-height:32px; text-align:center; border-radius:50%; transition: background 0.2s ease; }
      .eod-modal-close:hover { background: rgba(255,255,255,0.2); }
      .eod-modal-body { padding:25px; overflow-y:auto; flex:1; }
      .eod-loading { text-align:center; padding:40px; color:#666; }
      .eod-spinner { border:4px solid #f3f3f3; border-top:4px solid #667eea; border-radius:50%; width:40px; height:40px; animation: spin 1s linear infinite; margin:0 auto 20px; }
      @keyframes spin { 0%{ transform:rotate(0deg);} 100%{ transform:rotate(360deg);} }
      .eod-report-card { background:#f8f9fa; border-left:4px solid #667eea; padding:20px; margin-bottom:15px; border-radius:8px; }
      .eod-report-date { font-size:0.85rem; color:#666; margin-bottom:15px; font-weight:500; }
      .eod-report-label { font-weight:600; color:#333; margin-bottom:8px; font-size:0.95rem; display:block; }
      .eod-report-value { color:#555; line-height:1.6; white-space:pre-wrap; word-wrap:break-word; }
      .eod-no-data { text-align:center; padding:40px 20px; color:#666; }
      .eod-no-data-icon { font-size:3rem; margin-bottom:15px; opacity:0.5; }
      .eod-error { background:#fee; border-left:4px solid #e53e3e; padding:15px 20px; border-radius:8px; color:#c53030; }
    </style>

    <div class="workday-tracker-container">

      <?php if ($pending_manager_count > 0): ?>
        <div class="va-note">
          <strong>Note:</strong> You have <?php echo (int)$pending_manager_count; ?> VT selection pending manager approval. They will appear here once approved.
        </div>
      <?php endif; ?>

      <?php if (empty($available_vas)): ?>
        <div style="text-align:center;padding:40px;background:#fff;border-radius:10px;max-width:900px;margin:0 auto;">
          <p>No manager-approved VTs yet.</p>
        </div>
      <?php else: ?>
        <div class="workday-tracker-grid">
          <?php foreach ($available_vas as $va): ?>
            <?php $can_open_workday = !empty($va['report_id']); ?>
            <div class="profile-card">
              <img src="<?php echo esc_url($va['image']); ?>" alt="<?php echo esc_attr($va['name']); ?>">
              <h2><?php echo esc_html($va['name']); ?></h2>
              <p><?php echo esc_html($va['description']); ?></p>

              <?php if ($can_open_workday): ?>
                <button onclick="openWorkdayReport('<?php echo esc_js($va['report_id']); ?>')">📊 View Workday Tracker</button>
              <?php else: ?>
                <button disabled title="No workday tracker report ID set">📊 View Workday Tracker</button>
              <?php endif; ?>

              <button onclick="openEndOfDayReport('<?php echo esc_js($va['email']); ?>', '<?php echo esc_js($va['name']); ?>')">
                📄 View End of Day Report
              </button>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

    </div>

    <!-- End of Day Report Modal -->
    <div id="eodModal" class="eod-modal">
      <div class="eod-modal-content">
        <div class="eod-modal-header">
          <h2 id="eodModalTitle">📄 End of Day Report</h2>
          <button class="eod-modal-close" onclick="closeEodModal()">&times;</button>
        </div>
        <div class="eod-modal-body" id="eodModalBody">
          <div class="eod-loading">
            <div class="eod-spinner"></div>
            <p>Loading report data...</p>
          </div>
        </div>
      </div>
    </div>

    <script>
      function getWeekdayDate() {
        const date = new Date();
        const day = date.getDay();
        if (day === 6) date.setDate(date.getDate() - 1);
        else if (day === 0) date.setDate(date.getDate() - 2);
        return date.toISOString().split('T')[0];
      }

      function openWorkdayReport(reportId) {
        const currentDate = getWeekdayDate();
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

      async function openEndOfDayReport(vaEmail, vaName) {
        const modal = document.getElementById('eodModal');
        const modalTitle = document.getElementById('eodModalTitle');
        const modalBody = document.getElementById('eodModalBody');

        modal.style.display = 'block';
        modalTitle.textContent = `📄 End of Day Report - ${vaName}`;
        modalBody.innerHTML = `
          <div class="eod-loading">
            <div class="eod-spinner"></div>
            <p>Loading report data...</p>
          </div>
        `;

        try {
          const csvUrl = 'https://docs.google.com/spreadsheets/d/e/2PACX-1vRYd0DJbkV4bkPkCJ86my09kdSfj-WANlRj4dJh5cWCQEXWyq6uAB-gQjvnYbRwgtxCF94EG2crm1o-/pub?output=csv&timestamp=' + Date.now();
          const response = await fetch(csvUrl);
          if (!response.ok) throw new Error('Failed to fetch spreadsheet data');

          const csvText = await response.text();
          const rows = parseCSV(csvText);
          if (rows.length < 2) throw new Error('No data found in spreadsheet');

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
              <div class="eod-no-data">
                <div class="eod-no-data-icon">📭</div>
                <h3>No Reports Found</h3>
                <p>No end of day reports found for <strong>${vaEmail}</strong></p>
              </div>
            `;
          } else {
            let html = '';
            reports.reverse().forEach(report => {
              html += `
                <div class="eod-report-card">
                  <div class="eod-report-date">📅 ${report['Timestamp'] || 'Date not available'}</div>

                  ${report['Best Work Achieved Today/ This week'] ? `
                    <div class="eod-report-section">
                      <span class="eod-report-label">✨ Best Work Achieved:</span>
                      <div class="eod-report-value">${escapeHtml(report['Best Work Achieved Today/ This week'])}</div>
                    </div>` : ''}

                  ${report['Where I Need Help or Clarification'] ? `
                    <div class="eod-report-section">
                      <span class="eod-report-label">❓ Where I Need Help:</span>
                      <div class="eod-report-value">${escapeHtml(report['Where I Need Help or Clarification'])}</div>
                    </div>` : ''}

                  ${report['Focus for Tomorrow or Next Week'] ? `
                    <div class="eod-report-section">
                      <span class="eod-report-label">🎯 Focus for Tomorrow:</span>
                      <div class="eod-report-value">${escapeHtml(report['Focus for Tomorrow or Next Week'])}</div>
                    </div>` : ''}

                  ${(report['Pending / Waiting On'] || report['Pending / Waiting On '] || report[headers[headers.length - 1]]) ? `
                    <div class="eod-report-section">
                      <span class="eod-report-label">⏳ Pending / Waiting On:</span>
                      <div class="eod-report-value">${escapeHtml(report['Pending / Waiting On'] || report['Pending / Waiting On '] || report[headers[headers.length - 1]] || '')}</div>
                    </div>` : ''}
                </div>
              `;
            });
            modalBody.innerHTML = html;
          }

        } catch (error) {
          modalBody.innerHTML = `
            <div class="eod-error">
              <strong>⚠️ Error Loading Report</strong>
              <p style="margin:10px 0 0 0;">Unable to load the end of day report. Please try again later.</p>
              <p style="margin:5px 0 0 0; font-size:0.9rem;">Error: ${error.message}</p>
            </div>
          `;
        }
      }

      function closeEodModal() {
        document.getElementById('eodModal').style.display = 'none';
      }

      window.onclick = function(event) {
        const modal = document.getElementById('eodModal');
        if (event.target === modal) closeEodModal();
      }

      function parseCSV(text) {
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
              if (inQuotes && line[i + 1] === '"') { current += '"'; i++; }
              else { inQuotes = !inQuotes; }
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

      function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
      }
    </script>
    <?php

    return ob_get_clean();
}