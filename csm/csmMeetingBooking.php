/**
 * Part 4 — Meeting System (Manager-Client Meetings Only)
 * Filename: part-4-va-meetings.php
 */

/* ========== 1. CREATE MEETINGS CPT ========== */
add_action('init', 'va_register_cpt_meetings');
function va_register_cpt_meetings() {
    register_post_type('va_meeting', array(
        'labels' => array('name' => 'VA Meetings'),
        'public' => false,
        'show_ui' => true,
        'supports' => array('title'),
        'has_archive' => false,
    ));
}

/* ========== 2. HELPER FUNCTIONS ========== */

/**
 * Get all clients connected to a manager
 */
function va_get_manager_clients($manager_id) {
    $manager_id = intval($manager_id);
    if (!$manager_id) return array();

    $clients = array();

    // Get all conversations where manager is a participant
    $convos = get_posts(array(
        'post_type' => 'va_conversation',
        'posts_per_page' => -1,
        'meta_query' => array(
            array(
                'key' => 'participants',
                'value' => $manager_id,
                'compare' => 'LIKE'
            )
        )
    ));

    foreach ($convos as $conv) {
        $parts = get_post_meta($conv->ID, 'participants', true);
        if (!is_array($parts)) continue;

        foreach ($parts as $p) {
            $p = intval($p);
            if ($p === $manager_id) continue;

            $user = get_userdata($p);
            if (!$user) continue;

            // Only include actual clients (not managers or VAs)
            if (!va_is_manager($p) && !in_array('um_ambassador', (array)$user->roles, true)) {
                $clients[$p] = array(
                    'id' => $p,
                    'name' => $user->display_name ?: $user->user_login
                );
            }
        }
    }

    return array_values($clients);
}

/**
 * Get all meetings for a manager
 */
function va_get_manager_meetings($manager_id) {
    $manager_id = intval($manager_id);
    if (!$manager_id) return array();

    $meetings = get_posts(array(
        'post_type' => 'va_meeting',
        'posts_per_page' => -1,
        'meta_query' => array(
            array(
                'key' => 'manager_id',
                'value' => $manager_id,
                'compare' => '='
            )
        ),
        'orderby' => 'meta_value',
        'meta_key' => 'meeting_date',
        'order' => 'DESC'
    ));

    $result = array();
    foreach ($meetings as $meeting) {
        $client = get_userdata(get_post_meta($meeting->ID, 'client_id', true));
        $result[] = array(
            'id' => $meeting->ID,
            'title' => get_post_meta($meeting->ID, 'meeting_title', true),
            'client_id' => get_post_meta($meeting->ID, 'client_id', true),
            'client_name' => $client ? $client->display_name : 'Unknown Client',
            'meeting_link' => get_post_meta($meeting->ID, 'meeting_link', true),
            'platform' => get_post_meta($meeting->ID, 'platform', true),
            'meeting_date' => get_post_meta($meeting->ID, 'meeting_date', true),
            'meeting_time_start' => get_post_meta($meeting->ID, 'meeting_time_start', true),
            'meeting_time_end' => get_post_meta($meeting->ID, 'meeting_time_end', true),
            'agenda' => get_post_meta($meeting->ID, 'agenda', true)
        );
    }

    return $result;
}

/**
 * Get all meetings for a client
 */
function va_get_client_meetings($client_id) {
    $client_id = intval($client_id);
    if (!$client_id) return array();

    $meetings = get_posts(array(
        'post_type' => 'va_meeting',
        'posts_per_page' => -1,
        'meta_query' => array(
            array(
                'key' => 'client_id',
                'value' => $client_id,
                'compare' => '='
            )
        ),
        'orderby' => 'meta_value',
        'meta_key' => 'meeting_date',
        'order' => 'DESC'
    ));

    $result = array();
    foreach ($meetings as $meeting) {
        $manager_id = get_post_meta($meeting->ID, 'manager_id', true);
        $manager = get_userdata($manager_id);

        $result[] = array(
            'id' => $meeting->ID,
            'title' => get_post_meta($meeting->ID, 'meeting_title', true),
            'manager_id' => $manager_id,
            'manager_name' => $manager ? ($manager->display_name ?: $manager->user_login) : 'Manager',
            'meeting_link' => get_post_meta($meeting->ID, 'meeting_link', true),
            'platform' => get_post_meta($meeting->ID, 'platform', true),
            'meeting_date' => get_post_meta($meeting->ID, 'meeting_date', true),
            'meeting_time_start' => get_post_meta($meeting->ID, 'meeting_time_start', true),
            'meeting_time_end' => get_post_meta($meeting->ID, 'meeting_time_end', true),
            'agenda' => get_post_meta($meeting->ID, 'agenda', true)
        );
    }

    return $result;
}

/**
 * Build meeting notification email
 */
