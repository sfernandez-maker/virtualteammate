/**
 * Ultimate Member: Use ACF images from "vt-list-by-category" profile posts
 * as default Avatar + Cover (only when UM photo is empty).
 *
 * - Profile post type: vt-list-by-category
 * - Profile slug matches user's display_name/login/nicename
 * - ACF fields: profile_picture (avatar), cover_picture (cover)  <-- change cover field if needed
 * - Cache profile post ID in user_meta: vt_profile_post_id
 */

define('VT_PROFILE_POST_TYPE', 'vt-list-by-category');
define('VT_PROFILE_POST_ID_META', 'vt_profile_post_id');

define('VT_ACF_AVATAR_FIELD', 'profile_picture');
define('VT_ACF_COVER_FIELD',  'cover_picture'); // <-- change if your cover field name is different


/** ========== AVATAR ========== */
add_filter('um_user_avatar_url_filter', function ($avatar_uri, $user_id, $args) {
	if ( ! $user_id ) return $avatar_uri;

	// Don't override if UM already has a manual avatar
	if ( get_user_meta($user_id, 'profile_photo', true) ) return $avatar_uri;

	$post_id = vt_get_profile_post_id($user_id);
	if ( ! $post_id ) return $avatar_uri;

	$att_id = vt_get_acf_image_attachment_id($post_id, VT_ACF_AVATAR_FIELD);
	if ( ! $att_id ) return $avatar_uri;

	$url = wp_get_attachment_image_url($att_id, 'thumbnail');
	return $url ? $url : $avatar_uri;
}, 10, 3);


/** ========== COVER ========== */
add_filter('um_user_cover_photo_uri__filter', function ($cover_uri, $is_default, $attrs) {
	$user_id = function_exists('um_profile_id') ? um_profile_id() : 0;
	if ( ! $user_id ) return $cover_uri;

	// Don't override if UM already has a manual cover
	if ( get_user_meta($user_id, 'cover_photo', true) ) return $cover_uri;

	$post_id = vt_get_profile_post_id($user_id);
	if ( ! $post_id ) return $cover_uri;

	$att_id = vt_get_acf_image_attachment_id($post_id, VT_ACF_COVER_FIELD);
	if ( ! $att_id ) return $cover_uri;

	$url = wp_get_attachment_image_url($att_id, 'full');
	return $url ? $url : $cover_uri;
}, 10, 3);


/** Find and cache the related profile post ID for a user */
function vt_get_profile_post_id($user_id) {
	$cached = (int) get_user_meta($user_id, VT_PROFILE_POST_ID_META, true);
	if ( $cached ) return $cached;

	$user = get_userdata($user_id);
	if ( ! $user ) return 0;

	$slugs = array_unique(array_filter([
		sanitize_title($user->display_name),
		sanitize_title($user->user_login),
		sanitize_title($user->user_nicename),
	]));

	foreach ($slugs as $slug) {
		$post = get_page_by_path($slug, OBJECT, VT_PROFILE_POST_TYPE);
		if ( $post && ! empty($post->ID) ) {
			update_user_meta($user_id, VT_PROFILE_POST_ID_META, (int) $post->ID);
			return (int) $post->ID;
		}
	}

	return 0;
}


/** Read ACF image field stored in postmeta and return attachment ID */
function vt_get_acf_image_attachment_id($post_id, $field_key) {
	$raw = get_post_meta($post_id, $field_key, true);
	$raw = maybe_unserialize($raw);

	// Usually attachment ID
	if ( is_numeric($raw) ) return (int) $raw;

	// Sometimes array
	if ( is_array($raw) ) {
		if ( isset($raw['ID']) ) return (int) $raw['ID'];
		if ( isset($raw['id']) ) return (int) $raw['id'];
		if ( isset($raw['url']) ) return (int) attachment_url_to_postid($raw['url']);
	}

	// Sometimes URL
	if ( is_string($raw) && filter_var($raw, FILTER_VALIDATE_URL) ) {
		return (int) attachment_url_to_postid($raw);
	}

	return 0;
}