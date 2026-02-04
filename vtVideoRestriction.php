/**
 * Restrict Video for VAs on VA profile pages
 */
add_filter('do_shortcode_tag', 'restrict_presto_player_for_vas', 10, 3);
function restrict_presto_player_for_vas($output, $tag, $attr) {
    if ($tag !== 'presto_player') {
        return $output;
    }
    
    if (!is_singular('vt-list-by-category') || !is_user_logged_in()) {
        return $output;
    }
    
    $current_user = wp_get_current_user();
    if (!$current_user || !isset($current_user->roles)) {
        return $output;
    }
    
    $blocked_roles = array('um_ambassador', 'um_applicant');
    
    foreach ($blocked_roles as $role) {
        if (in_array($role, $current_user->roles)) {
            return '<div style="padding: 20px; background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 5px; margin: 20px 0;">
                <p style="margin: 0 0 10px 0;"><strong>⚠️ Video Content Restricted</strong></p>
                <p style="margin: 0;">This video is only available to clients.</p>
            </div>';
        }
    }
    
    return $output;
}

/**
 * Restrict Resume for VAs on VA profile pages - ENHANCED VERSION
 */
add_action('wp_footer', 'hide_resume_for_vas', 999);
function hide_resume_for_vas() {
    if (!is_singular('vt-list-by-category') || !is_user_logged_in()) {
        return;
    }
    
    $current_user = wp_get_current_user();
    $blocked_roles = array('um_ambassador', 'um_applicant');
    
    foreach ($blocked_roles as $role) {
        if (in_array($role, $current_user->roles)) {
            ?>
            <script>
            jQuery(document).ready(function($) {
                // Run multiple times to catch dynamically loaded content
                function hideResumeContent() {
                    // Remove ACF resume field
                    $('[data-name="upload_resume"]').remove();
                    $('.acf-field-upload-resume').remove();
                    
                    // Hide PDF iframes (embedded PDF viewers)
                    $('iframe').filter(function() {
                        var src = $(this).attr('src') || '';
                        return src.toLowerCase().indexOf('.pdf') !== -1;
                    }).replaceWith(
                        '<div style="padding: 30px; background: #f8f9fa; border: 2px dashed #dee2e6; border-radius: 8px; color: #666; margin: 20px 0; text-align: center;">' +
                        '<svg style="width: 48px; height: 48px; margin-bottom: 15px; opacity: 0.4;" fill="currentColor" viewBox="0 0 20 20">' +
                        '<path d="M9 2a2 2 0 00-2 2v8a2 2 0 002 2h6a2 2 0 002-2V6.414A2 2 0 0016.414 5L14 2.586A2 2 0 0012.586 2H9z"></path>' +
                        '<path d="M3 8a2 2 0 012-2v10h8a2 2 0 01-2 2H5a2 2 0 01-2-2V8z"></path>' +
                        '</svg>' +
                        '<h3 style="margin: 0 0 10px 0; font-size: 18px; color: #495057;">📄 Resume Document</h3>' +
                        '<p style="margin: 0; font-size: 14px;">Resume access is restricted for Virtual Teammate</p>' +
                        '</div>'
                    );
                    
                    // Hide PDF embed tags
                    $('embed[src*=".pdf"], object[data*=".pdf"]').replaceWith(
                        '<div style="padding: 30px; background: #f8f9fa; border: 2px dashed #dee2e6; border-radius: 8px; color: #666; margin: 20px 0; text-align: center;">' +
                        '<svg style="width: 48px; height: 48px; margin-bottom: 15px; opacity: 0.4;" fill="currentColor" viewBox="0 0 20 20">' +
                        '<path d="M9 2a2 2 0 00-2 2v8a2 2 0 002 2h6a2 2 0 002-2V6.414A2 2 0 0016.414 5L14 2.586A2 2 0 0012.586 2H9z"></path>' +
                        '<path d="M3 8a2 2 0 012-2v10h8a2 2 0 01-2 2H5a2 2 0 01-2-2V8z"></path>' +
                        '</svg>' +
                        '<h3 style="margin: 0 0 10px 0; font-size: 18px; color: #495057;">📄 Resume Document</h3>' +
                        '<p style="margin: 0; font-size: 14px;">Resume access is restricted for Virtual Teammate</p>' +
                        '</div>'
                    );
                    
                    // Replace all PDF links
                    $('a[href$=".pdf"]').replaceWith(
                        '<div style="padding: 15px; background: #f8f9fa; border-left: 4px solid #6c757d; border-radius: 5px; color: #666; margin: 10px 0; text-align: center;">' +
                        '<strong>📄 Resume Download</strong><br>' +
                        '<small>Resume access is restricted for Virtual Teammate</small>' +
                        '</div>'
                    );
                }
                
                // Run immediately
                hideResumeContent();
                
                // Run again after 300ms (catch lazy-loaded content)
                setTimeout(hideResumeContent, 300);
                
                // Run again after 1 second (catch very slow content)
                setTimeout(hideResumeContent, 1000);
            });
            </script>
            
            <style>
            /* Hide resume elements with CSS as backup */
            [data-name="upload_resume"],
            .acf-field-upload-resume,
            a[href$=".pdf"],
            iframe[src*=".pdf"],
            embed[src*=".pdf"],
            object[data*=".pdf"] {
                display: none !important;
                visibility: hidden !important;
                opacity: 0 !important;
                height: 0 !important;
                overflow: hidden !important;
            }
            </style>
            <?php
            return;
        }
    }
}