function va_build_meeting_email($meeting_id, $manager_name) {
    $meeting_title = get_post_meta($meeting_id, 'meeting_title', true);
    $meeting_link = get_post_meta($meeting_id, 'meeting_link', true);
    $platform = get_post_meta($meeting_id, 'platform', true);
    $meeting_date = get_post_meta($meeting_id, 'meeting_date', true);
    $meeting_time_start = get_post_meta($meeting_id, 'meeting_time_start', true);
    $meeting_time_end = get_post_meta($meeting_id, 'meeting_time_end', true);
    $agenda = get_post_meta($meeting_id, 'agenda', true);

    $formatted_date = date('F j, Y', strtotime($meeting_date));
    $formatted_time = date('g:i A', strtotime($meeting_time_start)) . ' - ' . date('g:i A', strtotime($meeting_time_end));

    $site = get_bloginfo('name');

    $body = "<html><body style='font-family: Arial, sans-serif; line-height: 1.6;'>
        <div style='max-width: 600px; margin: 0 auto; padding: 20px; background: #f9f9f9; border-radius: 10px;'>
            <h2 style='color: #2271b1;'>📅 New Meeting Scheduled</h2>

            <div style='background: white; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                <p>Hi,</p>
                <p><strong>" . esc_html($manager_name) . "</strong> has scheduled a meeting with you.</p>

                <div style='background: #e8f4f8; padding: 15px; border-left: 4px solid #2271b1; margin: 20px 0;'>
                    <strong>📋 Meeting Details:</strong><br><br>
                    <strong>Title:</strong> " . esc_html($meeting_title) . "<br>
                    <strong>Date:</strong> " . esc_html($formatted_date) . "<br>
                    <strong>Time:</strong> " . esc_html($formatted_time) . "<br>
                    <strong>Platform:</strong> " . esc_html($platform) . "
                </div>";

    if (!empty($agenda)) {
        $body .= "<div style='background: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; margin: 20px 0;'>
                    <strong>Agenda:</strong><br>" . nl2br(esc_html($agenda)) . "
                </div>";
    }

    $body .= "<p style='margin-top: 30px;'>
                    <a href='" . esc_url($meeting_link) . "'
                       style='background: #2271b1; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; display: inline-block;'>
                        Join Meeting
                    </a>
                </p>
            </div>

            <p style='color: #666; font-size: 12px; margin-top: 20px;'>
                — " . esc_html($site) . "<br>
                This meeting was scheduled by your manager.
            </p>
        </div>
    </body></html>";

    return $body;
}

