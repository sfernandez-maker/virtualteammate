/**
 * Part 1 — core setup, utilities, notification + select/remove VA AJAX (FIXED)
 * Filename: part-1-va-core.php
 */

/* ============================
   1. CPTs
   ============================ */
add_action('init', 'va_register_cpt_messages');
function va_register_cpt_messages() {
    register_post_type('va_conversation', array(
        'labels' => array('name' => 'VA Conversations'),
        'public' => false,
        'show_ui' => true,
        'supports' => array('title'),
        'has_archive' => false,
    ));
    register_post_type('va_message', array(
        'labels' => array('name' => 'VA Messages'),
        'public' => false,
        'show_ui' => true,
        'supports' => array('title','editor'),
        'has_archive' => false,
    ));
}

/* ============================
   2. Utilities
   ============================ */
/**
 * Check if a VA has a manager assigned
 * Returns manager user ID if found, 0 if not
 */
function va_get_va_manager($va_user_id) {
    $va_user_id = intval($va_user_id);
    if (!$va_user_id) return 0;
    
    // Get manager from user meta
    $manager_id = get_user_meta($va_user_id, 'va_manager', true);
    
    if ($manager_id && get_userdata($manager_id)) {
        return intval($manager_id);
    }
    
    return 0;
}

/**
 * STATIC MANAGER ASSIGNMENT (for testing)
 * You can replace this with dynamic logic later
 */
function va_get_static_manager($va_user_id) {
    $va_user_id = intval($va_user_id);
    if (!$va_user_id) return false;
    
    // Get the VA user data
    $va_user = get_userdata($va_user_id);
    if (!$va_user) return false;
	
// 	   error_log("DEBUG: Checking manager for user ID: {$va_user_id}");
//     error_log("DEBUG: User login: {$va_user->user_login}");
    error_log("DEBUG: User nicename: {$va_user->user_nicename}");
//     error_log("DEBUG: Display name: {$va_user->display_name}");
    
    // Get the user's nicename (slug) - this is what appears in URLs
    $va_slug = $va_user->user_nicename;
// 	$va_slug = strtolower($va_user->user_nicename);
    
    // Static assignment using SLUGS (from the URL)
    $managers = array(
        'emer' => array(
            'vt_aprilv',		
			'miguel-angelo-a',
			'vt_vincyf',
			'agnes-lipumano',
			'vt_ravenm',
			'vt_aileenc',
			'vt_joarrad',
			'vt_jesamaes',
			'vt_johnpaule',
			'marvieyvonneb',
			'vt_paolo_eljirehb',
			'vt_rachelmaea',
			'vt_johnthomasl',
			'vt_neljohnn',
			'vt_julvincef',
			'vt_rodericko',
			'yuri-c',
			'winieleah',
			'therese',
			'theresag',
			'sterlyn',
			'shiela',
			'shella',
			'shaira',
			'rudolph',
			'roselle',
			'romeo',
			'rodnie',
			'ravenr',
			'ranzelmae',
			'patrizia',
			'patrickjohn',
			'nielsen',
			'nichole',
			'michellea',
			'michael',
			'michaela',
			'mharieliz',
			'melissa',
			'maryhyacinth',
			'marygrace',
			'marvieyvonneb',
			'markdaniel',
			'marionejeremy',
			'marigold',
			'maribel',
			'maeann',
			'lucille',
			'lovely',
			'kisha',
			'keneth',
			'kene',
			'kashif',
			'karina',
			'jullie',
			'juliocesar',
			'judilene',
			'juan',
			'juanb',
			'joshua',
			'jorge',
			'johnrey',
			'johnmartin',
			'joanna',
			'jiroes',
			'jhandave',
			'iron',
			'gracem',
			'giancarlo',
			'gerald',
			'fretzel',
			'fatima',
			'eldie',
			'efhraime',
			'deo',
			'denise',
			'cliffer',
			'clarrise',
			'clariz',
			'christiane',
			'cherish',
			'arielle',
			'aires',
			'alaine',
			'alonikamae',
			'alyssa',
			'angelakim',
			'angelique',
			'anika',
			'neasty',
			'jenny',
			'casey',
			'vt_alainjudel',
			'vt_miraflorm',
			'vt_vienacarlaa',
			'carmela'
        ),
        'jonasorana' => array(
			'vt_francesr',
			'angelo-vincent-n',
			'vt_harroldg',
			'vt_jessavelp ',
			'vt_josephd',
			'piag',
			'adrian-jay-torculas',
			'vt_peterpault',
			'clarisecerine',
			'vt_janmavericg',
			'jessamae',
			'vt_jenardg',
			'vt_johncarlof',
			'markalaind',
			'vt_marieangelicar',
			'vt_markjosephe',
			'vt_ethela',
			'junaline',
			'vt_marlono',
			'vt_carlemilioi',
			'vt_michellep',
			'vt_pocholoa',
			'vt_anatheresan',
			'vt_danialm',
			'vt_czaruhc',
			'vt_jaypeeb',
			'vt_janinepearlt',
			'vt_analeahg',
			'vt_johnluthert',
			'vt_jeffreyd',
			'vt_isheenkaec',
			'vt_kengabriell',
			'vt_ralphreymonf',
			'john-carlo-francia',
			'vt_meicom',
			'vt_markalaind',
			'vt_francism',
			'vt_kimb',
			'vt_elfc',
			'vt_annaloug',
			'winchell',
			'willard',
			'vincecarl',
			'terencejoyce',
			'susiekay',
			'shane',
			'satish',
			'ruselle',
			'ronald',
			'rosemary',
			'ricajane',
			'rachquel',
			'quenie',
			'queenie',
			'piag',
			'paul',
			'nur',
			'nicolyn',
			'nestor',
			'michelleeve',
			'michellec',
			'merryjoy',
			'melyrose',
			'melchor',
			'maryjoy',
			'markjesson',
			'marcylen',
			'marben',
			'manohar',
			'madaniessa',
			'laura',
			'krizz',
			'kristyle',
			'kristaljoy',
			'kris',
			'kit',
			'keith',
			'juriza',
			'vt_ruthannemaried',
			'vt_libertylarov',
			'vt_clarissem',
			'vt_mikhaelaandrind',
			'vt_pepej',
			'vt_tinaa',
			'vt_patriciagywenethk',
			'vt_justinejayc',
			'joshuaa',
			'johnkarl',
			'joenarddale',
			'jerlyn',
			'jeffreye',
			'jaxcine',
			'jasmin',
			'janineruth',
			'janicajean',
			'jane',
			'jamielou',
			'hubert',
			'glaiza',
			'gemspaulo',
			'ethelwolda',
			'elisha',
			'dominiquer',
			'diamam',
			'deborahjane',
			'dane',
			'crisleenmay',
			'cleajade',
			'chrisi',
			'cheyene',
			'chelseaangela',
			'charlotte',
			'byron',
			'benedicto',
			'avram',
			'argelyn',
			'anthony',
			'adrianjay',
			'marghe',
			'marko',
			'jeinzy',
			'zuhair-f',
			'vt_miguela',
			'vt_jenniferd'
        ),
		'csm_elderz' => array(
		
		)
    ); 
// 	error_log("DEBUG: Manager assignments: " . print_r($managers, true));
    
    // Check which manager has this VA
    foreach ($managers as $manager_name => $va_list) {
        if (in_array($va_slug, $va_list)) {
// 			error_log("DEBUG: Found manager '{$manager_name}' for slug '{$va_slug}'");
            return $manager_name;
        }
    }
	
//     error_log("DEBUG: No manager found for slug '{$va_slug}'");
    return false; // No manager found
}


