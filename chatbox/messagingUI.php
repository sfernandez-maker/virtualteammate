/**
 * Part 2 — Conversations & messaging handlers (with access control)
 * Filename: part-2-va-conversations.php
 */

/* ===========
   Helpers
   =========== */

function va_generate_pair_hash($a, $b) {
    $x = array(intval($a), intval($b));
    sort($x);
    return 'pair_' . $x[0] . '_' . $x[1];
}

function va_user_can_access_conversation($user_id, $conv_id) {
    $user_id = intval($user_id); $conv_id = intval($conv_id);
    if (!$user_id || !$conv_id) return false;
    $parts = get_post_meta($conv_id, 'participants', true);
    if (!is_array($parts)) return false;
    $parts = array_map('intval', $parts);
    return in_array($user_id, $parts, true) || current_user_can('manage_options');
}

function va_get_existing_private_conversation($user_a, $user_b) {
    $user_a = intval($user_a); $user_b = intval($user_b);
    if (!$user_a || !$user_b || $user_a === $user_b) return false;
    $pair_hash = va_generate_pair_hash($user_a, $user_b);
    $found = get_posts(array(
        'post_type' => 'va_conversation',
        'meta_key'  => 'pair_hash',
        'meta_value'=> $pair_hash,
        'posts_per_page' => 1,
        'post_status' => 'publish',
    ));
    if (!empty($found)) return intval($found[0]->ID);
    return false;
}

/**
 * Check if user is a manager
 */
function va_is_manager($user_id) {
    $user = get_userdata($user_id);
    if (!$user) return false;
    
    $manager_emails = array(
        'egerona@virtualteammate.com' => 'emer',
        'jorana@virtualteammate.com' => 'jonasorana',
		'ezamora@virtualteammate.com' => 'csm_elderz',
    );
    
    return isset($manager_emails[$user->user_email]);
}

/**
 * Get manager user ID by manager name
 */
function va_get_manager_user_id($manager_name) {
    $manager_emails = array(
        'emer' => 'egerona@virtualteammate.com',
        'jonasorana' => 'jorana@virtualteammate.com',
		'csm_elderz' => 'ezamora@virtualteammate.com',
    );
    
    if (!isset($manager_emails[$manager_name])) return false;
    
    $users = get_users(array('search' => $manager_emails[$manager_name], 'search_columns' => array('user_email')));
    if (empty($users)) return false;
    
    return $users[0]->ID;
}

/* ===========
   Conversations
   =========== */