/* ========== 3. MANAGER BOOKING SHORTCODE ========== */
add_shortcode('va_manager_booking', 'va_sc_manager_booking');
function va_sc_manager_booking($atts) {
    if (!is_user_logged_in()) {
        return '<p>Please login to access the booking system.</p>';
    }

    $manager_id = get_current_user_id();

    // Check if user is a manager
    if (!va_is_manager($manager_id)) {
        return '<p>You do not have manager privileges.</p>';
    }

    // Get manager's clients and meetings
    $manager_clients = va_get_manager_clients($manager_id);
    $meetings = va_get_manager_meetings($manager_id);

    ob_start();
    ?>

    <style>
    .booking-wrapper {
        max-width: 1200px;
        margin: 20px auto;
        padding: 20px;
    }

    .booking-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
    }

    .booking-header h2 {
        margin: 0;
        font-size: 28px;
        color: #333;
    }

    .btn-create-meeting {
        background: #4E46DC;
        color: white;
        border: none;
        padding: 12px 24px;
        border-radius: 8px;
        cursor: pointer;
        font-weight: 600;
        font-size: 15px;
        transition: background 0.2s;
    }

    .btn-create-meeting:hover {
        background: #3d38b8;
    }

    .meetings-table-wrap {
        background: white;
        border-radius: 12px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        overflow: hidden;
    }

    .meetings-table {
        width: 100%;
        border-collapse: collapse;
    }

    .meetings-table thead {
        background: #f8f9fa;
    }

    .meetings-table th {
        padding: 16px;
        text-align: left;
        font-weight: 600;
        color: #333;
        font-size: 14px;
        border-bottom: 2px solid #e9ecef;
    }

    .meetings-table td {
        padding: 16px;
        border-bottom: 1px solid #f0f0f0;
        font-size: 14px;
        color: #555;
    }

    .meetings-table tbody tr:hover {
        background: #f8f9fa;
    }

    .meeting-title {
        font-weight: 600;
        color: #333;
    }

    .platform-badge {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 600;
        background: #e8f4f8;
        color: #2271b1;
    }

    .status-upcoming {
        color: #856404;
        background: #fff3cd;
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 600;
    }

    .status-past {
        color: #666;
        font-size: 12px;
    }

    .btn-delete-meeting {
        background: #dc3545;
        color: white;
        border: none;
        padding: 6px 12px;
        border-radius: 5px;
        cursor: pointer;
        font-size: 12px;
        font-weight: 600;
    }

    .btn-delete-meeting:hover {
        background: #c82333;
    }

    .btn-delete-meeting:disabled {
        background: #6c757d;
        cursor: not-allowed;
    }

    .empty-state {
        padding: 60px 40px;
        text-align: center;
    }

    .placeholder-icon {
        font-size: 60px;
        margin-bottom: 20px;
        opacity: 0.3;
    }

    /* Meeting Creation Modal */
    .meeting-modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.6);
        z-index: 9999;
        align-items: center;
        justify-content: center;
    }

    .meeting-modal.active {
        display: flex;
    }

    .meeting-modal-content {
        background: white;
        border-radius: 16px;
        padding: 30px;
        max-width: 600px;
        width: 90%;
        max-height: 90vh;
        overflow-y: auto;
        box-shadow: 0 10px 40px rgba(0,0,0,0.3);
    }

    .meeting-modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 25px;
        border-bottom: 2px solid #f0f0f0;
        padding-bottom: 15px;
    }

    .meeting-modal-header h3 {
        margin: 0;
        font-size: 24px;
        color: #333;
    }

    .modal-close {
        background: none;
        border: none;
        font-size: 32px;
        cursor: pointer;
        color: #999;
        padding: 0;
        width: 35px;
        height: 35px;
        line-height: 1;
    }

    .modal-close:hover {
        color: #333;
    }

    .form-group label {
        display: block;
        font-weight: 600;
        color: #333;
        font-size: 14px;
    }

    .form-group label .required {
        color: #dc3545;
    }

    .form-group input[type="text"],
    .form-group input[type="date"],
    .form-group input[type="time"],
    .form-group select,
    .form-group textarea {
        width: 100%;
        padding: 12px;
        border: 1px solid #ddd;
        border-radius: 8px;
        font-size: 15px;
        box-sizing: border-box;
        font-family: inherit;
    }

    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
        outline: none;
        border-color: #4E46DC;
        box-shadow: 0 0 0 3px rgba(78, 70, 220, 0.1);
    }

    .form-group textarea {
        resize: vertical;
        min-height: 100px;
    }

    .form-row {
        display: flex;
    }

    .form-row .form-group {
        flex: 1;
    }

    .modal-actions {
        display: flex;
        gap: 10px;
        margin-top: 30px;
        padding-top: 20px;
        border-top: 2px solid #f0f0f0;
    }

    .btn-save-meeting {
        flex: 1;
        padding: 14px;
        background: #28a745;
        color: white;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        font-weight: 600;
        font-size: 16px;
        transition: background 0.2s;
    }

    .btn-save-meeting:hover {
        background: #218838;
    }

    .btn-save-meeting:disabled {
        background: #ccc;
        cursor: not-allowed;
    }

    .btn-cancel-modal {
        flex: 1;
        padding: 14px;
        background: #6c757d;
        color: white;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        font-weight: 600;
        font-size: 16px;
        transition: background 0.2s;
    }

    .btn-cancel-modal:hover {
        background: #5a6268;
    }

    /* Notification Modal */
    .notification-modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.6);
        z-index: 10000;
        align-items: center;
        justify-content: center;
    }

    .notification-modal.active {
        display: flex;
    }

    .notification-modal-content {
        background: white;
        border-radius: 16px;
        padding: 30px;
        max-width: 400px;
        width: 90%;
        text-align: center;
        box-shadow: 0 10px 40px rgba(0,0,0,0.3);
    }

    .notification-icon {
        font-size: 48px;
        margin-bottom: 20px;
    }

    .notification-success .notification-icon {
        color: #28a745;
    }

    .notification-error .notification-icon {
        color: #dc3545;
    }

    .notification-modal h3 {
        margin: 0 0 15px 0;
        font-size: 24px;
        color: #333;
    }

    .notification-modal p {
        margin: 0 0 25px 0;
        color: #666;
        font-size: 16px;
        line-height: 1.5;
    }

    .btn-ok-notification {
        background: #4E46DC;
        color: white;
        border: none;
        padding: 12px 24px;
        border-radius: 8px;
        cursor: pointer;
        font-weight: 600;
        font-size: 15px;
        transition: background 0.2s;
        margin: 0 auto;
        display: block;
    }

    .btn-ok-notification:hover {
        background: #3d38b8;
    }

    /* Confirm Delete Modal (CUSTOM, replaces browser confirm popup) */
    .confirm-modal {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.6);
        z-index: 10001;
        align-items: center;
        justify-content: center;
    }

    .confirm-modal.active {
        display: flex;
    }

    .confirm-modal-content {
        background: white;
        border-radius: 16px;
        padding: 28px;
        max-width: 420px;
        width: 90%;
        text-align: center;
        box-shadow: 0 10px 40px rgba(0,0,0,0.3);
    }

    .confirm-icon {
        font-size: 44px;
        margin-bottom: 14px;
    }

    .confirm-modal-content h3 {
        margin: 0 0 10px 0;
        font-size: 22px;
        color: #333;
    }

    .confirm-modal-content p {
        margin: 0 0 22px 0;
        color: #666;
        font-size: 15px;
        line-height: 1.5;
    }

    .confirm-actions {
        display: flex;
        gap: 10px;
    }

    .btn-confirm-cancel {
        flex: 1;
        padding: 12px 14px;
        background: #6c757d;
        color: #fff;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        font-weight: 600;
        font-size: 15px;
    }

    .btn-confirm-cancel:hover {
        background: #5a6268;
    }

    .btn-confirm-delete {
        flex: 1;
        padding: 12px 14px;
        background: #dc3545;
        color: #fff;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        font-weight: 700;
        font-size: 15px;
    }

    .btn-confirm-delete:hover {
        background: #c82333;
    }

    .btn-confirm-delete:disabled,
    .btn-confirm-cancel:disabled {
        background: #ccc;
        cursor: not-allowed;
    }

    /* Loading Spinner */
    .loading-spinner {
        display: inline-block;
        width: 20px;
        height: 20px;
        border: 3px solid rgba(255,255,255,.3);
        border-radius: 50%;
        border-top-color: #fff;
        animation: spin 1s ease-in-out infinite;
        margin-right: 8px;
        vertical-align: middle;
    }

    @keyframes spin {
        to { transform: rotate(360deg); }
    }

    @media (max-width: 768px) {
        .form-row {
            flex-direction: column;
        }

        .booking-header {
            flex-direction: column;
            gap: 15px;
        }

        .modal-actions {
            flex-direction: column;
        }

        .meetings-table-wrap {
            overflow-x: auto;
        }
    }

    @media (max-width: 480px) {
        .confirm-actions {
            flex-direction: column;
        }
    }
    </style>

    <div class="booking-wrapper">
        <div class="booking-header">
            <h2>📅 Meeting Schedule</h2>
            <button class="btn-create-meeting" id="open-meeting-modal">
                Create Meeting
            </button>
        </div>

        <div id="meetings-table-container">
            <?php echo va_get_manager_meetings_table_html($meetings); ?>
        </div>
    </div>

    <!-- Meeting Creation Modal -->
    <div class="meeting-modal" id="meeting-modal">
        <div class="meeting-modal-content">
            <div class="meeting-modal-header">
                <h3>Create New Meeting</h3>
                <button class="modal-close" id="close-meeting-modal">&times;</button>
            </div>

            <form id="meeting-form">
                <div class="form-group">
                    <label for="meeting-title">
                        Meeting Title <span class="required">*</span>
                    </label>
                    <input type="text"
                           id="meeting-title"
                           name="meeting_title"
                           placeholder="e.g., Weekly Sync, Progress Review, Strategy Meeting"
                           required>
                </div>

                <div class="form-group">
                    <label for="client-select">
                        Select Client <span class="required">*</span>
                    </label>
                    <select id="client-select" name="client_id" required>
                        <option value="">-- Select a Client --</option>
                        <?php foreach ($manager_clients as $client): ?>
                            <option value="<?php echo $client['id']; ?>">
                                <?php echo esc_html($client['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="meeting-link">
                        Meeting Link (Zoom/Google Meet) <span class="required">*</span>
                    </label>
                    <input type="text"
                           id="meeting-link"
                           name="meeting_link"
                           placeholder="https://zoom.us/j/123456789"
                           required>
                </div>

                <div class="form-group">
                    <label for="platform">
                        Platform <span class="required">*</span>
                    </label>
                    <select id="platform" name="platform" required>
                        <option value="Zoom" selected>Zoom</option>
                        <option value="Google Meet">Google Meet</option>
                        <option value="Microsoft Teams">Microsoft Teams</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="meeting-date">
                            Date <span class="required">*</span>
                        </label>
                        <input type="date"
                               id="meeting-date"
                               name="meeting_date"
                               min="<?php echo date('Y-m-d'); ?>"
                               required>
                    </div>

                    <div class="form-group">
                        <label for="meeting-time-start">
                            Start Time <span class="required">*</span>
                        </label>
                        <input type="time"
                               id="meeting-time-start"
                               name="meeting_time_start"
                               required>
                    </div>

                    <div class="form-group">
                        <label for="meeting-time-end">
                            End Time <span class="required">*</span>
                        </label>
                        <input type="time"
                               id="meeting-time-end"
                               name="meeting_time_end"
                               required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="agenda">Agenda (Optional)</label>
                    <textarea id="agenda"
                              name="agenda"
                              placeholder="Enter meeting agenda or topics to discuss..."></textarea>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn-cancel-modal" id="cancel-meeting">Cancel</button>
                    <button type="submit" class="btn-save-meeting">Create Meeting</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Notification Modal -->
    <div class="notification-modal" id="notification-modal">
        <div class="notification-modal-content">
            <div class="notification-icon" id="notification-icon">!</div>
            <h3 id="notification-title">Notification</h3>
            <p id="notification-message">This is a notification message.</p>
            <button class="btn-ok-notification" id="close-notification">OK</button>
        </div>
    </div>

    <!-- Confirm Delete Modal (CUSTOM, replaces browser confirm popup) -->
    <div class="confirm-modal" id="confirm-modal" role="dialog" aria-modal="true" aria-hidden="true">
        <div class="confirm-modal-content" role="document">
            <div class="confirm-icon">⚠️</div>
            <h3 id="confirm-title">Delete Meeting?</h3>
            <p id="confirm-message">Are you sure you want to delete this meeting? This cannot be undone.</p>

            <div class="confirm-actions">
                <button type="button" class="btn-confirm-cancel" id="confirm-cancel">Cancel</button>
                <button type="button" class="btn-confirm-delete" id="confirm-ok">Delete</button>
            </div>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($){
        var ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
        var nonce = '<?php echo wp_create_nonce('va_meeting_nonce'); ?>';

        // ===== Modal Helpers =====
        function showNotificationModal(title, message, isSuccess = true) {
            $('#notification-title').text(title);
            $('#notification-message').text(message);
            $('#notification-icon').html(isSuccess ? '✓' : '!');
            $('#notification-modal').removeClass('notification-error notification-success');
            $('#notification-modal').addClass(isSuccess ? 'notification-success' : 'notification-error');
            $('#notification-modal').addClass('active');
        }

        function closeAllModals() {
            $('#meeting-modal').removeClass('active');
            $('#notification-modal').removeClass('active');
            $('#confirm-modal').removeClass('active').attr('aria-hidden', 'true');
        }

        // Refresh meetings table via AJAX
        function refreshMeetingsTable() {
            $.post(ajaxurl, {
                action: 'va_refresh_manager_meetings',
                nonce: nonce
            }, function(response) {
                if (response && response.success) {
                    $('#meetings-table-container').html(response.data.html);
                } else {
                    showNotificationModal('Error', 'Failed to refresh meetings', false);
                }
            }).fail(function(){
                showNotificationModal('Error', 'Failed to refresh meetings', false);
            });
        }

        // ===== Open/Close Create Meeting Modal =====
        $('#open-meeting-modal').on('click', function(){
            $('#meeting-modal').addClass('active');
        });

        $('#close-meeting-modal, #cancel-meeting').on('click', function(){
            closeAllModals();
            $('#meeting-form')[0].reset();
        });

        // Close notification modal
        $('#close-notification').on('click', closeAllModals);

        // Close modals when clicking outside
        $(document).on('click', function(e){
            if ($(e.target).is('#meeting-modal')) {
                closeAllModals();
                $('#meeting-form')[0].reset();
            }
            if ($(e.target).is('#notification-modal')) {
                closeAllModals();
            }
            if ($(e.target).is('#confirm-modal')) {
                closeConfirmModal();
            }
        });

        // ESC key closes active modals
        $(document).on('keydown', function(e){
            if (e.key === 'Escape') {
                closeAllModals();
                closeConfirmModal();
            }
        });

        // ===== Create Meeting via AJAX =====
        $('#meeting-form').on('submit', function(e){
            e.preventDefault();

            var btn = $('.btn-save-meeting');
            btn.prop('disabled', true).html('<span class="loading-spinner"></span> Creating...');

            var formData = {
                action: 'va_create_meeting',
                nonce: nonce,
                meeting_title: $('#meeting-title').val(),
                client_id: $('#client-select').val(),
                meeting_link: $('#meeting-link').val(),
                platform: $('#platform').val(),
                meeting_date: $('#meeting-date').val(),
                meeting_time_start: $('#meeting-time-start').val(),
                meeting_time_end: $('#meeting-time-end').val(),
                agenda: $('#agenda').val()
            };

            $.post(ajaxurl, formData, function(res){
                if (res && res.success) {
                    closeAllModals();
                    $('#meeting-form')[0].reset();
                    showNotificationModal('Success', 'Meeting created successfully!');
                    refreshMeetingsTable();
                } else {
                    showNotificationModal('Error', (res && res.data && res.data.message) ? res.data.message : 'Failed to create meeting', false);
                }
                btn.prop('disabled', false).html('Create Meeting');
            }).fail(function(){
                showNotificationModal('Error', 'Request failed. Please try again.', false);
                btn.prop('disabled', false).html('Create Meeting');
            });
        });

        // ===== Confirm Delete Modal (CUSTOM) =====
        var pendingDelete = { id: null, btn: null };

        function openConfirmModal(meetingId, btnEl) {
            pendingDelete.id = meetingId;
            pendingDelete.btn = btnEl;

            $('#confirm-ok').prop('disabled', false).html('Delete');
            $('#confirm-modal').addClass('active').attr('aria-hidden', 'false');
        }

        function closeConfirmModal() {
            $('#confirm-modal').removeClass('active').attr('aria-hidden', 'true');
            pendingDelete.id = null;
            pendingDelete.btn = null;
        }

        $('#confirm-cancel').on('click', function(){
            closeConfirmModal();
        });

        // Open confirm modal instead of browser confirm()
        $(document).on('click', '.btn-delete-meeting', function(){
            var btn = $(this);
            var meetingId = btn.data('meeting-id');
            if (!meetingId) return;

            openConfirmModal(meetingId, btn);
        });

        // Confirm delete -> AJAX
        $('#confirm-ok').on('click', function(){
            if (!pendingDelete.id) {
                closeConfirmModal();
                return;
            }

            var okBtn = $(this);
            var meetingId = pendingDelete.id;
            var rowBtn = pendingDelete.btn;

            okBtn.prop('disabled', true).html('<span class="loading-spinner"></span> Deleting...');

            if (rowBtn && rowBtn.length) {
                rowBtn.prop('disabled', true).html('<span class="loading-spinner"></span> Deleting...');
            }

            $.post(ajaxurl, {
                action: 'va_delete_meeting',
                meeting_id: meetingId,
                nonce: nonce
            }, function(res){
                closeConfirmModal();
                okBtn.prop('disabled', false).html('Delete');

                if (res && res.success) {
                    showNotificationModal('Success', 'Meeting deleted successfully!');
                    refreshMeetingsTable();
                } else {
                    showNotificationModal('Error', (res && res.data && res.data.message) ? res.data.message : 'Failed to delete meeting', false);
                    if (rowBtn && rowBtn.length) {
                        rowBtn.prop('disabled', false).html('Delete');
                    }
                }
            }).fail(function(){
                closeConfirmModal();
                okBtn.prop('disabled', false).html('Delete');

                showNotificationModal('Error', 'Request failed. Please try again.', false);

                if (rowBtn && rowBtn.length) {
                    rowBtn.prop('disabled', false).html('Delete');
                }
            });
        });

        // Set minimum date to today
        var today = new Date().toISOString().split('T')[0];
        $('#meeting-date').attr('min', today);
    });
    </script>

    <?php
    return ob_get_clean();
}