function va_get_va_user_id_from_post($post_id = 0) {
    $post_id = intval($post_id);
    if (!$post_id) {
        global $post;
        if (!$post || !isset($post->ID)) return 0;
        $post_id = $post->ID;
    }

    $acf_field = 'link_users';
    $va_user = 0;

    if (function_exists('get_field')) {
        $val = get_field($acf_field, $post_id);
        if ($val) {
            if (is_numeric($val)) $va_user = intval($val);
            elseif (is_array($val) && isset($val['ID'])) $va_user = intval($val['ID']);
            elseif (is_object($val) && isset($val->ID)) $va_user = intval($val->ID);
            elseif (is_string($val) && is_numeric($val)) $va_user = intval($val);
        }
    }

    if (!$va_user) {
        $meta = get_post_meta($post_id, $acf_field, true);
        if ($meta) {
            if (is_numeric($meta)) $va_user = intval($meta);
            elseif (is_array($meta)) {
                if (isset($meta['ID'])) $va_user = intval($meta['ID']);
                else {
                    foreach ($meta as $v) {
                        if (is_numeric($v)) { $va_user = intval($v); break; }
                        if (is_array($v) && isset($v['ID'])) { $va_user = intval($v['ID']); break; }
                    }
                }
            } elseif (is_object($meta) && isset($meta->ID)) {
                $va_user = intval($meta->ID);
            }
        }
    }

    // fallback to post_author only if still not found
    if (!$va_user) {
        $post_obj = get_post($post_id);
        if ($post_obj && !empty($post_obj->post_author)) $va_user = intval($post_obj->post_author);
    }

    if ($va_user && !get_userdata($va_user)) return 0;
    return $va_user ? $va_user : 0;
}

function va_get_selected_vas($user_id = 0) {
    $user_id = intval($user_id) ?: get_current_user_id();
    $val = get_user_meta($user_id, 'selected_vas', true);
    if (empty($val)) return array();
    if (is_array($val)) return array_map('intval', $val);
    if (is_string($val)) {
        $arr = array_filter(array_map('trim', explode(',', $val)));
        return array_map('intval', $arr);
    }
    return array();
}

function va_add_selected_va($client_id, $va_user_id) {
    $client_id = intval($client_id); $va_user_id = intval($va_user_id);
    if (!$client_id || !$va_user_id) return false;
    if (!get_userdata($va_user_id)) return false;
    $current = va_get_selected_vas($client_id);
    if (!in_array($va_user_id, $current, true)) {
        $current[] = $va_user_id;
        update_user_meta($client_id, 'selected_vas', array_values(array_unique(array_map('intval', $current))));
        return true;
    }
    return false;
}

function va_remove_selected_va($client_id, $va_user_id) {
    $client_id = intval($client_id); $va_user_id = intval($va_user_id);
    if (!$client_id || !$va_user_id) return false;
    $current = va_get_selected_vas($client_id);
    if (($k = array_search($va_user_id, $current, true)) !== false) {
        unset($current[$k]);
        $current = array_values($current);
        update_user_meta($client_id, 'selected_vas', $current);
        return true;
    }
    return false;
}

/**
 * Remove VA from conversations (placeholder - expand as needed)
 */
// function va_remove_va_from_conversations($va_user_id) {
//     // This function can be expanded to clean up conversations
//     // For now, it's a placeholder that returns true
//     return true;
// }

/**
 * Store conversation ID for a user
 */
