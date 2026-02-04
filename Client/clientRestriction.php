add_filter('the_content', function ($content) {

    // Pages to restrict
    $restricted_pages = ['vt-page', 'careers'];

    // Only target selected pages
    if ( ! is_page($restricted_pages) ) {
        return $content;
    }

    // If user is not logged in, allow normal content
    if ( ! is_user_logged_in() ) {
        return $content;
    }

    $user = wp_get_current_user();

    // If logged-in user is a subscriber (client)
    if ( in_array('subscriber', (array) $user->roles, true) ) {

        return '
        <div style="
            min-height:80vh;
            display:flex;
            align-items:center;
            justify-content:center;
            text-align:center;
        ">
            <div style="
                max-width:500px;
                padding:70px;
                border:1px solid #e5e5e5;
                border-radius:8px;
                background:#fff;
                box-shadow: rgba(0, 0, 0, 0.35) 0px 5px 15px;
            ">
                <h1>Access Restricted</h1>
                <p>This page is not available for client accounts.</p>

                <a href="https://virtualteammate.com"
                   style="
                       display:inline-block;
                       margin-top:20px;
                       padding:12px 24px;
                       background:#0073aa;
                       color:#fff;
                       text-decoration:none;
                       border-radius:5px;
                   ">
                    Go back to Dashboard
                </a>
            </div>
        </div>
        ';
    }

    return $content;
});