/**
 * Get manager meetings table HTML
 */
function va_get_manager_meetings_table_html($meetings = null) {
    if ($meetings === null) {
        $manager_id = get_current_user_id();
        $meetings = va_get_manager_meetings($manager_id);
    }

    ob_start();
    ?>

    <div class="meetings-table-wrap">
        <?php if (empty($meetings)): ?>
            <div class="empty-state">
                <div class="placeholder-icon">📅</div>
                <h3>No Meetings Yet</h3>
                <p style="color: #666;">Click "Create Meeting" to schedule your first client meeting.</p>
            </div>
        <?php else: ?>
            <table class="meetings-table">
                <thead>
                    <tr>
                        <th>Meeting Title</th>
                        <th>Client</th>
                        <th>Date & Time</th>
                        <th>Platform</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($meetings as $meeting):
                        $meeting_datetime = strtotime($meeting['meeting_date'] . ' ' . $meeting['meeting_time_start']);
                        $is_upcoming = $meeting_datetime > time();
                        $formatted_date = date('M j, Y', $meeting_datetime);
                        $formatted_time = date('g:i A', strtotime($meeting['meeting_time_start'])) . ' - ' . date('g:i A', strtotime($meeting['meeting_time_end']));
                    ?>
                    <tr>
                        <td class="meeting-title"><?php echo esc_html($meeting['title']); ?></td>
                        <td><?php echo esc_html($meeting['client_name']); ?></td>
                        <td>
                            <strong><?php echo esc_html($formatted_date); ?></strong><br>
                            <span style="font-size: 12px; color: #666;"><?php echo esc_html($formatted_time); ?></span>
                        </td>
                        <td><span class="platform-badge"><?php echo esc_html($meeting['platform']); ?></span></td>
                        <td>
                            <?php if ($is_upcoming): ?>
                                <span class="status-upcoming">UPCOMING</span>
                            <?php else: ?>
                                <span class="status-past">Past</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button class="btn-delete-meeting" data-meeting-id="<?php echo intval($meeting['id']); ?>">
                                Delete
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <?php
    return ob_get_clean();
}