function va_store_user_conversation($user_id, $conv_id) {
    $convos = get_user_meta($user_id, 'va_conversations', true);
    if (!is_array($convos)) {
        $convos = array();
    }
    if (!in_array($conv_id, $convos)) {
        $convos[] = intval($conv_id);
        update_user_meta($user_id, 'va_conversations', $convos);
    }
}

/* ============================
   3. Notifications (supports optional meta)
   ============================ */

function va_get_manager_email($manager_name) {
    $manager_emails = array(
        'emer' => 'egerona@virtualteammate.com',
        'jonasorana' => 'jorana@virtualteammate.com',
		'csm_elderz' => 'ezamora@virtualteammate.com',
    );
    
    return isset($manager_emails[$manager_name]) ? $manager_emails[$manager_name] : false;
}

/**
 * AJAX Handler: Manager Approves VA Selection
 * Add this to part-1-va-core.php
 */
add_action('wp_ajax_va_manager_approve_va', 'va_ajax_manager_approve_va');
function va_ajax_manager_approve_va() {
    check_ajax_referer('va_select_nonce', 'nonce');
    
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'not_logged_in'));
    }
    
    $manager_id = get_current_user_id();
    
    // Check if user is a manager
    if (!va_is_manager($manager_id)) {
        wp_send_json_error(array('message' => 'You do not have manager privileges'));
    }
    
    $client_id = isset($_POST['client_id']) ? intval($_POST['client_id']) : 0;
    $va_user_id = isset($_POST['va_user_id']) ? intval($_POST['va_user_id']) : 0;
    
    if (!$client_id || !get_userdata($client_id)) {
        wp_send_json_error(array('message' => 'Invalid client'));
    }
    
    if (!$va_user_id || !get_userdata($va_user_id)) {
        wp_send_json_error(array('message' => 'Invalid VA'));
    }
    
    // Verify this VA belongs to this manager
    $manager_user = get_userdata($manager_id);
    $manager_slug = $manager_user->user_nicename;
    $va_manager = va_get_static_manager($va_user_id);
    
    if ($va_manager !== $manager_slug) {
        wp_send_json_error(array('message' => 'This VA does not belong to you'));
    }
    
    // Update manager approval status
    update_user_meta($client_id, '_manager_approval_' . $va_user_id, 'approved');
    
    // Notify client
    $client_user = get_userdata($client_id);
    $va_user = get_userdata($va_user_id);
    $client_name = $client_user->display_name ?: $client_user->user_login;
    $va_name = $va_user->display_name ?: $va_user->user_login;
    
    va_add_notification($client_id, "Manager has approved your selection of {$va_name}!", array('va_user_id' => $va_user_id));
    
    // Send email to client
    $subject = 'VA Selection Approved - ' . $va_name;
    $body = "<html><body style='font-family: Arial, sans-serif; line-height: 1.6;'>
        <div style='max-width: 600px; margin: 0 auto; padding: 20px; background: #f9f9f9; border-radius: 10px;'>
            <h2 style='color: #28a745;'>✓ VT Selection Approved</h2>
            
            <div style='background: white; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                <p>Hi <strong>" . esc_html($client_name) . "</strong>,</p>
                
                <p>Great news! Your manager has approved your selection of <strong>" . esc_html($va_name) . "</strong>.</p>
                
                <div style='background: #d4edda; padding: 15px; border-left: 4px solid #28a745; margin: 20px 0;'>
                    <strong>✓ Next Steps:</strong><br><br>
                    Your VA has been notified and will need to accept the invitation. Once they accept, you can start messaging them directly!
                </div>
                
                <p style='margin-top: 30px;'>
                    <a href='" . esc_url(home_url('/client-dashboard/')) . "' 
                       style='background: #28a745; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; display: inline-block;'>
                        View Your Dashboard
                    </a>
                </p>
            </div>
            
            <p style='color: #666; font-size: 12px; margin-top: 20px;'>
                — " . esc_html(get_bloginfo('name')) . "
            </p>
        </div>
    </body></html>";
    
    $headers = array('Content-Type: text/html; charset=UTF-8');
    wp_mail($client_user->user_email, $subject, $body, $headers);
    
    // Notify VA
    va_add_notification($va_user_id, "Manager approved your assignment to {$client_name}. The client is waiting for your acceptance!", array('client_id' => $client_id));
    
    wp_send_json_success(array(
        'message' => 'VT selection approved successfully',
        'client_id' => $client_id,
        'va_user_id' => $va_user_id
    ));
}

/**
 * AJAX Handler: Manager Declines VA Selection
 * Fixed version that properly clears approval status
 */
