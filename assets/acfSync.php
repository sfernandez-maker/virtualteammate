add_action('acf/save_post', 'sync_user_by_email_to_acf', 20);
function sync_user_by_email_to_acf($post_id) {

    // Run only for your custom post type: vt-list
    if (get_post_type($post_id) !== 'vt-list') {
        return;
    }

    // Read the email from ACF field "enter_email"
    $email = get_field('enter_email', $post_id);

    if (!$email) { 
        return; // No email entered → stop
    }

    // Check if a user already exists with this email
    $user = get_user_by('email', $email);

    // If no user, create one automatically
    if (!$user) {

        $password = wp_generate_password();
        $username = sanitize_user(current(explode('@', $email)));

        $user_id = wp_insert_user([
            'user_login' => $username,
            'user_email' => $email,
            'user_pass'  => $password,
            'role'       => 'ambassador',
        ]);

        if (is_wp_error($user_id)) {
            return; // Stop if creation failed
        }

    } else {
        $user_id = $user->ID; // Use existing user ID
    }

    // Save user to ACF "linked_user" field
    update_field('linked_user', $user_id, $post_id);
}