/* ========== 4. CLIENT MEETINGS SHORTCODE ========== */
add_shortcode('va_client_meetings', 'va_sc_client_meetings');
function va_sc_client_meetings($atts) {
    if (!is_user_logged_in()) {
        return '<p>Please login to view your meetings.</p>';
    }

    $client_id = get_current_user_id();
    $meetings = va_get_client_meetings($client_id);

    ob_start();
    ?>

    <style>
    .client-meetings-wrapper {
        max-width: 1200px;
        margin: 20px auto;
        padding: 20px;
    }

    .client-meetings-header {
        margin-bottom: 30px;
    }

    .client-meetings-header h2 {
        margin: 0 0 10px 0;
        font-size: 28px;
        color: #333;
    }

    .client-meetings-header p {
        color: #666;
        margin: 0;
    }

    .meetings-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
        gap: 20px;
    }

    .meeting-card {
        background: white;
        border-radius: 12px;
        padding: 24px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        transition: transform 0.2s, box-shadow 0.2s;
        border-left: 4px solid #4E46DC;
    }

    .meeting-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 16px rgba(0,0,0,0.12);
    }

    .meeting-card-header {
        display: flex;
        justify-content: space-between;
        align-items: start;
        margin-bottom: 16px;
    }

    .meeting-card-title {
        font-size: 18px;
        font-weight: 700;
        color: #333;
        margin: 0;
    }

    .meeting-card-platform {
        background: #e8f4f8;
        color: #2271b1;
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 600;
    }

    .meeting-card-info {
        margin: 12px 0;
    }

    .meeting-card-info-item {
        display: flex;
        align-items: center;
        margin-bottom: 10px;
        font-size: 14px;
        color: #555;
    }

    .meeting-card-info-item svg {
        width: 18px;
        height: 18px;
        margin-right: 8px;
        color: #4E46DC;
        flex-shrink: 0;
    }

    .meeting-card-agenda {
        margin: 16px 0;
        padding: 12px;
        background: #f8f9fa;
        border-radius: 8px;
        font-size: 14px;
        color: #555;
        line-height: 1.6;
    }

    .meeting-card-agenda strong {
        display: block;
        margin-bottom: 6px;
        color: #333;
    }

    .meeting-card-link {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        padding: 12px;
        background: #4E46DC;
        color: white;
        text-decoration: none;
        border-radius: 8px;
        font-weight: 600;
        transition: background 0.2s;
        margin-top: 16px;
    }

    .meeting-card-link:hover {
        background: #3d38b8;
    }

    .meeting-card-link svg {
        width: 18px;
        height: 18px;
        margin-right: 8px;
    }

    .upcoming-label {
        display: inline-block;
        background: #fff3cd;
        color: #856404;
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 11px;
        font-weight: 600;
        margin-bottom: 12px;
    }

    .empty-state {
        text-align: center;
        padding: 60px 20px;
        background: white;
        border-radius: 12px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.08);
    }

    .empty-state-icon {
        font-size: 60px;
        margin-bottom: 20px;
        opacity: 0.3;
    }

    @media (max-width: 768px) {
        .meetings-grid {
            grid-template-columns: 1fr;
        }
    }
    </style>

    <div class="client-meetings-wrapper">
        <div class="client-meetings-header">
            <h2>📅 My Meetings</h2>
            <p>View all your scheduled meetings with your CSM</p>
        </div>

        <?php if (empty($meetings)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">📅</div>
                <h3>No Meetings Scheduled</h3>
                <p style="color: #666;">Your CSM will schedule meetings with you as needed.</p>
            </div>
        <?php else: ?>
            <div class="meetings-grid">
                <?php foreach ($meetings as $meeting):
                    $meeting_datetime = strtotime($meeting['meeting_date'] . ' ' . $meeting['meeting_time_start']);
                    $is_upcoming = $meeting_datetime > time();
                    $formatted_date = date('F j, Y', $meeting_datetime);
                    $formatted_time = date('g:i A', strtotime($meeting['meeting_time_start'])) . ' – ' . date('g:i A', strtotime($meeting['meeting_time_end']));
                ?>
                <div class="meeting-card">
                    <?php if ($is_upcoming): ?>
                        <div class="upcoming-label">UPCOMING</div>
                    <?php endif; ?>

                    <div class="meeting-card-header">
                        <h3 class="meeting-card-title"><?php echo esc_html($meeting['title']); ?></h3>
                        <span class="meeting-card-platform"><?php echo esc_html($meeting['platform']); ?></span>
                    </div>

                    <div class="meeting-card-info">
                        <div class="meeting-card-info-item">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                            </svg>
                            <strong><?php echo esc_html($formatted_date); ?></strong>
                        </div>

                        <div class="meeting-card-info-item">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <?php echo esc_html($formatted_time); ?>
                        </div>

                        <div class="meeting-card-info-item">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                            </svg>
                            With: CSM <?php echo esc_html($meeting['manager_name']); ?>
                        </div>
                    </div>

                    <?php if (!empty($meeting['agenda'])): ?>
                    <div class="meeting-card-agenda">
                        <strong>Agenda:</strong>
                        <?php echo nl2br(esc_html($meeting['agenda'])); ?>
                    </div>
                    <?php endif; ?>

                    <a href="<?php echo esc_url($meeting['meeting_link']); ?>"
                       target="_blank"
                       class="meeting-card-link">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                        </svg>
                        Join <?php echo esc_html($meeting['platform']); ?> Meeting
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php
    return ob_get_clean();
}