add_action('wp_ajax_va_manager_decline_va', 'va_ajax_manager_decline_va');
function va_ajax_manager_decline_va() {
    check_ajax_referer('va_select_nonce', 'nonce');
    
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'not_logged_in'));
    }
    
    $manager_id = get_current_user_id();
    
    // Check if user is a manager
    if (!va_is_manager($manager_id)) {
        wp_send_json_error(array('message' => 'You do not have manager privileges'));
    }
    
    $client_id = isset($_POST['client_id']) ? intval($_POST['client_id']) : 0;
    $va_user_id = isset($_POST['va_user_id']) ? intval($_POST['va_user_id']) : 0;
    
    if (!$client_id || !get_userdata($client_id)) {
        wp_send_json_error(array('message' => 'Invalid client'));
    }
    
    if (!$va_user_id || !get_userdata($va_user_id)) {
        wp_send_json_error(array('message' => 'Invalid VA'));
    }
    
    // Verify this VA belongs to this manager
    $manager_user = get_userdata($manager_id);
    $manager_slug = $manager_user->user_nicename;
    $va_manager = va_get_static_manager($va_user_id);
    
    if ($va_manager !== $manager_slug) {
        wp_send_json_error(array('message' => 'This VA does not belong to you'));
    }
    
    // ✅ FIX: Instead of setting to 'declined', DELETE the meta entirely
    // This way, if client selects again, it starts fresh as 'pending'
    delete_user_meta($client_id, '_manager_approval_' . $va_user_id);
    
    // Remove from selected VAs
    va_remove_selected_va($client_id, $va_user_id);
    
    // Remove VA status
    delete_user_meta($client_id, '_va_status_' . $va_user_id);
    
    // ✅ FIX: Clear post meta if exists
    $all_va_posts = get_posts(array(
        'post_type' => 'vt-list-by-category',
        'posts_per_page' => -1,
        'post_status' => 'publish'
    ));
    
    foreach ($all_va_posts as $post) {
        $linked_user = va_get_va_user_id_from_post($post->ID);
        if ($linked_user === $va_user_id) {
            $current_client = get_post_meta($post->ID, '_selected_client_' . $va_user_id, true);
            if ($current_client == $client_id) {
                delete_post_meta($post->ID, '_selected_client_' . $va_user_id);
            }
            break;
        }
    }
    
    // Notify client
    $client_user = get_userdata($client_id);
    $va_user = get_userdata($va_user_id);
    $client_name = $client_user->display_name ?: $client_user->user_login;
    $va_name = $va_user->display_name ?: $va_user->user_login;
    
    va_add_notification($client_id, "Manager declined your selection of {$va_name}. Please choose another VT.", array('va_user_id' => $va_user_id));
    
    // Send email to client
    $subject = 'VA Selection Declined - ' . $va_name;
    $body = "<html><body style='font-family: Arial, sans-serif; line-height: 1.6;'>
        <div style='max-width: 600px; margin: 0 auto; padding: 20px; background: #f9f9f9; border-radius: 10px;'>
            <h2 style='color: #dc3545;'>VA Selection Update</h2>
            
            <div style='background: white; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                <p>Hi <strong>" . esc_html($client_name) . "</strong>,</p>
                
                <p>Your manager has reviewed your selection of <strong>" . esc_html($va_name) . "</strong> and decided that this VA is not available at this time.</p>
                
                <div style='background: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; margin: 20px 0;'>
                    <strong>📋 Next Steps:</strong><br><br>
                    Please browse our available VAs and select another team member who better fits your needs. Your manager will review the new selection.
                </div>
                
                <p style='margin-top: 30px;'>
                    <a href='" . esc_url(home_url('/browse-vas/')) . "' 
                       style='background: #2271b1; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; display: inline-block;'>
                        Browse Available VAs
                    </a>
                </p>
            </div>
            
            <p style='color: #666; font-size: 12px; margin-top: 20px;'>
                — " . esc_html(get_bloginfo('name')) . "
            </p>
        </div>
    </body></html>";
    
    $headers = array('Content-Type: text/html; charset=UTF-8');
    wp_mail($client_user->user_email, $subject, $body, $headers);
    
    // Notify VA (optional)
    va_add_notification($va_user_id, "Manager declined an assignment request from {$client_name}.", array('client_id' => $client_id));
    
    wp_send_json_success(array(
        'message' => 'VA selection declined',
        'client_id' => $client_id,
        'va_user_id' => $va_user_id
    ));
}

/**
 * Build and send email to manager when their VA is selected
 */
function va_notify_manager_of_selection($client_id, $va_user_id, $manager_name) {
    $client = get_userdata($client_id);
    $va_user = get_userdata($va_user_id);
    
    if (!$client || !$va_user) return false;
    
    // Get manager email from static list
    $manager_email = va_get_manager_email($manager_name);
    
    if (!$manager_email || !is_email($manager_email)) {
//         error_log("VA Manager notification failed: No valid email for manager '{$manager_name}'");
        return false;
    }
    
    $client_name = $client->display_name ?: $client->user_login;
    $va_name = $va_user->display_name ?: $va_user->user_login;
    $site_name = get_bloginfo('name');
    
    // Build email
    $subject = "Client Selected Your VA: {$va_name}";
    
    $body = "<html><body style='font-family: Arial, sans-serif; line-height: 1.6;'>
        <div style='max-width: 600px; margin: 0 auto; padding: 20px; background: #f9f9f9; border-radius: 10px;'>
            <h2 style='color: #2271b1; margin-top: 0;'>VT Selection Notification</h2>
            
            <div style='background: white; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                <p>Hi <strong>" . esc_html($manager_name) . "</strong>,</p>
                
                <p>A client has selected one of your VTs:</p>
                
                <div style='background: #e8f4f8; padding: 15px; border-left: 4px solid #2271b1; margin: 15px 0;'>
                    <strong>📋 Selection Details:</strong><br>
                    <strong>VA Name:</strong> " . esc_html($va_name) . "<br>
                    <strong>Client Name:</strong> " . esc_html($client_name) . "<br>
                    <strong>Client Email:</strong> " . esc_html($client->user_email) . "<br>
                    <strong>Date:</strong> " . date('F j, Y g:i A') . "
                </div>
                
                <p>Please coordinate with your VA and ensure they're ready to work with this client.</p>
                
                <p style='margin-top: 30px;'>
                    <a href='" . esc_url('https://clientvtm.wpenginepowered.com/csm-log-in/') . "' 
                       style='background: #2271b1; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; display: inline-block;'>
                        View Dashboard
                    </a>
                </p>
            </div>
            
            <p style='color: #666; font-size: 12px; margin-top: 20px;'>
                — " . esc_html($site_name) . "<br>
                This is an automated notification from your VA management system.
            </p>
        </div>
    </body></html>";
    
    $headers = array('Content-Type: text/html; charset=UTF-8');
    
    $sent = wp_mail($manager_email, $subject, $body, $headers);
    
//     if ($sent) {
//         error_log("VA Manager notification sent to '{$manager_name}' ({$manager_email}) for VA #{$va_user_id}");
//     } else {
//         error_log("Failed to send VA Manager notification to '{$manager_name}' ({$manager_email})");
//     }
    return $sent;
}