function va_get_or_create_private_conversation($user_a, $user_b) {
    $user_a = intval($user_a); $user_b = intval($user_b);
    if (!$user_a || !$user_b) return false;
    if ($user_a === $user_b) return false;
    
    // Check if either user is a manager
    $user_a_is_manager = va_is_manager($user_a);
    $user_b_is_manager = va_is_manager($user_b);
    
    // Manager <-> Client: Always allowed
    if ($user_a_is_manager || $user_b_is_manager) {
        if ($existing = va_get_existing_private_conversation($user_a, $user_b)) return intval($existing);
        
        // Create conversation
        $pair_hash = va_generate_pair_hash($user_a, $user_b);
        $title = sprintf('Private conversation: %d_%d', $user_a, $user_b);

        $conv_id = wp_insert_post(array(
            'post_type' => 'va_conversation',
            'post_title' => sanitize_text_field($title),
            'post_name' => sanitize_text_field($pair_hash),
            'post_status' => 'publish'
        ));

        if ($conv_id && !is_wp_error($conv_id)) {
            update_post_meta($conv_id, 'participants', array_map('intval', array_values(array_unique(array($user_a, $user_b)))));
            update_post_meta($conv_id, 'is_group', 0);
            update_post_meta($conv_id, 'pair_hash', $pair_hash);
            va_store_user_conversation($user_a, $conv_id);
            va_store_user_conversation($user_b, $conv_id);
            return intval($conv_id);
        }
        return false;
    }
    
    // Client <-> VA: Check if VA has accepted
    $selected_by_a = va_get_selected_vas($user_a);
    $selected_by_b = va_get_selected_vas($user_b);
    
    // If user_b is selected by user_a, check acceptance
    if (in_array($user_b, $selected_by_a)) {
        if (!va_is_invitation_accepted($user_a, $user_b)) {
            return false; // VA hasn't accepted yet
        }
    }
    
    // If user_a is selected by user_b, check acceptance
    if (in_array($user_a, $selected_by_b)) {
        if (!va_is_invitation_accepted($user_b, $user_a)) {
            return false; // VA hasn't accepted yet
        }
    }
    
    if ($existing = va_get_existing_private_conversation($user_a, $user_b)) return intval($existing);

    $pair_hash = va_generate_pair_hash($user_a, $user_b);
    $title = sprintf('Private conversation: %d_%d', $user_a, $user_b);

    $conv_id = wp_insert_post(array(
        'post_type' => 'va_conversation',
        'post_title' => sanitize_text_field($title),
        'post_name' => sanitize_text_field($pair_hash),
        'post_status' => 'publish'
    ));

    if ($conv_id && !is_wp_error($conv_id)) {
        update_post_meta($conv_id, 'participants', array_map('intval', array_values(array_unique(array($user_a, $user_b)))));
        update_post_meta($conv_id, 'is_group', 0);
        update_post_meta($conv_id, 'pair_hash', $pair_hash);
        va_store_user_conversation($user_a, $conv_id);
        va_store_user_conversation($user_b, $conv_id);

        if ($existing = va_get_existing_private_conversation($user_a, $user_b)) {
            if (intval($existing) !== intval($conv_id)) {
                wp_delete_post($conv_id, true);
                return intval($existing);
            }
        }

        return intval($conv_id);
    }
    return false;
}

function va_create_group_conversation($creator_id, $participant_ids = array(), $name = '') {
    $creator_id = intval($creator_id);
    $participant_ids = array_map('intval', array_values(array_unique(array_filter($participant_ids))));
    if (!$creator_id || empty($participant_ids)) return false;
    if (!in_array($creator_id, $participant_ids, true)) $participant_ids[] = $creator_id;
    sort($participant_ids);
    $title = $name ? sanitize_text_field($name) : 'Group Chat: ' . implode('-', $participant_ids);

    $conv_id = wp_insert_post(array(
        'post_type' => 'va_conversation',
        'post_title' => sanitize_text_field($title),
        'post_status' => 'publish'
    ));
    if ($conv_id && !is_wp_error($conv_id)) {
        update_post_meta($conv_id, 'participants', $participant_ids);
        update_post_meta($conv_id, 'is_group', 1);
        foreach ($participant_ids as $pid) va_store_user_conversation($pid, $conv_id);
    }
    return $conv_id;
}

/* ===========
   Messages
   =========== */

function wp_create_user_message($conversation_id, $sender_id, $content) {
    $conversation_id = intval($conversation_id);
    $sender_id = intval($sender_id);
    $content = trim((string)$content);
    if (!$conversation_id || !$sender_id || $content === '') return false;
    if (!get_post($conversation_id)) return false;
    if (!get_userdata($sender_id)) return false;

    $participants = get_post_meta($conversation_id, 'participants', true);
    if (!is_array($participants) || !in_array($sender_id, array_map('intval', $participants), true)) return false;

    $safe_content = sanitize_textarea_field($content);

    $msg_post = array(
        'post_type' => 'va_message',
        'post_title' => sanitize_text_field(wp_trim_words(wp_strip_all_tags($safe_content), 8, '...')),
        'post_content' => $safe_content,
        'post_status' => 'publish',
    );
    $msg_id = wp_insert_post($msg_post);
    if ($msg_id && !is_wp_error($msg_id)) {
        update_post_meta($msg_id, 'conversation_id', $conversation_id);
        update_post_meta($msg_id, 'sender_id', $sender_id);
        update_post_meta($msg_id, 'sent_time', current_time('mysql'));
        update_post_meta($msg_id, 'deleted', 0);

        if (is_array($participants)) {
            $sender = get_userdata($sender_id);
            $sender_name = $sender ? $sender->display_name : ('User#' . $sender_id);
            foreach ($participants as $p) {
                $p = intval($p);
                if ($p === $sender_id) continue;
                va_add_notification($p, sprintf('New message from %s in conversation #%d', esc_html($sender_name), $conversation_id), array('message_id' => $msg_id, 'conversation_id' => $conversation_id));
            }
        }
    }
    return $msg_id;
}

