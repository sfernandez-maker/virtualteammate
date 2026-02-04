/**
 * Plugin: VA Chat Monitor
 * Admin menu, chat management, and message monitoring page.
 */

/* Admin menu */
add_action('admin_menu', function () {
    add_menu_page('VA Chat Monitor', 'VA Chat Monitor', 'manage_options', 'va-chat-monitor', 'va_render_clean_chat_monitor_page', 'dashicons-format-chat', 25);
});

/* Styles */
add_action('admin_head', 'va_admin_chat_styles');
function va_admin_chat_styles() { ?>
    <style>
        /* Minimal admin CSS (same look as earlier) */
        .vt-admin-wrapper {
            max-width: 1200px;
            margin: 30px auto;
            padding: 22px;
            font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial;
            color: #1b2430;
            box-sizing: border-box;
        }
        .vt-admin-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 18px;
        }
        .vt-admin-title {
            font-size: 28px;
            font-weight: 800;
            margin: 0;
        }
        .vt-admin-sub {
            color: #6b7280;
            font-size: 13px;
            margin-top: 4px;
        }
        .vt-stats-row {
            display: flex;
            gap: 14px;
            margin-bottom: 20px;
        }
        .vt-stat-box {
            flex: 1;
            background: #ffffff;
            padding: 18px;
            border-radius: 12px;
            box-shadow: 0 8px 22px rgba(17, 24, 39, 0.04);
            border: 1px solid rgba(15, 23, 42, 0.03);
        }
        .vt-stat-title {
            font-size: 12px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.6px;
        }
        .vt-stat-number {
            font-size: 22px;
            font-weight: 800;
            margin-top: 8px;
            color: #111827;
        }
        .vt-filter-bar {
            display: flex;
            gap: 12px;
            background: #fff;
            padding: 14px;
            border-radius: 12px;
            align-items: center;
            box-shadow: 0 6px 20px rgba(17, 24, 39, 0.03);
            margin-bottom: 18px;
            border: 1px solid rgba(15, 23, 42, 0.03);
        }
        .vt-filter-bar label {
            font-size: 13px;
            color: #374151;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .vt-filter-bar input[type="number"], .vt-filter-bar input[type="text"] {
            padding: 10px 12px;
            border-radius: 8px;
            border: 1px solid #e6e9ef;
            min-width: 180px;
            box-shadow: none;
            outline: none;
        }
        .vt-btn {
            padding: 9px 12px;
            border-radius: 9px;
            font-weight: 700;
            border: 0;
            cursor: pointer;
        }
        .vt-btn-primary {
            background: linear-gradient(90deg, #4E46DC, #5F56E6);
            color: #fff;
        }
        .vt-btn-neutral {
            background: #f3f4f6;
            color: #111827;
            border: 1px solid #e6e9ef;
        }
        .vt-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 12px;
        }
        .vt-table thead th {
            text-align: left;
            padding: 12px 14px;
            color: #6b7280;
            font-size: 13px;
            text-transform: uppercase;
        }
        .vt-table tbody td {
            background: #fff;
            padding: 14px;
            border-radius: 10px;
            vertical-align: top;
            border: 1px solid rgba(15, 23, 42, 0.03);
        }
        .vt-msg-content {
            color: #111827;
            font-size: 14px;
            line-height: 1.45;
        }
        .vt-meta {
            color: #6b7280;
            font-size: 13px;
            margin-top: 6px;
        }
        .vt-actions {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .vt-action-link {
            font-weight: 700;
            color: #4E46DC;
            text-decoration: none;
            padding: 8px 10px;
            border-radius: 8px;
        }
        .vt-action-delete {
            color: #D7263D;
        }
        .vt-open-btn {
            background: #fff;
            border: 1px solid #e6e9ef;
            padding: 8px 12px;
            border-radius: 8px;
        }
    </style>
<?php }

