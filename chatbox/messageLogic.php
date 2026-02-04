/**
 * Part 3 — Enhanced Chat Window with Manager Group Chat Creator
 * Filename: part-3-va-shortcodes.php (UPDATED WITH ENHANCED UI)
 */

/* ========== 1. CLIENT CHAT WINDOW [va_chat_window] ========== */
add_shortcode('va_chat_window', 'va_sc_client_chat_window');
function va_sc_client_chat_window($atts) {
    if (!is_user_logged_in()) return '<p>Please login to access messages.</p>';
    
    $user_id = get_current_user_id();
    $conv_selected = isset($_GET['conv']) ? intval($_GET['conv']) : 0;

    // Get client's conversations (with accepted VAs + manager)
    $user_convo_ids = get_user_meta($user_id, 'va_conversations', true);
    $user_convos = array();

    if (is_array($user_convo_ids) && !empty($user_convo_ids)) {
        foreach ($user_convo_ids as $conv_id) {
            $conv = get_post($conv_id);
            if (!$conv) continue;
            
            $parts = get_post_meta($conv_id, 'participants', true);
            if (!is_array($parts)) continue;
            
            // Filter: only show conversations where access is allowed
            $is_valid = false;
            foreach ($parts as $p) {
                if (intval($p) === $user_id) continue;
                
                // Check if participant is a manager (always allow)
                if (va_is_manager($p)) {
                    $is_valid = true;
                    break;
                }
                
                // Check if it's an accepted VA
                if (va_is_invitation_accepted($user_id, $p)) {
                    $is_valid = true;
                }
            }
            
            if ($is_valid) {
                $user_convos[] = $conv;
            }
        }
    }

    // Get accepted VAs for group chat creation
    $selected_vas = va_get_selected_vas($user_id);
    $accepted_vas = array();
    foreach ($selected_vas as $va_id) {
        if (va_is_invitation_accepted($user_id, $va_id)) {
            $va_user = get_userdata($va_id);
            if ($va_user) {
                $accepted_vas[] = array(
                    'id' => $va_id,
                    'name' => $va_user->display_name ?: $va_user->user_login
                );
            }
        }
    }

    return va_render_unified_chat_window($user_id, $user_convos, $conv_selected, 'Client Dashboard', $accepted_vas);
}

/* ========== 2. MANAGER CHAT WINDOW [va_manager_chat] ========== */
add_shortcode('va_manager_chat', 'va_sc_manager_chat_window');
function va_sc_manager_chat_window($atts) {
    if (!is_user_logged_in()) return '<p>Please login to access messages.</p>';
    
    $user_id = get_current_user_id();
    
    // Check if user is a manager
    if (!va_is_manager($user_id)) {
        return '<p>You do not have manager privileges.</p>';
    }
    
    $conv_selected = isset($_GET['conv']) ? intval($_GET['conv']) : 0;

    // Get manager's conversations (with clients and VAs)
    $user_convo_ids = get_user_meta($user_id, 'va_conversations', true);
    $user_convos = array();

    if (is_array($user_convo_ids) && !empty($user_convo_ids)) {
        $posts = get_posts(array(
            'post_type' => 'va_conversation',
            'post__in' => $user_convo_ids,
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'orderby' => 'post_date',
            'order' => 'DESC'
        ));
        $user_convos = $posts;
    }

    // Get manager's clients (users who have conversations with them)
    $manager_clients = array();
    foreach ($user_convos as $conv) {
        $parts = get_post_meta($conv->ID, 'participants', true);
        if (!is_array($parts)) continue;
        
        foreach ($parts as $p) {
            if (intval($p) === $user_id) continue;
            
            // Check if this is a client (not a VA/manager)
            $p_user = get_userdata($p);
            if (!$p_user) continue;
            
            if (!va_is_manager($p) && !in_array('um_ambassador', $p_user->roles)) {
                $manager_clients[$p] = array(
                    'id' => $p,
                    'name' => $p_user->display_name ?: $p_user->user_login
                );
            }
        }
    }

    return va_render_unified_chat_window($user_id, $user_convos, $conv_selected, 'Manager Dashboard', array(), $manager_clients);
}

/* ========== 3. VA CHAT WINDOW [va_assistant_chat] ========== */
add_shortcode('va_assistant_chat', 'va_sc_va_chat_window');
function va_sc_va_chat_window($atts) {
    if (!is_user_logged_in()) return '<p>Please login to access messages.</p>';
    
    $user_id = get_current_user_id();
    $conv_selected = isset($_GET['conv']) ? intval($_GET['conv']) : 0;

    // Get VA's conversations (with accepted clients + manager)
    $user_convo_ids = get_user_meta($user_id, 'va_conversations', true);
    $user_convos = array();

    if (is_array($user_convo_ids) && !empty($user_convo_ids)) {
        foreach ($user_convo_ids as $conv_id) {
            $conv = get_post($conv_id);
            if (!$conv) continue;
            
            $parts = get_post_meta($conv_id, 'participants', true);
            if (!is_array($parts)) continue;
            
            // Filter: only show conversations where VA has accepted or it's with manager
            $is_valid = false;
            foreach ($parts as $p) {
                if (intval($p) === $user_id) continue;
                
                // Check if participant is a manager (always allow)
                if (va_is_manager($p)) {
                    $is_valid = true;
                    break;
                }
                
                // Check if VA has accepted this client
                if (va_is_invitation_accepted($p, $user_id)) {
                    $is_valid = true;
                }
            }
            
            if ($is_valid) {
                $user_convos[] = $conv;
            }
        }
    }

    return va_render_unified_chat_window($user_id, $user_convos, $conv_selected, 'VA Dashboard', array());
}

/* ========== UNIFIED CHAT WINDOW RENDERER (WITH ENHANCED UI) ========== */
function va_render_unified_chat_window($user_id, $user_convos, $conv_selected, $title, $accepted_vas = array(), $manager_clients = array()) {
    $show_group_creator = !empty($accepted_vas) && count($accepted_vas) >= 2;
    $show_manager_group_creator = !empty($manager_clients) && va_is_manager($user_id);

    ob_start();
?>
<style>
/* ========== MODERN DESIGN ========== */

.chat-wrapper {
    display: flex;
    gap: 20px;
    height: 640px;
    max-width: 1100px;
    margin: 20px auto;
    background: #f5f7fb;
    padding: 14px;
    border-radius: 18px;
    box-shadow: 0 4px 14px rgba(0,0,0,0.05);
    box-sizing: border-box;
}

.chat-sidebar {
    width: 28%;
    background: #fff;
    border-radius: 14px;
    padding: 14px 0;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    overflow-y: auto;
}

.chat-sidebar h3 {
    padding: 0 16px;
    font-size: 18px;
    font-weight: 700;
    margin-bottom: 12px;
}

.conversation-item {
    padding: 14px 16px;
    border-bottom: 1px solid #eee;
    cursor: pointer;
    transition: background 0.15s;
}

.conversation-item:hover {
    background: #f0f2ff;
}

.conversation-item.active {
    background: #e6e8ff;
    border-left: 4px solid #4E46DC;
}

.chat-panel {
    flex: 1;
    background: #fff;
    border-radius: 16px;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

.chat-header {
    padding: 16px;
    font-size: 18px;
    font-weight: 700;
    border-bottom: 1px solid #eee;
    background: #fafbff;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.clear-conv-btn {
    background: #dc3545;
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
    transition: background 0.2s;
}

.clear-conv-btn:hover {
    background: #c82333;
}

.chat-messages {
    flex: 1;
    padding: 18px;
    overflow-y: auto;
    background: #fafbff;
}

.message-row {
    margin-bottom: 16px;
    display: flex;
}

.message-row.user {
    justify-content: flex-end;
}

.message-bubble {
    max-width: 65%;
    background: #fff;
    padding: 12px 14px;
    font-size: 15px;
    border-radius: 12px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.04);
}

.message-row.user .message-bubble {
    background: #4E46DC;
    color: #fff;
}

.timestamp {
    font-size: 11px;
    opacity: 0.6;
    margin-top: 4px;
}

.delete-btn {
    font-size: 11px;
    background: #d92e4a;
    color: #fff;
    padding: 5px 8px;
    border-radius: 6px;
    cursor: pointer;
    margin-top: 6px;
    display: none;
}

.message-row.user:hover .delete-btn {
    display: inline-block;
}

.chat-input {
    display: flex;
    padding: 16px;
    border-top: 1px solid #eee;
    background: #fafbff;
    gap: 10px;
}

.chat-input textarea {
    flex: 1;
    resize: none;
    padding: 12px;
    border-radius: 10px;
    border: 1px solid #ddd;
    font-size: 15px;
}

.chat-input button {
    background: #4E46DC;
    color: #fff;
    border: none;
    padding: 12px 20px;
    border-radius: 10px;
    cursor: pointer;
    font-weight: 600;
}

.chat-input button:hover {
    background: #3d38b8;
}

.chat-input button:disabled {
    background: #ccc;
    cursor: not-allowed;
}

/* ========== GROUP CHAT CREATOR ========== */
.create-group-btn {
    margin: 10px 16px;
    padding: 12px;
    background: #4E46DC;
    color: white;
    border: none;
    border-radius: 10px;
    cursor: pointer;
    font-weight: 600;
    width: calc(100% - 32px);
    transition: background 0.2s;
}

.create-group-btn:hover {
    background: #3d38b8;
}

.group-chat-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 9999;
    align-items: center;
    justify-content: center;
}