/* ===========
   AJAX Chat Endpoints
   =========== */

add_action('wp_ajax_va_create_private_conversation', 'va_ajax_create_private_conversation');
function va_ajax_create_private_conversation() {
    check_ajax_referer('va_chat_nonce','nonce');
    if (!is_user_logged_in()) wp_send_json_error('not_logged_in');
    $client = get_current_user_id();
    $va_user_id = isset($_POST['va_user_id']) ? intval($_POST['va_user_id']) : 0;
    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    if (!$va_user_id && $post_id) $va_user_id = va_get_va_user_id_from_post($post_id);
    if (!$va_user_id || !get_userdata($va_user_id)) wp_send_json_error('invalid_va');

    $conv_id = va_get_or_create_private_conversation($client, $va_user_id);
    if ($conv_id) wp_send_json_success(array('conversation_id' => $conv_id));
    wp_send_json_error('cannot_create');
}

add_action('wp_ajax_va_create_group_chat', 'va_ajax_create_group_chat');
function va_ajax_create_group_chat() {
    check_ajax_referer('va_chat_nonce','nonce');
    if (!is_user_logged_in()) wp_send_json_error('not_logged_in');
    $client = get_current_user_id();
    $va_ids = isset($_POST['va_ids']) ? $_POST['va_ids'] : array();
    if (!is_array($va_ids)) $va_ids = array($va_ids);
    $va_ids = array_map('intval', array_values(array_filter($va_ids)));
    if (empty($va_ids)) wp_send_json_error('no_vas');
    
    // Filter to only include accepted VAs
    $accepted_vas = array();
    foreach ($va_ids as $vid) {
        if (!get_userdata($vid)) continue;
        if (va_is_invitation_accepted($client, $vid)) {
            $accepted_vas[] = $vid;
        }
    }
    
    if (empty($accepted_vas)) {
        wp_send_json_error(array('message' => 'No accepted VAs available for group chat'));
    }

    $conv_id = va_create_group_conversation($client, $accepted_vas, 'Group Chat by client ' . $client);
    if ($conv_id) {
        foreach ($accepted_vas as $vid) {
            va_add_notification($vid, sprintf('You were added to a group chat by user #%d', $client), array('conversation_id' => $conv_id));
            va_send_email_to_user($vid, 'Added to group chat', "<p>Hi " . esc_html(get_userdata($vid)->display_name) . ",</p><p>You were added to a group chat by " . esc_html(get_userdata($client)->display_name) . ".</p>");
        }
        wp_send_json_success(array('conversation_id' => $conv_id));
    }
    wp_send_json_error('cannot_create');
}

add_action('wp_ajax_va_send_message', 'va_ajax_send_message');
function va_ajax_send_message() {
    check_ajax_referer('va_chat_nonce','nonce');
    if (!is_user_logged_in()) wp_send_json_error('not_logged_in');
    $sender = get_current_user_id();
    $conv_id = isset($_POST['conversation_id']) ? intval($_POST['conversation_id']) : 0;
    $content = isset($_POST['content']) ? trim($_POST['content']) : '';
    if (!$conv_id || $content === '') wp_send_json_error('invalid_input');

    if (!va_user_can_access_conversation($sender, $conv_id)) wp_send_json_error('not_participant');

    $msg_id = wp_create_user_message($conv_id, $sender, $content);
    if ($msg_id) wp_send_json_success(array('message_id' => $msg_id, 'time' => current_time('mysql')));
    wp_send_json_error('failed');
}

