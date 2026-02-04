function acf_presto_video_shortcode() {
    // Get the BunnyCDN video URL from the ACF field
    $video_url = get_field('upload_video'); // Replace with your actual ACF field name
    
    // Check if the video URL exists
    if ($video_url) {
        // Embed the Presto Player with the BunnyCDN video URL
        return '<presto-player url="' . esc_url($video_url) . '"></presto-player>';
    } else {
        return 'No video available.'; // Display this text if no video URL is found
    }
}
add_shortcode('acf_presto_video', 'acf_presto_video_shortcode');
