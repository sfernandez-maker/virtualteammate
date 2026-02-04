/**
 * VTM Members Portals — FULL SNIPPET (Client → CSM → VT) — V28 (Calendar + No-Flicker + Popover Prompts)
 *
 * Shortcodes:
 *   Client: [vtm_portal role="client"]
 *   CSM:    [vtm_portal role="csm"]
 *   VT:     [vtm_portal role="vt"]
 *
 * What’s fixed / hardened:
 * - Routing fix: CSM assignment stored correctly via Google Sheet mapping (supports “Jonas Orana” and “Emerson Gerona/EMER”).
 * - Compatibility: backend accepts both param names (action/csm_action, action/vt_action, request_update/revision, cancel_request/request_cancel).
 * - Calendar rules (“Calendar”):
 *     - start date cannot be before today
 *     - due date cannot be before today
 *     - due date cannot be earlier than start date
 *     - UI enforces due.min = max(today, start) on every render + on change
 * - No flicker: list/detail re-render only when assignment hash changes; countdown text updates without DOM rebuild.
 * - Draft safety: Client “Create assignment” fields + VT selection persist in localStorage and never get wiped by polling.
 * - Prompt protection: NO dialog modal; uses a small inline popover near the button.
 * - Activity delete rules:
 *     - VT can delete ONLY their own activity items
 *     - Client/CSM do not see delete buttons at all
 *     - Admin can delete anything
 * - Neon time label now has dark background for readability; no colored borders introduced.
 */

if (!defined('ABSPATH')) exit;

// TEMPORARY: Catch all PHP errors
function vtm_debug_handler($errno, $errstr, $errfile, $errline) {
    error_log("VTM PHP ERROR: [$errno] $errstr in $errfile on line $errline");
    return false;
}
set_error_handler('vtm_debug_handler');

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        error_log("VTM FATAL ERROR: " . print_r($error, true));
    }
});

// Prevent Elementor conflict
if (defined('DOING_AJAX') && DOING_AJAX) {
    remove_action('save_post', ['ElementorTemplateLibrarySource_Local', 'on_save_post'], 10);
}

remove_shortcode('vtm_portal');

class VTM_Members_Portals_Full_V28 {
  const CPT   = 'vtm_assignment';
  const NONCE = 'vtm_portal_nonce_v28';

  // Google Sheet (CSV export)
  const SHEET_ID   = '1JNnmDUZoBGDqT6z_syR6dFOOnqiGQEHYdKbhlbKUB-Y';
  const SHEET_TABS = ['EMER', 'Jonas']; // must match your actual tab names

  // WP usernames for CSMs
  const CSM_LOGINS = ['jonasorana', 'emer', 'csm_elderz'];

  // Map CSM label/name -> WP username
  const CSM_USERNAME_MAP = [
    'jonas' => 'jonasorana',
    'jonas orana' => 'jonasorana',
    'jonasorana' => 'jonasorana',

    'emer' => 'emer',
    'emerson' => 'emer',
    'emerson gerona' => 'emer',
	  
	'csm_elderz' => 'csm_elderz',
	'Elder Zamora' => 'csm_elderz',
  ];

  public static function boot() {
    add_action('init', [__CLASS__, 'register_cpt']);
    add_shortcode('vtm_portal', [__CLASS__, 'shortcode']);

    add_action('wp_ajax_vtm_state', [__CLASS__, 'ajax_state']);
    add_action('wp_ajax_vtm_save_roster', [__CLASS__, 'ajax_save_roster']);

    add_action('wp_ajax_vtm_client_create', [__CLASS__, 'ajax_client_create']);
    add_action('wp_ajax_vtm_client_update', [__CLASS__, 'ajax_client_update']);

    add_action('wp_ajax_vtm_csm_action', [__CLASS__, 'ajax_csm_action']);
    add_action('wp_ajax_vtm_vt_action', [__CLASS__, 'ajax_vt_action']);

    add_action('wp_ajax_vtm_send_message', [__CLASS__, 'ajax_send_message']);
    add_action('wp_ajax_vtm_delete_activity', [__CLASS__, 'ajax_delete_activity']);
  }

  public static function register_cpt() {
    if (post_type_exists(self::CPT)) return;
    register_post_type(self::CPT, [
      'label' => 'VTM Assignments',
      'public' => false,
      'show_ui' => false,
      'supports' => ['title', 'editor', 'author'],
    ]);
  }

  // ---------------- helpers ----------------
  private static function clean_text($s) {
    $s = is_string($s) ? $s : '';
    $s = wp_strip_all_tags($s);
    $s = preg_replace('/\s+/', ' ', $s);
    return trim($s);
  }

  private static function require_logged_in() {
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Please log in to continue.'], 401);
  }

  private static function verify_nonce() {
    $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
    if (!wp_verify_nonce($nonce, self::NONCE)) {
      wp_send_json_error(['message' => 'Security check failed. Please refresh and try again.'], 403);
    }
  }

  private static function user_login_lower() {
    if (!is_user_logged_in()) return '';
    $u = wp_get_current_user();
    return strtolower((string)($u->user_login ?? ''));
  }

  private static function get_user_label() {
    $u = wp_get_current_user();
    $display = $u ? $u->display_name : '';
    $email = $u ? $u->user_email : '';
    return trim($display ?: $email);
  }

  private static function email_for_username($username) {
    $username = self::clean_text($username);
    if (!$username) return '';
    $user = get_user_by('login', $username);
    return ($user && !empty($user->user_email)) ? (string)$user->user_email : '';
  }

  private static function send_mail($to, $subject, $message) {
    if (!$to) return;
    $headers = ['Content-Type: text/html; charset=UTF-8'];
    @wp_mail($to, $subject, $message, $headers);
  }