add_action('wp_ajax_va_fetch_messages', 'va_ajax_fetch_messages');
function va_ajax_fetch_messages() {
    check_ajax_referer('va_chat_nonce','nonce');
    if (!is_user_logged_in()) wp_send_json_error('not_logged_in');
    $user = get_current_user_id();
    $conv_id = isset($_POST['conversation_id']) ? intval($_POST['conversation_id']) : 0;
    if (!$conv_id) wp_send_json_error('invalid_conv');
    if (!va_user_can_access_conversation($user, $conv_id)) wp_send_json_error('not_participant');

    $limit = isset($_POST['limit']) ? max(1, min(200, intval($_POST['limit']))) : 50;
    $page  = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
    $offset = ($page - 1) * $limit;
    $since_id = isset($_POST['since_id']) ? intval($_POST['since_id']) : 0;

    $args = array(
        'post_type' => 'va_message',
        'meta_key'  => 'conversation_id',
        'meta_value'=> $conv_id,
        'posts_per_page' => $limit,
        'orderby' => 'ID',
        'order' => 'ASC',
        'post_status' => 'publish',
    );

    if (!$since_id) {
        $args['offset'] = $offset;
    }

    $msgs = get_posts($args);
    $out = array();
    foreach ($msgs as $m) {
        if ($since_id && $m->ID <= $since_id) continue;
        $content = get_post_field('post_content', $m->ID);
        $safe_output = wpautop( esc_html( $content ) );
        $sender_id = intval(get_post_meta($m->ID, 'sender_id', true));
        
        // Get sender name
        $sender_user = get_userdata($sender_id);
        $sender_name = 'Unknown User';
        if ($sender_user) {
            $sender_name = $sender_user->display_name ?: $sender_user->user_login;
            
            // Add role indicator for managers
            if (va_is_manager($sender_id)) {
                $sender_name .= ' (Manager)';
            }
        }
        
        $out[] = array(
            'id' => $m->ID,
            'sender_id' => $sender_id,
            'sender_name' => $sender_name,
            'content' => $safe_output,
            'time' => get_post_meta($m->ID, 'sent_time', true) ?: $m->post_date,
        );
    }
    wp_send_json_success(array('messages' => $out, 'page' => $page, 'limit' => $limit));
}

add_action('wp_ajax_va_user_delete_message', 'va_ajax_user_delete_message');
function va_ajax_user_delete_message() {
    check_ajax_referer('va_chat_nonce','nonce');
    if (!is_user_logged_in()) wp_send_json_error('not_logged_in');
    $user_id = get_current_user_id();
    $msg_id  = isset($_POST['message_id']) ? intval($_POST['message_id']) : 0;
    $conv_id = isset($_POST['conversation_id']) ? intval($_POST['conversation_id']) : 0;
    if (!$msg_id || !$conv_id) wp_send_json_error('invalid_input');

    $msg = get_post($msg_id);
    if (!$msg || $msg->post_type !== 'va_message') wp_send_json_error('invalid_message');

    $msg_conv = intval(get_post_meta($msg_id, 'conversation_id', true));
    if ($msg_conv !== $conv_id) wp_send_json_error('invalid_conversation');

    $sender_id = intval(get_post_meta($msg_id, 'sender_id', true));
    if ($sender_id !== $user_id) wp_send_json_error('not_authorized');

    $parts = get_post_meta($conv_id, 'participants', true);
    if (!is_array($parts) || !in_array($user_id, array_map('intval', $parts), true)) wp_send_json_error('not_participant');

    va_remove_notifications_for_message($msg_id, $parts);

    update_post_meta($msg_id, 'deleted', 1);
    wp_update_post(array(
        'ID' => $msg_id,
        'post_content' => '[message deleted by user]',
        'post_title' => '[deleted]'
    ));

    wp_send_json_success(array('deleted' => $msg_id));
}

