add_action('um_submit_form_errors_hook_login', 'restrict_login_by_role_and_form', 10, 1);
function restrict_login_by_role_and_form( $args ) {

    $form_role_map = [
        39   => 'subscriber',    // Client
        2680 => 'wpseo_manager',   // Manager
        1131 => 'um_ambassador',    // VA
        1636 => 'um_applicant',     // Applicant
    ];

    if ( empty($args['form_id']) || empty($form_role_map[$args['form_id']]) ) {
        return;
    }

    $required_role = $form_role_map[$args['form_id']];

    // 🔥 THIS IS THE KEY FIX
    $user_id = UM()->login()->auth_id;

    if ( ! $user_id ) {
        return; // Let UM handle invalid login
    }

    $user = get_user_by('ID', $user_id);

    if ( ! $user ) {
        return;
    }
	
// 	error_log( print_r( $user->roles, true ) );

    if ( ! in_array($required_role, (array) $user->roles, true ) ) {
        UM()->form()->add_error(
            'username',
            'You are not allowed to log in using this form.'
        );
    }
}