.group-chat-modal.active {
    display: flex;
}

.modal-content {
    background: white;
    border-radius: 16px;
    padding: 30px;
    max-width: 500px;
    width: 90%;
    max-height: 80vh;
    overflow-y: auto;
    box-shadow: 0 10px 40px rgba(0,0,0,0.2);
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.modal-header h3 {
    margin: 0;
    font-size: 22px;
    color: #333;
}

.modal-close {
    background: none;
    border: none;
    font-size: 28px;
    cursor: pointer;
    color: #999;
    padding: 0;
    width: 30px;
    height: 30px;
    line-height: 1;
}

.modal-close:hover {
    color: #333;
}

.group-name-input {
    width: 100%;
    padding: 12px;
    border: 1px solid #ddd;
    border-radius: 8px;
    font-size: 15px;
    margin-bottom: 20px;
    box-sizing: border-box;
}

.va-selection-list {
    margin-bottom: 20px;
}

.va-checkbox-item {
    display: flex;
    align-items: center;
    padding: 12px;
    margin-bottom: 8px;
    background: #f8f9fa;
    border-radius: 8px;
    cursor: pointer;
    transition: background 0.2s;
}

.va-checkbox-item:hover {
    background: #e9ecef;
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
    font-size: 15px;
}

.modal-actions {
    display: flex;
    gap: 10px;
    margin-top: 20px;
}

.btn-create-group {
    flex: 1;
    padding: 12px;
    background: #28a745;
    color: white;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
    font-size: 15px;
}

.btn-create-group:hover {
    background: #218838;
}

.btn-create-group:disabled {
    background: #ccc;
    cursor: not-allowed;
}

.btn-cancel {
    flex: 1;
    padding: 12px;
    background: #6c757d;
    color: white;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
    font-size: 15px;
}

.btn-cancel:hover {
    background: #5a6268;
}

.selection-count {
    font-size: 13px;
    color: #666;
    margin-bottom: 10px;
}

.message-sender {
    font-size: 11px;
    font-weight: 700;
    margin-bottom: 6px;
    padding-bottom: 6px;
    border-bottom: 1px solid rgba(0,0,0,0.1);
    color: #333;
}

.message-row.user .message-sender {
    color: rgba(255,255,255,0.9);
    border-bottom-color: rgba(255,255,255,0.2);
}

/* ========== SUCCESS/LOADING OVERLAY ========== */
.action-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.6);
    z-index: 99999;
    align-items: center;
    justify-content: center;
}

.action-overlay.active {
    display: flex;
}

.action-message {
    background: white;
    border-radius: 16px;
    padding: 40px;
    max-width: 400px;
    text-align: center;
    box-shadow: 0 10px 40px rgba(0,0,0,0.3);
    animation: slideIn 0.3s ease;
}