add_action('wp_ajax_va_clear_conversation', 'va_ajax_clear_conversation');
function va_ajax_clear_conversation() {
    check_ajax_referer('va_chat_nonce','nonce');
    
    if (!is_user_logged_in()) {
        wp_send_json_error('not_logged_in');
    }
    
    $user_id = get_current_user_id();
    $conv_id = isset($_POST['conversation_id']) ? intval($_POST['conversation_id']) : 0;
    
    if (!$conv_id) {
        wp_send_json_error('invalid_conversation');
    }
    
    // Check if user is a manager
    if (!va_is_manager($user_id)) {
        wp_send_json_error('not_authorized');
    }
    
    // Check if user has access to this conversation
    if (!va_user_can_access_conversation($user_id, $conv_id)) {
        wp_send_json_error('not_participant');
    }
    
    // Get participants before deleting
    $participants = get_post_meta($conv_id, 'participants', true);
    if (!is_array($participants)) {
        $participants = array();
    }
    
    // Get all messages in this conversation and delete them
    $messages = get_posts(array(
        'post_type' => 'va_message',
        'posts_per_page' => -1,
        'meta_key' => 'conversation_id',
        'meta_value' => $conv_id,
        'post_status' => 'publish'
    ));
    
    $deleted_count = 0;
    foreach ($messages as $msg) {
        // Permanently delete messages
        wp_delete_post($msg->ID, true);
        $deleted_count++;
    }
    
    // Remove conversation from all participants' meta
    foreach ($participants as $participant_id) {
        $participant_id = intval($participant_id);
        if (!$participant_id) continue;
        
        $user_convos = get_user_meta($participant_id, 'va_conversations', true);
        if (is_array($user_convos)) {
            $user_convos = array_diff($user_convos, array($conv_id));
            update_user_meta($participant_id, 'va_conversations', array_values($user_convos));
        }
    }
    
    // Delete the conversation post itself
    wp_delete_post($conv_id, true);
    
    wp_send_json_success(array(
        'deleted_count' => $deleted_count,
        'message' => "Conversation completely deleted: {$deleted_count} messages removed"
    ));
}

function va_remove_va_from_conversations($va_user_id) {
    $va_user_id = intval($va_user_id);
    if (!$va_user_id) return;

    $convs = get_posts([
        'post_type' => 'va_conversation',
        'posts_per_page' => -1,
        'meta_query' => [
            [
                'key' => 'participants',
                'value' => $va_user_id,
                'compare' => 'LIKE'
            ]
        ]
    ]);

    foreach ($convs as $conv) {
        $conv_id = $conv->ID;

        $participants = get_post_meta($conv_id, 'participants', true);
        if (is_array($participants)) {
            $participants = array_map('intval', $participants);
            if (($k = array_search($va_user_id, $participants, true)) !== false) {
                unset($participants[$k]);
                update_post_meta($conv_id, 'participants', array_values($participants));
            }
        }

        $msgs = get_posts([
            'post_type' => 'va_message',
            'posts_per_page' => -1,
            'meta_key' => 'conversation_id',
            'meta_value' => $conv_id,
            'meta_query' => [
                [
                    'key' => 'sender_id',
                    'value' => $va_user_id,
                    'compare' => '='
                ]
            ]
        ]);

        foreach ($msgs as $msg) {
            update_post_meta($msg->ID, 'deleted', 1);
            wp_update_post([
                'ID' => $msg->ID,
                'post_content' => '[message deleted]',
                'post_title' => '[deleted]'
            ]);
        }
    }
}

/* ========== GROUP INVITATION ACCEPTANCE/DECLINE ==========

 */