/* ========== 5. AJAX HANDLERS ========== */

add_action('wp_ajax_va_create_meeting', 'va_ajax_create_meeting');
function va_ajax_create_meeting() {
    check_ajax_referer('va_meeting_nonce', 'nonce');

    if (!is_user_logged_in() || !va_is_manager(get_current_user_id())) {
        wp_send_json_error(array('message' => 'Not authorized'));
    }

    $manager_id = get_current_user_id();
    $meeting_title = isset($_POST['meeting_title']) ? sanitize_text_field($_POST['meeting_title']) : '';
    $client_id = isset($_POST['client_id']) ? intval($_POST['client_id']) : 0;
    $meeting_link = isset($_POST['meeting_link']) ? esc_url_raw($_POST['meeting_link']) : '';
    $platform = isset($_POST['platform']) ? sanitize_text_field($_POST['platform']) : 'Zoom';
    $meeting_date = isset($_POST['meeting_date']) ? sanitize_text_field($_POST['meeting_date']) : '';
    $meeting_time_start = isset($_POST['meeting_time_start']) ? sanitize_text_field($_POST['meeting_time_start']) : '';
    $meeting_time_end = isset($_POST['meeting_time_end']) ? sanitize_text_field($_POST['meeting_time_end']) : '';
    $agenda = isset($_POST['agenda']) ? sanitize_textarea_field($_POST['agenda']) : '';

    // Validation
    if (empty($meeting_title) || !$client_id || empty($meeting_link) || empty($meeting_date) || empty($meeting_time_start) || empty($meeting_time_end)) {
        wp_send_json_error(array('message' => 'Please fill in all required fields'));
    }

    if (!get_userdata($client_id)) {
        wp_send_json_error(array('message' => 'Invalid client'));
    }

    // Create meeting post
    $meeting_id = wp_insert_post(array(
        'post_type' => 'va_meeting',
        'post_title' => sanitize_text_field($meeting_title),
        'post_status' => 'publish'
    ));

    if ($meeting_id && !is_wp_error($meeting_id)) {
        update_post_meta($meeting_id, 'manager_id', $manager_id);
        update_post_meta($meeting_id, 'meeting_title', $meeting_title);
        update_post_meta($meeting_id, 'client_id', $client_id);
        update_post_meta($meeting_id, 'meeting_link', $meeting_link);
        update_post_meta($meeting_id, 'platform', $platform);
        update_post_meta($meeting_id, 'meeting_date', $meeting_date);
        update_post_meta($meeting_id, 'meeting_time_start', $meeting_time_start);
        update_post_meta($meeting_id, 'meeting_time_end', $meeting_time_end);
        update_post_meta($meeting_id, 'agenda', $agenda);

        // Notify client
        va_add_notification($client_id, "Your CSM scheduled a meeting: {$meeting_title}", array('meeting_id' => $meeting_id));

        // Send email to client
        $manager = get_userdata($manager_id);
        $manager_name = $manager->display_name ?: 'Your CSM';
        $subject = 'New Meeting Scheduled: ' . $meeting_title;
        $body = va_build_meeting_email($meeting_id, $manager_name);
        va_send_email_to_user($client_id, $subject, $body);

        wp_send_json_success(array('meeting_id' => $meeting_id));
    }

    wp_send_json_error(array('message' => 'Failed to create meeting'));
}