@keyframes slideIn {
    from { transform: translateY(-20px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

.action-message .icon {
    font-size: 48px;
    margin-bottom: 20px;
}

.action-message h3 {
    margin: 0 0 10px 0;
    font-size: 22px;
    color: #333;
}

.action-message p {
    margin: 0 0 20px 0;
    color: #666;
    font-size: 15px;
    line-height: 1.5;
}

.loading-spinner {
    border: 4px solid #f3f3f3;
    border-top: 4px solid #2271b1;
    border-radius: 50%;
    width: 50px;
    height: 50px;
    animation: spin 1s linear infinite;
    margin: 0 auto 20px;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.btn-continue {
    background: #2271b1;
    color: white;
    border: none;
    padding: 12px 30px;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
    font-size: 15px;
}

.btn-continue:hover {
    background: #1a5a8e;
}

/* ========== DELETE CONFIRMATION MODAL ========== */
.delete-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 9999;
    align-items: center;
    justify-content: center;
}

.delete-modal.active {
    display: flex;
}

.delete-modal-content {
    background: white;
    border-radius: 16px;
    padding: 30px;
    max-width: 450px;
    width: 90%;
    box-shadow: 0 10px 40px rgba(0,0,0,0.2);
    animation: slideIn 0.3s ease;
}

.delete-modal-header {
    text-align: center;
    margin-bottom: 20px;
}

.delete-modal-header .warning-icon {
    font-size: 60px;
    color: #dc3545;
    margin-bottom: 10px;
}

.delete-modal-header h3 {
    margin: 0 0 10px 0;
    font-size: 24px;
    color: #333;
}

.delete-modal-body {
    margin-bottom: 25px;
}

.delete-modal-body p {
    margin: 10px 0;
    color: #666;
    line-height: 1.6;
}

.delete-modal-body .warning-text {
    background: #fff3cd;
    border-left: 4px solid #ffc107;
    padding: 15px;
    margin: 20px 0;
    border-radius: 4px;
}

.delete-modal-body .warning-text strong {
    color: #856404;
    display: block;
    margin-bottom: 5px;
}

.delete-modal-body .warning-text p {
    color: #856404;
}

.delete-modal-actions {
    display: flex;
    gap: 10px;
}

.btn-delete-confirm {
    flex: 1;
    padding: 12px;
    background: #dc3545;
    color: white;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
    font-size: 15px;
}

.btn-delete-confirm:hover {
    background: #c82333;
}

.btn-delete-cancel {
    flex: 1;
    padding: 12px;
    background: #6c757d;
    color: white;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
    font-size: 15px;
}

.btn-delete-cancel:hover {
    background: #5a6268;
}

@media (max-width:900px) {
    .chat-wrapper { flex-direction: column; height: auto; }
    .chat-sidebar { width: 100%; }
    .modal-content { padding: 20px; }
    .delete-modal-content { padding: 20px; }
}
</style>

<div class="chat-wrapper">

    <!-- Sidebar -->
    <div class="chat-sidebar">
        <h3>Conversations</h3>

        <?php if ($show_group_creator): ?>
            <button class="create-group-btn" id="open-group-modal">
                ➕ Create Group Chat
            </button>
        <?php endif; ?>

        <?php if ($show_manager_group_creator): ?>
            <button class="create-group-btn" id="open-manager-group-modal">
                👥 Create Manager Group
            </button>
        <?php endif; ?>

        <?php if (empty($user_convos)): ?>
            <p style="padding: 0 16px; color: #666;" id="no-conversations-message">No conversations yet</p>
        <?php else: ?>
            <?php foreach ($user_convos as $c):
                $parts = get_post_meta($c->ID, 'participants', true);
                $label = $c->post_title;

                $is_group = get_post_meta($c->ID, 'is_group', true);
                if (!$is_group && is_array($parts)) {
                    $other = array_filter($parts, function($p) use ($user_id) {
                        return intval($p) !== intval($user_id);
                    });
                    $other_id = array_shift($other);
                    $u = $other_id ? get_userdata($other_id) : null;
                    if ($u) {
                        $label = $u->display_name;
                        if (va_is_manager($other_id)) {
                            $label .= ' (Manager)';
                        }
                    }
                }
            ?>
            <div class="conversation-item <?php echo ($c->ID === $conv_selected) ? 'active' : ''; ?>"
                 data-conv="<?php echo $c->ID; ?>"
                 data-conv-name="<?php echo esc_attr($label); ?>">
                <?php echo esc_html($label); ?>
                <?php if ($is_group): ?>
                    <span style="font-size: 12px; color: #666;"> (Group)</span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Chat Panel -->
    <div class="chat-panel">
        <div class="chat-header">
            <span id="chat-header-title">
                <?php
                if ($conv_selected) {
                    $conv = get_post($conv_selected);
                    if ($conv) {
                        $parts = get_post_meta($conv_selected, 'participants', true);
                        $is_group = get_post_meta($conv_selected, 'is_group', true);

                        if (!$is_group && is_array($parts)) {
                            $other = array_filter($parts, function($p) use ($user_id) {
                                return intval($p) !== intval($user_id);
                            });
                            $other_id = array_shift($other);
                            $u = $other_id ? get_userdata($other_id) : null;
                            if ($u) {
                                $display_name = $u->display_name;
                                if (va_is_manager($other_id)) {
                                    $display_name .= ' (Manager)';
                                }
                                echo esc_html($display_name);
                            } else {
                                echo "Conversation";
                            }
                        } else {
                            echo esc_html($conv->post_title);
                        }
                    } else {
                        echo "Select a conversation";
                    }
                } else {
                    echo "Select a conversation";
                }
                ?>
            </span>

            <?php if (va_is_manager($user_id)): ?>
                <button id="clear-conversation-btn"
                        class="clear-conv-btn"
                        style="display: none;"
                        data-conv="<?php echo $conv_selected; ?>"
                        title="Permanently delete this conversation">
                    Delete Conversation
                </button>
            <?php endif; ?>
        </div>

        <div class="chat-messages" id="va-messages" data-conv="<?php echo $conv_selected; ?>">
           <?php echo $conv_selected ? "<p>Loading messages...</p>" : "<p>Select a conversation to view messages.</p>"; ?>
        </div>

        <div class="chat-input">
            <textarea id="va-message-input" placeholder="Type a message..." <?php echo !$conv_selected ? 'disabled' : ''; ?>></textarea>
            <button id="va-send-btn" <?php echo !$conv_selected ? 'disabled' : ''; ?>>Send</button>
        </div>
    </div>

</div>

<!-- Success/Loading Overlay -->
<div class="action-overlay" id="action-overlay">
    <div class="action-message" id="action-message"></div>
</div>

<!-- Delete Confirmation Modal (REUSED for Conversation + Message) -->
<div class="delete-modal" id="delete-modal">
    <div class="delete-modal-content">
        <div class="delete-modal-header">
            <div class="warning-icon">⚠️</div>
            <h3 id="delete-modal-title">Delete</h3>
        </div>

        <div class="delete-modal-body">
            <p id="delete-modal-desc">Are you sure?</p>

            <div class="warning-text">
                <strong id="delete-modal-warning-title">⚠️ This action cannot be undone!</strong>
                <p id="delete-modal-warning-points" style="margin: 5px 0 0 0;"></p>
            </div>
        </div>

        <div class="delete-modal-actions">
            <button class="btn-delete-cancel" id="cancel-delete">Cancel</button>
            <button class="btn-delete-confirm" id="confirm-delete">Delete</button>
        </div>
    </div>
</div>

<!-- Client Group Chat Creation Modal -->
<?php if ($show_group_creator): ?>
<div class="group-chat-modal" id="group-chat-modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Create Group Chat</h3>
            <button class="modal-close" id="close-group-modal">&times;</button>
        </div>

        <input type="text"
               class="group-name-input"
               id="group-name"
               placeholder="Group name (optional)"
               maxlength="100">

        <div class="selection-count">
            Select VTs to add to the group (minimum 2):
            <span id="selected-count">0 selected</span>
        </div>

        <div class="va-selection-list">
            <?php foreach ($accepted_vas as $va): ?>
                <div class="va-checkbox-item">
                    <input type="checkbox"
                           id="va-<?php echo $va['id']; ?>"
                           value="<?php echo $va['id']; ?>"
                           class="va-checkbox">
                    <label for="va-<?php echo $va['id']; ?>">
                        <?php echo esc_html($va['name']); ?>
                    </label>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="modal-actions">
            <button class="btn-cancel" id="cancel-group">Cancel</button>
            <button class="btn-create-group" id="create-group-btn" disabled>Create Group</button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Manager Group Chat Creation Modal -->
<?php if ($show_manager_group_creator): ?>
<div class="group-chat-modal" id="manager-group-modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Create Manager Group Chat</h3>
            <button class="modal-close" id="close-manager-group-modal">&times;</button>
        </div>

        <input type="text"
               class="group-name-input"
               id="manager-group-name"
               placeholder="Group name (optional)"
               maxlength="100">

        <div class="selection-count">
            <strong>Client (Auto-added):</strong>
        </div>

        <select id="manager-client-select" style="width: 100%; padding: 12px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #ddd; font-size: 15px;">
            <option value="">-- Select a Client --</option>
            <?php foreach ($manager_clients as $client): ?>
                <option value="<?php echo $client['id']; ?>"><?php echo esc_html($client['name']); ?></option>
            <?php endforeach; ?>
        </select>

        <div class="selection-count" id="manager-va-section" style="display: none;">
            Select VAs to invite (they need to accept):
            <span id="manager-selected-count">0 selected</span>
        </div>

        <div class="va-selection-list" id="manager-va-list"></div>

        <div class="modal-actions">
            <button class="btn-cancel" id="cancel-manager-group">Cancel</button>
            <button class="btn-create-group" id="create-manager-group-btn" disabled>Create Group</button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
(function($){

    var ajaxurl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
    var chatNonce = <?php echo wp_json_encode(wp_create_nonce('va_chat_nonce')); ?>;
    var currentConv = <?php echo intval($conv_selected); ?>;
    var currentUser = <?php echo intval($user_id); ?>;
    var lastMessageId = 0;
    let poller;

    // ========== HELPER FUNCTION FOR ACTION MESSAGES ==========
    function showActionMessage(type, title, message, callback) {
        var overlay = $('#action-overlay');
        var messageBox = $('#action-message');
        var content = '';

        if (type === 'loading') {
            content = `
                <div class="loading-spinner"></div>
                <h3>${title}</h3>
                <p>${message}</p>
            `;
        } else if (type === 'success') {
            content = `
                <div class="icon" style="color: #28a745;">✓</div>
                <h3>${title}</h3>
                <p>${message}</p>
                <button class="btn-continue" id="continue-btn">Continue</button>
            `;
        } else if (type === 'error') {
            content = `
                <div class="icon" style="color: #dc3545;">✕</div>
                <h3>${title}</h3>
                <p>${message}</p>
                <button class="btn-continue" id="continue-btn">OK</button>
            `;
        }

        messageBox.html(content);
        overlay.addClass('active');

        // IMPORTANT: prevent stacking multiple click handlers
        messageBox.off('click', '#continue-btn');

        if (type !== 'loading') {
            messageBox.on('click', '#continue-btn', function() {
                overlay.removeClass('active');
                if (callback) callback();
            });
        }
    }

    // ========== CLIENT GROUP CHAT MODAL FUNCTIONALITY ==========
    $('#open-group-modal').on('click', function() {
        $('#group-chat-modal').addClass('active');
    });

    $('#close-group-modal, #cancel-group').on('click', function() {
        $('#group-chat-modal').removeClass('active');
        $('.va-checkbox').prop('checked', false);
        $('#group-name').val('');
        updateSelectedCount();
    });

    $('#group-chat-modal').on('click', function(e) {
        if ($(e.target).is('#group-chat-modal')) {
            $(this).removeClass('active');
            $('.va-checkbox').prop('checked', false);
            $('#group-name').val('');
            updateSelectedCount();
        }
    });

    function updateSelectedCount() {
        var count = $('.va-checkbox:checked').length;
        $('#selected-count').text(count + ' selected');
        $('#create-group-btn').prop('disabled', count < 2);
    }

    $('.va-checkbox').on('change', updateSelectedCount);

    $('#create-group-btn').on('click', function() {
        var selectedVAs = [];
        $('.va-checkbox:checked').each(function() {
            selectedVAs.push($(this).val());
        });

        if (selectedVAs.length < 2) {
            showActionMessage('error', '❌ Error', 'Please select at least 2 VAs for a group chat.');
            return;
        }

        var groupName = $('#group-name').val().trim();

        showActionMessage('loading', 'Creating Group Chat...', 'Please wait while we set up your group chat.');
        $('#group-chat-modal').removeClass('active');

        $.post(ajaxurl, {
            action: 'va_create_group_chat',
            va_ids: selectedVAs,
            group_name: groupName,
            nonce: chatNonce
        }, function(res) {
            if (res.success) {
                var newConvId = res.data.conversation_id;
                var convTitle = groupName || 'Group Chat';

                showActionMessage('success', '✓ Group Chat Created!',
                    'Your group chat has been created successfully.',
                    function() {
                        var newConvHtml = `
                            <div class="conversation-item" data-conv="${newConvId}" data-conv-name="${convTitle}">
                                ${convTitle}
                                <span style="font-size: 12px; color: #666;"> (Group)</span>
                            </div>
                        `;

                        var $sidebar = $('.chat-sidebar');
                        var $noConversationsMsg = $sidebar.find('#no-conversations-message');
                        var $conversationItems = $sidebar.find('.conversation-item');

                        if ($noConversationsMsg.length) {
                            $noConversationsMsg.replaceWith(newConvHtml);
                        } else if ($conversationItems.length) {
                            $conversationItems.first().before(newConvHtml);
                        } else {
                            var $lastButton = $sidebar.find('.create-group-btn').last();
                            if ($lastButton.length) $lastButton.after(newConvHtml);
                            else $sidebar.find('h3').after(newConvHtml);
                        }

                        $('.conversation-item').removeClass('active');
                        $(`.conversation-item[data-conv="${newConvId}"]`).addClass('active');

                        currentConv = newConvId;
                        lastMessageId = 0;

                        $('#chat-header-title').text(convTitle);
                        history.replaceState({}, "", "?conv=" + newConvId);

                        $("#va-message-input").prop("disabled", false);
                        $("#va-send-btn").prop("disabled", false);

                        var clearBtn = $("#clear-conversation-btn");
                        if (clearBtn.length > 0) {
                            clearBtn.attr("data-conv", newConvId).show();
                        }

                        if (poller) clearInterval(poller);
                        loadMessages(newConvId, 0, true);
                        poller = setInterval(function() {
                            loadMessages(newConvId, lastMessageId, false);
                        }, 5000);
                    });
            } else {
                showActionMessage('error', '❌ Failed', res.data.message || 'Failed to create group chat');
            }
        }).fail(function() {
            showActionMessage('error', '❌ Request Failed', 'Please try again later.');
        });

        $('.va-checkbox').prop('checked', false);
        $('#group-name').val('');
        updateSelectedCount();
    });

    // ========== MANAGER GROUP CHAT MODAL FUNCTIONALITY ==========
    $('#open-manager-group-modal').on('click', function() {
        $('#manager-group-modal').addClass('active');
    });

    $('#close-manager-group-modal, #cancel-manager-group').on('click', function() {
        $('#manager-group-modal').removeClass('active');
        $('#manager-client-select').val('');
        $('#manager-va-list').empty();
        $('#manager-va-section').hide();
        $('#manager-group-name').val('');
        updateManagerSelectedCount();
    });

    $('#manager-group-modal').on('click', function(e) {
        if ($(e.target).is('#manager-group-modal')) {
            $(this).removeClass('active');
            $('#manager-client-select').val('');
            $('#manager-va-list').empty();
            $('#manager-va-section').hide();
            $('#manager-group-name').val('');
            updateManagerSelectedCount();
        }
    });

    $('#manager-client-select').on('change', function() {
        var clientId = $(this).val();

        if (!clientId) {
            $('#manager-va-list').empty();
            $('#manager-va-section').hide();
            updateManagerSelectedCount();
            return;
        }

        $.post(ajaxurl, {
            action: 'va_get_client_vas',
            client_id: clientId,
            nonce: chatNonce
        }, function(res) {
            if (res.success && res.data.vas) {
                $('#manager-va-list').empty();

                if (res.data.vas.length === 0) {
                    $('#manager-va-list').html('<p style="padding: 12px; color: #666;">This client has no VAs yet.</p>');
                    $('#manager-va-section').hide();
                    updateManagerSelectedCount();
                    return;
                }

                $.each(res.data.vas, function(i, va) {
                    var item = $('<div class="va-checkbox-item"></div>');
                    var checkbox = $('<input type="checkbox" class="manager-va-checkbox" value="' + va.id + '" id="manager-va-' + va.id + '">');
                    var label = $('<label for="manager-va-' + va.id + '">' + va.name + '</label>');

                    item.append(checkbox).append(label);
                    $('#manager-va-list').append(item);
                });

                $('#manager-va-section').show();
                updateManagerSelectedCount();
                $('.manager-va-checkbox').on('change', updateManagerSelectedCount);
            }
        });
    });

    function updateManagerSelectedCount() {
        var clientSelected = $('#manager-client-select').val();
        var vaCount = $('.manager-va-checkbox:checked').length;
        $('#manager-selected-count').text(vaCount + ' selected');
        $('#create-manager-group-btn').prop('disabled', !(clientSelected && vaCount > 0));
    }

    $('#create-manager-group-btn').on('click', function() {
        var clientId = $('#manager-client-select').val();
        var selectedVAs = [];

        $('.manager-va-checkbox:checked').each(function() {
            selectedVAs.push($(this).val());
        });

        if (!clientId) {
            showActionMessage('error', '❌ Error', 'Please select a client.');
            return;
        }

        if (selectedVAs.length === 0) {
            showActionMessage('error', '❌ Error', 'Please select at least 1 VA to invite.');
            return;
        }

        var groupName = $('#manager-group-name').val().trim();
        showActionMessage('loading', 'Creating Manager Group...', 'Please wait while we set up the group chat and send invitations.');
        $('#manager-group-modal').removeClass('active');

        $.post(ajaxurl, {
            action: 'va_manager_create_group',
            client_id: clientId,
            va_ids: selectedVAs,
            group_name: groupName,
            nonce: chatNonce
        }, function(res) {
            if (res.success) {
                var newConvId = res.data.conversation_id;
                var convTitle = groupName || 'Manager Group Chat';

                showActionMessage('success', '✓ Manager Group Created!',
                    'Your group chat has been created successfully. Client has been notified and VAs will receive invitations.',
                    function() {
                        var newConvHtml = `
                            <div class="conversation-item" data-conv="${newConvId}" data-conv-name="${convTitle}">
                                ${convTitle}
                                <span style="font-size: 12px; color: #666;"> (Group)</span>
                            </div>
                        `;

                        var $sidebar = $('.chat-sidebar');
                        var $noConversationsMsg = $sidebar.find('#no-conversations-message');
                        var $conversationItems = $sidebar.find('.conversation-item');

                        if ($noConversationsMsg.length) {
                            $noConversationsMsg.replaceWith(newConvHtml);
                        } else if ($conversationItems.length) {
                            $conversationItems.first().before(newConvHtml);
                        } else {
                            var $lastButton = $sidebar.find('.create-group-btn').last();
                            if ($lastButton.length) $lastButton.after(newConvHtml);
                            else $sidebar.find('h3').after(newConvHtml);
                        }

                        $('.conversation-item').removeClass('active');
                        $(`.conversation-item[data-conv="${newConvId}"]`).addClass('active');

                        currentConv = newConvId;
                        lastMessageId = 0;

                        $('#chat-header-title').text(convTitle);
                        history.replaceState({}, "", "?conv=" + newConvId);

                        $("#va-message-input").prop("disabled", false);
                        $("#va-send-btn").prop("disabled", false);

                        var clearBtn = $("#clear-conversation-btn");
                        if (clearBtn.length > 0) {
                            clearBtn.attr("data-conv", newConvId).show();
                        }

                        if (poller) clearInterval(poller);
                        loadMessages(newConvId, 0, true);
                        poller = setInterval(function() {
                            loadMessages(newConvId, lastMessageId, false);
                        }, 5000);
                    });
            } else {
                showActionMessage('error', '❌ Failed', res.data.message || 'Failed to create manager group');
            }
        }).fail(function() {
            showActionMessage('error', '❌ Request Failed', 'Please try again later.');
        });

        $('#manager-client-select').val('');
        $('#manager-va-list').empty();
        $('#manager-va-section').hide();
        $('#manager-group-name').val('');
        updateManagerSelectedCount();
    });

    // ========== DELETE (GENERIC MODAL) — Conversation + Message ==========
    var deleteContext = {
        type: null,      // 'conversation' | 'message'
        convId: 0,
        msgId: 0
    };

    function openDeleteModal(opts) {
        deleteContext.type  = opts.type || null;
        deleteContext.convId = opts.convId || 0;
        deleteContext.msgId  = opts.msgId || 0;

        $('#delete-modal-title').text(opts.title || 'Delete');
        $('#delete-modal-desc').text(opts.desc || 'Are you sure?');
        $('#delete-modal-warning-points').html(opts.pointsHtml || '');
        $('#confirm-delete').text(opts.confirmText || 'Delete').prop('disabled', false);

        $('#delete-modal').addClass('active');
    }

    function closeDeleteModal() {
        $('#delete-modal').removeClass('active');
        $('#confirm-delete').prop('disabled', false);
        deleteContext.type = null;
        deleteContext.convId = 0;
        deleteContext.msgId = 0;
    }

    $('#cancel-delete').on('click', closeDeleteModal);

    $('#delete-modal').on('click', function(e) {
        if ($(e.target).is('#delete-modal')) closeDeleteModal();
    });

    // Open "Delete Conversation"
    $(document).on("click", "#clear-conversation-btn", function(){
        var convId = $(this).data("conv");
        if (!convId) return;

        openDeleteModal({
            type: 'conversation',
            convId: convId,
            title: 'Delete Conversation',
            desc: 'Are you sure you want to permanently delete this conversation?',
            confirmText: 'Delete Conversation',
            pointsHtml: `
                • All messages will be permanently deleted<br>
                • Conversation will be removed from all participants<br>
                • This cannot be recovered
            `
        });
    });

    // Open "Delete Message" (SAME UX)
    $(document).on("click", ".delete-btn", function(){
        var msgId = $(this).data("msg-id");
        if (!msgId) return;

        openDeleteModal({
            type: 'message',
            msgId: msgId,
            title: 'Delete Message',
            desc: 'Are you sure you want to permanently delete this message?',
            confirmText: 'Delete Message',
            pointsHtml: `
                • This message will be permanently removed<br>
                • This cannot be undone
            `
        });
    });

    function deleteConversation(convId) {
        closeDeleteModal();
        showActionMessage('loading', 'Deleting Conversation...', 'Please wait while we remove this conversation.');

        $.post(ajaxurl, {
            action: "va_clear_conversation",
            conversation_id: convId,
            nonce: chatNonce
        }, function(res){
            if(res.success){
                if (poller) clearInterval(poller);

                showActionMessage('success', '✓ Conversation Deleted',
                    'The conversation has been permanently deleted and removed from all participants.',
                    function() {
                        $(`.conversation-item[data-conv="${convId}"]`).fadeOut(300, function(){
                            $(this).remove();

                            if ($('.conversation-item').length === 0) {
                                var $sidebar = $('.chat-sidebar');
                                if (!$('#no-conversations-message').length) {
                                    $sidebar.find('h3').after('<p style="padding: 0 16px; color: #666;" id="no-conversations-message">No conversations yet</p>');
                                }
                            }
                        });

                        currentConv = 0;
                        $("#va-messages").html('<div style="text-align: center; padding: 40px; color: #666;"><p style="font-size: 18px; margin-bottom: 10px;">Select a conversation to continue messaging.</p></div>');
                        $("#va-message-input").prop("disabled", true).val("");
                        $("#va-send-btn").prop("disabled", true);
                        $("#clear-conversation-btn").hide();
                        $("#chat-header-title").text("Select a conversation");
                        history.replaceState({}, "", window.location.pathname);
                    }
                );
            } else {
                showActionMessage('error', '❌ Delete Failed', (res.data && res.data.message) ? res.data.message : (res.data || "Failed to delete conversation"));
            }
        }).fail(function(){
            showActionMessage('error', '❌ Request Failed', "Please try again later.");
        });
    }

    function deleteMessage(msgId) {
        closeDeleteModal();
        showActionMessage('loading', 'Deleting Message...', 'Please wait while we remove this message.');

        $.post(ajaxurl, {
            action: "va_user_delete_message",
            message_id: msgId,
            conversation_id: currentConv,
            nonce: chatNonce
        }, function(res){
            if(res.success){
                showActionMessage('success', '✓ Message Deleted',
                    'The message has been permanently deleted.',
                    function() {
                        $(`.message-row[data-msg-id="${msgId}"]`).fadeOut(200, function(){
                            $(this).remove();

                            var $wrap = $("#va-messages");
                            if ($wrap.find('.message-row').length === 0) {
                                $wrap.html('<div style="text-align:center; padding:40px; color:#666;"><p style="font-size:16px;">No messages yet.</p></div>');
                            }
                        });
                    }
                );
            } else {
                showActionMessage('error', '❌ Delete Failed', (res.data && res.data.message) ? res.data.message : (res.data || "Failed to delete message"));
            }
        }).fail(function(){
            showActionMessage('error', '❌ Request Failed', "Please try again later.");
        });
    }

    // Confirm button executes correct delete action
    $('#confirm-delete').on('click', function() {
        if (!deleteContext.type) return;

        if (deleteContext.type === 'conversation' && deleteContext.convId) {
            deleteConversation(deleteContext.convId);
            return;
        }

        if (deleteContext.type === 'message' && deleteContext.msgId) {
            deleteMessage(deleteContext.msgId);
            return;
        }

        closeDeleteModal();
    });

    // ========== CHAT FUNCTIONALITY ==========
    function renderMessages(messages, replace){
        var wrap = $("#va-messages");
        if (wrap.length === 0) return;

        var isScrolledToBottom = wrap[0].scrollHeight - wrap.scrollTop() - wrap.outerHeight() < 100;
        var html = "";

        $.each(messages, function(i, m){
            var mine = (m.sender_id == currentUser) ? "user" : "";
            var content = m.content || "";
            var time = m.time || "";
            var senderName = m.sender_name || "Unknown User";

            html += `
                <div class="message-row ${mine}" data-msg-id="${m.id}">
                    <div class="message-bubble">
                        <div class="message-sender">${senderName}</div>
                        ${content}
                        <div class="timestamp">${time}</div>
                        ${ mine ? `<div class="delete-btn" data-msg-id="${m.id}">Delete</div>` : "" }
                    </div>
                </div>
            `;

            if(m.id > lastMessageId) lastMessageId = m.id;
        });

        if(replace) {
            wrap.html(html);
            wrap.scrollTop(wrap[0].scrollHeight);
        } else {
            wrap.append(html);
            if(isScrolledToBottom) wrap.scrollTop(wrap[0].scrollHeight);
        }
    }

    function loadMessages(conv, sinceId, replace){
        if(!conv) return;

        $.post(ajaxurl, {
            action: "va_fetch_messages",
            conversation_id: conv,
            nonce: chatNonce,
            since_id: sinceId
        }, function(res) {
            if (res.success) renderMessages(res.data.messages, replace);
        });
    }

    $(document).on("click", ".conversation-item", function(){
        if (poller) clearInterval(poller);

        $(".conversation-item").removeClass("active");
        $(this).addClass("active");

        currentConv = $(this).data("conv");

        var convName = $(this).data("conv-name");
        if (!convName) convName = $(this).clone().children().remove().end().text().trim();
        $("#chat-header-title").text(convName);

        lastMessageId = 0;

        $("#va-messages").html("<p>Loading messages...</p>");
        $("#va-message-input").prop("disabled", false);
        $("#va-send-btn").prop("disabled", false);

        var clearBtn = $("#clear-conversation-btn");
        if (clearBtn.length > 0) {
            clearBtn.attr("data-conv", currentConv).show();
        }

        history.replaceState({}, "", "?conv=" + currentConv);

        if (currentConv > 0) {
            loadMessages(currentConv, 0, true);
            poller = setInterval(function() {
                loadMessages(currentConv, lastMessageId, false);
            }, 5000);
        } else {
            $("#va-messages").html("<p>Select a conversation to view messages.</p>");
            $("#va-message-input").prop("disabled", true);
            $("#va-send-btn").prop("disabled", true);
            if (clearBtn.length > 0) clearBtn.hide();
        }
    });

    $("#va-send-btn").on("click", function(){
        var text = $("#va-message-input").val();
        if(!text.trim()) return;
        if(!currentConv) return alert("Select a conversation first");

        $.post(ajaxurl, {
            action: "va_send_message",
            conversation_id: currentConv,
            content: text,
            nonce: chatNonce
        }, function(res){
            if(res.success){
                $("#va-message-input").val("");
                loadMessages(currentConv, lastMessageId, false);
            }
        });
    });

    $("#va-message-input").on("keydown", function(e){
        if(e.key === "Enter" && !e.shiftKey){
            e.preventDefault();
            $("#va-send-btn").click();
        }
    });

    if(currentConv){
        loadMessages(currentConv, 0, true);
        poller = setInterval(function(){
            loadMessages(currentConv, lastMessageId, false);
        }, 5000);

        var clearBtn = $("#clear-conversation-btn");
        if (clearBtn.length > 0 && currentConv > 0) clearBtn.show();
    }

})(jQuery);
</script>

<?php
    return ob_get_clean();
}

/* ========== REST OF THE SHORTCODES (UNCHANGED) ========== */

/* VA INVITATIONS SHORTCODE */
add_shortcode('va_invitations', 'va_sc_invitations');
function va_sc_invitations() {
    if (!is_user_logged_in()) {
        return '<p>Please login to view invitations.</p>';
    }
    
    $va_user_id = get_current_user_id();
    
    $all_users = get_users();
    $pending_invitations = array();
    $accepted_invitations = array();
    $declined_invitations = array();
    
    foreach ($all_users as $user) {
        $selected_vas = va_get_selected_vas($user->ID);
        if (in_array($va_user_id, $selected_vas)) {
            $status = get_user_meta($user->ID, '_va_status_' . $va_user_id, true);
            $invitation = array(
                'client_id' => $user->ID,
                'client_name' => $user->display_name ?: $user->user_login,
                'client_email' => $user->user_email,
                'status' => $status ?: 'pending'
            );
            
            if ($status === 'accepted') {
                $accepted_invitations[] = $invitation;
            } elseif ($status === 'declined') {
                $declined_invitations[] = $invitation;
            } else {
                $pending_invitations[] = $invitation;
            }
        }
    }
    
    $nonce = wp_create_nonce('va_accept_nonce');
    
    ob_start();
    ?>
    <style>
        .va-invitations-wrap {
            max-width: 800px;
            margin: 20px auto;
        }
        .invitation-card {
            background: white;
            padding: 20px;
            margin-bottom: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .invitation-card.pending {
            border-left: 4px solid #ffc107;
        }
        .invitation-card.accepted {
            border-left: 4px solid #28a745;
        }
        .invitation-card.declined {
            border-left: 4px solid #dc3545;
        }
        .invitation-actions {
            margin-top: 15px;
        }
        .btn-accept {
            background: #28a745;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            margin-right: 10px;
        }
        .btn-decline {
            background: #dc3545;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        .status-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: bold;
        }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-accepted { background: #d4edda; color: #155724; }
        .status-declined { background: #f8d7da; color: #721c24; }
    </style>
    
    <div class="va-invitations-wrap">
        <h2>Client Invitations</h2>
        
        <?php if (!empty($pending_invitations)): ?>
            <h3>Pending Invitations (<?php echo count($pending_invitations); ?>)</h3>
            <?php foreach ($pending_invitations as $inv): ?>
                <div class="invitation-card pending" data-client-id="<?php echo $inv['client_id']; ?>">
                    <h4><?php echo esc_html($inv['client_name']); ?> <span class="status-badge status-pending">PENDING</span></h4>
                    <p>Email: <?php echo esc_html($inv['client_email']); ?></p>
                    <div class="invitation-actions">
                        <button class="btn-accept va-accept-btn" data-client-id="<?php echo $inv['client_id']; ?>">
                            ✓ Accept
                        </button>
                        <button class="btn-decline va-decline-btn" data-client-id="<?php echo $inv['client_id']; ?>">
                            ✗ Decline
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p>No pending invitations.</p>
        <?php endif; ?>
        
        <?php if (!empty($accepted_invitations)): ?>
            <h3 style="margin-top: 30px;">Accepted Clients (<?php echo count($accepted_invitations); ?>)</h3>
            <?php foreach ($accepted_invitations as $inv): ?>
                <div class="invitation-card accepted">
                    <h4><?php echo esc_html($inv['client_name']); ?> <span class="status-badge status-accepted">ACCEPTED</span></h4>
                    <p>Email: <?php echo esc_html($inv['client_email']); ?></p>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <script>
    jQuery(document).ready(function($){
        var ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
        var nonce = '<?php echo $nonce; ?>';
        
        $('.va-accept-btn').on('click', function(){
            var btn = $(this);
            var clientId = btn.data('client-id');
            var card = btn.closest('.invitation-card');
            
            if (!confirm('Accept this invitation?')) return;
            
            btn.prop('disabled', true);
            
            $.post(ajaxurl, {
                action: 'va_accept_invitation',
                client_id: clientId,
                nonce: nonce
            }, function(res){
                if (res.success) {
                    alert('Invitation accepted!');
                    location.reload();
                } else {
                    alert(res.data.message || 'Error');
                    btn.prop('disabled', false);
                }
            });
        });
        
        $('.va-decline-btn').on('click', function(){
            var btn = $(this);
            var clientId = btn.data('client-id');
            
            if (!confirm('Decline this invitation?')) return;
            
            btn.prop('disabled', true);
            
            $.post(ajaxurl, {
                action: 'va_decline_invitation',
                client_id: clientId,
                nonce: nonce
            }, function(res){
                if (res.success) {
                    alert('Invitation declined.');
                    location.reload();
                } else {
                    alert(res.data.message || 'Error');
                    btn.prop('disabled', false);
                }
            });
        });
    });
    </script>
    <?php
    return ob_get_clean();
}

/* ========== SELECT VA BUTTON SHORTCODE ========== */
add_shortcode('select_va_button', 'va_sc_select_va_button');
function va_sc_select_va_button($atts) {
    $atts = shortcode_atts(array('post_id' => 0, 'show_text' => 'Select this VA'), $atts, 'select_va_button');
    $post_id = intval($atts['post_id']);
    if (!$post_id) {
        global $post;
        if (!$post) return '';
        $post_id = $post->ID;
    }

    $va_user_id = va_get_va_user_id_from_post($post_id);
    if (!$va_user_id) return '<p>VA not linked to a user account.</p>';

    if (!is_user_logged_in()) {
        return '<p>Please <a href="' . esc_url(wp_login_url(get_permalink($post_id))) . '">log in</a> to select a VA.</p>';
    }
    
    $client_id = get_current_user_id();
    
    // Check if logged-in user is a VA (Ambassador role)
    $current_user = wp_get_current_user();
    
    if (in_array('um_ambassador', $current_user->roles)) {
        return '';
    }
    
    if (in_array('wpseo_manager', $current_user->roles)) {
        return '';
    }
    
    if (in_array('um_applicant', $current_user->roles)) {
        return '';
    }
    
    if (in_array('um_vtm-admin', $current_user->roles)) {
        return '';
    }

    $selected = va_get_selected_vas($client_id);
    $is_selected = in_array($va_user_id, $selected, true);

    // Check if VA is already selected by another client
    $already_selected_by_another = false;
    $current_client = get_post_meta($post_id, '_selected_client_' . $va_user_id, true);
    
    if ($current_client && $current_client != $client_id) {
        $already_selected_by_another = true;
    }

    $select_nonce = wp_create_nonce('va_select_nonce');
    $chat_nonce = wp_create_nonce('va_chat_nonce');

    ob_start();
    
    // If VA is already selected by another client, don't show the button at all
    if ($already_selected_by_another && !$is_selected) {
        // Don't show anything for other clients
        return '<div class="va-selected-status"><span style="color: #666;">This VA is currently unavailable</span></div>';
    }
    
    // If current client has selected this VA, don't show the select button
    if ($is_selected) {
        // Option 1: Show nothing (hidden)
        // return '';
        
        // Option 2: Show a message (optional)
        return '<div class="va-selected-status"><span style="color: green; font-weight: bold;">✓ You have selected this VT</span></div>';
    }
    
    // Only show the button if:
    // 1. VA is not selected by anyone, OR
    // 2. Current client is the one who selected it (but we already handled this above)
    ?>
    <div class="va-select-wrap" data-post-id="<?php echo esc_attr($post_id); ?>" data-va-user="<?php echo esc_attr($va_user_id); ?>">
        <button class="va-select-btn" data-post-id="<?php echo esc_attr($post_id); ?>" data-va-user="<?php echo esc_attr($va_user_id); ?>"><?php echo esc_html($atts['show_text']); ?></button>
        <span class="va-select-feedback" aria-live="polite"></span>
    </div>

<style>
.va-unavailable {
    background-color: #6c757d !important;
    cursor: not-allowed !important;
    opacity: 0.65;
}

.va-select-feedback {
    margin-top: 10px;
    padding: 10px;
    border-radius: 4px;
    font-weight: 500;
}

#va-toast {
    position: fixed;
    bottom: 25px;
    right: 25px;
    background: #323232;
    color: #fff;
    padding: 14px 22px;
    border-radius: 8px;
    font-size: 15px;
    opacity: 0;
    pointer-events: none;
    transform: translateY(20px);
    transition: opacity .3s ease, transform .3s ease;
    z-index: 999999;
}

#va-toast.show {
    opacity: 1;
    transform: translateY(0);
}

.va-selected-status {
    padding: 10px;
    text-align: center;
}
</style>

<div id="va-toast"></div>

<script type="text/javascript">
jQuery(document).ready(function($) {
    var ajaxurl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
    var selectNonce = <?php echo wp_json_encode($select_nonce); ?>;
    var chatNonce = <?php echo wp_json_encode($chat_nonce); ?>;

    function vaToast(message, type) {
        var toast = $("#va-toast");
        if (type === "success") toast.css("background", "#28a745");
        else if (type === "error") toast.css("background", "#dc3545");
        else toast.css("background", "#323232");

        toast.text(message).addClass("show");
        setTimeout(function() {
            toast.removeClass("show");
        }, 3000);
    }

    // SELECT VA
    $(document).on('click', '.va-select-btn', function(e){
        e.preventDefault();
        var post_id = parseInt($(this).data('post-id'), 10);
        var va_user = parseInt($(this).data('va-user'), 10);
        var btn = $(this);
        
        btn.prop('disabled', true);
        $('.va-select-feedback').text('');

        $.post(ajaxurl, { 
            action: 'va_select_va', 
            post_id: post_id, 
            va_user_id: va_user, 
            nonce: selectNonce
        }, function(res){
            if (res.success) {
                // Hide the select button and show a success message
                btn.closest('.va-select-wrap').html('<div class="va-selected-status"><span style="color: green; font-weight: bold;">✓ Successfully selected VA!</span></div>');
                vaToast("Successfully selected VA!", "success");
            } else {
                btn.prop('disabled', false);
                var errorMsg = res.data.message || 'Error selecting VA';
                vaToast(errorMsg, "error");
                
                if (errorMsg.indexOf('already been selected') !== -1) {
                    // Hide the button and show unavailable message
                    btn.closest('.va-select-wrap').html('<div class="va-selected-status"><span style="color: #666;">This VA is currently unavailable</span></div>');
                }
            }
        }, 'json').fail(function(){ 
            btn.prop('disabled', false);
            var failMsg = 'Request failed. Please try again.';
            vaToast(failMsg, "error");
        });
    });

    // MESSAGE VA
    $(document).on('click', '.va-message-btn', function(e){
        e.preventDefault();
        var va_user = parseInt($(this).data('va-user'), 10); 
        
        if (!va_user) {
            vaToast("Please select a VA before messaging.", "error");
            return;
        }
        
        $.post(ajaxurl, { 
            action:'va_create_private_conversation', 
            va_user_id: va_user, 
            nonce: chatNonce 
        }, function(res){
            if (res.success) {
                var url = window.location.href.split('?')[0] + '?conv=' + res.data.conversation_id;
                window.location = url;
            } else {
                vaToast(res.data || 'Could not create conversation', "error");
            }
        }, 'json').fail(function() {
            vaToast('Request failed', "error"); 
        });
    });
});
</script>
    <?php
    return ob_get_clean();
}

/**
 * Updated selected_va_list shortcode - Shows ALL selected VAs with small status above name
 */
add_shortcode('selected_va_list', 'va_sc_selected_va_list');
function va_sc_selected_va_list($atts) {
    if (!is_user_logged_in()) return '<p>Please login to view selected VTs.</p>';
    
    $client = get_current_user_id();
    $selected = va_get_selected_vas($client);
    
    ob_start();
    ?>
    <style>
    .selected-vas {
        max-width: 1400px;
        margin: 0 auto;
        padding: 20px;
    }
    
    .selected-vas h3 {
        font-size: 28px;
        font-weight: 600;
        margin-bottom: 30px;
        color: #333;
    }
    
    .va-cards-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
        gap: 25px;
        padding: 0;
        list-style: none;
    }
    
    .va-card {
        background: white;
        border-radius: 16px;
        padding: 30px 25px;
        box-shadow: 0 2px 12px rgba(0,0,0,0.08);
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        position: relative;
        text-align: left;
    }
    
    .va-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 24px rgba(0,0,0,0.12);
    }
    
    .va-card-header {
        display: flex;
        align-items: flex-start;
        gap: 15px;
        margin-bottom: 20px;
        text-decoration: none;
    }
    
    .va-card-left {
        flex: 1;
        min-width: 0;
    }
    
    .status-label {
        display: inline-block;
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 2px 8px;
        border-radius: 10px;
        margin-bottom: 8px;
    }
    
    .status-approved {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }
    
    .status-pending {
        background: #fff3cd;
        color: #856404;
        border: 1px solid #ffeaa7;
    }
    
    .status-declined {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }
    
    .va-avatar {
        width: 80px;
        height: 80px;
        border-radius: 50% !important;
        object-fit: cover;
        border: 3px solid #e8e8ff;
        flex-shrink: 0;
    }
    
    .va-name {
        font-size: 22px;
        font-weight: 600;
        color: #6366f1;
		margin-top: 5px;
        margin-bottom: 5px;
        font-family: "Breathing", Sans-serif;
        line-height: 1.2;
    }
    
    .va-department {
        font-size: 15px;
        font-style: italic;
        color: #333;
        margin-bottom: 8px;
        font-weight: 500;
    }
    
    .va-country {
        font-size: 13px;
        color: #666;
        margin-bottom: 0;
    }
    
    .va-badges {
        display: flex;
        flex-direction: column;
        gap: 10px;
        margin-bottom: 20px;
    }
    
    .badge-row {
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 14px;
        color: #555;
    }
    
    .badge-icon {
        width: 24px;
        height: 24px;
        object-fit: contain;
        flex-shrink: 0;
    }
    
    .badge-text {
        flex: 1;
        font-weight: 500;
        line-height: 1.3;
    }
    
    .va-skills {
        margin-top: 20px;
        padding-top: 15px;
        border-top: 1px solid #e5e7eb;
    }
    
    .va-skills h4 {
        font-size: 15px;
        font-weight: 700;
        margin-bottom: 8px;
        color: #333;
    }
    
    .va-skills-text {
        font-size: 13px;
        color: #666;
        line-height: 1.5;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
        text-overflow: ellipsis;
        max-height: calc(1.5em * 2);
    }
    
    .view-profile-btn {
        display: block;
        width: 100%;
        padding: 12px;
        background: #5b21b6;
        color: white;
        text-align: center;
        border-radius: 25px;
        text-decoration: none;
        font-weight: 600;
        font-size: 15px;
        transition: background 0.3s ease;
        margin-top: 20px;
    }
    
    .view-profile-btn:hover {
        background: #4c1d95;
        color: white;
    }
    
    .no-vas-message {
        text-align: center;
        padding: 60px 20px;
        color: #666;
        font-size: 16px;
    }
    
    @media (max-width: 768px) {
        .va-cards-grid {
            grid-template-columns: 1fr;
        }
        
        .va-card-header {
            flex-direction: column;
            align-items: center;
            text-align: center;
        }
        
        .va-avatar {
            margin-bottom: 15px;
        }
        
        .status-label {
            align-self: center;
        }
    }
    </style>

    <div class="selected-vas" aria-live="polite">
        <h3>Your Selected VTs</h3>
        
        <?php if (empty($selected)): ?>
            <div class="no-vas-message">
                <p>You have not selected any VTs yet.</p>
            </div>
        <?php else: ?>
            <ul class="va-cards-grid">
            <?php foreach ($selected as $uid):
                $u = get_userdata($uid);
                if (!$u) continue;

                // Get manager approval status
                $manager_status = get_user_meta($client, '_manager_approval_' . $uid, true);
                $status_class = '';
                $status_text = '';
                
                switch($manager_status) {
                    case 'approved':
                        $status_class = 'status-approved';
                        $status_text = 'Approved';
                        break;
                    case 'declined':
                        $status_class = 'status-declined';
                        $status_text = 'Declined';
                        break;
                    default:
                        $status_class = 'status-pending';
                        $status_text = 'Pending Approval';
                }

                // Get profile picture from the VA's post
                $avatar_url = '';
                $department = '';
                $va_name = '';
                $summary = '';
                
                $all_va_posts = get_posts(array(
                    'post_type' => 'vt-list-by-category',
                    'posts_per_page' => -1,
                    'post_status' => 'publish'
                ));
                
                $va_post_id = null;
                
                foreach ($all_va_posts as $post) {
                    $linked_user = get_field('link_users', $post->ID);
                    
                    $linked_user_id = 0;
                    if (is_numeric($linked_user)) {
                        $linked_user_id = intval($linked_user);
                    } elseif (is_array($linked_user) && isset($linked_user['ID'])) {
                        $linked_user_id = intval($linked_user['ID']);
                    } elseif (is_object($linked_user) && isset($linked_user->ID)) {
                        $linked_user_id = intval($linked_user->ID);
                    }
                    
                    if ($linked_user_id === intval($uid)) {
                        $va_post_id = $post->ID;
                        break;
                    }
                }
                
                if ($va_post_id) {
                    $profile_pic = get_field('profile_picture', $va_post_id);
                    
                    if (!empty($profile_pic)) {
                        if (is_array($profile_pic) && isset($profile_pic['url'])) {
                            $avatar_url = $profile_pic['url'];
                        } elseif (is_string($profile_pic) && !empty($profile_pic)) {
                            $avatar_url = $profile_pic;
                        } elseif (is_numeric($profile_pic)) {
                            $avatar_url = wp_get_attachment_url($profile_pic);
                        }
                    }
                    
                    $va_name = get_field('name', $va_post_id);
                    $department = get_field('department', $va_post_id);
                    $summary = get_field('summary', $va_post_id);
                    
                    $badge_1 = get_field('upload_badge_1', $va_post_id);
                    $badge_1_text = get_field('input_technical_skills_1', $va_post_id);
                    
                    $badge_2 = get_field('upload_bagde_2', $va_post_id);
                    $badge_2_text = get_field('input_technical_skills_2', $va_post_id);
                    
                    $badge_3 = get_field('upload_badge_3', $va_post_id);
                    $badge_3_text = get_field('input_technical_skills_3', $va_post_id);
                    
                    $badge_4 = get_field('upload_badge_4', $va_post_id);
                    $badge_4_text = get_field('input_technical_skills_4', $va_post_id);
                    
                    $badge_5 = get_field('upload_badge_5', $va_post_id);
                    $badge_5_text = get_field('input_technical_skills_5', $va_post_id);
                }
                
                if (empty($avatar_url)) {
                    $avatar_url = get_avatar_url($uid, array('size' => 200));
                }
                
                $display_name = $va_name ?: ($u->display_name ?: $u->user_login);
            ?>
            <li class="va-card" data-va="<?php echo esc_attr($uid); ?>">
                <div class="va-card-header">
                    <div class="va-card-left">
                        <?php if ($status_text): ?>
                            <div class="status-label <?php echo $status_class; ?>">
                                <?php echo $status_text; ?>
                            </div>
                        <?php endif; ?>
                        <div class="va-name"><?php echo esc_html($display_name); ?></div>
                        <?php if ($department): ?>
                            <div class="va-department"><?php echo esc_html($department); ?></div>
                        <?php endif; ?>
                    </div>
                    <img src="<?php echo esc_url($avatar_url); ?>" 
                         alt="<?php echo esc_attr($display_name); ?>" 
                         class="va-avatar"
                         onerror="this.src='<?php echo esc_url(get_avatar_url($uid, array('size' => 200))); ?>'">
                </div>
                
                <!-- Badges section -->
                <div class="va-badges">
                    <?php if ($badge_1): ?>
                        <div class="badge-row">
                            <?php 
                            $badge_url = '';
                            if (is_array($badge_1) && isset($badge_1['url'])) {
                                $badge_url = $badge_1['url'];
                            } elseif (is_numeric($badge_1)) {
                                $badge_url = wp_get_attachment_url($badge_1);
                            }
                            if ($badge_url): ?>
                                <img src="<?php echo esc_url($badge_url); ?>" alt="Badge" class="badge-icon">
                            <?php endif; ?>
                            <span class="badge-text"><?php echo esc_html($badge_1_text ?: 'Technical Skill'); ?></span>
                        </div>
                    <?php endif; ?>

                    <!-- Badge 2 (EF Badge) -->
                    <?php if ($badge_2): ?>
                        <div class="badge-row">
                            <?php 
                            $badge_url = '';
                            if (is_array($badge_2) && isset($badge_2['url'])) {
                                $badge_url = $badge_2['url'];
                            } elseif (is_numeric($badge_2)) {
                                $badge_url = wp_get_attachment_url($badge_2);
                            }
                            
                            if ($badge_url): ?>
                                <img src="<?php echo esc_url($badge_url); ?>" alt="EF Badge" class="badge-icon">
                            <?php endif; ?>
                            <span class="badge-text">
                                <?php 
                                // Handle array format for badge text
                                if (is_array($badge_2_text)) {
                                    echo esc_html(implode(', ', $badge_2_text));
                                } else {
                                    echo esc_html($badge_2_text ?: 'English Proficiency');
                                }
                                ?>
                            </span>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Badge 3 (Predictive Index) -->
                    <?php if ($badge_3): ?>
                        <div class="badge-row">
                            <?php 
                            $badge_url = '';
                            if (is_array($badge_3) && isset($badge_3['url'])) {
                                $badge_url = $badge_3['url'];
                            } elseif (is_numeric($badge_3)) {
                                $badge_url = wp_get_attachment_url($badge_3);
                            }
                            
                            if ($badge_url): ?>
                                <img src="<?php echo esc_url($badge_url); ?>" alt="Predictive Index" class="badge-icon">
                            <?php endif; ?>
                            <span class="badge-text">
                                <?php 
                                if (is_array($badge_3_text)) {
                                    echo esc_html(implode(', ', $badge_3_text));
                                } else {
                                    echo esc_html($badge_3_text ?: 'Predictive Index');
                                }
                                ?>
                            </span>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Badge 4 (AT Badge) -->
                    <?php if ($badge_4): ?>
                        <div class="badge-row">
                            <?php 
                            $badge_url = '';
                            if (is_array($badge_4) && isset($badge_4['url'])) {
                                $badge_url = $badge_4['url'];
                            } elseif (is_numeric($badge_4)) {
                                $badge_url = wp_get_attachment_url($badge_4);
                            }
                            
                            if ($badge_url): ?>
                                <img src="<?php echo esc_url($badge_url); ?>" alt="AT Badge" class="badge-icon">
                            <?php endif; ?>
                            <span class="badge-text">
                                <?php 
                                if (is_array($badge_4_text)) {
                                    echo esc_html(implode(', ', $badge_4_text));
                                } else {
                                    echo esc_html($badge_4_text ?: 'Assessment');
                                }
                                ?>
                            </span>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Badge 5 (IQ Badge) -->
                    <?php if ($badge_5): ?>
                        <div class="badge-row">
                            <?php 
                            $badge_url = '';
                            if (is_array($badge_5) && isset($badge_5['url'])) {
                                $badge_url = $badge_5['url'];
                            } elseif (is_numeric($badge_5)) {
                                $badge_url = wp_get_attachment_url($badge_5);
                            }
                            
                            if ($badge_url): ?>
                                <img src="<?php echo esc_url($badge_url); ?>" alt="IQ Badge" class="badge-icon">
                            <?php endif; ?>
                            <span class="badge-text">
                                <?php 
                                if (is_array($badge_5_text)) {
                                    echo esc_html(implode(', ', $badge_5_text));
                                } else {
                                    echo esc_html($badge_5_text ?: 'IQ Assessment');
                                }
                                ?>
                            </span>
                        </div>
                    <?php endif; ?>
                </div>
                
                <?php if ($summary): ?>
                    <div class="va-skills">
                        <h4>Skills</h4>
                        <div class="va-skills-text"><?php echo esc_html($summary); ?></div>
                    </div>
                <?php endif; ?>
                
                <?php if ($va_post_id): ?>
                <a href="<?php echo esc_url(get_permalink($va_post_id)); ?>" class="view-profile-btn">
                    View Profile
                </a>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

/* ========== AJAX HANDLER FOR GETTING CLIENT'S VAs ========== */
add_action('wp_ajax_va_get_client_vas', 'va_ajax_get_client_vas');
function va_ajax_get_client_vas() {
    check_ajax_referer('va_chat_nonce', 'nonce');
    
    if (!is_user_logged_in() || !va_is_manager(get_current_user_id())) {
        wp_send_json_error('not_authorized');
    }
    
    $client_id = isset($_POST['client_id']) ? intval($_POST['client_id']) : 0;
    
    if (!$client_id) {
        wp_send_json_error('invalid_client');
    }
    
    $selected_vas = va_get_selected_vas($client_id);
    $vas_data = array();
    
    foreach ($selected_vas as $va_id) {
        $va_user = get_userdata($va_id);
        if ($va_user) {
            $vas_data[] = array(
                'id' => $va_id,
                'name' => $va_user->display_name ?: $va_user->user_login
            );
        }
    }
    
    wp_send_json_success(array('vas' => $vas_data));
}

/* ========== AJAX HANDLER FOR MANAGER GROUP CREATION ========== */
add_action('wp_ajax_va_manager_create_group', 'va_ajax_manager_create_group');
function va_ajax_manager_create_group() {
    check_ajax_referer('va_chat_nonce', 'nonce');
    
    if (!is_user_logged_in() || !va_is_manager(get_current_user_id())) {
        wp_send_json_error('not_authorized');
    }
    
    $manager_id = get_current_user_id();
    $client_id = isset($_POST['client_id']) ? intval($_POST['client_id']) : 0;
    $va_ids = isset($_POST['va_ids']) ? $_POST['va_ids'] : array();
    $group_name = isset($_POST['group_name']) ? sanitize_text_field($_POST['group_name']) : '';
    
    if (!$client_id || !get_userdata($client_id)) {
        wp_send_json_error(array('message' => 'Invalid client'));
    }
    
    if (!is_array($va_ids) || empty($va_ids)) {
        wp_send_json_error(array('message' => 'No VAs selected'));
    }
    
    $va_ids = array_map('intval', $va_ids);
    
    // Create the group with manager and client only (VAs will be added after acceptance)
    $initial_participants = array($manager_id, $client_id);
    
    if (empty($group_name)) {
        $client_user = get_userdata($client_id);
        $group_name = 'Manager Group: ' . ($client_user->display_name ?: 'Client');
    }
    
    $conv_id = wp_insert_post(array(
        'post_type' => 'va_conversation',
        'post_title' => sanitize_text_field($group_name),
        'post_status' => 'publish'
    ));
    
    if ($conv_id && !is_wp_error($conv_id)) {
        update_post_meta($conv_id, 'participants', $initial_participants);
        update_post_meta($conv_id, 'is_group', 1);
        update_post_meta($conv_id, 'pending_vas', $va_ids); // Store pending VAs
        
        // Store conversation for manager and client
        va_store_user_conversation($manager_id, $conv_id);
        va_store_user_conversation($client_id, $conv_id);
        
        // Send welcome message from manager
        if (function_exists('wp_create_user_message')) {
            $manager_user = get_userdata($manager_id);
            $manager_name = $manager_user->display_name ?: 'Manager';
            $welcome_msg = "👋 Welcome! This is a manager group chat. VAs have been invited and will join once they accept.";
            wp_create_user_message($conv_id, $manager_id, $welcome_msg);
        }
        
        // Notify client
        va_add_notification($client_id, "Manager created a group chat with you!", array('conversation_id' => $conv_id));
        
        // Send email to client
        $client_user = get_userdata($client_id);
        $manager_user = get_userdata($manager_id);
        $manager_name = $manager_user->display_name ?: 'Your manager';

        $client_email_subject = 'New Manager Group Chat Created';
        $client_email_body = "<html><body style='font-family: Arial, sans-serif; line-height: 1.6;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px; background: #f9f9f9; border-radius: 10px;'>
                <h2 style='color: #2271b1;'>👥 New Group Chat Created</h2>
                
                <div style='background: white; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                    <p>Hi " . esc_html($client_user->display_name) . ",</p>
                    <p><strong>" . esc_html($manager_name) . "</strong> has created a group chat with you and your VAs.</p>
                    
                    <div style='background: #e8f4f8; padding: 15px; border-left: 4px solid #2271b1; margin: 20px 0;'>
                        <strong>📋 Group Chat Details:</strong><br><br>
                        <strong>Group Name:</strong> " . esc_html($group_name) . "<br>
                        <strong>Created by:</strong> " . esc_html($manager_name) . "<br>
                        <strong>VAs Invited:</strong> " . count($va_ids) . " team members
                    </div>
                    
                    <p style='margin-top: 30px;'>
                        <a href='" . esc_url(add_query_arg('redirect_to', urlencode(home_url('/client-chat/?conv=' . $conv_id)), 'https://clientvtm.wpenginepowered.com/client-login/')) . "' 
                           style='background: #2271b1; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; display: inline-block;'>
                            Login to View Group Chat
                        </a>
                    </p>
                </div>
                
                <p style='color: #666; font-size: 12px; margin-top: 20px;'>
                    — " . esc_html(get_bloginfo('name')) . "<br>
                    This group chat was created by your manager.
                </p>
            </div>
        </body></html>";

        $headers = array('Content-Type: text/html; charset=UTF-8');
        wp_mail($client_user->user_email, $client_email_subject, $client_email_body, $headers);
        
        // Send invitations to VAs
        foreach ($va_ids as $va_id) {
            if (!get_userdata($va_id)) continue;
            
            // Create invitation status
            update_post_meta($conv_id, '_va_group_status_' . $va_id, 'pending');
            
            // Notify VA
            va_add_notification($va_id, "You've been invited to a manager group chat! Check your invitations to accept.", array('group_conversation_id' => $conv_id));
            
            // Send email to VA
            $va_user = get_userdata($va_id);
            $subject = 'Manager Group Chat Invitation';
            $body = va_build_group_invitation_email($manager_id, $client_id, $conv_id, $va_id);
            va_send_email_to_user($va_id, $subject, $body);
        }
        
        wp_send_json_success(array('conversation_id' => $conv_id));
    }
    
    wp_send_json_error(array('message' => 'Failed to create group'));
}

/**
 * Build group invitation email
 */
function va_build_group_invitation_email($manager_id, $client_id, $conv_id, $va_user_id) {
    $manager = get_userdata($manager_id);
    $client = get_userdata($client_id);
    $site = get_bloginfo('name');
    
    $manager_name = $manager ? ($manager->display_name ?: $manager->user_login) : 'Your manager';
    $client_name = $client ? ($client->display_name ?: $client->user_login) : 'a client';
    
    // Create acceptance link
    $accept_url = add_query_arg(array(
        'va_action' => 'accept_group',
        'conv_id' => $conv_id,
        'nonce' => wp_create_nonce('va_group_accept_' . $conv_id . '_' . $va_user_id)
    ), home_url('/va-invitations/'));
    
    $decline_url = add_query_arg(array(
        'va_action' => 'decline_group',
        'conv_id' => $conv_id,
        'nonce' => wp_create_nonce('va_group_accept_' . $conv_id . '_' . $va_user_id)
    ), home_url('/va-invitations/'));
    
    $body = "<html><body style='font-family: Arial, sans-serif; line-height: 1.6;'>
        <div style='max-width: 600px; margin: 0 auto; padding: 20px; background: #f9f9f9; border-radius: 10px;'>
            <h2 style='color: #2271b1;'>Manager Group Chat Invitation</h2>
            
            <div style='background: white; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                <p>Hi,</p>
                <p><strong>" . esc_html($manager_name) . "</strong> has invited you to join a group chat with <strong>" . esc_html($client_name) . "</strong> on <strong>" . esc_html($site) . "</strong>.</p>
                
                <div style='background: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; margin: 20px 0;'>
                    <strong>⚠️ Action Required:</strong><br>
                    Please review this invitation and accept or decline.
                </div>
                
                <div style='margin: 30px 0; text-align: center;'>
                    <a href='" . esc_url($accept_url) . "' 
                       style='background: #28a745; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block; margin-right: 10px;'>
                        ✓ Accept Invitation
                    </a>
                    <a href='" . esc_url($decline_url) . "' 
                       style='background: #dc3545; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block;'>
                        ✗ Decline Invitation
                    </a>
                </div>
                
                <p style='font-size: 12px; color: #666;'>
                    Or login to your dashboard to manage invitations manually.
                </p>
            </div>
            
            <p style='color: #666; font-size: 12px; margin-top: 20px;'>
                — " . esc_html($site) . "
            </p>
        </div>
    </body></html>";
    
    return $body;
}