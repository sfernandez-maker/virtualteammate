add_filter('body_class', function ($classes) {
    // Not logged in = treat as non-medical (or guest)
    if (!is_user_logged_in()) {
        $classes[] = 'niche-guest';
        $classes[] = 'niche-non_medical';
        return $classes;
    }

    $user_id = get_current_user_id();

    // ACF user field must be read using user_{$id}
    $niche = function_exists('get_field') ? get_field('medical_niche', 'user_' . $user_id) : '';

    // Normalize blanks to non_medical
    if (empty($niche)) {
        $niche = 'non_medical';
    }

    // Add a predictable body class
    $classes[] = 'niche-' . sanitize_html_class($niche);

    return $classes;
});