add_action('wp_ajax_va_delete_meeting', 'va_ajax_delete_meeting');
function va_ajax_delete_meeting() {
    check_ajax_referer('va_meeting_nonce', 'nonce');

    if (!is_user_logged_in() || !va_is_manager(get_current_user_id())) {
        wp_send_json_error(array('message' => 'Not authorized'));
    }

    $manager_id = get_current_user_id();
    $meeting_id = isset($_POST['meeting_id']) ? intval($_POST['meeting_id']) : 0;

    if (!$meeting_id) {
        wp_send_json_error(array('message' => 'Invalid meeting ID'));
    }

    $meeting = get_post($meeting_id);
    if (!$meeting || $meeting->post_type !== 'va_meeting') {
        wp_send_json_error(array('message' => 'Invalid meeting'));
    }

    // Verify this manager owns the meeting
    $meeting_manager_id = get_post_meta($meeting_id, 'manager_id', true);
    if (intval($meeting_manager_id) !== $manager_id) {
        wp_send_json_error(array('message' => 'Not authorized to delete this meeting'));
    }

    // Delete the meeting
    if (wp_delete_post($meeting_id, true)) {
        wp_send_json_success(array('message' => 'Meeting deleted successfully'));
    }

    wp_send_json_error(array('message' => 'Failed to delete meeting'));
}

add_action('wp_ajax_va_refresh_manager_meetings', 'va_ajax_refresh_manager_meetings');
function va_ajax_refresh_manager_meetings() {
    check_ajax_referer('va_meeting_nonce', 'nonce');

    if (!is_user_logged_in() || !va_is_manager(get_current_user_id())) {
        wp_send_json_error(array('message' => 'Not authorized'));
    }

    $manager_id = get_current_user_id();
    $meetings = va_get_manager_meetings($manager_id);
    $html = va_get_manager_meetings_table_html($meetings);

    wp_send_json_success(array('html' => $html));
}