add_action('wp_ajax_va_accept_group_invitation', 'va_ajax_accept_group_invitation');
function va_ajax_accept_group_invitation() {
    check_ajax_referer('va_chat_nonce', 'nonce');
    
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'not_logged_in'));
    }
    
    $va_user_id = get_current_user_id();
    $conv_id = isset($_POST['conv_id']) ? intval($_POST['conv_id']) : 0;
    
    if (!$conv_id || !get_post($conv_id)) {
        wp_send_json_error(array('message' => 'Invalid conversation'));
    }
    
    // Check if VA is pending
    $status = get_post_meta($conv_id, '_va_group_status_' . $va_user_id, true);
    
    if ($status !== 'pending') {
        wp_send_json_error(array('message' => 'You are not invited to this group'));
    }
    
    // Add VA to participants
    $participants = get_post_meta($conv_id, 'participants', true);
    if (!is_array($participants)) $participants = array();
    
    if (!in_array($va_user_id, $participants)) {
        $participants[] = $va_user_id;
        update_post_meta($conv_id, 'participants', $participants);
    }
    
    // Update status
    update_post_meta($conv_id, '_va_group_status_' . $va_user_id, 'accepted');
    
    // Remove from pending list
    $pending_vas = get_post_meta($conv_id, 'pending_vas', true);
    if (is_array($pending_vas)) {
        $pending_vas = array_diff($pending_vas, array($va_user_id));
        update_post_meta($conv_id, 'pending_vas', $pending_vas);
    }
    
    // Store conversation for VA
    va_store_user_conversation($va_user_id, $conv_id);
    
    // Send message to group
    $va_user = get_userdata($va_user_id);
    $va_name = $va_user->display_name ?: $va_user->user_login;
    
    if (function_exists('wp_create_user_message')) {
        wp_create_user_message($conv_id, $va_user_id, "👋 {$va_name} has joined the group!");
    }
    
    // Notify other participants
    foreach ($participants as $p) {
        if ($p != $va_user_id) {
            va_add_notification($p, "{$va_name} joined the manager group chat!", array('conversation_id' => $conv_id));
        }
    }
    
    wp_send_json_success(array('message' => 'Group invitation accepted', 'conv_id' => $conv_id));
}

add_action('wp_ajax_va_decline_group_invitation', 'va_ajax_decline_group_invitation');
function va_ajax_decline_group_invitation() {
    check_ajax_referer('va_chat_nonce', 'nonce');
    
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'not_logged_in'));
    }
    
    $va_user_id = get_current_user_id();
    $conv_id = isset($_POST['conv_id']) ? intval($_POST['conv_id']) : 0;
    
    if (!$conv_id || !get_post($conv_id)) {
        wp_send_json_error(array('message' => 'Invalid conversation'));
    }
    
    // Check if VA is pending
    $status = get_post_meta($conv_id, '_va_group_status_' . $va_user_id, true);
    
    if ($status !== 'pending') {
        wp_send_json_error(array('message' => 'You are not invited to this group'));
    }
    
    // Update status to declined
    update_post_meta($conv_id, '_va_group_status_' . $va_user_id, 'declined');
    
    // Remove from pending list
    $pending_vas = get_post_meta($conv_id, 'pending_vas', true);
    if (is_array($pending_vas)) {
        $pending_vas = array_diff($pending_vas, array($va_user_id));
        update_post_meta($conv_id, 'pending_vas', $pending_vas);
    }
    
    // Notify manager
    $participants = get_post_meta($conv_id, 'participants', true);
    if (is_array($participants)) {
        $va_user = get_userdata($va_user_id);
        $va_name = $va_user->display_name ?: $va_user->user_login;
        
        foreach ($participants as $p) {
            if (va_is_manager($p)) {
                va_add_notification($p, "{$va_name} declined the group chat invitation.", array('conversation_id' => $conv_id));
            }
        }
    }
    
    wp_send_json_success(array('message' => 'Group invitation declined'));
}