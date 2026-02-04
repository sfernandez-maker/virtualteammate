/**
 * Notification Center UX Upgrade (Conflict-Safe)
 * - Inline confirm for Clear All (no browser confirm)
 * - Undo toast for single dismiss + clear all (real undo, server delete delayed)
 * - Uses NEW ajax actions: va2_dismiss_notification, va2_clear_all_notifications, va2_toggle_email_notifications
 * - Does NOT redeclare your old ajax handlers
 *
 * Usage: [va_notification_center]
 */

if (!defined('ABSPATH')) exit;

add_action('init', 'va2_register_notification_center_shortcode', 99999);
function va2_register_notification_center_shortcode() {
    remove_shortcode('va_notification_center');
    add_shortcode('va_notification_center', 'va2_sc_notification_center');
}

function va2_sc_notification_center($atts) {
    if (!is_user_logged_in()) return '<p>Please login to view notifications.</p>';

    wp_enqueue_script('jquery');

    $user_id = get_current_user_id();
    $notifications = get_user_meta($user_id, 'va_notifications', true);
    if (!is_array($notifications)) $notifications = array();

    $user = wp_get_current_user();
    $is_csm    = in_array('csm', $user->roles) || strpos(strtolower($user->display_name), 'csm') !== false;
    $is_client = in_array('client', $user->roles) || (!$is_csm && !in_array('va', $user->roles));
    $is_va     = in_array('va', $user->roles);

    $urgent_count = 0;
    $caught_up = true;

    foreach ($notifications as $notif) {
        $meta = isset($notif['meta']) ? $notif['meta'] : array();
        $message = isset($notif['message']) ? $notif['message'] : '';

        if (
            isset($meta['assignment_id']) ||
            stripos($message, 'assignment') !== false ||
            stripos($message, 'submitted') !== false ||
            stripos($message, 'cancelled') !== false ||
            stripos($message, 'extension') !== false ||
            stripos($message, 'declined') !== false ||
            stripos($message, 'urgent') !== false
        ) {
            $caught_up = false;
            if (
                stripos($message, 'cancelled') !== false ||
                stripos($message, 'declined') !== false ||
                stripos($message, 'extension') !== false ||
                stripos($message, 'submitted') !== false
            ) {
                $urgent_count++;
            }
        }
    }

    // Hide message notifs
    $filtered_notifications = array();
    foreach ($notifications as $notif) {
        $message = isset($notif['message']) ? $notif['message'] : '';
        $meta = isset($notif['meta']) ? $notif['meta'] : array();

        $is_message_notif = false;
        if (isset($meta['conversation_id'])) $is_message_notif = true;
        if (stripos($message, 'message') !== false || stripos($message, 'sent you') !== false) $is_message_notif = true;

        if (!$is_message_notif) $filtered_notifications[] = $notif;
    }

    usort($filtered_notifications, function($a, $b) {
        $ta = isset($a['time']) ? strtotime($a['time']) : 0;
        $tb = isset($b['time']) ? strtotime($b['time']) : 0;
        return $tb - $ta;
    });

    // NEW nonce just for our new endpoints (so it always matches)
    $va2_nonce = wp_create_nonce('va2_notif_nonce');

    ob_start();
    ?>
    <style>
        .va-notification-center{max-width:800px;margin:20px auto;}
        .notification-item{background:#fff;padding:18px;margin-bottom:12px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.08);border-left:4px solid #2271b1;transition:transform .2s,box-shadow .2s;}
        .notification-item:hover{transform:translateY(-2px);box-shadow:0 4px 12px rgba(0,0,0,.12);}
        .notification-item.unread{background:#f0f6fc;border-left-color:#0073aa;}
        .notification-item.read{opacity:.85;border-left-color:#ddd;}
        .notification-item.type-urgent{border-left-color:#ff3860;border-left-width:6px;background:#fff5f5;animation:pulseUrgent 2s infinite;}
        @keyframes pulseUrgent{0%{box-shadow:0 0 0 0 rgba(255,56,96,.4)}70%{box-shadow:0 0 0 10px rgba(255,56,96,0)}100%{box-shadow:0 0 0 0 rgba(255,56,96,0)}}
        .notification-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;}
        .notification-icon{font-size:24px;margin-right:12px;}
        .notification-message{font-size:15px;line-height:1.6;margin-bottom:10px;color:#333;}
        .notification-time{font-size:12px;color:#666;font-style:italic;}
        .notification-actions{align-items:center;margin-top:15px;display:flex;gap:10px;flex-wrap:wrap;}

        .btn-notif{padding:8px 16px;border:none;border-radius:8px;cursor:pointer;font-size:14px;font-weight:700;transition:background .2s,transform .08s;}
        .btn-notif:active{transform:translateY(1px);}
        .btn-notif[disabled]{opacity:.65;cursor:not-allowed;}

        .btn-accept{background:#28a745;color:#fff;}
        .btn-accept:hover{background:#218838;}
        .btn-decline{background:#dc3545;color:#fff;}
        .btn-decline:hover{background:#c82333;}
        .btn-view{background:#2271b1;color:#fff;}
        .btn-view:hover{background:#135e96;}

        .notification-badge{display:inline-block;padding:4px 8px;border-radius:6px;font-size:11px;font-weight:900;text-transform:uppercase;margin-left:8px;}
        .badge-urgent{background:#ff3860;color:#fff;}

        .notifications-list.scrollable{max-height:400px;overflow-y:auto;padding-right:10px;}

        /* Email toggle */
        .email-toggle-container{margin-bottom:20px;padding:15px;background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.08);display:flex;align-items:center;justify-content:space-between;}
        .email-toggle-label{font-weight:900;color:#333;font-size:14px;}
        .toggle-switch{position:relative;display:inline-block;width:50px;height:24px;}
        .toggle-switch input{opacity:0;width:0;height:0;}
        .toggle-slider{position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;background-color:#ccc;transition:.4s;border-radius:24px;}
        .toggle-slider:before{position:absolute;content:"";height:18px;width:18px;left:3px;bottom:3px;background-color:#fff;transition:.4s;border-radius:50%;}
        input:checked + .toggle-slider{background-color:#28a745;}
        input:checked + .toggle-slider:before{transform:translateX(26px);}
        .toggle-status-text{margin-left:10px;font-size:13px;color:#666;}

        /* Status header */
        .assignment-status-header{display:flex;align-items:center;gap:15px;margin-bottom:20px;padding:15px;background:#f8f9fa;border-radius:8px;border:1px solid #dee2e6;}
        .status-indicator{display:flex;align-items:center;gap:8px;padding:8px 16px;border-radius:50px;font-weight:900;font-size:14px;}
        .status-indicator.urgent{background:#ff3860;color:#fff;}
        .status-indicator.caught-up{background:#00d1b2;color:#fff;}
        .status-dot{width:10px;height:10px;border-radius:50%;background:#fff;}
        .urgent-count{background:#fff;color:#ff3860;border-radius:50%;width:24px;height:24px;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:900;margin-left:8px;}

        /* Clear all UX */
        .notif-toolbar{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:15px;}
        .clear-all-btn{background:transparent;color:#dc3545;border:1px solid rgba(220,53,69,.25);border-radius:8px;padding:8px 12px;font-weight:900;cursor:pointer;}
        .clear-all-btn:hover{background:rgba(220,53,69,.08);}
        .clear-all-confirm{display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:10px;border:1px solid #eee;background:#fff;box-shadow:0 2px 10px rgba(0,0,0,.06);font-size:13px;color:#333;}
        .btn-ghost{background:#f1f3f5;color:#333;}
        .btn-ghost:hover{background:#e9ecef;}

        /* Dismiss button */
        .btn-icon{width:34px;height:34px;padding:0;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;background:#f1f3f5;color:#333;}
        .btn-icon:hover{background:#e9ecef;}
        .notification-item.pending-delete{opacity:.55;filter:grayscale(.15);}

        /* Toast */
        #va-toast-container{position:fixed;right:18px;bottom:18px;z-index:99999;display:flex;flex-direction:column;gap:10px;max-width:360px;}
        .va-toast{background:#111;color:#fff;border-radius:12px;padding:12px;box-shadow:0 10px 30px rgba(0,0,0,.25);display:flex;align-items:center;gap:10px;}
        .va-toast-msg{flex:1;font-size:13px;line-height:1.35;}
        .va-toast-action{border:1px solid rgba(255,255,255,.25);background:rgba(255,255,255,.08);color:#fff;border-radius:10px;padding:8px 10px;cursor:pointer;font-weight:900;font-size:12px;}
        .va-toast-action:hover{background:rgba(255,255,255,.14);}
        .va-toast-close{background:transparent;border:none;color:rgba(255,255,255,.8);cursor:pointer;font-size:16px;line-height:1;padding:4px 6px;}
        .va-toast-close:hover{color:#fff;}

        .empty-notifications{text-align:center;padding:40px 20px;color:#666;}
        .empty-notifications-icon{font-size:48px;margin-bottom:15px;opacity:.5;}
    </style>

    <div class="va-notification-center">

        <div class="email-toggle-container">
            <span class="email-toggle-label">📧 Email Notifications</span>
            <div style="display:flex;align-items:center;">
                <label class="toggle-switch">
                    <input type="checkbox" id="email-notifications-toggle"
                        <?php echo (get_user_meta($user_id, 'va_email_notifications_enabled', true) !== 'off') ? 'checked' : ''; ?>>
                    <span class="toggle-slider"></span>
                </label>
                <span class="toggle-status-text" id="toggle-status-text">
                    <?php echo (get_user_meta($user_id, 'va_email_notifications_enabled', true) !== 'off') ? 'On' : 'Off'; ?>
                </span>
            </div>
        </div>

        <h2>Your Notifications</h2>

        <div class="assignment-status-header">
            <?php if ($urgent_count > 0): ?>
                <div class="status-indicator urgent">
                    <span class="status-dot"></span>
                    <span>URGENT ACTION REQUIRED</span>
                    <span class="urgent-count"><?php echo esc_html($urgent_count); ?></span>
                </div>
                <div style="font-size:13px;color:#666;">
                    <?php if ($is_csm): ?>Assignments need your review<?php elseif ($is_client): ?>Assignments need your attention<?php elseif ($is_va): ?>Assignments need your response<?php endif; ?>
                </div>
            <?php elseif ($caught_up): ?>
                <div class="status-indicator caught-up">
                    <span class="status-dot"></span>
                    <span>ALL CAUGHT UP!</span>
                </div>
                <div style="font-size:13px;color:#666;">No pending assignments requiring attention</div>
            <?php else: ?>
                <div class="status-indicator" style="background:#f0f0f0;color:#666;">
                    <span class="status-dot" style="background:#666;"></span>
                    <span>ACTIVE ASSIGNMENTS</span>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($filtered_notifications)): ?>
            <div class="notif-toolbar">
                <button class="clear-all-btn" id="clear-all-notifications" type="button">Clear all</button>

                <div class="clear-all-confirm" id="clear-all-confirm" style="display:none;">
                    <span>Clear all <?php echo (int)count($filtered_notifications); ?> notifications?</span>
                    <button class="btn-notif btn-decline" id="clear-all-confirm-yes" type="button">Clear</button>
                    <button class="btn-notif btn-ghost" id="clear-all-confirm-no" type="button">Cancel</button>
                </div>
            </div>
        <?php endif; ?>

        <div class="notifications-list">
            <?php if (empty($filtered_notifications)): ?>
                <div class="empty-notifications">
                    <div class="empty-notifications-icon">🔔</div>
                    <p><strong>No notifications yet</strong></p>
                    <p>You're all caught up!</p>
                </div>
            <?php else: ?>
                <?php foreach ($filtered_notifications as $notif):
                    $message  = isset($notif['message']) ? $notif['message'] : '';
                    $meta     = isset($notif['meta']) ? $notif['meta'] : array();
                    $time     = isset($notif['time']) ? $notif['time'] : '';
                    $read     = isset($notif['read']) ? (int)$notif['read'] : 0;
                    $notif_id = isset($notif['id']) ? (string)$notif['id'] : '';

                    $type = 'default';
                    $icon = '📢';

                    $is_urgent = false;
                    if (
                        stripos($message, 'assignment') !== false ||
                        stripos($message, 'submitted') !== false ||
                        stripos($message, 'cancelled') !== false ||
                        stripos($message, 'extension') !== false ||
                        stripos($message, 'declined') !== false
                    ) {
                        if (
                            stripos($message, 'cancelled') !== false ||
                            stripos($message, 'declined') !== false ||
                            stripos($message, 'extension') !== false ||
                            stripos($message, 'submitted') !== false
                        ) {
                            $is_urgent = true;
                            $type = 'urgent';
                            $icon = '🚨';
                        } else {
                            $type = 'assignment';
                            $icon = '📋';
                        }
                    }

                    $read_class = $read ? 'read' : 'unread';
                ?>
                <div class="notification-item type-<?php echo esc_attr($type); ?> <?php echo esc_attr($read_class); ?>"
                     data-notif-id="<?php echo esc_attr($notif_id); ?>">
                    <div class="notification-header">
                        <div>
                            <span class="notification-icon"><?php echo esc_html($icon); ?></span>
                            <?php if ($is_urgent): ?>
                                <span class="notification-badge badge-urgent">URGENT</span>
                            <?php endif; ?>
                        </div>

                        <button class="btn-notif btn-icon dismiss-notification"
                                type="button"
                                data-notif-id="<?php echo esc_attr($notif_id); ?>"
                                aria-label="Dismiss notification"
                                title="Dismiss this notification">
                            ✕
                        </button>
                    </div>

                    <div class="notification-message"><?php echo esc_html($message); ?></div>

                    <div class="notification-time">
                        <?php
                        if ($time) {
                            $time_ago = human_time_diff(strtotime($time), current_time('timestamp'));
                            echo esc_html($time_ago . ' ago');
                        }
                        ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div id="va-toast-container" aria-live="polite" aria-atomic="true"></div>
    </div>

    <script>
    jQuery(function($){
        var ajaxurl = <?php echo json_encode(admin_url('admin-ajax.php')); ?>;
        var va2Nonce = <?php echo json_encode($va2_nonce); ?>;

        function updateScrollableList(){
            $('.notifications-list').removeClass('scrollable');
            if ($('.notification-item').length > 3) $('.notifications-list').addClass('scrollable');
        }
        updateScrollableList();

        function renderEmptyState(){
            $('.notifications-list').html(`
                <div class="empty-notifications">
                    <div class="empty-notifications-icon">🔔</div>
                    <p><strong>No notifications yet</strong></p>
                    <p>You're all caught up!</p>
                </div>
            `);
            $('.notif-toolbar').hide();
            updateScrollableList();
        }

        function vaToast(message, actionText, actionFn, ttlMs){
            ttlMs = ttlMs || 6000;
            var $toast = $(`
              <div class="va-toast" role="status">
                <div class="va-toast-msg"></div>
                ${actionText ? `<button type="button" class="va-toast-action"></button>` : ``}
                <button type="button" class="va-toast-close" aria-label="Dismiss">✕</button>
              </div>
            `);

            $toast.find('.va-toast-msg').text(message);

            if (actionText){
                $toast.find('.va-toast-action').text(actionText).on('click', function(){
                    if (typeof actionFn === 'function') actionFn();
                    $toast.fadeOut(150, function(){ $(this).remove(); });
                });
            }

            $toast.find('.va-toast-close').on('click', function(){
                $toast.fadeOut(150, function(){ $(this).remove(); });
            });

            $('#va-toast-container').append($toast);

            var t = setTimeout(function(){
                $toast.fadeOut(150, function(){ $(this).remove(); });
            }, ttlMs);

            $toast.on('mouseenter', function(){ clearTimeout(t); });
            $toast.on('mouseleave', function(){
                t = setTimeout(function(){
                    $toast.fadeOut(150, function(){ $(this).remove(); });
                }, 1500);
            });

            return $toast;
        }

        // Email toggle -> NEW action
        $('#email-notifications-toggle').on('change', function(){
            var isEnabled = $(this).is(':checked');
            $('#toggle-status-text').text(isEnabled ? 'On' : 'Off');

            $.post(ajaxurl, {
                action: 'va2_toggle_email_notifications',
                enabled: isEnabled ? 'on' : 'off',
                nonce: va2Nonce
            }, function(res){
                if (!res || !res.success){
                    alert('Failed to update email preferences');
                    $('#email-notifications-toggle').prop('checked', !isEnabled);
                    $('#toggle-status-text').text(isEnabled ? 'Off' : 'On');
                }
            }).fail(function(){
                alert('Request failed. Please try again.');
                $('#email-notifications-toggle').prop('checked', !isEnabled);
                $('#toggle-status-text').text(isEnabled ? 'Off' : 'On');
            });
        });

        // ---------- Single dismiss with UNDO ----------
        var pendingDeletes = {}; // id -> {timeoutId, $item, $placeholder, $btn}

        function undoSingleDismiss(notifId){
            var entry = pendingDeletes[notifId];
            if (!entry) return;

            clearTimeout(entry.timeoutId);
            entry.$placeholder.replaceWith(entry.$item);
            entry.$item.removeClass('pending-delete').hide().slideDown(180);
            entry.$btn.prop('disabled', false);
            delete pendingDeletes[notifId];

            if ($('.notification-item').length > 0) $('.notif-toolbar').show();
            updateScrollableList();
        }

        function commitSingleDismiss(notifId){
            var entry = pendingDeletes[notifId];
            if (!entry) return;

            $.post(ajaxurl, {
                action: 'va2_dismiss_notification',
                notification_id: notifId,
                nonce: va2Nonce
            }, function(res){
                if (res && res.success){
                    entry.$placeholder.remove();
                    entry.$item.remove();
                    delete pendingDeletes[notifId];

                    if ($('.notification-item').length === 0) renderEmptyState();
                    else updateScrollableList();
                } else {
                    undoSingleDismiss(notifId);
                    vaToast('Could not remove notification. Restored.');
                }
            }).fail(function(){
                undoSingleDismiss(notifId);
                vaToast('Request failed. Notification restored.');
            });
        }

        $(document).on('click', '.dismiss-notification', function(){
            var $btn = $(this);
            var notifId = $btn.data('notif-id');
            var $item = $btn.closest('.notification-item');

            if (!notifId) { vaToast('This notification has no id, cannot dismiss.'); return; }
            if (pendingDeletes[notifId]) return;

            $btn.prop('disabled', true);

            var $placeholder = $('<div></div>');
            $placeholder.insertBefore($item);

            $item.addClass('pending-delete').slideUp(160, function(){
                $item.detach();
            });

            var timeoutId = setTimeout(function(){
                commitSingleDismiss(notifId);
            }, 6000);

            pendingDeletes[notifId] = { timeoutId: timeoutId, $item: $item, $placeholder: $placeholder, $btn: $btn };

            vaToast('Notification removed.', 'Undo', function(){
                undoSingleDismiss(notifId);
            });
        });

        // ---------- Clear All: inline confirm + UNDO ----------
        var pendingClearAll = null; // {timeoutId, $items}

        $('#clear-all-notifications').on('click', function(){
            $(this).hide();
            $('#clear-all-confirm').fadeIn(120);
        });

        $('#clear-all-confirm-no').on('click', function(){
            $('#clear-all-confirm').hide();
            $('#clear-all-notifications').show();
        });

        function undoClearAll(){
            if (!pendingClearAll) return;

            clearTimeout(pendingClearAll.timeoutId);
            $('.notifications-list').empty().append(pendingClearAll.$items);
            pendingClearAll = null;

            $('.notif-toolbar').show();
            $('#clear-all-confirm').hide();
            $('#clear-all-notifications').show().prop('disabled', false).text('Clear all');

            updateScrollableList();
        }

        function commitClearAll(){
            if (!pendingClearAll) return;

            $.post(ajaxurl, {
                action: 'va2_clear_all_notifications',
                nonce: va2Nonce
            }, function(res){
                if (res && res.success){
                    pendingClearAll = null;
                    renderEmptyState();
                } else {
                    undoClearAll();
                    vaToast('Failed to clear notifications. Restored.');
                }
            }).fail(function(){
                undoClearAll();
                vaToast('Request failed. Notifications restored.');
            });
        }

        $('#clear-all-confirm-yes').on('click', function(){
            var $items = $('.notifications-list .notification-item').detach();
            if ($items.length === 0){ renderEmptyState(); return; }

            renderEmptyState();
            $('#clear-all-confirm').hide();
            $('#clear-all-notifications').show().prop('disabled', true).text('Clearing...');

            pendingClearAll = { $items: $items, timeoutId: null };
            pendingClearAll.timeoutId = setTimeout(function(){
                commitClearAll();
            }, 6000);

            vaToast('All notifications cleared.', 'Undo', function(){ undoClearAll(); });
        });
    });
    </script>
    <?php
    return ob_get_clean();
}

/* ===========================
 * NEW AJAX HANDLERS (unique)
 * =========================== */

add_action('wp_ajax_va2_dismiss_notification', 'va2_ajax_dismiss_notification');
function va2_ajax_dismiss_notification() {
    if (!is_user_logged_in()) wp_send_json_error(array('message' => 'Not logged in'));
    $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
    if (!wp_verify_nonce($nonce, 'va2_notif_nonce')) wp_send_json_error(array('message' => 'Invalid nonce'));

    $user_id = get_current_user_id();
    $notif_id = isset($_POST['notification_id']) ? sanitize_text_field($_POST['notification_id']) : '';
    if ($notif_id === '') wp_send_json_error(array('message' => 'Missing notification id'));

    $notifications = get_user_meta($user_id, 'va_notifications', true);
    if (!is_array($notifications)) $notifications = array();

    $new = array();
    foreach ($notifications as $n) {
        $id = isset($n['id']) ? (string)$n['id'] : '';
        if ($id !== (string)$notif_id) $new[] = $n;
    }

    update_user_meta($user_id, 'va_notifications', $new);
    wp_send_json_success(array('message' => 'Dismissed'));
}

add_action('wp_ajax_va2_clear_all_notifications', 'va2_ajax_clear_all_notifications');
function va2_ajax_clear_all_notifications() {
    if (!is_user_logged_in()) wp_send_json_error(array('message' => 'Not logged in'));
    $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
    if (!wp_verify_nonce($nonce, 'va2_notif_nonce')) wp_send_json_error(array('message' => 'Invalid nonce'));

    $user_id = get_current_user_id();
    update_user_meta($user_id, 'va_notifications', array());
    wp_send_json_success(array('message' => 'Cleared'));
}

add_action('wp_ajax_va2_toggle_email_notifications', 'va2_ajax_toggle_email_notifications');
function va2_ajax_toggle_email_notifications() {
    if (!is_user_logged_in()) wp_send_json_error(array('message' => 'Not logged in'));
    $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
    if (!wp_verify_nonce($nonce, 'va2_notif_nonce')) wp_send_json_error(array('message' => 'Invalid nonce'));

    $user_id = get_current_user_id();
    $enabled = isset($_POST['enabled']) ? sanitize_text_field($_POST['enabled']) : 'on';

    update_user_meta($user_id, 'va_email_notifications_enabled', ($enabled === 'off') ? 'off' : 'on');
    wp_send_json_success(array('enabled' => ($enabled === 'off') ? 'off' : 'on'));
}