/**
 * Add an internal notification to a user meta array 'va_notifications'
 * $meta is optional associative array (e.g. ['message_id'=>123])
 */
function va_add_notification($user_id, $message, $meta = array(), $max_notes = 200) {
    $user_id = intval($user_id);
    if (!$user_id) return false;
    $notes = get_user_meta($user_id, 'va_notifications', true);
    if (!is_array($notes)) $notes = array();
    // sanitize message and meta keys/values lightly
    $safe_message = sanitize_text_field($message);
    $safe_meta = is_array($meta) ? array_map(function($v){ return is_scalar($v) ? sanitize_text_field((string)$v) : $v; }, $meta) : array();
    $notes[] = array(
        'id' => wp_generate_uuid4(),
        'message' => $safe_message,
        'meta' => $safe_meta,
        'time' => current_time('mysql'),
        'read' => 0,
    );
    if (count($notes) > $max_notes) $notes = array_slice($notes, -$max_notes);
    update_user_meta($user_id, 'va_notifications', $notes);
    return true;
}

function va_build_selection_request_email($client_id, $va_user_id) {
    $client = get_userdata(intval($client_id));
    $site = get_bloginfo('name');
    $client_name = $client ? ($client->display_name ?: $client->user_login) : 'A client';
    
    // Create acceptance link
    $accept_url = add_query_arg(array(
        'va_action' => 'accept',
        'client_id' => $client_id,
        'nonce' => wp_create_nonce('va_accept_' . $client_id . '_' . $va_user_id)
    ), home_url('/va-invitations/'));
    
    $decline_url = add_query_arg(array(
        'va_action' => 'decline',
        'client_id' => $client_id,
        'nonce' => wp_create_nonce('va_accept_' . $client_id . '_' . $va_user_id)
    ), home_url('/va-invitations/'));
    
    $body = "<html><body style='font-family: Arial, sans-serif; line-height: 1.6;'>
        <div style='max-width: 600px; margin: 0 auto; padding: 20px; background: #f9f9f9; border-radius: 10px;'>
            <h2 style='color: #2271b1;'>New Client Selection Request</h2>
            
            <div style='background: white; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                <p>Hi,</p>
                <p><strong>" . esc_html($client_name) . "</strong> has selected you as a Virtual Teammate on <strong>" . esc_html($site) . "</strong>.</p>
                
                <div style='background: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; margin: 20px 0;'>
                    <strong>⚠️ Action Required:</strong><br>
                    Please review this request and accept or decline the invitation.
                </div>
                
                <p><strong>Client Details:</strong><br>
                Name: " . esc_html($client_name) . "<br>
                Email: " . esc_html($client->user_email) . "</p>
                
                <div style='margin: 30px 0; text-align: center;'>
                    <a href='" . esc_url($accept_url) . "' 
                       style='background: #28a745; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block; margin-right: 10px;'>
                        ✓ Accept Invitation
                    </a>
                    <a href='" . esc_url($decline_url) . "' 
                       style='background: #dc3545; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block;'>
                        ✗ Decline Invitation
                    </a>
                </div>
                
                <p style='font-size: 12px; color: #666;'>
                    Or login to your dashboard to manage invitations manually.
                </p>
            </div>
            
            <p style='color: #666; font-size: 12px; margin-top: 20px;'>
                — " . esc_html($site) . "
            </p>
        </div>
    </body></html>";
    
    return $body;
}

/* ============================
   VA ACCEPTANCE/DECLINE SYSTEM
   ============================ */

add_action('wp_ajax_va_accept_invitation', 'va_ajax_accept_invitation');
function va_ajax_accept_invitation() {
    check_ajax_referer('va_accept_nonce', 'nonce');
    
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'not_logged_in'));
    }
    
    $va_user_id = get_current_user_id();
    $client_id = isset($_POST['client_id']) ? intval($_POST['client_id']) : 0;
    
    if (!$client_id || !get_userdata($client_id)) {
        wp_send_json_error(array('message' => 'Invalid client'));
    }
    
    $selected = va_get_selected_vas($client_id);
    if (!in_array($va_user_id, $selected)) {
        wp_send_json_error(array('message' => 'You are not selected by this client'));
    }
    
    // Update status to "accepted"
    update_user_meta($client_id, '_va_status_' . $va_user_id, 'accepted');
    
    // Notify client
    $va_user = get_userdata($va_user_id);
    $va_name = $va_user->display_name ?: $va_user->user_login;
    va_add_notification($client_id, "{$va_name} has accepted your invitation!", array('va_user_id' => $va_user_id));
    
    // ✅ NOW create private conversation with client (after acceptance)
    if (function_exists('va_get_or_create_private_conversation')) {
        $conv_id = va_get_or_create_private_conversation($client_id, $va_user_id);
        if ($conv_id && function_exists('wp_create_user_message')) {
            $welcome_message = "🎉 Great! {$va_name} has accepted your invitation. You can now start messaging!";
            wp_create_user_message($conv_id, $va_user_id, $welcome_message);
            va_store_user_conversation($client_id, $conv_id);
            va_store_user_conversation($va_user_id, $conv_id);
        }
    }
    
    wp_send_json_success(array('message' => 'Invitation accepted successfully', 'client_id' => $client_id));
}