/* Render admin page */
function va_render_clean_chat_monitor_page() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized.');

    $conv   = isset($_GET['conv']) ? intval($_GET['conv']) : 0;
    $user   = isset($_GET['user']) ? intval($_GET['user']) : 0;
    $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
    $paged  = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $per_page = 50;

    // Build meta_query only if filters are present
    $meta_query = array();
    if ($conv) $meta_query[] = array('key' => 'conversation_id', 'value' => $conv, 'compare' => '=');
    if ($user) $meta_query[] = array('key' => 'sender_id', 'value' => $user, 'compare' => '=');

    $args = array(
        'post_type' => 'va_message',
        'posts_per_page' => $per_page,
        'paged' => $paged,
        'orderby' => 'date',
        'order' => 'DESC',
    );
    if ($search !== '') $args['s'] = $search;
    if (!empty($meta_query)) $args['meta_query'] = $meta_query;

    $q = new WP_Query($args);
    $messages = $q->posts;

    $counts = wp_count_posts('va_message');
    $total_messages = is_object($counts) && isset($counts->publish) ? intval($counts->publish) : 0;
    $counts_conv = wp_count_posts('va_conversation');
    $total_conversations = is_object($counts_conv) && isset($counts_conv->publish) ? intval($counts_conv->publish) : 0;

    $unique_senders = get_transient('va_unique_senders_count');
    if ($unique_senders === false) {
        global $wpdb;
        $pm = $wpdb->postmeta;
        $unique_senders = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT pm.meta_value) FROM $pm pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type = %s AND p.post_status = 'publish'",
            'sender_id', 'va_message'
        )));
        set_transient('va_unique_senders_count', $unique_senders, 5 * MINUTE_IN_SECONDS);
    }

    $base_url = esc_url(admin_url('admin.php?page=va-chat-monitor'));
    $query_args = array();
    if ($conv) $query_args['conv'] = $conv;
    if ($user) $query_args['user'] = $user;
    if ($search !== '') $query_args['s'] = $search;

    echo '<div class="vt-admin-wrapper">';
    echo '<div class="vt-admin-header"><div><h1 class="vt-admin-title">VA Chat Monitor</h1><div class="vt-admin-sub">View and manage messages, conversations, and notifications.</div></div><div><button id="vt-refresh-btn" class="vt-btn vt-btn-primary">Refresh</button></div></div>';

    echo '<div class="vt-stats-row">';
    echo '<div class="vt-stat-box"><div class="vt-stat-title">Total Messages</div><div class="vt-stat-number">' . intval($total_messages) . '</div></div>';
    echo '<div class="vt-stat-box"><div class="vt-stat-title">Total Conversations</div><div class="vt-stat-number">' . intval($total_conversations) . '</div></div>';
    echo '<div class="vt-stat-box"><div class="vt-stat-title">Unique Senders</div><div class="vt-stat-number">' . intval($unique_senders) . '</div></div>';
    echo '<div class="vt-stat-box"><div class="vt-stat-title">Showing (page)</div><div class="vt-stat-number">' . intval(count($messages)) . ' (' . intval($paged) . ')</div></div>';
    echo '</div>';

    echo '<form method="get" class="vt-filter-bar" style="align-items:flex-end;">';
    echo '<input type="hidden" name="page" value="va-chat-monitor">';
    echo '<label>Conversation ID<br><input type="number" name="conv" value="' . esc_attr($conv) . '"></label>';
    echo '<label>Sender ID<br><input type="number" name="user" value="' . esc_attr($user) . '"></label>';
    echo '<label>Search<br><input type="text" name="s" value="' . esc_attr($search) . '" placeholder="Search messages..."></label>';
    echo '<div style="margin-left:auto; display:flex; gap:10px;">';
    echo '<button class="vt-btn vt-btn-primary" type="submit">Filter</button>';
    echo '<a class="vt-btn vt-btn-neutral" href="' . esc_url($base_url) . '">Reset</a>';
    echo '</div></form>';

    echo '<table class="vt-table" aria-describedby="va-chat-monitor-table"><thead><tr>';
    echo '<th>Msg ID</th><th>Conv</th><th>Sender</th><th>Message</th><th>Date</th><th>Actions</th>';
    echo '</tr></thead><tbody>';

    foreach ($messages as $msg) {
        $id = intval($msg->ID);
        $conv_id = esc_html(get_post_meta($id, 'conversation_id', true));
        $sender_id = esc_html(get_post_meta($id, 'sender_id', true));
        $deleted_flag = intval(get_post_meta($id, 'deleted', true));
        $delete_nonce = wp_create_nonce("va_delete_$id");
        $delete_url = add_query_arg(array_merge($query_args, array('action' => 'va_admin_delete_msg', 'id' => $id, '_wpnonce' => $delete_nonce)), admin_url('admin-post.php'));
        $full_content = wp_kses_post($msg->post_content);
        $preview = wp_trim_words(wp_strip_all_tags($full_content), 20);

        echo '<tr>';
        echo '<td>' . intval($id) . '</td>';
        echo '<td>' . esc_html($conv_id) . '</td>';
        echo '<td>User #' . esc_html($sender_id) . '</td>';
        echo '<td>';
        if ($deleted_flag) {
            echo '<em class="vt-msg-content">[deleted]</em>';
        } else {
            echo '<div class="vt-msg-content"><strong>' . esc_html($preview) . '</strong>';
            echo '<details style="margin-top:8px;"><summary>View full message</summary><div style="margin-top:8px;">' . wp_kses_post(wpautop($full_content)) . '</div></details>';
            echo '</div>';
        }
        echo '</td>';
        echo '<td>' . esc_html($msg->post_date) . '</td>';
        echo '<td>';
        echo '<a class="vt-open-btn" href="' . esc_url(admin_url("post.php?post=$id&action=edit")) . '">Open</a> ';
        echo '<a class="vt-action-link vt-action-delete" onclick="return confirm(\'Delete message? This will mark it deleted.\')" href="' . esc_url($delete_url) . '">Delete</a>';
        if ($conv_id) {
            $conv_view = add_query_arg(array_merge($query_args, array('conv' => $conv_id)), esc_url($base_url));
            echo ' <a class="vt-action-link" href="' . esc_url($conv_view) . '">View Conversation</a>';
        }
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';

    $total_pages = max(1, (int)$q->max_num_pages);
    if ($total_pages > 1) {
        echo '<div style="margin-top:18px;">';
        $base_paged_url = add_query_arg($query_args, esc_url($base_url));
        echo '<nav aria-label="VA chat pagination">';
        for ($p = 1; $p <= $total_pages; $p++) {
            $link = add_query_arg('paged', $p, $base_paged_url);
            $class = ($p === $paged) ? 'vt-btn-primary' : 'vt-btn-neutral';
            echo '<a class="vt-btn ' . esc_attr($class) . '" href="' . esc_url($link) . '" style="margin-right:6px;">' . intval($p) . '</a>';
        }
        echo '</nav></div>';
    }

    echo '</div>'; // wrapper

    ?>
    <script>
    document.addEventListener("DOMContentLoaded", function () {
        const btn = document.getElementById("vt-refresh-btn");
        if (btn) btn.addEventListener("click", () => location.reload());
    });
    </script>
<?php }

/* Soft-delete admin handler (preserves audit trail) */
add_action('admin_post_va_admin_delete_msg', function () {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    if (!$id) wp_die('Invalid ID');
    $nonce = isset($_GET['_wpnonce']) ? $_GET['_wpnonce'] : '';
    if (!wp_verify_nonce($nonce, "va_delete_$id")) wp_die('Security check failed');

    update_post_meta($id, 'deleted', 1);
    // Optionally modify the content/title to indicate deletion; keep original content as audit in DB if you prefer.
    wp_update_post(array('ID' => $id, 'post_content' => '[message deleted by admin]', 'post_title' => '[deleted]'));

    $redirect = wp_get_referer();
    if (!$redirect) $redirect = admin_url('admin.php?page=va-chat-monitor');
    wp_safe_redirect($redirect);
    exit;
});

/* Shortcode for admin monitor (optional) */
add_shortcode('admin_chat_monitor', function () {
    if (!current_user_can('manage_options')) return "Access denied.";
    va_admin_chat_styles();
    ob_start();
    va_render_clean_chat_monitor_page();
    return ob_get_clean();
});
