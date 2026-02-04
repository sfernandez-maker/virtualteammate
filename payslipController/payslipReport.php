// Shortcode: [admin_upload_payslip]
add_shortcode('admin_upload_payslip', function () {

    // Only allow administrators or managers
    if (!current_user_can('administrator') && !current_user_can('manager')) {
        return '<p>You do not have permission to upload payslips.</p>';
    }

    // Handle form submission
    if (isset($_POST['aup_submit']) && isset($_POST['aup_user_id'])) {

        $user_id = intval($_POST['aup_user_id']);

        if (!empty($_FILES['aup_file']['name'])) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');

            $uploaded = wp_handle_upload($_FILES['aup_file'], ['test_form' => false]);

            if (!isset($uploaded['error'])) {

                // Add file to WordPress media library
                $attachment = [
                    'post_mime_type' => $uploaded['type'],
                    'post_title'     => sanitize_file_name($_FILES['aup_file']['name']),
                    'post_content'   => '',
                    'post_status'    => 'inherit'
                ];

                $attachment_id = wp_insert_attachment($attachment, $uploaded['file']);
                require_once(ABSPATH . 'wp-admin/includes/image.php');
                wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $uploaded['file']));

                // Save to Ultimate Member meta field
                $meta_key = 'va_payslips'; // <<< change if needed

                $existing = get_user_meta($user_id, $meta_key, true);

                if (!is_array($existing)) {
                    $existing = [];
                }

                $existing[] = $attachment_id;
                update_user_meta($user_id, $meta_key, $existing);

                echo "<p><strong>Payslip uploaded successfully!</strong></p>";
            } else {
                echo "<p>Error: " . $uploaded['error'] . "</p>";
            }
        }
    }

    // Output form
    ob_start();

    $users = get_users([
        'role' => 'va', // <<< Only display VAs
        'orderby' => 'display_name'
    ]);
    ?>

    <form method="post" enctype="multipart/form-data">

        <label><strong>Select VA:</strong></label><br>
        <select name="aup_user_id" required>
            <option value="">-- Choose VA --</option>
            <?php foreach ($users as $u): ?>
                <option value="<?php echo $u->ID; ?>">
                    <?php echo esc_html($u->display_name); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <br><br>

        <label><strong>Upload Payslip:</strong></label><br>
        <input type="file" name="aup_file" required>

        <br><br>

        <button type="submit" name="aup_submit">Upload Payslip</button>

    </form>

    <?php

    return ob_get_clean();
});

// Shortcode: [um_user_files meta="va_payslips" title="My Payslips"]
add_shortcode('um_user_files', function($atts) {

    $a = shortcode_atts([
        'meta' => '',
        'title' => 'Files'
    ], $atts);

    if (!is_user_logged_in()) {
        return '<p>You must be logged in to view this.</p>';
    }

    $user_id = get_current_user_id();
    $files = get_user_meta($user_id, $a['meta'], true);

    $output = "<h3>{$a['title']}</h3>";

    if (!$files) {
        return $output . "<p>No files uploaded yet.</p>";
    }

    if (!is_array($files)) {
        $files = [$files];
    }

    $output .= "<ul>";
    foreach ($files as $file) {
        $file_url = wp_get_attachment_url($file);
        $file_name = basename($file_url);
        $output .= "<li><a href='{$file_url}' target='_blank'>{$file_name}</a></li>";
    }
    $output .= "</ul>";

    return $output;
});