add_action('wp_ajax_va_decline_invitation', 'va_ajax_decline_invitation');
function va_ajax_decline_invitation() {
    check_ajax_referer('va_accept_nonce', 'nonce');
    
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'not_logged_in'));
    }
    
    $va_user_id = get_current_user_id();
    $client_id = isset($_POST['client_id']) ? intval($_POST['client_id']) : 0;
    
    if (!$client_id || !get_userdata($client_id)) {
        wp_send_json_error(array('message' => 'Invalid client'));
    }
    
    // Check if VA is actually selected by this client
    $selected = va_get_selected_vas($client_id);
    if (!in_array($va_user_id, $selected)) {
        wp_send_json_error(array('message' => 'You are not selected by this client'));
    }
    
    // Update status to "declined"
    update_user_meta($client_id, '_va_status_' . $va_user_id, 'declined');
    
    // Remove from selected list
    va_remove_selected_va($client_id, $va_user_id);
    
    // Notify client
    $va_user = get_userdata($va_user_id);
    $va_name = $va_user->display_name ?: $va_user->user_login;
    va_add_notification($client_id, "{$va_name} has declined your invitation.", array('va_user_id' => $va_user_id));
    
    wp_send_json_success(array('message' => 'Invitation declined', 'client_id' => $client_id));
}

/**
 * Toggle email notifications on/off
 */
add_action('wp_ajax_va_toggle_email_notifications', 'va_ajax_toggle_email_notifications');
function va_ajax_toggle_email_notifications() {
    check_ajax_referer('va_accept_nonce', 'nonce');
    
    $user_id = get_current_user_id();
    if (!$user_id) {
        wp_send_json_error(['message' => 'Not logged in']);
    }
    
    $enabled = isset($_POST['enabled']) ? sanitize_text_field($_POST['enabled']) : 'on';
    
    update_user_meta($user_id, 'va_email_notifications_enabled', $enabled);
    
    wp_send_json_success([
        'message' => 'Email notification preferences updated',
        'enabled' => $enabled
    ]);
}
/**
 * Check if VA has accepted the client's invitation
 */
function va_is_invitation_accepted($client_id, $va_user_id) {
    $status = get_user_meta($client_id, '_va_status_' . $va_user_id, true);
    return $status === 'accepted';
}

/**
 * Remove notifications tied to a specific message_id for given participants
 */
function va_remove_notifications_for_message($message_id, $participant_ids = array()) {
    $message_id = intval($message_id);
    if (!$message_id || empty($participant_ids) || !is_array($participant_ids)) return false;
    foreach ($participant_ids as $uid) {
        $uid = intval($uid);
        if (!$uid) continue;
        $notes = get_user_meta($uid, 'va_notifications', true);
        if (!is_array($notes)) continue;
        $new = array();
        foreach ($notes as $n) {
            if (isset($n['meta']) && is_array($n['meta']) && isset($n['meta']['message_id']) && intval($n['meta']['message_id']) === $message_id) {
                // skip (remove)
                continue;
            }
            $new[] = $n;
        }
        update_user_meta($uid, 'va_notifications', $new);
    }
    return true;
}



function va_send_email_to_user($user_id, $subject, $html_body) {
    $u = get_userdata(intval($user_id));
    if (!$u || !is_email($u->user_email)) return false;
    $headers = array('Content-Type: text/html; charset=UTF-8');
    return wp_mail($u->user_email, $subject, $html_body, $headers);
}

function va_build_selected_email($client_id) {
    $client = get_userdata(intval($client_id));
    $site = get_bloginfo('name');
    $client_name = $client ? ($client->display_name ?: $client->user_login) : 'A client';
    $login = wp_login_url();
    $body = "<html><body>
        <p>Hi,</p>
        <p><strong>" . esc_html($client_name) . "</strong> has selected you as a Virtual Teammate on <strong>" . esc_html($site) . "</strong>.</p>
        <p>Please <a href='" . esc_url($login) . "'>login</a> to review the client and start messaging.</p>
        <p>— " . esc_html($site) . "</p>
    </body></html>";
    return $body;
}

/* ============================
   4. AJAX: select / remove VA
   ============================ */

