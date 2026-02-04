function vt_acf_video_shortcode() {
    $shortcode = get_field('upload_video');
    if (!$shortcode) return '';
    return do_shortcode($shortcode);
}
add_shortcode('vt_acf_video','vt_acf_video_shortcode');
