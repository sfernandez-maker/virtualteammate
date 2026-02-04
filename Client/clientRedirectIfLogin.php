add_action('template_redirect', function () {

    if (strpos($_SERVER['REQUEST_URI'], '/talent-pool/') !== false) {

        // If logged in → send to talents list
        if (is_user_logged_in()) {
            wp_redirect('https://virtualteammate.com/talents-list/');
            exit;
        }

        // Not logged in → stay on talent pool
        return;
    }
});