add_action('wp_ajax_va_select_va', 'va_ajax_select_va');
function va_ajax_select_va() {
    check_ajax_referer('va_select_nonce','nonce'); 	
    
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'not_logged_in'));
    }
    
    $client_id = get_current_user_id();
    $va_user_id = isset($_POST['va_user_id']) ? intval($_POST['va_user_id']) : 0;
    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    
    if (!$va_user_id && $post_id) {
        $va_user_id = va_get_va_user_id_from_post($post_id);
    }
    
    if (!$va_user_id) {
        wp_send_json_error(array('message' => 'Invalid VA User'));
    }
    
    if ($va_user_id === $client_id) {
        wp_send_json_error(array('message' => 'Cannot select yourself'));
    }
    
    if (!get_userdata($va_user_id)) {
        wp_send_json_error(array('message' => 'Invalid VA User'));
    }
    
    $manager = va_get_static_manager($va_user_id);
    if (!$manager) {
        wp_send_json_error(array('message' => 'This VT has no manager assigned and cannot be selected.'));
    }
    
    $current = va_get_selected_vas($client_id);
    if (in_array($va_user_id, $current, true)) {
        wp_send_json_error(array('message' => 'VT already selected'));
    }
    
    if ($post_id) {
        $current_client = get_post_meta($post_id, '_selected_client_' . $va_user_id, true);
        
        if ($current_client && $current_client != $client_id) {
            wp_send_json_error(array('message' => 'This VA has already been selected by another client.'));
        }
    }
    
    $added = va_add_selected_va($client_id, $va_user_id);
    
    if ($added) {
        if ($post_id) {
            update_post_meta($post_id, '_selected_client_' . $va_user_id, $client_id);
        }
		
		update_user_meta($client_id, '_va_status_' . $va_user_id, 'pending');
		
		if ($manager) {
			va_notify_manager_of_selection($client_id, $va_user_id, $manager);
		}
        
        // Send notification to VA
        $client = wp_get_current_user();
        $msg = sprintf('%s (user #%d) selected you as a Virtual Teammate.', esc_html($client->display_name ?: $client->user_login), intval($client_id));
        va_add_notification($va_user_id, $msg);
        
        // Send email to VA with updated body (acceptance required)
        $subject = 'New Client Selection Request - Action Required';
        $body = va_build_selection_request_email($client_id, $va_user_id);
        va_send_email_to_user($va_user_id, $subject, $body);
        
        // ✅ NEW: Create/update conversation with manager
        do_action('va_after_select_va', $client_id, $va_user_id);
        
        // DO NOT create conversation with VA yet (wait for acceptance)
        // The conversation will be created in va_ajax_accept_invitation
        
        wp_send_json_success(array('selected' => va_get_selected_vas($client_id)));
    } else {
        wp_send_json_error(array('message' => 'Failed to add VT. Please try again.'));
    }
}

add_action('wp_ajax_va_remove_va', 'va_ajax_remove_va');
function va_ajax_remove_va() {
    check_ajax_referer('va_select_nonce','nonce');
    
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'not_logged_in'));
    }
    
    $client_id = get_current_user_id();
    $va_user_id = isset($_POST['va_user_id']) ? intval($_POST['va_user_id']) : 0;
    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    
    if (!$va_user_id && $post_id) {
        $va_user_id = va_get_va_user_id_from_post($post_id);
    }
    
    if (!$va_user_id || !get_userdata($va_user_id)) {
        wp_send_json_error(array('message' => 'invalid_va_user'));
    }
    
    // Check if this VA is in the client's selected list FIRST
    $selected = va_get_selected_vas($client_id); // ✅ Use correct function
    if (!in_array($va_user_id, $selected, true)) {
        wp_send_json_error(array('message' => 'VA not in your selected list.'));
    }
    
    // Verify post meta ownership if post_id is provided
    if ($post_id) {
        $current_client = get_post_meta($post_id, '_selected_client_' . $va_user_id, true);
        
        // If someone else owns it in post meta, don't allow removal
        if ($current_client && $current_client != $client_id) {
            wp_send_json_error(array('message' => 'You cannot remove a VA selected by another client.'));
        }
        
        // Delete post meta if this client owns it
        if ($current_client == $client_id) {
            delete_post_meta($post_id, '_selected_client_' . $va_user_id);
        }
    }
    
    // Remove from conversations (only affects this client's conversations)
    va_remove_va_from_conversations($va_user_id);
    
    // Remove from selected VAs list
    $removed = va_remove_selected_va($client_id, $va_user_id);
    
    if ($removed) {
        wp_send_json_success(array('selected' => va_get_selected_vas($client_id)));
    } else {
        wp_send_json_error(array('message' => 'Failed to remove VA.'));
    }
}

add_action('va_after_select_va', 'va_create_manager_conversation', 10, 2);
function va_create_manager_conversation($client_id, $va_user_id) {
    // Get the VA's manager
    $manager_name = va_get_static_manager($va_user_id);
    if (!$manager_name) return;
    
    // Get manager user ID
    $manager_user_id = va_get_manager_user_id($manager_name);
    if (!$manager_user_id) return;
    
    // Get VA details
    $va_user = get_userdata($va_user_id);
    $va_name = $va_user ? $va_user->display_name : 'VA';
    
    // Get client details
    $client_user = get_userdata($client_id);
    $client_name = $client_user ? $client_user->display_name : 'Client';
    
    // Check if conversation already exists
    $existing_conv_id = false;
    if (function_exists('va_get_existing_private_conversation')) {
        $existing_conv_id = va_get_existing_private_conversation($client_id, $manager_user_id);
    }
    
    if ($existing_conv_id) {
        // ✅ Conversation already exists - send a message about the new VA
        if (function_exists('wp_create_user_message')) {
            $new_va_message = "👋 Hi {$client_name}! I'm also the manager for {$va_name}, whom you just selected. Feel free to reach out if you need any assistance with your new VA!";
            
            wp_create_user_message($existing_conv_id, $manager_user_id, $new_va_message);
            
            // Notify client about the new message
            va_add_notification($client_id, "Your VA's manager sent you a message!", array('conversation_id' => $existing_conv_id));
        }
        return;
    }
    
    // ✅ Create new private conversation between client and manager
    if (function_exists('va_get_or_create_private_conversation')) {
        $conv_id = va_get_or_create_private_conversation($client_id, $manager_user_id);
        
        if ($conv_id && function_exists('wp_create_user_message')) {
            // Send welcome message from manager
            $welcome_message = "👋 Hello {$client_name}! I'm the manager for {$va_name}. Feel free to reach out if you need any assistance or have questions about your VA.";
            
            wp_create_user_message($conv_id, $manager_user_id, $welcome_message);
            
            // Store conversation for both parties
            va_store_user_conversation($client_id, $conv_id);
            va_store_user_conversation($manager_user_id, $conv_id);
            
            // Notify client about new manager conversation
            va_add_notification($client_id, "Your VT's manager has opened a conversation with you!", array('conversation_id' => $conv_id));
        }
    }
}