  // ------------- Calendar helpers (backend “Calendar”) -------------
  private static function is_ymd($d) {
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d);
  }

  private static function today_ymd() {
    return (string)current_time('Y-m-d');
  }

  private static function ymd_gte_today($d) {
    $d = self::clean_text($d);
    if (!self::is_ymd($d)) return false;
    return $d >= self::today_ymd();
  }

  // ---------------- name matching ----------------
  private static function norm_token($t) {
    $t = strtolower(self::clean_text($t));
    $t = preg_replace('/[^\p{L}\p{N}]/u', '', $t);
    return trim($t);
  }

  private static function name_tokens($name) {
    $name = self::clean_text($name);
    $name = preg_replace('/\s+/', ' ', $name);
    $raw = preg_split('/\s+/', strtolower($name));
    $tokens = [];
    foreach ($raw as $r) {
      $t = self::norm_token($r);
      if ($t !== '') $tokens[] = $t;
    }
    return $tokens;
  }

  private static function last_is_initial($last) {
    $last = self::norm_token($last);
    return (strlen($last) === 1);
  }

  // First-name must match; last-name allows initials and single-name cases.
  private static function names_match($a, $b) {
    $A = self::name_tokens($a);
    $B = self::name_tokens($b);
    if (!$A || !$B) return false;

    $aFirst = $A[0] ?? '';
    $bFirst = $B[0] ?? '';
    if (!$aFirst || !$bFirst) return false;
    if ($aFirst !== $bFirst) return false;

    if (count($A) === 1 || count($B) === 1) return (strlen($aFirst) >= 3);

    $aLast = $A[count($A)-1] ?? '';
    $bLast = $B[count($B)-1] ?? '';
    if (!$aLast || !$bLast) return true;

    if ($aLast === $bLast) return true;

    $aIsInit = self::last_is_initial($aLast);
    $bIsInit = self::last_is_initial($bLast);
    if ($aIsInit && !$bIsInit) return ($aLast === substr($bLast, 0, 1));
    if ($bIsInit && !$aIsInit) return ($bLast === substr($aLast, 0, 1));

    return false;
  }

  private static function canon_client($client) {
    $client = strtolower(self::clean_text($client));
    $client = preg_replace('/[^\p{L}\p{N}\s]/u', '', $client);
    $client = preg_replace('/\s+/', ' ', $client);
    return trim($client);
  }

  private static function canon_person_key($name) {
    $name = strtolower(self::clean_text($name));
    $name = preg_replace('/[^\p{L}\p{N}\s]/u', '', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    return trim($name);
  }

  private static function csm_username_from_name($csmName) {
    $k = self::canon_person_key($csmName);
    if (!$k) return '';
    if (isset(self::CSM_USERNAME_MAP[$k])) return self::CSM_USERNAME_MAP[$k];
    // fallback contains checks
    if (strpos($k, 'jonas') !== false) return 'jonasorana';
    if (strpos($k, 'emer') !== false) return 'emer';
    if (strpos($k, 'emerson') !== false) return 'emer';
    return '';
  }

  // ---------------- Google Sheet mapping ----------------
  private static function sheet_csv_url($sheetName) {
    return 'https://docs.google.com/spreadsheets/d/' . rawurlencode(self::SHEET_ID) . '/gviz/tq?tqx=out:csv&sheet=' . rawurlencode($sheetName);
  }

  private static function parse_csv($csv) {
    $csv = preg_replace("/\r\n|\r/", "\n", (string)$csv);
    $lines = explode("\n", $csv);
    $rows = [];
    foreach ($lines as $line) {
      if (trim($line) === '') continue;
      $rows[] = str_getcsv($line);
    }
    return $rows;
  }

  private static function header_index($upperHeaders, $candidates) {
    foreach ($candidates as $cand) {
      $idx = array_search($cand, $upperHeaders, true);
      if ($idx !== false) return $idx;
    }
    // soft match: contains
    foreach ($upperHeaders as $i => $h) {
      foreach ($candidates as $cand) {
        if ($cand && strpos($h, $cand) !== false) return $i;
      }
    }
    return false;
  }

  // Returns rows with CLIENT / VT / CSM / csm_username
  private static function fetch_csm_map() {
    $cache_key = 'vtm_csm_map_full_v28';
    $cached = get_transient($cache_key);
    if (is_array($cached) && isset($cached['rows'])) return $cached;

    $rows = [];
    $ok_any = false;

    foreach (self::SHEET_TABS as $tab) {
      $url = self::sheet_csv_url($tab);
      $resp = wp_remote_get($url, ['timeout' => 12, 'redirection' => 5]);
      if (is_wp_error($resp)) continue;

      $code = (int)wp_remote_retrieve_response_code($resp);
      $csv  = wp_remote_retrieve_body($resp);
      if ($code !== 200 || !$csv) continue;

      $parsed = self::parse_csv($csv);
      if (!$parsed || count($parsed) < 2) continue;

      $header = array_map([__CLASS__, 'clean_text'], $parsed[0]);
      $upper  = array_map('strtoupper', $header);

      $idxClient = self::header_index($upper, ['CLIENT', "CLIENT'S COMPANY", 'CLIENTS COMPANY', 'COMPANY', 'CLIENT COMPANY']);
      $idxVT     = self::header_index($upper, ['VT', 'TEAMMATE NAME', 'VIRTUAL TEAMMATE', 'TEammate NAME', 'TEammate']);
      $idxCSM    = self::header_index($upper, ['CSM', 'CSM |CSM', 'CSM NAME', 'SUPERVISOR', 'SUPERVISOR NAME']);

      // Tab fallback label matches your naming
      $tabCsmName = ($tab === 'Jonas') ? 'Jonas Orana' : 'Emerson Gerona';
      $tabCsmUser = self::csm_username_from_name($tabCsmName);

      for ($i=1; $i<count($parsed); $i++) {
        $r = $parsed[$i];

        $client = ($idxClient !== false && isset($r[$idxClient])) ? self::clean_text($r[$idxClient]) : '';
        $vt     = ($idxVT     !== false && isset($r[$idxVT]))     ? self::clean_text($r[$idxVT])     : '';
        $csm    = ($idxCSM    !== false && isset($r[$idxCSM]))    ? self::clean_text($r[$idxCSM])    : $tabCsmName;

        if (!$vt) continue;

        $csmUser = self::csm_username_from_name($csm) ?: $tabCsmUser;

        $rows[] = [
          'client' => $client,
          'client_canon' => self::canon_client($client),
          'vt' => $vt,
          'csm' => $csm,
          'csm_username' => $csmUser,
          'tab' => $tab,
        ];
      }

      $ok_any = true;
    }

    $out = ['rows' => $rows, 'ok' => $ok_any, 'fetched_at' => current_time('mysql')];
    set_transient($cache_key, $out, $ok_any ? 15 * MINUTE_IN_SECONDS : 2 * MINUTE_IN_SECONDS);
    return $out;
  }

  private static function current_role_real() {
    if (!is_user_logged_in()) return 'guest';
    $login = self::user_login_lower();
    if ($login && in_array($login, array_map('strtolower', self::CSM_LOGINS), true)) return 'csm';
    return 'client';
  }

  // VT eligibility: display name must match any VT entry in the sheet (or admin).
  private static function current_user_is_vt_in_sheet() {
    if (!is_user_logged_in()) return false;
    if (current_user_can('manage_options')) return true;

    $me = self::get_user_label();
    if (!$me) return false;

    $map = self::fetch_csm_map();
    if (empty($map['rows'])) return false;

    foreach ($map['rows'] as $r) {
      if (!empty($r['vt']) && self::names_match($me, $r['vt'])) return true;
    }
    return false;
  }

  private static function role_allowed_for_shortcode($forced_role, $real_role) {
    $forced_role = strtolower(self::clean_text($forced_role));
    if ($forced_role === 'auto' || $forced_role === '') return true;
    if (current_user_can('manage_options')) return true;

    if ($forced_role === 'vt') return self::current_user_is_vt_in_sheet();

    // Client/CSM portals: forced role must match real role
    return ($forced_role === $real_role);
  }

  private static function csm_username_for_vt($clientName, $vtName) {
    $clientCanon = self::canon_client($clientName);
    $map = self::fetch_csm_map();

    // 1) try match by client + vt
    if (!empty($map['rows'])) {
      foreach ($map['rows'] as $r) {
        if ($r['client_canon'] && $clientCanon && $r['client_canon'] === $clientCanon) {
          if (self::names_match($r['vt'], $vtName)) {
            return strtolower((string)($r['csm_username'] ?? ''));
          }
        }
      }
    }

    // 2) fallback match by vt only
    if (!empty($map['rows'])) {
      foreach ($map['rows'] as $r) {
        if (self::names_match($r['vt'], $vtName)) {
          return strtolower((string)($r['csm_username'] ?? ''));
        }
      }
    }

    return '';
  }

  private static function find_user_email_by_name($targetName) {
    $targetName = self::clean_text($targetName);
    if (!$targetName) return '';

    $q = new WP_User_Query([
      'number' => 2000,
      'fields' => ['ID','display_name','user_email'],
    ]);

    $users = (array)$q->get_results();
    foreach ($users as $u) {
      if (!empty($u->display_name) && self::names_match($u->display_name, $targetName)) {
        return (string)$u->user_email;
      }
    }
    return '';
  }

  // ---------------- client roster storage ----------------
  private static function normalize_roster_items($items) {
    $out = [];
    $seen = [];
    foreach ((array)$items as $it) {
      if (!is_array($it)) continue;
      $name = self::clean_text($it['name'] ?? '');
      if (!$name) continue;
      $key = strtolower($name);
      if (isset($seen[$key])) continue;
      $seen[$key] = true;
      $out[] = [
        'name' => $name,
        'profile' => esc_url_raw(self::clean_text($it['profile'] ?? '')),
        'email' => self::clean_text($it['email'] ?? ''),
        'dept' => self::clean_text($it['dept'] ?? ''),
      ];
    }
    usort($out, fn($a,$b)=>strcmp(strtolower($a['name']), strtolower($b['name'])));
    return $out;
  }

  private static function get_client_vts($uid) {
    $vts = get_user_meta($uid, 'vtm_vts', true);
    if (!is_array($vts)) $vts = [];
    return self::normalize_roster_items($vts);
  }

  private static function set_client_vts($uid, $items) {
    $items = self::normalize_roster_items($items);
    update_user_meta($uid, 'vtm_vts', $items);
    update_user_meta($uid, 'vtm_vts_updated_at', current_time('mysql'));
    return $items;
  }

  // ---------------- assignments ----------------
  private static function assignment_to_array($post_id) {
    $p = get_post($post_id);
    if (!$p) return null;
    $m = function($k) use ($post_id) { return get_post_meta($post_id, $k, true); };

    return [
      'id' => (int)$post_id,
      'title' => $p->post_title,
      'brief' => $m('_vtm_brief'),
      'steps' => $m('_vtm_steps'),
      'start' => $m('_vtm_start'),
      'due' => $m('_vtm_due'),
      'status' => $m('_vtm_status'),
      'client_name' => $m('_vtm_client_name'),
      'client_id' => (int)$m('_vtm_client_id'),
      'vt_names' => (array)$m('_vtm_vt_names'),
      'csm_usernames' => (array)$m('_vtm_csm_usernames'),
      'files' => (array)$m('_vtm_files'),
      'csm_files' => (array)$m('_vtm_csm_files'),
      'activity' => (array)$m('_vtm_activity'),
      'messages' => (array)$m('_vtm_messages'),
      'vt_accept' => (array)$m('_vtm_vt_accept'),
      'vt_deliveries' => (array)$m('_vtm_vt_deliveries'),
    ];
  }

  private static function assignment_visible_to_csm($a, $me_login) {
    $me_login = strtolower((string)$me_login);
    if (!$me_login) return false;
    $stored = array_map('strtolower', (array)($a['csm_usernames'] ?? []));
    return ($stored && in_array($me_login, $stored, true));
  }

  private static function current_vt_aliases_from_sheet() {
    $me = self::get_user_label();
    if (!$me) return [];
    $map = self::fetch_csm_map();
    if (empty($map['rows'])) return [self::clean_text($me)];

    $aliases = [];
    foreach ($map['rows'] as $r) {
      if (!empty($r['vt']) && self::names_match($me, $r['vt'])) {
        $aliases[] = self::clean_text($r['vt']);
      }
    }
    $aliases[] = self::clean_text($me);
    $aliases = array_values(array_unique(array_filter($aliases)));
    return $aliases;
  }

  private static function assignment_matches_current_vt($assignment) {
    $aliases = self::current_vt_aliases_from_sheet();
    if (!$aliases) return false;
    $vt_names = (array)($assignment['vt_names'] ?? []);
    if (!$vt_names) return false;

    foreach ($vt_names as $assigned) {
      foreach ($aliases as $meAlias) {
        if (self::names_match($assigned, $meAlias)) return true;
      }
    }
    return false;
  }

  private static function list_assignments_for_role($role, $uid) {
    $q = new WP_Query([
      'post_type' => self::CPT,
      'post_status' => 'publish',
      'posts_per_page' => 800,
      'orderby' => 'date',
      'order' => 'DESC',
      'meta_query' => [
        ['key' => '_vtm_status', 'compare' => 'EXISTS'],
      ],
    ]);

    $out = [];
    $me_login = self::user_login_lower();

    foreach ($q->posts as $p) {
      $a = self::assignment_to_array($p->ID);
      if (!$a) continue;

      if ((string)$a['status'] === 'deleted') continue;

      if ($role === 'client') {
        if ((int)$a['client_id'] !== (int)$uid) continue;
        $out[] = $a;
        continue;
      }

      if ($role === 'csm') {
        if (self::assignment_visible_to_csm($a, $me_login) || current_user_can('manage_options')) $out[] = $a;
        continue;
      }

      if ($role === 'vt') {
        $allowed = ['approved_for_vt','in_progress','delivered','completed'];
        if (!in_array((string)$a['status'], $allowed, true)) continue;
        if (self::assignment_matches_current_vt($a) || current_user_can('manage_options')) $out[] = $a;
        continue;
      }
    }

    return $out;
  }

  // ---------------- AJAX: State ----------------
  public static function ajax_state() {
    self::require_logged_in();
    self::verify_nonce();

    $forced = isset($_POST['forced_role']) ? self::clean_text($_POST['forced_role']) : 'auto';
    $real   = self::current_role_real();

    if (!self::role_allowed_for_shortcode($forced, $real)) {
      wp_send_json_success([
        'ok' => false,
        'forced_role' => $forced,
        'real_role' => $real,
        'message' => 'You don’t have access to this portal.',
      ]);
    }

    $role = ($forced && strtolower($forced) !== 'auto') ? strtolower($forced) : $real;
    $uid  = get_current_user_id();

    $client_vts = ($role === 'client') ? self::get_client_vts($uid) : [];
    $assignments = self::list_assignments_for_role($role, $uid);

    $u = wp_get_current_user();
    wp_send_json_success([
      'ok' => true,
      'role' => $role,
      'forced_role' => $forced,
      'real_role' => $real,
      'today' => self::today_ymd(),
      'user' => [
        'id' => $uid,
        'name' => $u->display_name,
        'email' => $u->user_email,
        'login' => $u->user_login,
        'can_admin' => current_user_can('manage_options'),
      ],
      'client_vts' => $client_vts,
      'vt_count' => count($client_vts),
      'assignments' => $assignments,
    ]);
  }

  public static function ajax_save_roster() {
    self::require_logged_in();
    self::verify_nonce();

    $uid = get_current_user_id();
    $items = isset($_POST['items']) ? json_decode(wp_unslash($_POST['items']), true) : [];
    if (!is_array($items)) $items = [];

    $saved = self::set_client_vts($uid, $items);

    wp_send_json_success([
      'count' => count($saved),
      'items' => $saved,
    ]);
  }

  // ---------------- AJAX: Client create assignment ----------------
public static function ajax_client_create() {
    try {
        $debug = [];
        $debug[] = '=== VTM CREATE START ===';
        
        self::require_logged_in();
        self::verify_nonce();

        $forced = isset($_POST['forced_role']) ? self::clean_text($_POST['forced_role']) : 'auto';
        $real   = self::current_role_real();
        
        $debug[] = 'forced_role=' . $forced . ', real_role=' . $real;
        
        if (!self::role_allowed_for_shortcode($forced, $real)) {
            $debug[] = 'Role not allowed';
            wp_send_json_error(['message' => 'Not allowed.', 'debug' => $debug], 403);
        }

    $uid = get_current_user_id();
    $client_name = self::get_user_label();

    $title = self::clean_text($_POST['title'] ?? '');
    $brief = self::clean_text($_POST['brief'] ?? '');
    $steps = self::clean_text($_POST['steps'] ?? '');
    $start = self::clean_text($_POST['start'] ?? '');
    $due   = self::clean_text($_POST['due'] ?? '');

    $debug[] = 'Title=' . $title . ', Due=' . $due;

    // Validation
    if (!$title) wp_send_json_error(['message' => 'Assignment name is required.', 'debug' => $debug], 400);
    if (!$due)   wp_send_json_error(['message' => 'Due date is required.', 'debug' => $debug], 400);

    if ($start && !self::is_ymd($start)) wp_send_json_error(['message'=>'Start date must be YYYY-MM-DD.', 'debug' => $debug], 400);
    if ($due   && !self::is_ymd($due))   wp_send_json_error(['message'=>'Due date must be YYYY-MM-DD.', 'debug' => $debug], 400);

    if ($start && !self::ymd_gte_today($start)) wp_send_json_error(['message'=>'Start date cannot be before today.', 'debug' => $debug], 400);
    if ($due   && !self::ymd_gte_today($due))   wp_send_json_error(['message'=>'Due date cannot be before today.', 'debug' => $debug], 400);
    if ($start && $due && self::is_ymd($start) && self::is_ymd($due) && $due < $start) {
      wp_send_json_error(['message'=>'Due date cannot be earlier than the start date.', 'debug' => $debug], 400);
    }

    $vt_names = isset($_POST['vt_names']) ? json_decode(wp_unslash($_POST['vt_names']), true) : [];
    if (!is_array($vt_names)) $vt_names = [];
    $vt_names = array_values(array_filter(array_map([__CLASS__, 'clean_text'], $vt_names)));
    
    $debug[] = 'VT Names=' . implode(', ', $vt_names);
    
    if (count($vt_names) < 1) wp_send_json_error(['message' => 'Select at least one Virtual Teammate.', 'debug' => $debug], 400);

    // Upload files
    $files = [];
    if (!empty($_FILES['files']) && is_array($_FILES['files']['name'])) {
      require_once ABSPATH . 'wp-admin/includes/file.php';
      require_once ABSPATH . 'wp-admin/includes/media.php';
      require_once ABSPATH . 'wp-admin/includes/image.php';

      $count = count($_FILES['files']['name']);
      for ($i=0; $i<$count; $i++) {
        if (empty($_FILES['files']['name'][$i])) continue;
        $file = [
          'name' => $_FILES['files']['name'][$i],
          'type' => $_FILES['files']['type'][$i],
          'tmp_name' => $_FILES['files']['tmp_name'][$i],
          'error' => $_FILES['files']['error'][$i],
          'size' => $_FILES['files']['size'][$i],
        ];
        $moved = wp_handle_upload($file, ['test_form' => false]);
        if (!empty($moved['url'])) {
          $files[] = [
            'name' => basename($moved['file']),
            'url' => esc_url_raw($moved['url']),
          ];
        }
      }
    }

    $debug[] = 'Files uploaded=' . count($files);

    // Route to CSM(s) - VT name only
    $csm_usernames = [];
    $map = self::fetch_csm_map();

    $debug[] = 'Sheet rows=' . (isset($map['rows']) ? count($map['rows']) : 0);
    $debug[] = 'Sheet fetch OK=' . ($map['ok'] ? 'yes' : 'no');

    if (!empty($map['rows'])) {
      foreach ($vt_names as $vt) {
        foreach ($map['rows'] as $r) {
          if (self::names_match($r['vt'], $vt)) {
            $csmUser = strtolower((string)($r['csm_username'] ?? ''));
            if ($csmUser) {
              $csm_usernames[] = $csmUser;
              $debug[] = 'Matched VT "' . $vt . '" -> CSM "' . $csmUser . '"';
              break;
            }
          }
        }
      }
    }

    $csm_usernames = array_values(array_unique(array_filter($csm_usernames)));

    $debug[] = 'Final CSM usernames=' . implode(', ', $csm_usernames);

    if (!$csm_usernames) {
      $debug[] = 'ROUTING FAILED - No CSM found for VTs: ' . implode(', ', $vt_names);
      wp_send_json_error(['message' => 'Could not route to CSM. VT "' . implode('", "', $vt_names) . '" not found in sheet.', 'debug' => $debug], 400);
    }

    // Remove Elementor hooks
    remove_all_actions('save_post');

    $debug[] = 'Creating post...';

    $post_id = wp_insert_post([
      'post_type' => self::CPT,
      'post_status' => 'publish',
      'post_title' => $title,
      'post_content' => '',
      'post_author' => $uid,
    ], true);

    if (is_wp_error($post_id)) {
      $debug[] = 'POST CREATION FAILED - ' . $post_id->get_error_message();
      wp_send_json_error(['message' => 'Could not submit the assignment.', 'debug' => $debug], 500);
    }

    $debug[] = 'Post created ID=' . $post_id;

    update_post_meta($post_id, '_vtm_client_id', $uid);
    update_post_meta($post_id, '_vtm_client_name', $client_name);
    update_post_meta($post_id, '_vtm_brief', $brief);
    update_post_meta($post_id, '_vtm_steps', $steps);
    update_post_meta($post_id, '_vtm_start', $start);
    update_post_meta($post_id, '_vtm_due', $due);
    update_post_meta($post_id, '_vtm_vt_names', $vt_names);
    update_post_meta($post_id, '_vtm_csm_usernames', $csm_usernames);
    update_post_meta($post_id, '_vtm_files', $files);
    update_post_meta($post_id, '_vtm_csm_files', []);
    update_post_meta($post_id, '_vtm_status', 'pending_csm');
    update_post_meta($post_id, '_vtm_messages', []);

    $activity = [[
      'ts' => current_time('mysql'),
      'by' => $client_name,
      'type' => 'Submitted',
      'note' => 'Submitted for review.',
      'urgent' => 0,
    ]];
    update_post_meta($post_id, '_vtm_activity', $activity);

    $debug[] = 'Metadata saved, sending emails...';

    // Email CSM(s)
    foreach ($csm_usernames as $uLogin) {
      $email = self::email_for_username($uLogin);
      if ($email) {
        self::send_mail(
          $email,
          'New Assignment to Review: ' . $title,
          '<p><strong>New assignment submitted.</strong></p>'
            . '<p><strong>Client:</strong> ' . esc_html($client_name) . '</p>'
            . '<p><strong>Assignment:</strong> ' . esc_html($title) . '</p>'
            . '<p><strong>Start:</strong> ' . esc_html($start ?: '—') . '</p>'
            . '<p><strong>Due:</strong> ' . esc_html($due) . '</p>'
            . '<p><strong>Brief:</strong><br>' . nl2br(esc_html($brief)) . '</p>'
            . '<p>Open your CSM portal to review.</p>'
        );
        $debug[] = 'Email sent to ' . $email;
      }
    }

   $debug[] = 'SUCCESS - Returning assignment';
    wp_send_json_success([
        'assignment' => self::assignment_to_array($post_id),
        'debug' => $debug
    ]);
    
    } catch (Exception $e) {
        error_log("VTM EXCEPTION: " . $e->getMessage() . " in " . $e->getFile() . " line " . $e->getLine());
        wp_send_json_error([
            'message' => 'Server error: ' . $e->getMessage(),
            'debug' => isset($debug) ? $debug : [],
            'exception' => $e->getFile() . ':' . $e->getLine()
        ], 500);
    } catch (Error $e) {
        error_log("VTM FATAL: " . $e->getMessage() . " in " . $e->getFile() . " line " . $e->getLine());
        wp_send_json_error([
            'message' => 'Fatal error: ' . $e->getMessage(),
            'debug' => isset($debug) ? $debug : [],
            'exception' => $e->getFile() . ':' . $e->getLine()
        ], 500);
    }
  }
  // ---------------- AJAX: Client update (edit/extend/cancel/delete) ----------------
  public static function ajax_client_update() {
    self::require_logged_in();
    self::verify_nonce();

    $forced = isset($_POST['forced_role']) ? self::clean_text($_POST['forced_role']) : 'auto';
    $real = self::current_role_real();
    if (!self::role_allowed_for_shortcode($forced, $real)) wp_send_json_error(['message' => 'Not allowed.'], 403);

    $role = ($forced && strtolower($forced) !== 'auto') ? strtolower($forced) : $real;
    if ($role !== 'client') wp_send_json_error(['message' => 'Only clients can update assignments.'], 403);

    $uid = get_current_user_id();
    $client_name = self::get_user_label();

    $id = (int)($_POST['id'] ?? 0);
    $action = self::clean_text($_POST['update_action'] ?? '');
    if (!$id || !$action) wp_send_json_error(['message' => 'Missing request.'], 400);

    $a = self::assignment_to_array($id);
    if (!$a || (int)$a['client_id'] !== (int)$uid) wp_send_json_error(['message' => 'Not allowed.'], 403);

    $activity = is_array($a['activity']) ? $a['activity'] : [];

    if ($action === 'cancel') {
      update_post_meta($id, '_vtm_status', 'cancelled');
      $activity[] = ['ts'=>current_time('mysql'),'by'=>$client_name,'type'=>'Cancelled','note'=>'Cancelled.','urgent'=>1];

    } elseif ($action === 'delete') {
      update_post_meta($id, '_vtm_status', 'deleted');
      $activity[] = ['ts'=>current_time('mysql'),'by'=>$client_name,'type'=>'Deleted','note'=>'Deleted.','urgent'=>1];

    } elseif ($action === 'extend') {
      $new_due = self::clean_text($_POST['due'] ?? '');
      if (!$new_due) wp_send_json_error(['message' => 'New due date is required.'], 400);
      if (!self::is_ymd($new_due)) wp_send_json_error(['message'=>'Due date must be YYYY-MM-DD.'], 400);
      if (!self::ymd_gte_today($new_due)) wp_send_json_error(['message' => 'Due date cannot be before today.'], 400);

      $start_saved = self::clean_text((string)get_post_meta($id, '_vtm_start', true));
      if ($start_saved && self::is_ymd($start_saved) && $new_due < $start_saved) {
        wp_send_json_error(['message'=>'Due date cannot be earlier than the start date.'], 400);
      }

      update_post_meta($id, '_vtm_due', $new_due);
      update_post_meta($id, '_vtm_status', 'pending_csm');
      $activity[] = ['ts'=>current_time('mysql'),'by'=>$client_name,'type'=>'Deadline updated','note'=>'Deadline updated.','urgent'=>0];

    } elseif ($action === 'edit') {
      $title = self::clean_text($_POST['title'] ?? '');
      $brief = self::clean_text($_POST['brief'] ?? '');
      $steps = self::clean_text($_POST['steps'] ?? '');
      $start = self::clean_text($_POST['start'] ?? '');
      $due   = self::clean_text($_POST['due'] ?? '');

      if ($start && !self::is_ymd($start)) wp_send_json_error(['message'=>'Start date must be YYYY-MM-DD.'], 400);
      if ($due   && !self::is_ymd($due))   wp_send_json_error(['message'=>'Due date must be YYYY-MM-DD.'], 400);
      if ($start && !self::ymd_gte_today($start)) wp_send_json_error(['message'=>'Start date cannot be before today.'], 400);
      if ($due   && !self::ymd_gte_today($due))   wp_send_json_error(['message'=>'Due date cannot be before today.'], 400);
      if ($start && $due && self::is_ymd($start) && self::is_ymd($due) && $due < $start) {
        wp_send_json_error(['message'=>'Due date cannot be earlier than the start date.'], 400);
      }

      if ($title) wp_update_post(['ID'=>$id,'post_title'=>$title]);
      update_post_meta($id, '_vtm_brief', $brief);
      update_post_meta($id, '_vtm_steps', $steps);
      update_post_meta($id, '_vtm_start', $start);
      if ($due) update_post_meta($id, '_vtm_due', $due);

      update_post_meta($id, '_vtm_status', 'pending_csm');
      $activity[] = ['ts'=>current_time('mysql'),'by'=>$client_name,'type'=>'Updated','note'=>'Updated and re-submitted for review.','urgent'=>0];

    } else {
      wp_send_json_error(['message' => 'Unknown action.'], 400);
    }

    update_post_meta($id, '_vtm_activity', $activity);

    // Notify CSM(s)
    $csm_usernames = array_map('strtolower', (array)$a['csm_usernames']);
    $csm_usernames = array_values(array_unique(array_filter($csm_usernames)));
    foreach ($csm_usernames as $uLogin) {
      $email = self::email_for_username($uLogin);
      if ($email) {
        self::send_mail(
          $email,
          'Assignment Updated: ' . ($a['title'] ?? ''),
          '<p><strong>Assignment updated by client.</strong></p>'
            . '<p><strong>Client:</strong> ' . esc_html($client_name) . '</p>'
            . '<p><strong>Assignment:</strong> ' . esc_html($a['title'] ?? '') . '</p>'
            . '<p><strong>Update:</strong> ' . esc_html(ucfirst($action)) . '</p>'
        );
      }
    }

    wp_send_json_success(['assignment' => self::assignment_to_array($id)]);
  }

  // ---------------- AJAX: CSM actions (approve/decline/revision/approve_extension) ----------------
  public static function ajax_csm_action() {
    self::require_logged_in();
    self::verify_nonce();

    $forced = isset($_POST['forced_role']) ? self::clean_text($_POST['forced_role']) : 'auto';
    $real = self::current_role_real();
    if (!self::role_allowed_for_shortcode($forced, $real)) wp_send_json_error(['message' => 'Not allowed.'], 403);

    $role = ($forced && strtolower($forced) !== 'auto') ? strtolower($forced) : $real;
    if ($role !== 'csm') wp_send_json_error(['message' => 'Only CSMs can take this action.'], 403);

    $id = (int)($_POST['id'] ?? 0);

    // Compatibility: accept both csm_action and action
    $action = self::clean_text($_POST['csm_action'] ?? ($_POST['action'] ?? ''));
    $note   = self::clean_text($_POST['note'] ?? '');
    $new_due = self::clean_text($_POST['new_due'] ?? ($_POST['due'] ?? ''));

    if (!$id || !$action) wp_send_json_error(['message' => 'Missing request.'], 400);

    // Compatibility: request_update maps to revision
    if ($action === 'request_update') $action = 'revision';

    $a = self::assignment_to_array($id);
    if (!$a) wp_send_json_error(['message' => 'Assignment not found.'], 404);

    $me_login = self::user_login_lower();
    if (!current_user_can('manage_options') && !self::assignment_visible_to_csm($a, $me_login)) {
      wp_send_json_error(['message' => 'Not assigned to you.'], 403);
    }

    $by = self::get_user_label();
    $activity = is_array($a['activity']) ? $a['activity'] : [];

    // Optional CSM file upload
    $csm_files = is_array($a['csm_files']) ? $a['csm_files'] : [];
    if (!empty($_FILES['files']) && is_array($_FILES['files']['name'])) {
      require_once ABSPATH . 'wp-admin/includes/file.php';
      require_once ABSPATH . 'wp-admin/includes/media.php';
      require_once ABSPATH . 'wp-admin/includes/image.php';

      $count = count($_FILES['files']['name']);
      for ($i=0; $i<$count; $i++) {
        if (empty($_FILES['files']['name'][$i])) continue;
        $file = [
          'name' => $_FILES['files']['name'][$i],
          'type' => $_FILES['files']['type'][$i],
          'tmp_name' => $_FILES['files']['tmp_name'][$i],
          'error' => $_FILES['files']['error'][$i],
          'size' => $_FILES['files']['size'][$i],
        ];
        $moved = wp_handle_upload($file, ['test_form' => false]);
        if (!empty($moved['url'])) {
          $csm_files[] = ['name' => basename($moved['file']), 'url' => esc_url_raw($moved['url'])];
        }
      }
      update_post_meta($id, '_vtm_csm_files', $csm_files);
    }

    $client_user = get_user_by('id', (int)$a['client_id']);

    if ($action === 'approve') {
      update_post_meta($id, '_vtm_status', 'approved_for_vt');
      $activity[] = ['ts'=>current_time('mysql'),'by'=>$by,'type'=>'Approved','note'=>($note ?: 'Approved.'),'urgent'=>0];

      if ($client_user) {
        self::send_mail($client_user->user_email, 'Assignment Approved: ' . ($a['title'] ?? ''), '<p>Your assignment was approved.</p><p><strong>' . esc_html($a['title'] ?? '') . '</strong></p>');
      }

      foreach ((array)$a['vt_names'] as $vtName) {
        $vtEmail = self::find_user_email_by_name($vtName);
        if ($vtEmail) {
          self::send_mail(
            $vtEmail,
            'New Assignment: ' . ($a['title'] ?? ''),
            '<p><strong>Client:</strong> ' . esc_html($a['client_name'] ?? '') . '</p>'
              . '<p><strong>Assignment:</strong> ' . esc_html($a['title'] ?? '') . '</p>'
              . '<p><strong>Start:</strong> ' . esc_html($a['start'] ?: '—') . '</p>'
              . '<p><strong>Due:</strong> ' . esc_html($a['due'] ?? '') . '</p>'
              . '<p><strong>Brief:</strong><br>' . nl2br(esc_html($a['brief'] ?? '')) . '</p>'
              . '<p>Open your VT portal to accept or decline.</p>'
          );
        }
      }

    } elseif ($action === 'decline') {
      update_post_meta($id, '_vtm_status', 'declined');
      $activity[] = ['ts'=>current_time('mysql'),'by'=>$by,'type'=>'Declined','note'=>($note ?: 'Declined.'),'urgent'=>1];

      if ($client_user) {
        self::send_mail($client_user->user_email, 'Assignment Declined: ' . ($a['title'] ?? ''), '<p>Your assignment was declined.</p><p>' . nl2br(esc_html($note)) . '</p>');
      }

    } elseif ($action === 'revision') {
      update_post_meta($id, '_vtm_status', 'needs_revision');
      $activity[] = ['ts'=>current_time('mysql'),'by'=>$by,'type'=>'Update requested','note'=>($note ?: 'Update requested.'),'urgent'=>1];

      if ($client_user) {
        self::send_mail($client_user->user_email, 'Update Requested: ' . ($a['title'] ?? ''), '<p>Please review the request and update your submission.</p><p>' . nl2br(esc_html($note)) . '</p>');
      }

    } elseif ($action === 'approve_extension') {
      if (!$new_due) wp_send_json_error(['message' => 'New due date is required.'], 400);
      if (!self::is_ymd($new_due)) wp_send_json_error(['message'=>'Due date must be YYYY-MM-DD.'], 400);
      if (!self::ymd_gte_today($new_due)) wp_send_json_error(['message' => 'Due date cannot be before today.'], 400);

      $start_saved = self::clean_text((string)get_post_meta($id, '_vtm_start', true));
      if ($start_saved && self::is_ymd($start_saved) && $new_due < $start_saved) {
        wp_send_json_error(['message'=>'Due date cannot be earlier than the start date.'], 400);
      }

      update_post_meta($id, '_vtm_due', $new_due);
      update_post_meta($id, '_vtm_status', 'in_progress');
      $activity[] = ['ts'=>current_time('mysql'),'by'=>$by,'type'=>'Extension approved','note'=>('Extension approved. New due date: ' . $new_due),'urgent'=>0];

      if ($client_user) self::send_mail($client_user->user_email, 'Extension Approved: ' . ($a['title'] ?? ''), '<p>Extension approved. New due date: <strong>' . esc_html($new_due) . '</strong></p>');

    } else {
      wp_send_json_error(['message' => 'Unknown action.'], 400);
    }

    update_post_meta($id, '_vtm_activity', $activity);

    wp_send_json_success(['assignment' => self::assignment_to_array($id)]);
  }

  // ---------------- AJAX: VT actions ----------------
  public static function ajax_vt_action() {
    self::require_logged_in();
    self::verify_nonce();

    $forced = isset($_POST['forced_role']) ? self::clean_text($_POST['forced_role']) : 'auto';
    $real = self::current_role_real();
    if (!self::role_allowed_for_shortcode($forced, $real)) wp_send_json_error(['message' => 'Not allowed.'], 403);

    $role = ($forced && strtolower($forced) !== 'auto') ? strtolower($forced) : $real;
    if ($role !== 'vt') wp_send_json_error(['message' => 'Only VTs can take this action.'], 403);

    $id = (int)($_POST['id'] ?? 0);

    // Compatibility: accept both vt_action and action
    $action = self::clean_text($_POST['vt_action'] ?? ($_POST['action'] ?? ''));
    $note   = self::clean_text($_POST['note'] ?? '');

    // Compatibility: cancel_request maps to request_cancel
    if ($action === 'cancel_request') $action = 'request_cancel';

    if (!$id || !$action) wp_send_json_error(['message' => 'Missing request.'], 400);

    $a = self::assignment_to_array($id);
    if (!$a) wp_send_json_error(['message' => 'Assignment not found.'], 404);

    if (!self::assignment_matches_current_vt($a) && !current_user_can('manage_options')) {
      wp_send_json_error(['message' => 'Not assigned to you.'], 403);
    }

    $allowed = ['approved_for_vt','in_progress','delivered','completed'];
    if (!in_array((string)$a['status'], $allowed, true)) wp_send_json_error(['message' => 'This assignment is not ready yet.'], 400);

    $by = self::get_user_label();
    $activity = is_array($a['activity']) ? $a['activity'] : [];
    $vt_accept = is_array($a['vt_accept']) ? $a['vt_accept'] : [];
    $vt_deliveries = is_array($a['vt_deliveries']) ? $a['vt_deliveries'] : [];

    $aliases = self::current_vt_aliases_from_sheet();
    $meKey = $aliases ? $aliases[0] : $by;

    $client_user = get_user_by('id', (int)$a['client_id']);
    $csm_usernames = array_map('strtolower', (array)($a['csm_usernames'] ?? []));
    $csm_usernames = array_values(array_unique(array_filter($csm_usernames)));

    if ($action === 'accept') {
      $vt_accept[self::clean_text($meKey)] = ['status'=>'accepted','note'=>$note,'ts'=>current_time('mysql')];
      update_post_meta($id, '_vtm_status', 'in_progress');
      $activity[] = ['ts'=>current_time('mysql'),'by'=>$by,'type'=>'Accepted','note'=>($note ?: 'Accepted.'),'urgent'=>0];

      if ($client_user) self::send_mail($client_user->user_email, 'VT Accepted: ' . ($a['title'] ?? ''), '<p><strong>' . esc_html($by) . '</strong> accepted the assignment.</p><p><strong>Assignment:</strong> ' . esc_html($a['title'] ?? '') . '</p>');
      foreach ($csm_usernames as $csmLogin) {
        $csmEmail = self::email_for_username($csmLogin);
        if ($csmEmail) self::send_mail($csmEmail, 'VT Accepted: ' . ($a['title'] ?? ''), '<p><strong>' . esc_html($by) . '</strong> accepted the assignment.</p><p><strong>Assignment:</strong> ' . esc_html($a['title'] ?? '') . '</p>');
      }

    } elseif ($action === 'decline') {
      $vt_accept[self::clean_text($meKey)] = ['status'=>'declined','note'=>$note,'ts'=>current_time('mysql')];
      $activity[] = ['ts'=>current_time('mysql'),'by'=>$by,'type'=>'Declined','note'=>($note ?: 'Declined.'),'urgent'=>1];

      if ($client_user) self::send_mail($client_user->user_email, 'VT Declined: ' . ($a['title'] ?? ''), '<p><strong>' . esc_html($by) . '</strong> declined the assignment.</p><p><strong>Reason:</strong><br>' . nl2br(esc_html($note)) . '</p>');
      foreach ($csm_usernames as $csmLogin) {
        $csmEmail = self::email_for_username($csmLogin);
        if ($csmEmail) self::send_mail($csmEmail, 'VT Declined: ' . ($a['title'] ?? ''), '<p><strong>' . esc_html($by) . '</strong> declined the assignment.</p><p><strong>Reason:</strong><br>' . nl2br(esc_html($note)) . '</p>');
      }

    } elseif ($action === 'request') {
      $activity[] = ['ts'=>current_time('mysql'),'by'=>$by,'type'=>'Request','note'=>($note ?: 'Sent a request.'),'urgent'=>1];

      if ($client_user) self::send_mail($client_user->user_email, 'VT Request: ' . ($a['title'] ?? ''), '<p><strong>' . esc_html($by) . '</strong> sent a request.</p><p><strong>Assignment:</strong> ' . esc_html($a['title'] ?? '') . '</p><p><strong>Message:</strong><br>' . nl2br(esc_html($note)) . '</p>');
      foreach ($csm_usernames as $csmLogin) {
        $csmEmail = self::email_for_username($csmLogin);
        if ($csmEmail) self::send_mail($csmEmail, 'VT Request: ' . ($a['title'] ?? ''), '<p><strong>' . esc_html($by) . '</strong> sent a request.</p><p><strong>Assignment:</strong> ' . esc_html($a['title'] ?? '') . '</p><p><strong>Message:</strong><br>' . nl2br(esc_html($note)) . '</p>');
      }

    } elseif ($action === 'request_extension') {
      $activity[] = ['ts'=>current_time('mysql'),'by'=>$by,'type'=>'Extension requested','note'=>($note ?: 'Requested a due-date extension.'),'urgent'=>1];

      if ($client_user) self::send_mail($client_user->user_email, 'VT Extension Request: ' . ($a['title'] ?? ''), '<p><strong>' . esc_html($by) . '</strong> requested an extension.</p><p><strong>Assignment:</strong> ' . esc_html($a['title'] ?? '') . '</p><p><strong>Message:</strong><br>' . nl2br(esc_html($note)) . '</p>');
      foreach ($csm_usernames as $csmLogin) {
        $csmEmail = self::email_for_username($csmLogin);
        if ($csmEmail) self::send_mail($csmEmail, 'VT Extension Request: ' . ($a['title'] ?? ''), '<p><strong>' . esc_html($by) . '</strong> requested an extension.</p><p><strong>Assignment:</strong> ' . esc_html($a['title'] ?? '') . '</p><p><strong>Message:</strong><br>' . nl2br(esc_html($note)) . '</p>');
      }

    } elseif ($action === 'request_cancel') {
      $activity[] = ['ts'=>current_time('mysql'),'by'=>$by,'type'=>'Cancel requested','note'=>($note ?: 'Requested to cancel this assignment.'),'urgent'=>1];

      if ($client_user) self::send_mail($client_user->user_email, 'VT Cancel Request: ' . ($a['title'] ?? ''), '<p><strong>' . esc_html($by) . '</strong> requested cancellation.</p><p><strong>Assignment:</strong> ' . esc_html($a['title'] ?? '') . '</p><p><strong>Message:</strong><br>' . nl2br(esc_html($note)) . '</p>');
      foreach ($csm_usernames as $csmLogin) {
        $csmEmail = self::email_for_username($csmLogin);
        if ($csmEmail) self::send_mail($csmEmail, 'VT Cancel Request: ' . ($a['title'] ?? ''), '<p><strong>' . esc_html($by) . '</strong> requested cancellation.</p><p><strong>Assignment:</strong> ' . esc_html($a['title'] ?? '') . '</p><p><strong>Message:</strong><br>' . nl2br(esc_html($note)) . '</p>');
      }

    } elseif ($action === 'deliver') {
      $deliver_files = [];
      if (!empty($_FILES['files']) && is_array($_FILES['files']['name'])) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $count = count($_FILES['files']['name']);
        for ($i=0; $i<$count; $i++) {
          if (empty($_FILES['files']['name'][$i])) continue;
          $file = [
            'name' => $_FILES['files']['name'][$i],
            'type' => $_FILES['files']['type'][$i],
            'tmp_name' => $_FILES['files']['tmp_name'][$i],
            'error' => $_FILES['files']['error'][$i],
            'size' => $_FILES['files']['size'][$i],
          ];
          $moved = wp_handle_upload($file, ['test_form' => false]);
          if (!empty($moved['url'])) {
            $deliver_files[] = ['name' => basename($moved['file']), 'url' => esc_url_raw($moved['url'])];
          }
        }
      }

      $vt_deliveries[] = [
        'vt' => $meKey,
        'ts' => current_time('mysql'),
        'note' => $note,
        'files' => $deliver_files,
        'status' => 'submitted',
      ];

      update_post_meta($id, '_vtm_status', 'delivered');
      $activity[] = ['ts'=>current_time('mysql'),'by'=>$by,'type'=>'Submitted','note'=>($note ?: 'Submitted files.'),'urgent'=>0];

      if ($client_user) self::send_mail($client_user->user_email, 'Submission Received: ' . ($a['title'] ?? ''), '<p>A submission was received for:</p><p><strong>' . esc_html($a['title'] ?? '') . '</strong></p><p><strong>From:</strong> ' . esc_html($by) . '</p>');
      foreach ($csm_usernames as $csmLogin) {
        $csmEmail = self::email_for_username($csmLogin);
        if ($csmEmail) self::send_mail($csmEmail, 'Submission Ready to Review: ' . ($a['title'] ?? ''), '<p>A submission is ready for review.</p><p><strong>' . esc_html($a['title'] ?? '') . '</strong></p><p><strong>From:</strong> ' . esc_html($by) . '</p>');
      }

    } else {
      wp_send_json_error(['message' => 'Unknown action.'], 400);
    }

    update_post_meta($id, '_vtm_activity', $activity);
    update_post_meta($id, '_vtm_vt_accept', $vt_accept);
    update_post_meta($id, '_vtm_vt_deliveries', $vt_deliveries);

    wp_send_json_success(['assignment' => self::assignment_to_array($id)]);
  }

  // ---------------- AJAX: Send inline message (reply) ----------------
  public static function ajax_send_message() {
    self::require_logged_in();
    self::verify_nonce();

    $forced = isset($_POST['forced_role']) ? self::clean_text($_POST['forced_role']) : 'auto';
    $real = self::current_role_real();
    if (!self::role_allowed_for_shortcode($forced, $real)) wp_send_json_error(['message' => 'Not allowed.'], 403);

    $role = ($forced && strtolower($forced) !== 'auto') ? strtolower($forced) : $real;

    $id = (int)($_POST['id'] ?? 0);
    $text = self::clean_text($_POST['text'] ?? '');
    $target_role = strtolower(self::clean_text($_POST['target_role'] ?? ''));
    $target_login = self::clean_text($_POST['target_login'] ?? '');
    $target_name = self::clean_text($_POST['target_name'] ?? '');

    if (!$id || !$text) wp_send_json_error(['message' => 'Message is required.'], 400);

    $a = self::assignment_to_array($id);
    if (!$a) wp_send_json_error(['message' => 'Assignment not found.'], 404);

    if ($role === 'client' && (int)$a['client_id'] !== (int)get_current_user_id()) wp_send_json_error(['message' => 'Not allowed.'], 403);
    if ($role === 'csm' && !self::assignment_visible_to_csm($a, self::user_login_lower()) && !current_user_can('manage_options')) wp_send_json_error(['message' => 'Not assigned to you.'], 403);
    if ($role === 'vt' && !self::assignment_matches_current_vt($a) && !current_user_can('manage_options')) wp_send_json_error(['message' => 'Not assigned to you.'], 403);

    $by = self::get_user_label();
    $messages = is_array($a['messages']) ? $a['messages'] : [];
    $msg_id = 'm_' . substr(md5(uniqid('', true)), 0, 10);

    $messages[] = [
      'id' => $msg_id,
      'ts' => current_time('mysql'),
      'from_role' => $role,
      'from_name' => $by,
      'to_role' => $target_role,
      'to_login' => $target_login,
      'to_name' => $target_name,
      'text' => $text,
    ];
    update_post_meta($id, '_vtm_messages', $messages);

    // Add to Activity (visible everywhere)
    $activity = is_array($a['activity']) ? $a['activity'] : [];
    $toLabel = $target_name ?: $target_login ?: strtoupper($target_role ?: 'recipient');
    $activity[] = [
      'ts' => current_time('mysql'),
      'by' => $by,
      'type' => 'Reply',
      'note' => 'To ' . $toLabel . ': ' . $text,
      'urgent' => 0,
    ];
    update_post_meta($id, '_vtm_activity', $activity);

    // Email recipient
    $toEmail = '';
    if ($target_role === 'client') {
      $cu = get_user_by('id', (int)$a['client_id']);
      $toEmail = $cu ? (string)$cu->user_email : '';
    } elseif ($target_role === 'csm') {
      $toEmail = self::email_for_username($target_login);
    } elseif ($target_role === 'vt') {
      $toEmail = self::find_user_email_by_name($target_name ?: (($a['vt_names'][0] ?? '')));
    }

    if ($toEmail) {
      self::send_mail(
        $toEmail,
        'New Reply: ' . ($a['title'] ?? ''),
        '<p><strong>' . esc_html($by) . '</strong> replied on:</p>'
          . '<p><strong>' . esc_html($a['title'] ?? '') . '</strong></p>'
          . '<p>' . nl2br(esc_html($text)) . '</p>'
      );
    }

    wp_send_json_success(['assignment' => self::assignment_to_array($id)]);
  }

  // ---------------- AJAX: Delete activity item (VT only own; admin any) ----------------
  public static function ajax_delete_activity() {
    self::require_logged_in();
    self::verify_nonce();

    $forced = isset($_POST['forced_role']) ? self::clean_text($_POST['forced_role']) : 'auto';
    $real = self::current_role_real();
    if (!self::role_allowed_for_shortcode($forced, $real)) wp_send_json_error(['message' => 'Not allowed.'], 403);

    $role = ($forced && strtolower($forced) !== 'auto') ? strtolower($forced) : $real;

    $id = (int)($_POST['id'] ?? 0);
    $ts = self::clean_text($_POST['ts'] ?? '');
    $by = self::clean_text($_POST['by'] ?? '');
    $type = self::clean_text($_POST['type'] ?? '');
    $note = self::clean_text($_POST['note'] ?? '');

    if (!$id || !$ts || !$by || !$type) wp_send_json_error(['message' => 'Missing request.'], 400);

    $a = self::assignment_to_array($id);
    if (!$a) wp_send_json_error(['message' => 'Assignment not found.'], 404);

    // Access to assignment
    if ($role === 'client') {
      if ((int)$a['client_id'] !== (int)get_current_user_id() && !current_user_can('manage_options')) wp_send_json_error(['message' => 'Not allowed.'], 403);
    } elseif ($role === 'csm') {
      if (!self::assignment_visible_to_csm($a, self::user_login_lower()) && !current_user_can('manage_options')) wp_send_json_error(['message' => 'Not allowed.'], 403);
    } elseif ($role === 'vt') {
      if (!self::assignment_matches_current_vt($a) && !current_user_can('manage_options')) wp_send_json_error(['message' => 'Not allowed.'], 403);
    }

    // Only allow:
    // - admin: anything
    // - VT: only their own items
    // Client/CSM cannot delete activity items
    $me = self::get_user_label();
    $is_owner = ($by && $me && trim($by) === trim($me));
    if (!current_user_can('manage_options')) {
      if ($role !== 'vt' || !$is_owner) {
        wp_send_json_error(['message' => 'You can only delete your own messages.'], 403);
      }
    }

    $activity = is_array($a['activity']) ? $a['activity'] : [];
    $new = [];
    $deleted = false;

    foreach ($activity as $ev) {
      $ev_ts = self::clean_text($ev['ts'] ?? '');
      $ev_by = self::clean_text($ev['by'] ?? '');
      $ev_type = self::clean_text($ev['type'] ?? '');
      $ev_note = self::clean_text($ev['note'] ?? '');

      if (!$deleted && $ev_ts === $ts && $ev_by === $by && $ev_type === $type && $ev_note === $note) {
        $deleted = true;
        continue;
      }
      $new[] = $ev;
    }

    if (!$deleted) wp_send_json_error(['message' => 'Message not found.'], 404);

    update_post_meta($id, '_vtm_activity', $new);
    wp_send_json_success(['assignment' => self::assignment_to_array($id)]);
  }

  // ---------------- UI Shortcode ----------------
  public static function shortcode($atts) {
    $atts = shortcode_atts([
      'role' => 'auto',
      'max_width' => '1440',
    ], $atts, 'vtm_portal');

    if (!is_user_logged_in()) {
      return '<div style="padding:16px;border:1px solid #E5E7EB;border-radius:14px;background:#fff;">
        <strong>Sign in required.</strong>
        <div style="margin-top:6px;color:#6B7280;">Please log in to access the portal.</div>
      </div>';
    }

    $forced_role = sanitize_text_field($atts['role']);
    if (!$forced_role) $forced_role = 'auto';

    $nonce = wp_create_nonce(self::NONCE);
    $ajax = admin_url('admin-ajax.php');

    ob_start();
    ?>
    <div class="vtmApp"
      data-forced-role="<?php echo esc_attr($forced_role); ?>"
      data-nonce="<?php echo esc_attr($nonce); ?>"
      data-ajax="<?php echo esc_url($ajax); ?>"
      data-maxw="<?php echo esc_attr((int)$atts['max_width']); ?>"
    ></div>

    <style>
      .vtmApp{
        --p:#7077FF;
        --a:#F6B945;
        --t:#0F172A;
        --m:#64748B;
        --b:#E5E7EB;
        --b2:#EEF2F7;
        --bg:#fff;
        --soft:#F8FAFF;
        --shadow: 0 10px 28px rgba(15,23,42,.10);
        --ring: 0 0 0 3px rgba(112,119,255,.24);
        font-family: ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial;
        color:var(--t);
      }
      .vtmApp *{box-sizing:border-box;}
      .vtmWrap{max-width:min(var(--maxw, 1440px), calc(100vw - 24px)); margin:0 auto; padding:12px 0;}
      .card{background:var(--bg); border:1px solid var(--b); border-radius:18px; box-shadow:var(--shadow); overflow:hidden;}
      .head{padding:14px 16px; border-bottom:1px solid var(--b2); background:linear-gradient(180deg,#fff,var(--soft));}
      .title{font-weight:950; font-size:16px; margin:0;}
      .desc{margin-top:6px; color:var(--m); font-size:13px; line-height:1.45;}
      .mini{color:var(--m); font-size:12px; line-height:1.35; font-weight:850;}
      .body{padding:14px 16px;}
      .shell{display:flex; gap:14px; align-items:stretch;}
      .left{flex:1 1 66%; min-width:0;}
      .right{flex:0 0 420px; width:420px;}
      @media (max-width: 1100px){ .shell{flex-direction:column;} .right{width:auto; flex:1 1 auto;} }

      .strip{display:flex; gap:10px; align-items:center; justify-content:space-between; flex-wrap:wrap; padding:12px 16px; border-bottom:1px solid var(--b2); background:#fff;}
      .meta{display:flex; gap:10px; align-items:center; flex-wrap:wrap;}
      .pill{display:inline-flex; align-items:center; gap:8px; padding:6px 10px; border-radius:999px; border:1px solid var(--b2); background:#fff; font-size:12px; font-weight:950; color:var(--t);}
      .dot{width:8px;height:8px;border-radius:999px;background:var(--p);}
      .dotA{background:var(--a);}

      .btn{
        border-radius:12px;
        border:1px solid var(--b);   /* neutral border only */
        background:#fff;
        padding:10px 12px;
        font-weight:950;
        font-size:13px;
        cursor:pointer;
        transition:transform .15s ease, box-shadow .15s ease, border-color .15s ease, background .15s ease;
      }
      .btn:hover{border-color:rgba(112,119,255,.45);}
      .btn:active{transform:translateY(1px);}
      .btn.primary{background:var(--p); color:#fff; border-color:rgba(112,119,255,.22);}
      .btn.warn{background:rgba(246,185,69,.18); border-color:var(--b);} /* neutral border */
      .btn.danger{background:rgba(239,68,68,.12); border-color:var(--b);} /* neutral border */
      .btn:disabled{opacity:.6; cursor:not-allowed;}
      .btn.success{background: rgba(34,197,94,1);border-color:rgba(34,197,94,.10);color:#fff;} /* near-neutral */
      .btn.dangerSolid{background: rgba(239,68,68,1);border-color:rgba(239,68,68,.10);color:#fff;} /* near-neutral */

      .banner{
        border:1px solid var(--b2);
        border-radius:14px;
        padding:10px 12px;
        background:linear-gradient(180deg,#fff,#F8FAFF);
        margin-top:12px;
      }
      .banner.ok{ background: rgba(34,197,94,.10); }
      .banner.warn{ background: rgba(246,185,69,.16); }
      .banner.bad{ background: rgba(239,68,68,.10); }
      .banner .bT{ font-weight:1000; font-size:13px; }
      .banner .bM{ margin-top:4px; font-size:12px; color:var(--m); line-height:1.35; font-weight:850;}

      /* Neon time label (dark bg so neon green is readable; no borders) */
      .neonTime{
        display:inline-block;
        padding:4px 8px;
        border-radius:10px;
        background:#0B0F0B;
        color:#39FF14;
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
        font-weight:1000;
        font-size:12px;
        line-height:1.2;
        letter-spacing:0.6px;
        white-space:nowrap;
      }

      .grid2{display:grid; grid-template-columns:1fr 1fr; gap:10px;}
      @media (max-width: 760px){ .grid2{grid-template-columns:1fr;} }
      .field{display:flex; flex-direction:column; gap:6px;}
      .label{font-size:12px; font-weight:1000; color:var(--t);}
      .input, .text, .select{
        width:100%;
        border:1px solid var(--b);
        border-radius:12px;
        padding:10px 12px;
        font-size:13px;
        outline:none;
        background:#fff;
      }
      .text{min-height:112px; resize:vertical;}
      .input:focus, .text:focus, .select:focus{box-shadow:var(--ring); border-color:rgba(112,119,255,.55);}

      .tableWrap{border:1px solid var(--b); border-radius:16px; overflow:hidden; background:#fff;}
      table{width:100%; border-collapse:separate; border-spacing:0;}
      thead th{position:sticky; top:0; z-index:2; background:#fff; border-bottom:1px solid var(--b2); text-align:left; font-size:12px; color:var(--m); padding:10px 12px;}
      tbody td{border-bottom:1px solid var(--b2); padding:12px; vertical-align:top; font-size:13px;}
      tbody tr:hover{background:rgba(112,119,255,.04); cursor:pointer;}
      tbody tr.active{background:rgba(112,119,255,.08);}

      .status{display:inline-flex; align-items:center; padding:4px 8px; border-radius:999px; font-size:12px; font-weight:1000; border:1px solid var(--b2); background:#fff;}
      .status.attn{background:rgba(246,185,69,.18);}
      .status.bad{background:rgba(239,68,68,.12);}

      .bar{height:10px; background:rgba(15,23,42,.08); border-radius:999px; overflow:hidden; margin-top:6px;}
      .bar > i{display:block; height:100%; width:0%; background:linear-gradient(90deg, var(--p), rgba(112,119,255,.72)); border-radius:999px;}

      .panel{border:1px solid var(--b2); border-radius:16px; padding:12px; background:#fff;}
      .panel + .panel{margin-top:12px;}
      .blockTitle{font-weight:1000; font-size:13px; margin:0;}
      .hr{height:1px;background:var(--b2); margin:12px 0;}

      .collBtn{
        width:100%;
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:10px;
        padding:10px 12px;
        border:1px solid var(--b2);
        border-radius:14px;
        background:linear-gradient(180deg,#fff,var(--soft));
        cursor:pointer;
        font-weight:1000;
        font-size:13px;
      }
		/* Add these styles to the existing <style> block in the shortcode function: */

.urgent-alert {
  background: rgba(239, 68, 68, 0.15) !important;
  border-color: rgba(239, 68, 68, 0.3) !important;
  color: #991B1B !important;
  animation: urgentPulse 2s ease-in-out infinite;
  box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4);
}

@keyframes urgentPulse {
  0%, 100% {
    box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4);
  }
  50% {
    box-shadow: 0 0 0 8px rgba(239, 68, 68, 0);
  }
}

.urgent-pulse {
  width: 10px;
  height: 10px;
  border-radius: 999px;
  background: #EF4444;
  display: inline-block;
  animation: pulse 1.5s ease-in-out infinite;
  box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7);
}

@keyframes pulse {
  0%, 100% {
    transform: scale(1);
    box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7);
  }
  50% {
    transform: scale(1.1);
    box-shadow: 0 0 0 6px rgba(239, 68, 68, 0);
  }
}

.success-alert {
  background: rgba(34, 197, 94, 0.15) !important;
  border-color: rgba(34, 197, 94, 0.3) !important;
  color: #166534 !important;
  animation: successGlow 3s ease-in-out infinite;
}

@keyframes successGlow {
  0%, 100% {
    box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.3);
  }
  50% {
    box-shadow: 0 0 0 6px rgba(34, 197, 94, 0);
  }
}

.success-pulse {
  width: 10px;
  height: 10px;
  border-radius: 999px;
  background: #22C55E;
  display: inline-block;
  box-shadow: 0 0 8px rgba(34, 197, 94, 0.6);
}
      .collBtn .hint{font-size:11px; color:var(--m); font-weight:900;}
      .collBody{display:none; padding:10px 12px; border:1px solid var(--b2); border-radius:14px; background:#fff; margin-top:8px;}
      .collBody.on{display:block;}
      .preview2{white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:100%; display:block; font-size:12px; color:var(--m); font-weight:850;}

      .act{max-height:320px; overflow:auto; padding-right:6px;}
      .actItem{border:1px solid var(--b2); border-radius:14px; padding:10px 12px; background:#fff;}
      .actItem + .actItem{margin-top:10px;}
      .actItem.urgent{background:rgba(239,68,68,.06);} /* no colored borders */
      .actItem.good{background:rgba(34,197,94,.06);}  /* no colored borders */
      .actTop{display:flex; align-items:flex-start; justify-content:space-between; gap:10px;}
      .actType{font-weight:1000; font-size:13px;}

      .replyIconBtn{
        border:1px solid var(--b2);   /* neutral border */
        background:rgba(112,119,255,.08);
        color:var(--t);
        border-radius:999px;
        padding:6px 10px;
        font-weight:1000;
        font-size:12px;
        cursor:pointer;
      }
      .replyIconBtn:hover{background:rgba(112,119,255,.14);}

      .dangerTag{display:inline-flex;align-items:center;gap:6px;font-weight:1000;font-size:12px;color:#7F1D1D;}
      .dangerTag i{width:8px;height:8px;border-radius:999px;background:#EF4444;display:inline-block;box-shadow:0 0 0 4px rgba(239,68,68,.14);}

      .inlineReply{display:none; margin-top:10px; border-top:1px dashed var(--b2); padding-top:10px;}
      .inlineReply.on{display:block;}
      .inlineReplyRow{display:flex; gap:10px; align-items:flex-end;}
      .inlineReplyRow .text{min-height:72px;}
      @media (max-width: 760px){ .inlineReplyRow{flex-direction:column; align-items:stretch;} }

      /* Toast */
      .toastWrap{position:fixed; left:16px; bottom:16px; z-index:999999; display:flex; flex-direction:column; gap:8px;}
      .toast{background:#0F172A; color:#fff; border-radius:14px; padding:10px 12px; box-shadow:0 18px 48px rgba(0,0,0,.22); min-width:260px; max-width:420px;}
      .toastT{font-weight:1000; font-size:13px;}
      .toastM{font-size:12px; opacity:.92; margin-top:2px; line-height:1.35; font-weight:850;}
      .spin{display:inline-block;width:14px;height:14px;border-radius:999px;border:2px solid rgba(255,255,255,.55);border-top-color:#fff;vertical-align:-2px;animation:spin 0.9s linear infinite;margin-right:8px;}
      @keyframes spin{to{transform:rotate(360deg);}}

      /* Modal overlay */
.vtmPopOverlay {
  display: none;
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 999998;
  background: rgba(0, 0, 0, 0.6);
  backdrop-filter: blur(4px);
  animation: fadeIn 0.2s ease;
}

.vtmPopOverlay.on {
  display: flex;
  align-items: center;
  justify-content: center;
}

/* Modal confirm (centered) */
.vtmPop{
  position: relative;
  z-index: 999999;
  background: #fff;
  border: 1px solid var(--b2);
  border-radius: 16px;
  box-shadow: 0 24px 80px rgba(2, 6, 23, 0.35);
  width: min(420px, calc(100vw - 32px));
  padding: 20px;
  animation: slideUp 0.25s ease;
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

@keyframes slideUp {
  from { 
    opacity: 0;
    transform: translateY(20px) scale(0.95);
  }
  to { 
    opacity: 1;
    transform: translateY(0) scale(1);
  }
}

.vtmPop .t{font-weight:1000;font-size:15px;color:var(--t);}
.vtmPop .m{margin-top:8px;color:var(--m);font-size:13px;line-height:1.5;font-weight:850;}
.vtmPop .f{display:flex;justify-content:flex-end;gap:10px;margin-top:16px;flex-wrap:wrap;}
.vtmPop .row{display:flex;gap:10px;align-items:center;margin-top:12px;}
    </style>

    <script>
    (function(){
      const root = document.querySelector('.vtmApp[data-nonce="<?php echo esc_js($nonce); ?>"]');
      if(!root) return;

      root.style.setProperty('--maxw', (root.dataset.maxw || '1440') + 'px');

      const cfg = {
        forced_role: (root.dataset.forcedRole || 'auto').toLowerCase(),
        nonce: root.dataset.nonce,
        ajax: root.dataset.ajax
      };

      const esc = (s)=>String(s??'').replace(/[&<>"']/g,c=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

      const toast = (t,m)=>{
        let w = document.querySelector('.toastWrap');
        if(!w) w = document.body.appendChild(Object.assign(document.createElement('div'), {className:'toastWrap'}));
        const b = document.createElement('div');
        b.className='toast';
        b.innerHTML = '<div class="toastT">'+esc(t)+'</div>' + (m?('<div class="toastM">'+esc(m)+'</div>'):'');
        w.appendChild(b);
        setTimeout(()=>{ b.style.opacity='0'; b.style.transform='translateY(6px)'; b.style.transition='all .22s ease'; }, 3200);
        setTimeout(()=> b.remove(), 3600);
      };

      const api = async (action, data={}, files=null)=>{
        data = Object.assign({ forced_role: cfg.forced_role }, data);
        let res;
        if(files){
          const fd = new FormData();
          fd.append('action', action);
          fd.append('nonce', cfg.nonce);
          for(const k in data){ fd.append(k, data[k]); }
          for(let i=0;i<files.length;i++){ fd.append('files[]', files[i]); }
          res = await fetch(cfg.ajax, {method:'POST', body: fd, credentials:'same-origin'});
        } else {
          const body = new URLSearchParams();
          body.set('action', action);
          body.set('nonce', cfg.nonce);
          Object.keys(data||{}).forEach(k=> body.set(k, data[k]));
          res = await fetch(cfg.ajax, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body, credentials:'same-origin'});
        }
        return res.json();
      };

     // Centered modal confirm
let popEl = null;
let popOverlay = null;

function hidePop(){
  if(popOverlay){ 
    popOverlay.classList.remove('on');
    setTimeout(() => {
      if(popOverlay) popOverlay.remove();
      popOverlay = null;
    }, 200);
  }
  if(popEl){ popEl = null; }
}

function showConfirm(btn, title, msg, okText='Yes', okClass='primary', extraHTML=''){
  hidePop();
  
  // Create overlay
  popOverlay = document.createElement('div');
  popOverlay.className = 'vtmPopOverlay';
  
  // Create modal
  popEl = document.createElement('div');
  popEl.className = 'vtmPop';
  popEl.innerHTML =
    '<div class="t">'+esc(title||'Confirm')+'</div>' +
    '<div class="m">'+esc(msg||'')+'</div>' +
    (extraHTML || '') +
    '<div class="f">' +
      '<button class="btn" type="button" data-cancel>Cancel</button>' +
      '<button class="btn '+esc(okClass)+'" type="button" data-ok>'+esc(okText||'Yes')+'</button>' +
    '</div>';

  popOverlay.appendChild(popEl);
  document.body.appendChild(popOverlay);
  
  // Trigger animation
  setTimeout(() => popOverlay.classList.add('on'), 10);

  return new Promise((resolve)=>{
    const cleanup = () => {
      document.removeEventListener('keydown', onKey, true);
    };
    
    const onKey = (e)=>{
      if(e.key === 'Escape'){
        hidePop();
        cleanup();
        resolve(false);
      }
    };

    // Click overlay to close
    popOverlay.addEventListener('click', (e) => {
      if(e.target === popOverlay){
        hidePop();
        cleanup();
        resolve(false);
      }
    });

    popEl.querySelector('[data-cancel]').onclick = ()=>{
      hidePop();
      cleanup();
      resolve(false);
    };
    
    popEl.querySelector('[data-ok]').onclick = ()=>{
      cleanup();
      resolve(true);
    };

    document.addEventListener('keydown', onKey, true);
  });
}

      // --- Draft: Client create form ---
      const createDraftKey = ()=>`vtmCreateDraft:v28:${cfg.forced_role}`;
      const createDraftGet = ()=>{ try{ return JSON.parse(localStorage.getItem(createDraftKey())||'{}')||{} }catch(e){ return {} } };
      const createDraftSet = (obj)=>{ try{ localStorage.setItem(createDraftKey(), JSON.stringify(obj||{})) }catch(e){} };
      const createDraftClear = ()=>{ try{ localStorage.removeItem(createDraftKey()) }catch(e){} };

      // -------- App state --------
      const S = {
        role:'',
        user:null,
        today:(new Date().toISOString().slice(0,10)),
        client_vts:[],
        vt_count:0,
        assignments:[],
        selectedId:null,

        // locks (detail)
        detailDirty:false,
        detailHasFiles:false,
        detailMountedFor:null,

        // client create form dirty state
        clientFormDirty:false,
        clientFormHasFiles:false,

        // reply toggle
        openReplyKey:'',
        lastHash:'',
      };

      function roleTitle(role){
        if(role==='client') return 'Client workspace';
        if(role==='csm') return 'CSM workspace';
        return 'VT workspace';
      }
      function roleDesc(role){
        if(role==='client') return 'Create assignments, choose teammate(s), attach files, and submit.';
        if(role==='csm') return 'Review submissions, respond quickly, and keep work moving.';
        return 'Review assignments and respond or submit files when ready.';
      }

      function statusLabel(s){
        const map = {
          'pending_csm': 'Pending review',
          'needs_revision': 'Update requested',
          'declined': 'Declined',
          'approved_for_vt': 'Approved',
          'in_progress': 'In progress',
          'delivered': 'Submitted',
          'completed': 'Completed',
          'cancelled': 'Cancelled',
          'deleted': 'Deleted'
        };
        return map[s] || s || '—';
      }

      function progressPct(s){
        const map = {'pending_csm':15,'needs_revision':25,'declined':0,'approved_for_vt':45,'in_progress':70,'delivered':88,'completed':100,'cancelled':0,'deleted':0};
        return (map[s] ?? 10);
      }

      // Countdown label without re-render flicker
      function countdownParts(due){
        if(!due) return null;
        const end = new Date(due + 'T23:59:59');
        const ms = end.getTime() - Date.now();
        if(!isFinite(ms)) return null;

        if(ms < 0) return {label:'OVERDUE', urgent:true};
        const sec = Math.floor(ms / 1000);
        const days = Math.floor(sec / 86400);
        const hrs  = Math.floor((sec % 86400) / 3600);
        const mins = Math.floor((sec % 3600) / 60);
        const s    = sec % 60;

        const dPart = days > 0 ? String(days).padStart(2,'0')+'D ' : '';
        const label = dPart + String(hrs).padStart(2,'0') + ':' + String(mins).padStart(2,'0') + ':' + String(s).padStart(2,'0');
        return {label, urgent: (days===0 && hrs<48)};
      }

      function deadlineLabel(due){
        const p = countdownParts(due);
        if(!p) return {label:'—', urgent:false};
        return {label:p.label, urgent:p.urgent};
      }

      // Update countdown text without rebuilding DOM
      function tickCountdown(){
        root.querySelectorAll('[data-countdown]').forEach(el=>{
          const due = el.getAttribute('data-countdown') || '';
          const p = countdownParts(due);
          if(!p) return;
          if(el.textContent !== p.label) el.textContent = p.label;
        });
      }
      setInterval(tickCountdown, 1000);

      // Calendar UI: due.min = max(today, start) on every render + on change
      function applyCalendarMins(scopeEl){
        const today = S.today || (new Date().toISOString().slice(0,10));
        const start = scopeEl.querySelector('[data-start-date]');
        const due   = scopeEl.querySelector('[data-due-date]');

        if(start){
          start.setAttribute('min', today);
          if(start.value && start.value < today) start.value = today;
        }

        if(due){
          const startVal = (start && start.value) ? start.value : '';
          const minDue = (startVal && startVal > today) ? startVal : today;
          due.setAttribute('min', minDue);
          if(due.value && due.value < minDue) due.value = minDue;
        }

        if(start && due && !start._vtmBound){
          start._vtmBound = true;
          start.addEventListener('change', ()=>applyCalendarMins(scopeEl));
          start.addEventListener('input',  ()=>applyCalendarMins(scopeEl));
        }
      }

      // Hash to prevent flicker (no rerender if unchanged)
      function assignmentHash(list){
        list = Array.isArray(list) ? list : [];
        return list.map(a=>{
          const id = a.id||0;
          const st = (a.status||'');
          const due = (a.due||'');
          const t = (a.title||'');
          const al = Array.isArray(a.activity) ? a.activity.length : 0;
          const ml = Array.isArray(a.messages) ? a.messages.length : 0;
          return `${id}|${st}|${due}|${al}|${ml}|${t}`;
        }).join('~');
      }

      function renderShell(){
        root.innerHTML = `
          <div class="vtmWrap">
            <div class="card">
              <div class="head">
                <div class="title" data-role-title>${esc(roleTitle(cfg.forced_role))}</div>
                <div class="desc" data-role-desc>${esc(roleDesc(cfg.forced_role))}</div>
              </div>
              <div class="strip">
                <div class="meta" data-pills></div>
                <div class="meta" style="justify-content:flex-end;" data-alerts></div>
              </div>
            </div>
            <div style="height:12px;"></div>
            <div class="shell">
              <div class="left">
                <div class="card">
                  <div class="head">
                    <div class="title">Assignments</div>
                    <div class="desc">Select an item to view details on the right.</div>
                  </div>
                  <div class="body" style="padding-top:0;" data-client-create-wrap></div>
                  <div class="body" style="padding-top:0;">
                    <div class="tableWrap">
                      <table>
                        <thead>
                          <tr>
                            <th style="width:36%;">Assignment</th>
                            <th>Teammates</th>
                            <th style="width:18%;">Progress</th>
                            <th style="width:18%;">Time</th>
                          </tr>
                        </thead>
                        <tbody data-rows>
                          <tr><td colspan="4" style="padding:14px;"><div class="mini">Loading…</div></td></tr>
                        </tbody>
                      </table>
                    </div>
                    <div style="margin-top:10px;" class="mini" data-list-hint></div>
                  </div>
                </div>
              </div>
              <div class="right">
                <div class="card">
                  <div class="head">
                    <div class="title">Details</div>
                    <div class="desc">Brief, steps, activity, messages, and actions appear here.</div>
                  </div>
                  <div class="body" data-detail>
                    <div class="panel"><div class="mini">Select an assignment to view details.</div></div>
                  </div>
                </div>
              </div>
            </div>
          </div>`;
      }

    function renderHeader(){
  const pills = root.querySelector('[data-pills]');
  const alerts = root.querySelector('[data-alerts]');
  if(!pills || !alerts) return;

  const aCount = (S.assignments||[]).length;
  let html = `<span class="pill"><span class="dot"></span><span>${aCount} assignments</span></span>`;
  if(S.role==='client'){
    html += `<span class="pill"><span class="dot dotA"></span><span>${S.vt_count||0} hired teammates</span></span>`;
  }
  pills.innerHTML = html;

  // Check for urgent and approved assignments
  const list = (S.assignments||[]);
  
  // Different urgent statuses based on role
  let urgentStatuses = [];
  if(S.role === 'vt'){
    // For VT: urgent means new assignments waiting for response
    urgentStatuses = ['approved_for_vt'];
  } else {
    // For Client/CSM: urgent means needs attention
    urgentStatuses = ['pending_csm', 'needs_revision', 'declined', 'cancelled'];
  }
  
  const approvedStatuses = ['in_progress'];
  const completedStatuses = ['completed', 'delivered'];
  
  const urgentCount = list.filter(a => urgentStatuses.includes(a.status)).length;
  const approvedCount = list.filter(a => approvedStatuses.includes(a.status)).length;
  const allCaughtUp = urgentCount === 0 && approvedCount === 0 && list.length > 0 && list.every(a => 
    completedStatuses.includes(a.status)
  );

  let statusHTML = `<span class="pill"><span class="dot"></span><span>Live</span></span>`;
  
  // Show BOTH urgent and approved if both exist
  if(urgentCount > 0){
    statusHTML += `<span class="pill urgent-alert"><span class="urgent-pulse"></span><span style="font-weight:1000;">URGENT (${urgentCount})</span></span>`;
  }
  
  if(approvedCount > 0){
    statusHTML += `<span class="pill success-alert"><span class="success-pulse"></span><span style="font-weight:1000;">IN PROGRESS (${approvedCount})</span></span>`;
  }
  
  if(allCaughtUp){
    statusHTML += `<span class="pill success-alert"><span class="success-pulse"></span><span style="font-weight:1000;">All Caught Up!</span></span>`;
  }
  
  alerts.innerHTML = statusHTML;
}

      function renderClientForm(){
        const wrap = root.querySelector('[data-client-create-wrap]');
        if(!wrap) return;

        if(S.role !== 'client'){ wrap.innerHTML = ''; return; }

        const vts = S.client_vts || [];
        const hasRoster = vts.length > 0;
        const showSelector = vts.length > 1;

        wrap.innerHTML = `
          <div class="panel" style="margin-top:12px;">
            <div class="blockTitle">Create an assignment</div>
            <div class="mini" style="margin-top:6px;">Fill in the details, attach files if needed, then click <strong>Submit</strong>.</div>
            ${!hasRoster ? `
              <div class="banner warn">
                <div class="bT">Hired teammates not showing yet</div>
                <div class="bM">Open your “My Virtual Teammates” or “End of Day/Week Reports” page in another tab, then come back here. Your teammate list will appear automatically.</div>
              </div>
            ` : ``}

            <form data-create-form>
              <div class="grid2" style="margin-top:12px;">
                <div class="field">
                  <div class="label">Assignment name</div>
                  <input class="input" name="title" type="text" placeholder="e.g., Weekly reporting cleanup">
                </div>
                <div class="field">
                  <div class="label">Start date</div>
                  <input class="input" name="start" type="date" data-start-date>
                </div>
              </div>

              <div class="grid2" style="margin-top:10px;">
                <div class="field">
                  <div class="label">Due date</div>
                  <input class="input" name="due" type="date" data-due-date>
                </div>
                <div class="field">
                  <div class="label">Attachments</div>
                  <input class="input" name="files" type="file" multiple>
                </div>
              </div>

              ${showSelector ? `
                <div class="field" style="margin-top:10px;">
                  <div class="label">Choose teammate(s)</div>
                  <div class="mini">Select one or multiple.</div>
                  <div style="margin-top:8px; display:flex; flex-wrap:wrap; gap:10px;">
                    ${vts.map(v=>`
                      <label style="display:flex; align-items:center; gap:8px; padding:8px 10px; border:1px solid var(--b2); border-radius:999px; background:#fff; cursor:pointer; font-weight:900;">
                        <input type="checkbox" data-vtcheck value="${esc(v.name)}">
                        <span>${esc(v.name)}</span>
                      </label>
                    `).join('')}
                  </div>
                </div>
              ` : (vts.length===1 ? `
                <div class="field" style="margin-top:10px;">
                  <div class="label">Assigned teammate</div>
                  <div class="pill"><span class="dot dotA"></span><span>${esc(vts[0].name)}</span></div>
                </div>
              ` : ``)}

              <div class="field" style="margin-top:10px;">
                <div class="label">Brief</div>
                <textarea class="text" name="brief" placeholder="Short summary of what to do…"></textarea>
              </div>

              <div class="field" style="margin-top:10px;">
                <div class="label">Step-by-step instructions</div>
                <textarea class="text" name="steps" placeholder="Numbered steps work well for clarity…"></textarea>
              </div>

              <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:12px;">
                <button class="btn primary" type="button" data-create-submit>Submit</button>
              </div>
            </form>
          </div>
        `;

        const form = wrap.querySelector('[data-create-form]');
        if(!form) return;

        // Restore draft
        const cd = createDraftGet();
        if(cd.title) form.title.value = cd.title;
        if(cd.start) form.start.value = cd.start;
        if(cd.due)   form.due.value   = cd.due;
        if(cd.brief) form.brief.value = cd.brief;
        if(cd.steps) form.steps.value = cd.steps;

        if(Array.isArray(cd.vt_names)){
          const set = new Set(cd.vt_names.map(x=>String(x||'').toLowerCase()));
          wrap.querySelectorAll('[data-vtcheck]').forEach(ch=>{
            if(set.has(String(ch.value||'').toLowerCase())) ch.checked = true;
          });
        }

        // Calendar enforce
        applyCalendarMins(wrap);

        // If no start chosen, default to today (but do not overwrite restored draft)
        if(!form.start.value) form.start.value = S.today;
        applyCalendarMins(wrap);

        // Persist draft on input/change
        if(!form._vtmBound){
          form._vtmBound = true;
          const save = ()=>{
            const chosen = Array.from(form.querySelectorAll('[data-vtcheck]:checked')).map(x=>x.value).filter(Boolean);
            createDraftSet({
              title: form.title.value||'',
              start: form.start.value||'',
              due:   form.due.value||'',
              brief: form.brief.value||'',
              steps: form.steps.value||'',
              vt_names: chosen
            });
          };

          form.addEventListener('input', ()=>{ S.clientFormDirty = true; save(); }, {passive:true});
          form.addEventListener('change', ()=>{ S.clientFormDirty = true; save(); }, {passive:true});

          const fileInput = form.querySelector('input[name="files"]');
          if(fileInput){
            fileInput.addEventListener('change', ()=>{
              S.clientFormHasFiles = (fileInput.files && fileInput.files.length > 0);
              S.clientFormDirty = true;
            });
          }
        }

        // Submit
        const submitBtn = wrap.querySelector('[data-create-submit]');
        submitBtn.addEventListener('click', async ()=>{
          if(!hasRoster){
            toast('Teammates not ready', 'Open “My Virtual Teammates” or “End of Day/Week Reports” then try again.');
            return;
          }

          const title = (form.title.value||'').trim();
          const start = (form.start.value||'').trim();
          const due   = (form.due.value||'').trim();
          const brief = (form.brief.value||'').trim();
          const steps = (form.steps.value||'').trim();

          let chosen = [];
          if(showSelector){
            chosen = Array.from(form.querySelectorAll('[data-vtcheck]:checked')).map(x=>x.value).filter(Boolean);
          } else if(vts.length===1){
            chosen = [vts[0].name];
          }

          // Frontend Calendar checks (still backed by backend)
          if(!title){ toast('Missing info', 'Assignment name is required.'); return; }
          if(!start){ toast('Missing info', 'Start date is required.'); return; }
          if(!due){ toast('Missing info', 'Due date is required.'); return; }
          if(due < start){ toast('Date rule', 'Due date cannot be earlier than the start date.'); return; }
          if(chosen.length<1){ toast('Missing info', 'Select at least one teammate.'); return; }

          submitBtn.disabled = true;
          submitBtn.innerHTML = '<span class="spin"></span>Submitting';

          const fileInput = form.querySelector('input[name="files"]');
          const files = (fileInput && fileInput.files) ? Array.from(fileInput.files) : [];

          const r = await api('vtm_client_create', {
            title, start, due, brief, steps,
            vt_names: JSON.stringify(chosen)
          }, files);

          submitBtn.disabled = false;
          submitBtn.textContent = 'Submit';

          if(r && r.success && r.data && r.data.assignment){
            toast('Saved', 'Submitted for review.');
            // Clear draft + reset
            createDraftClear();
            S.clientFormDirty = false;
            S.clientFormHasFiles = false;

            form.reset();
            form.start.value = S.today;
            applyCalendarMins(wrap);

            // Update state
            S.assignments.unshift(r.data.assignment);
            S.selectedId = r.data.assignment.id;
            renderHeader();
            renderList(true);
            renderDetail(true);
          } else {
            toast('Couldn’t submit', (r && r.data && r.data.message) ? r.data.message : 'Please try again.');
          }
        });
      }

      function renderList(force=false){
        const body = root.querySelector('[data-rows]');
        const hint = root.querySelector('[data-list-hint]');
        if(!body) return;

        const list = (S.assignments||[]);
        if(hint) hint.textContent = list.length > 80 ? 'Tip: Newest items are at the top. Select one to open details.' : '';

        body.innerHTML = (list.length ? list.map(a=>{
          const active = (a.id===S.selectedId) ? 'active' : '';
          const pct = progressPct(a.status);
          const dl = deadlineLabel(a.due);
          const stClass = (String(a.status).includes('declin') || String(a.status).includes('cancel')) ? 'status bad' : (dl.urgent ? 'status attn' : 'status');

          return `
            <tr class="${active}" data-row="${a.id}">
              <td>
                <div style="font-weight:1000;">${esc(a.title||'—')}</div>
                <div class="mini">${esc(a.client_name||'')}</div>
              </td>
              <td>${esc((a.vt_names||[]).join(', ')||'—')}</td>
              <td>
                <span class="${stClass}">${esc(statusLabel(a.status))}</span>
                <div class="bar"><i style="width:${pct}%;"></i></div>
              </td>
              <td>
                <div style="font-weight:1000;">${esc(a.due||'—')}</div>
                <div style="margin-top:6px;">
                  <span class="neonTime" data-countdown="${esc(a.due||'')}">${esc(dl.label)}</span>
                </div>
              </td>
            </tr>
          `;
        }).join('') : `<tr><td colspan="4" style="padding:14px;"><div class="mini">No assignments yet.</div></td></tr>`);

        body.querySelectorAll('tr[data-row]').forEach(tr=>{
          tr.addEventListener('click', ()=>{
            const id = Number(tr.getAttribute('data-row'));
            if((S.detailDirty || S.detailHasFiles) && S.selectedId !== id){
              toast('Draft protected', 'Finish or send your note/files first.');
              return;
            }
            S.selectedId = id;
            renderList(true);
            renderDetail(true);
          });
        });

        tickCountdown();
      }

      function filesBlock(files){
        files = files || [];
        if(!files.length) return `<div class="mini">No files attached.</div>`;
        return files.map(f=>`
          <div class="mini" style="padding:6px 0;">
            <a href="${esc(f.url)}" target="_blank" rel="noopener noreferrer" style="color:var(--p);font-weight:1000;text-decoration:none;">
              ${esc(f.name)}
            </a>
          </div>
        `).join('');
      }

      function mkActKey(ev){
        if(!ev) return '';
        return `${ev.ts||''}|${ev.by||''}|${ev.type||''}|${ev.note||''}`;
      }

      function activityIsUrgent(type){
        type = String(type||'').toLowerCase();
        return (type.includes('declin') || type.includes('cancel') || type.includes('request') || type.includes('extension') || type.includes('update requested'));
      }

      function activityIsGood(type){
        type = String(type||'').toLowerCase();
        return (type.includes('approved') || type.includes('accepted') || type.includes('completed') || type.includes('submitted'));
      }

      function inferRecipient(a){
        // sensible default recipient per role
        if(S.role === 'vt'){
          const csmLogin = (a.csm_usernames && a.csm_usernames[0]) ? a.csm_usernames[0] : '';
          if(csmLogin) return {role:'csm', login:csmLogin, name:csmLogin};
          return {role:'client', login:'', name:a.client_name||'Client'};
        }
        if(S.role === 'csm'){
          const vtName = (a.vt_names && a.vt_names[0]) ? a.vt_names[0] : '';
          return {role:'vt', login:'', name:vtName || 'VT'};
        }
        // client
        const st = String(a.status||'');
        const csmLogin = (a.csm_usernames && a.csm_usernames[0]) ? a.csm_usernames[0] : '';
        if(['pending_csm','needs_revision','declined'].includes(st) && csmLogin){
          return {role:'csm', login:csmLogin, name:csmLogin};
        }
        const vtName = (a.vt_names && a.vt_names[0]) ? a.vt_names[0] : '';
        return {role:'vt', login:'', name:vtName || 'VT'};
      }

      function renderDetail(force=false){
        const box = root.querySelector('[data-detail]');
        if(!box) return;

        const a = (S.assignments||[]).find(x=>x.id===S.selectedId);
        if(!a){
          box.innerHTML = `<div class="panel"><div class="mini">Select an assignment to view details.</div></div>`;
          return;
        }

        // protect typed drafts
        if(!force && (S.detailDirty || S.detailHasFiles) && S.detailMountedFor === a.id) return;
        S.detailMountedFor = a.id;

        const dl = deadlineLabel(a.due);
        const statusBad = (String(a.status).includes('declin') || String(a.status).includes('cancel'));
        const stClass = statusBad ? 'status bad' : (dl.urgent ? 'status attn' : 'status');

        const briefPreview = (a.brief||'').trim().slice(0, 140) || '—';
        const stepsPreview = (a.steps||'').trim().slice(0, 140) || '—';

        // Build activity list
        const activity = Array.isArray(a.activity) ? a.activity.slice().reverse() : [];

        const meName = String((S.user && S.user.name) ? S.user.name : '').trim();
        const isAdmin = !!(S.user && S.user.can_admin);

        const actHTML = activity.length ? `
          <div class="act" data-activity-wrap>
            ${activity.map(ev=>{
              const type = ev.type || 'Update';
              const isUrg = !!ev.urgent || activityIsUrgent(type);
              const isGood = activityIsGood(type);
              const rowClass = isUrg ? 'actItem urgent' : (isGood ? 'actItem good' : 'actItem');
              const key = mkActKey(ev);

              const recip = inferRecipient(a);
              const open = (S.openReplyKey === key);

              // Delete rules:
              // - admin can delete anything
              // - VT can delete ONLY their own items
              // - client/csm never see delete button
              const evBy = String(ev.by || '').trim();
              const isOwner = (meName && evBy && meName === evBy);
              const canDelete = isAdmin ? true : (S.role === 'vt' && isOwner);

              const urgentTag = isUrg ? `<span class="dangerTag"><i></i>Needs attention</span>` : ``;

              return `
                <div class="${rowClass}" data-act-key="${esc(key)}">
                  <div class="actTop">
                    <div class="actType">${esc(type)}</div>
                    <div style="display:flex; gap:8px; align-items:center;">
                      ${urgentTag}
                      <button class="replyIconBtn" type="button" data-act-reply-open data-act-key="${esc(key)}">↩ Reply</button>
                      ${canDelete ? `
                        <button class="replyIconBtn" type="button" data-act-del data-act-key="${esc(key)}">🗑</button>
                      ` : ``}
                    </div>
                  </div>
                  <div style="margin-top:6px; white-space:pre-wrap; color:var(--t); font-weight:850;">${esc(ev.note||'')}</div>
                  <div style="margin-top:6px;opacity:.92;font-size:11px;color:var(--m);font-weight:900;">${esc(ev.by||'')} • ${esc(ev.ts||'')}</div>

                  <div class="inlineReply ${open ? 'on' : ''}" data-inline-reply>
                    <div class="mini">Replying to <strong>${esc(recip.name || recip.login || recip.role)}</strong></div>
                    <div class="inlineReplyRow" style="margin-top:8px;">
                      <div class="field" style="flex:1 1 auto; margin:0;">
                        <textarea class="text" data-act-reply-text placeholder="Write your reply…"></textarea>
                      </div>
                      <div style="flex:0 0 auto;">
                        <button class="btn primary" type="button" data-act-reply-send
                          data-act-key="${esc(key)}"
                          data-to-role="${esc(recip.role)}"
                          data-to-login="${esc(recip.login)}"
                          data-to-name="${esc(recip.name)}">Send</button>
                      </div>
                    </div>
                  </div>
                </div>
              `;
            }).join('')}
          </div>
        ` : `<div class="mini">No updates yet.</div>`;

        // Role panels
        const canCSMAct = (S.role==='csm') && ['pending_csm','needs_revision'].includes(a.status);

        box.innerHTML = `
          <div class="panel">
            <div style="font-weight:1000;font-size:14px;">${esc(a.title||'Assignment')}</div>
            <div class="mini" style="margin-top:8px; display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
              <span class="${stClass}">${esc(statusLabel(a.status))}</span>
              <span style="opacity:.55;">•</span>
              <span><strong>Due:</strong> ${esc(a.due||'—')}</span>
              <span style="opacity:.55;">•</span>
              <span class="neonTime" data-countdown="${esc(a.due||'')}">${esc(dl.label)}</span>
            </div>
          </div>

          <div class="panel">
            <button class="collBtn" type="button" data-coll="brief">
              <span>Brief</span><span class="hint">Click to expand</span>
            </button>
            <span class="preview2" style="margin-top:8px;">${esc(briefPreview)}</span>
            <div class="collBody" data-coll-body="brief">
              <div style="white-space:pre-wrap; font-weight:850;">${esc(a.brief||'—')}</div>
            </div>

            <div class="hr"></div>

            <button class="collBtn" type="button" data-coll="steps">
              <span>Step-by-step</span><span class="hint">Click to expand</span>
            </button>
            <span class="preview2" style="margin-top:8px;">${esc(stepsPreview)}</span>
            <div class="collBody" data-coll-body="steps">
              <div style="white-space:pre-wrap; font-weight:850;">${esc(a.steps||'—')}</div>
            </div>
          </div>

          <div class="panel">
            <div class="blockTitle">Files</div>
            <div style="margin-top:8px;">${filesBlock(a.files)}</div>
            ${(a.csm_files && a.csm_files.length) ? `<div class="hr"></div><div class="blockTitle">CSM files</div><div style="margin-top:8px;">${filesBlock(a.csm_files)}</div>` : ``}
          </div>

          <div class="panel">
            <div class="blockTitle">Activity</div>
            <div class="mini" style="margin-top:6px;">Click <strong>Reply</strong> on a message to respond right there.</div>
            <div style="margin-top:10px;">${actHTML}</div>
          </div>

          ${S.role==='client' ? `
            <div class="panel" data-client-panel>
              <div class="blockTitle">Client actions</div>
              <div class="mini" style="margin-top:6px;">Edit, extend, cancel, or delete (protected).</div>

              <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:10px;">
                <button class="btn" type="button" data-client-edit> Edit </button>
                <button class="btn warn" type="button" data-client-extend> Extend deadline </button>
                <button class="btn danger" type="button" data-client-cancel> Cancel </button>
                <button class="btn dangerSolid" type="button" data-client-delete> Delete </button>
              </div>

              <div class="collBody" data-client-edit-body style="margin-top:12px;">
                <div class="grid2">
                  <div class="field">
                    <div class="label">Assignment name</div>
                    <input class="input" type="text" data-edit-title value="${esc(a.title||'')}">
                  </div>
                  <div class="field">
                    <div class="label">Start date</div>
                    <input class="input" type="date" data-edit-start data-start-date value="${esc(a.start||S.today)}">
                  </div>
                </div>
                <div class="grid2" style="margin-top:10px;">
                  <div class="field">
                    <div class="label">Due date</div>
                    <input class="input" type="date" data-edit-due data-due-date value="${esc(a.due||'')}">
                  </div>
                  <div class="field">
                    <div class="label">—</div>
                    <div class="mini">Updating re-submits for review.</div>
                  </div>
                </div>
                <div class="field" style="margin-top:10px;">
                  <div class="label">Brief</div>
                  <textarea class="text" data-edit-brief>${esc(a.brief||'')}</textarea>
                </div>
                <div class="field" style="margin-top:10px;">
                  <div class="label">Step-by-step instructions</div>
                  <textarea class="text" data-edit-steps>${esc(a.steps||'')}</textarea>
                </div>
                <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:12px;">
                  <button class="btn primary" type="button" data-client-save-edit>Save changes</button>
                </div>
              </div>
            </div>
          ` : ``}

          ${S.role==='csm' ? `
            <div class="panel" data-csm-panel>
              <div class="blockTitle">CSM actions</div>
              <div class="mini" style="margin-top:6px;">Add a note, attach files if needed, then choose an action.</div>

              <div class="field" style="margin-top:10px;">
                <div class="label">Note</div>
                <textarea class="text" data-csm-note placeholder="Add a short note…"></textarea>
              </div>
              <div class="field" style="margin-top:10px;">
                <div class="label">Attach file (optional)</div>
                <input class="input" type="file" data-csm-files multiple>
              </div>

              <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:12px;">
                <button class="btn primary" type="button" data-csm-action="approve" ${canCSMAct?'':'disabled'}>Approve</button>
                <button class="btn danger" type="button" data-csm-action="decline" ${canCSMAct?'':'disabled'}>Decline</button>
                <button class="btn warn" type="button" data-csm-action="revision" ${canCSMAct?'':'disabled'}>Request update</button>
              </div>
            </div>
          ` : ``}

          ${S.role==='vt' ? `
            <div class="panel" data-vt-panel>
              <div class="blockTitle">Your response</div>
              <div class="mini" style="margin-top:6px;">Accept, decline, request changes, or submit files.</div>

              <div class="field" style="margin-top:10px;">
                <div class="label">Note (optional)</div>
                <textarea class="text" data-vt-note placeholder="Add a short note…"></textarea>
              </div>

              <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:10px;">
                <button class="btn primary" type="button" data-vt-action="accept">Accept</button>
                <button class="btn danger" type="button" data-vt-action="decline">Decline</button>
                <button class="btn warn" type="button" data-vt-action="request">Request update</button>
                <button class="btn warn" type="button" data-vt-action="request_extension">Request extension</button>
                <button class="btn danger" type="button" data-vt-action="request_cancel">Request cancel</button>
              </div>

              <div class="hr"></div>

              <div class="blockTitle">Submit files</div>
              <div class="mini" style="margin-top:6px;">Attach files and add a short note.</div>
              <div class="field" style="margin-top:10px;">
                <div class="label">Attachments</div>
                <input class="input" type="file" data-vt-files multiple>
              </div>
              <div style="display:flex; justify-content:flex-end; margin-top:12px;">
                <button class="btn primary" type="button" data-vt-action="deliver">Submit</button>
              </div>
            </div>
          ` : ``}
        `;

        // Collapsibles
        box.querySelectorAll('[data-coll]').forEach(btn=>{
          btn.addEventListener('click', ()=>{
            const k = btn.getAttribute('data-coll');
            const body = box.querySelector(`[data-coll-body="${CSS.escape(k)}"]`);
            if(!body) return;
            body.classList.toggle('on');
            const hint = btn.querySelector('.hint');
            if(hint) hint.textContent = body.classList.contains('on') ? 'Click to collapse' : 'Click to expand';
          });
        });

        // Activity reply open
        box.querySelectorAll('[data-act-reply-open]').forEach(btn=>{
          btn.addEventListener('click', ()=>{
            const key = btn.getAttribute('data-act-key') || '';
            S.openReplyKey = (S.openReplyKey === key) ? '' : key;
            renderDetail(true);
          });
        });

        // Activity reply send
        box.querySelectorAll('[data-act-reply-send]').forEach(btn=>{
          btn.addEventListener('click', async ()=>{
            const key = btn.getAttribute('data-act-key') || '';
            const toRole = btn.getAttribute('data-to-role') || '';
            const toLogin = btn.getAttribute('data-to-login') || '';
            const toName = btn.getAttribute('data-to-name') || '';

            const actEl = box.querySelector(`[data-act-key="${CSS.escape(key)}"]`);
            const ta = actEl ? actEl.querySelector('[data-act-reply-text]') : null;
            const text = (ta ? ta.value : '').trim();
            if(!text){ toast('Message required', 'Write a short reply before sending.'); return; }

            btn.disabled = true;
            btn.innerHTML = '<span class="spin"></span>Sending';

            const r = await api('vtm_send_message', {
              id: String(a.id),
              text,
              target_role: toRole,
              target_login: toLogin,
              target_name: toName
            });

            btn.disabled = false;
            btn.textContent = 'Send';

            if(r && r.success && r.data && r.data.assignment){
              toast('Sent', 'Reply sent.');
              S.openReplyKey = '';
              upsertAssignment(r.data.assignment);
              renderDetail(true);
            } else {
              toast('Couldn’t send', (r && r.data && r.data.message) ? r.data.message : 'Please try again.');
            }
          });
        });

        // Activity delete (VT own only; admin any) — with popover confirm near button
        box.querySelectorAll('[data-act-del]').forEach(btn=>{
          btn.addEventListener('click', async ()=>{
            const key = btn.getAttribute('data-act-key') || '';
            const act = Array.isArray(a.activity) ? a.activity : [];
            let found = null;
            for(const ev of act){ if(mkActKey(ev) === key){ found = ev; break; } }
            if(!found) return;

            const ok = await showConfirm(btn, 'Delete this message?', 'This can’t be undone.', 'Delete', 'dangerSolid');
            if(!ok) return;

            btn.disabled = true;
            const r = await api('vtm_delete_activity', {
              id: String(a.id),
              ts: found.ts || '',
              by: found.by || '',
              type: found.type || '',
              note: found.note || ''
            });

            btn.disabled = false;

            if(r && r.success && r.data && r.data.assignment){
              hidePop();
              toast('Saved', 'Message deleted.');
              upsertAssignment(r.data.assignment);
              renderDetail(true);
            } else {
              toast('Couldn’t delete', (r && r.data && r.data.message) ? r.data.message : 'Please try again.');
            }
          });
        });

        // Client actions (edit/extend/cancel/delete) — use popover confirm near button
        if(S.role==='client'){
          const editBtn = box.querySelector('[data-client-edit]');
          const editBody = box.querySelector('[data-client-edit-body]');
          const saveBtn = box.querySelector('[data-client-save-edit]');
          const extendBtn = box.querySelector('[data-client-extend]');
          const cancelBtn = box.querySelector('[data-client-cancel]');
          const delBtn = box.querySelector('[data-client-delete]');

          if(editBtn && editBody){
            editBtn.addEventListener('click', ()=> editBody.classList.toggle('on'));
          }

          // Calendar enforce for edit fields
          if(editBody){
            applyCalendarMins(editBody);
            const st = editBody.querySelector('[data-edit-start]');
            const du = editBody.querySelector('[data-edit-due]');
            if(st && du){
              st.addEventListener('change', ()=>applyCalendarMins(editBody));
              st.addEventListener('input',  ()=>applyCalendarMins(editBody));
            }
          }

          if(saveBtn){
            saveBtn.addEventListener('click', async ()=>{
              const ok = await showConfirm(saveBtn, 'Save changes?', 'This will update the assignment and send it for review again.', 'Save', 'primary');
              if(!ok) return;

              const tEl = box.querySelector('[data-edit-title]');
              const bEl = box.querySelector('[data-edit-brief]');
              const sEl = box.querySelector('[data-edit-steps]');
              const stEl= box.querySelector('[data-edit-start]');
              const dEl = box.querySelector('[data-edit-due]');

              const start = (stEl ? stEl.value : '').trim();
              const due   = (dEl ? dEl.value : '').trim();

              if(due && start && due < start){
                toast('Date rule', 'Due date cannot be earlier than the start date.');
                return;
              }

              saveBtn.disabled = true;
              saveBtn.innerHTML = '<span class="spin"></span>Saving';

              const r = await api('vtm_client_update', {
                id: String(a.id),
                update_action: 'edit',
                title: (tEl?tEl.value:'').trim(),
                brief: (bEl?bEl.value:'').trim(),
                steps: (sEl?sEl.value:'').trim(),
                start,
                due
              });

              saveBtn.disabled = false;
              saveBtn.textContent = 'Save changes';

              if(r && r.success && r.data && r.data.assignment){
                hidePop();
                toast('Saved', 'Updated and re-submitted.');
                upsertAssignment(r.data.assignment);
                renderDetail(true);
              } else {
                toast('Couldn’t save', (r && r.data && r.data.message) ? r.data.message : 'Please try again.');
              }
            });
          }

          if(extendBtn){
            extendBtn.addEventListener('click', async ()=>{
              const today = S.today;
              const start = (a.start || today);

              const minDue = (start && start > today) ? start : today;
              const extra = `
                <div class="row">
                  <div class="mini" style="min-width:74px;">New due</div>
                  <input class="input" type="date" data-pop-due value="${esc(a.due||minDue)}" min="${esc(minDue)}" style="flex:1 1 auto;">
                </div>
              `;

              const ok = await showConfirm(extendBtn, 'Extend deadline?', 'Choose a new due date (can’t be before start/today).', 'Update', 'warn', extra);
              if(!ok) return;

              const input = document.querySelector('.vtmPop [data-pop-due]');
              const newDue = input ? input.value : '';
              if(!newDue){ toast('Missing info', 'Please choose a new due date.'); return; }
              if(newDue < minDue){ toast('Date rule', 'Due date cannot be earlier than the allowed minimum.'); return; }

              extendBtn.disabled = true;
              extendBtn.innerHTML = '<span class="spin"></span>Updating';

              const r = await api('vtm_client_update', { id:String(a.id), update_action:'extend', due:newDue });

              extendBtn.disabled = false;
              extendBtn.textContent = 'Extend deadline';

              if(r && r.success && r.data && r.data.assignment){
                hidePop();
                toast('Saved', 'Deadline updated.');
                upsertAssignment(r.data.assignment);
                renderDetail(true);
              } else {
                toast('Couldn’t update', (r && r.data && r.data.message) ? r.data.message : 'Please try again.');
              }
            });
          }

          if(cancelBtn){
            cancelBtn.addEventListener('click', async ()=>{
              const ok = await showConfirm(cancelBtn, 'Cancel this assignment?', 'This will notify the team.', 'Cancel assignment', 'danger');
              if(!ok) return;

              cancelBtn.disabled = true;
              cancelBtn.innerHTML = '<span class="spin"></span>Cancelling';

              const r = await api('vtm_client_update', { id:String(a.id), update_action:'cancel' });

              cancelBtn.disabled = false;
              cancelBtn.textContent = 'Cancel';

              if(r && r.success && r.data && r.data.assignment){
                hidePop();
                toast('Saved', 'Cancelled.');
                upsertAssignment(r.data.assignment);
                renderDetail(true);
              } else {
                toast('Couldn’t cancel', (r && r.data && r.data.message) ? r.data.message : 'Please try again.');
              }
            });
          }

          if(delBtn){
            delBtn.addEventListener('click', async ()=>{
              const ok = await showConfirm(delBtn, 'Delete this assignment?', 'This can’t be undone.', 'Delete', 'dangerSolid');
              if(!ok) return;

              delBtn.disabled = true;
              delBtn.innerHTML = '<span class="spin"></span>Deleting';

              const r = await api('vtm_client_update', { id:String(a.id), update_action:'delete' });

              delBtn.disabled = false;
              delBtn.textContent = 'Delete';

              if(r && r.success && r.data && r.data.assignment){
                hidePop();
                toast('Saved', 'Deleted.');
                upsertAssignment(r.data.assignment);
                // remove from list locally
                S.assignments = (S.assignments||[]).filter(x=>x.id !== a.id);
                S.selectedId = (S.assignments[0] ? S.assignments[0].id : null);
                renderHeader();
                renderList(true);
                renderDetail(true);
              } else {
                toast('Couldn’t delete', (r && r.data && r.data.message) ? r.data.message : 'Please try again.');
              }
            });
          }
        }

        // CSM actions — with popover confirms for risky actions
        if(S.role==='csm'){
          const panel = box.querySelector('[data-csm-panel]');
          if(panel){
            const noteEl = panel.querySelector('[data-csm-note]');
            const filesEl = panel.querySelector('[data-csm-files]');

            panel.querySelectorAll('button[data-csm-action]').forEach(btn=>{
              btn.addEventListener('click', async ()=>{
                const act = btn.getAttribute('data-csm-action');
                if(btn.disabled) return;

                if(act==='decline'){
                  const ok = await showConfirm(btn, 'Decline this assignment?', 'This will notify the client.', 'Decline', 'dangerSolid');
                  if(!ok) return;
                }
                if(act==='revision'){
                  const ok = await showConfirm(btn, 'Request an update?', 'This will notify the client.', 'Send request', 'warn');
                  if(!ok) return;
                }

                btn.disabled = true;
                const old = btn.textContent;
                btn.innerHTML = '<span class="spin"></span>Saving';

                const note = (noteEl ? noteEl.value : '') || '';
                const files = (filesEl && filesEl.files) ? Array.from(filesEl.files) : [];

                const r = await api('vtm_csm_action', { id:String(a.id), csm_action: act, note }, files);

                btn.disabled = false;
                btn.textContent = old;

                if(r && r.success && r.data && r.data.assignment){
                  hidePop();
                  toast('Saved', act==='approve' ? 'Approved and sent to VT.' : act==='decline' ? 'Declined and sent to client.' : 'Update request sent to client.');
                  upsertAssignment(r.data.assignment);
                  renderDetail(true);
                } else {
                  toast('Couldn’t save', (r && r.data && r.data.message) ? r.data.message : 'Please try again.');
                }
              });
            });
          }
        }

        // VT actions — popover confirms for decline/cancel request/extension request
        if(S.role==='vt'){
          const panel = box.querySelector('[data-vt-panel]');
          if(panel){
            const noteEl = panel.querySelector('[data-vt-note]');
            const filesEl = panel.querySelector('[data-vt-files]');

            panel.querySelectorAll('button[data-vt-action]').forEach(btn=>{
              btn.addEventListener('click', async ()=>{
                const act = btn.getAttribute('data-vt-action');
                const note = (noteEl ? noteEl.value : '') || '';

                if(act==='decline'){
                  const ok = await showConfirm(btn, 'Decline this assignment?', 'This will notify the client and CSM.', 'Decline', 'dangerSolid');
                  if(!ok) return;
                }
                if(act==='request_cancel'){
                  const ok = await showConfirm(btn, 'Request cancellation?', 'This will notify the client and CSM.', 'Send request', 'danger');
                  if(!ok) return;
                }
                if(act==='request_extension'){
                  const ok = await showConfirm(btn, 'Request an extension?', 'This will notify the client and CSM.', 'Send request', 'warn');
                  if(!ok) return;
                }

                btn.disabled = true;
                const old = btn.textContent;
                btn.innerHTML = '<span class="spin"></span>Saving';

                const files = (filesEl && filesEl.files) ? Array.from(filesEl.files) : null;
                const r = await api('vtm_vt_action', { id:String(a.id), vt_action: act, note }, (act==='deliver' ? files : null));

                btn.disabled = false;
                btn.textContent = old;

                if(r && r.success && r.data && r.data.assignment){
                  hidePop();
                  toast('Saved', act==='accept' ? 'Accepted.' : act==='decline' ? 'Declined.' : act==='deliver' ? 'Submitted.' : 'Sent.');
                  upsertAssignment(r.data.assignment);
                  renderDetail(true);
                } else {
                  toast('Couldn’t save', (r && r.data && r.data.message) ? r.data.message : 'Please try again.');
                }
              });
            });
          }
        }

        tickCountdown();
      }

      function upsertAssignment(updated){
        if(!updated || !updated.id) return;
        const idx = (S.assignments||[]).findIndex(x=>x.id===updated.id);
        if(String(updated.status) === 'deleted'){
          if(idx >= 0) S.assignments.splice(idx, 1);
          if(S.selectedId === updated.id) S.selectedId = (S.assignments[0] ? S.assignments[0].id : null);
        } else {
          if(idx >= 0) S.assignments[idx] = updated;
          else S.assignments.unshift(updated);
        }
      }

      async function refresh(force=false){
        // safe locks: don't wipe create form if dirty/files; don't wipe detail if dirty/files (renderDetail already protects)
        const r = await api('vtm_state', {});
        if(!r || !r.success){
          toast('Error', 'Could not load. Please refresh the page.');
          return;
        }

        const d = r.data || {};
        if(!d.ok){
          toast('Access', d.message || 'You don’t have access to this portal.');
          root.innerHTML = `<div class="panel"><div class="mini">${esc(d.message || 'Access restricted.')}</div></div>`;
          return;
        }

        S.role = d.role || cfg.forced_role;
        S.user = d.user || null;
        S.today = d.today || S.today;

        S.client_vts = d.client_vts || [];
        S.vt_count = d.vt_count || 0;

        S.assignments = Array.isArray(d.assignments) ? d.assignments : [];
        if(!S.selectedId && S.assignments.length) S.selectedId = S.assignments[0].id;
        if(S.selectedId && !S.assignments.some(x=>x.id===S.selectedId)) S.selectedId = (S.assignments[0] ? S.assignments[0].id : null);

        const t = root.querySelector('[data-role-title]');
        const p = root.querySelector('[data-role-desc]');
        if(t) t.textContent = roleTitle(S.role);
        if(p) p.textContent = roleDesc(S.role);

        const newHash = assignmentHash(S.assignments);
        const changed = (newHash !== S.lastHash);
        S.lastHash = newHash;

        // Render only when needed (prevents flicker)
        if(force || changed){
          renderHeader();

          // Don't rebuild client form if actively dirty/files
          if(!(S.role==='client' && (S.clientFormDirty || S.clientFormHasFiles))){
            renderClientForm();
          }

          renderList(true);
          renderDetail(false);
        } else {
          renderHeader();
          // keep countdown ticking without rebuild
          tickCountdown();
        }
      }

      // init
      renderShell();
      refresh(true);
      setInterval(()=>refresh(false), 12000);
    })();
    </script>
    <?php
    return ob_get_clean();
  }
}

VTM_Members_Portals_Full_V28::boot();