/**
 * Add this to part-1-va-core.php
 * New AJAX handler specifically for managers to remove VAs from clients
 */

add_action('wp_ajax_va_manager_remove_va', 'va_ajax_manager_remove_va');
function va_ajax_manager_remove_va() {
    check_ajax_referer('va_select_nonce', 'nonce');
    
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'not_logged_in'));
    }
    
    $manager_id = get_current_user_id();
    
    // Check if user is a manager
    if (!va_is_manager($manager_id)) {
        wp_send_json_error(array('message' => 'You do not have manager privileges'));
    }
    
    $client_id = isset($_POST['client_id']) ? intval($_POST['client_id']) : 0;
    $va_user_id = isset($_POST['va_user_id']) ? intval($_POST['va_user_id']) : 0;
    
    if (!$client_id || !get_userdata($client_id)) {
        wp_send_json_error(array('message' => 'Invalid client'));
    }
    
    if (!$va_user_id || !get_userdata($va_user_id)) {
        wp_send_json_error(array('message' => 'Invalid VA'));
    }
    
    // Verify this VA belongs to this manager
    $manager_user = get_userdata($manager_id);
    $manager_slug = $manager_user->user_nicename;
    $va_manager = va_get_static_manager($va_user_id);
    
    if ($va_manager !== $manager_slug) {
        wp_send_json_error(array('message' => 'This VA does not belong to you'));
    }
    
    // Check if this VA is in the client's selected list
    $selected = va_get_selected_vas($client_id);
    if (!in_array($va_user_id, $selected, true)) {
        wp_send_json_error(array('message' => 'VA not in client\'s selected list'));
    }
    
    // Find the VA's post to remove post_meta
    $all_va_posts = get_posts(array(
        'post_type' => 'vt-list-by-category',
        'posts_per_page' => -1,
        'post_status' => 'publish'
    ));
    
    $post_id = null;
    foreach ($all_va_posts as $post) {
        $linked_user = va_get_va_user_id_from_post($post->ID);
        if ($linked_user === $va_user_id) {
            $post_id = $post->ID;
            break;
        }
    }
    
    // Delete post meta if found
    if ($post_id) {
        $current_client = get_post_meta($post_id, '_selected_client_' . $va_user_id, true);
        if ($current_client == $client_id) {
            delete_post_meta($post_id, '_selected_client_' . $va_user_id);
        }
    }
    
    // ✅ FIX: Clear BOTH manager approval AND VA status
    delete_user_meta($client_id, '_manager_approval_' . $va_user_id);
    delete_user_meta($client_id, '_va_status_' . $va_user_id);
    
    // Remove from conversations
    va_remove_va_from_conversations_for_client($client_id, $va_user_id);
    
    // Remove from selected VAs list
    $removed = va_remove_selected_va($client_id, $va_user_id);
    
    if ($removed) {
        // Notify both parties
        $client_user = get_userdata($client_id);
        $va_user = get_userdata($va_user_id);
        
        $client_name = $client_user->display_name ?: $client_user->user_login;
        $va_name = $va_user->display_name ?: $va_user->user_login;
        
        va_add_notification($client_id, "Your manager removed {$va_name} from your VT list.", array('va_user_id' => $va_user_id));
        va_add_notification($va_user_id, "You were removed from {$client_name}'s VT list by your manager.", array('client_id' => $client_id));
        
        wp_send_json_success(array(
            'message' => 'VT removed successfully',
            'selected' => va_get_selected_vas($client_id)
        ));
    } else {
        wp_send_json_error(array('message' => 'Failed to remove VA'));
    }
}

/**
 * Remove VA from specific client's conversations only
 */
function va_remove_va_from_conversations_for_client($client_id, $va_user_id) {
    $client_id = intval($client_id);
    $va_user_id = intval($va_user_id);
    
    if (!$client_id || !$va_user_id) return;
    
    // Find private conversation between client and VA
    $pair_hash = va_generate_pair_hash($client_id, $va_user_id);
    
    $convs = get_posts(array(
        'post_type' => 'va_conversation',
        'posts_per_page' => -1,
        'meta_query' => array(
            array(
                'key' => 'pair_hash',
                'value' => $pair_hash,
                'compare' => '='
            )
        )
    ));
    
    foreach ($convs as $conv) {
        $conv_id = $conv->ID;
        
        // Remove from both users' conversation lists
        $client_convos = get_user_meta($client_id, 'va_conversations', true);
        if (is_array($client_convos)) {
            $client_convos = array_diff($client_convos, array($conv_id));
            update_user_meta($client_id, 'va_conversations', array_values($client_convos));
        }
        
        $va_convos = get_user_meta($va_user_id, 'va_conversations', true);
        if (is_array($va_convos)) {
            $va_convos = array_diff($va_convos, array($conv_id));
            update_user_meta($va_user_id, 'va_conversations', array_values($va_convos));
        }
        
        // Delete the conversation and its messages
        $msgs = get_posts(array(
            'post_type' => 'va_message',
            'posts_per_page' => -1,
            'meta_key' => 'conversation_id',
            'meta_value' => $conv_id
        ));
        
        foreach ($msgs as $msg) {
            wp_delete_post($msg->ID, true);
        }
        
        wp_delete_post($conv_id, true);
    }
}