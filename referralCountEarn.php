/**
 * Shortcode: [referrals_summary email="..." association_label="Referred by" show_errors="1"]
 * Counts Referral custom object associations for a Contact, filtered by association label.
 */

function hs_referrals_summary_shortcode($atts) {

  // =========================
  // 1) CONFIG
  // =========================
  $HUBSPOT_PRIVATE_APP_TOKEN = 'YOUR_HUBSPOT_TOKEN_HERE';
  $CUSTOM_OBJECT_TYPE        = '2-40827981';   // Your Referrals custom object type

  // =========================
  // 2) SHORTCODE ATTRS
  // =========================
  $atts = shortcode_atts([
    'email'                   => '',
    'title'                   => 'Referrals Summary',
    'referrals_per_free_week' => 1,
    'cache_minutes'           => 10,
    'association_label'       => 'REFERRED BY', // <-- label text in HubSpot
    'show_errors'             => 0,
  ], $atts);

  $email      = trim($atts['email']);
  $title      = $atts['title'];
  $perWeek    = max(1, (int)$atts['referrals_per_free_week']);
  $cacheMins  = max(0, (int)$atts['cache_minutes']);
  $showErrors = ((int)$atts['show_errors'] === 1);
  $labelNeed  = trim((string)$atts['association_label']);

  $debug = [];
  $dbg = function($msg, $data = null) use (&$debug, $showErrors) {
    if (!$showErrors) return;
    $debug[] = $data === null ? $msg : ($msg . ' ' . wp_json_encode($data));
  };

  // If email not passed, default to current WP user
  if ($email === '') {
    $user = wp_get_current_user();
    if ($user && !empty($user->user_email)) {
      $email = $user->user_email;
    }
  }

  if ($email === '') {
    $dbg('No email provided and no logged-in user email.');
    return hs_referrals_render_card($title, 0, 0, $debug);
  }

  // =========================
  // HUBSPOT REQUEST HELPER
  // =========================
  $hs_request = function ($method, $url, $body = null) use ($HUBSPOT_PRIVATE_APP_TOKEN) {

    $args = [
      'method'  => $method,
      'timeout' => 20,
      'headers' => [
        'Authorization' => 'Bearer ' . $HUBSPOT_PRIVATE_APP_TOKEN,
        'Content-Type'  => 'application/json',
      ],
    ];

    if ($body !== null) {
      $args['body'] = wp_json_encode($body);
    }

    $res = wp_remote_request($url, $args);
    if (is_wp_error($res)) return $res;

    $code = wp_remote_retrieve_response_code($res);
    $raw  = wp_remote_retrieve_body($res);
    $json = json_decode($raw, true);

    if ($code < 200 || $code >= 300) {
      return new WP_Error(
        'hubspot_http_error',
        'HubSpot API request failed',
        ['status' => $code, 'url' => $url, 'body' => $json ?: $raw]
      );
    }

    return $json;
  };

  // =========================
  // (A) FIND CONTACT ID BY EMAIL
  // =========================
  $contact = $hs_request('POST',
    'https://api.hubapi.com/crm/v3/objects/contacts/search',
    [
      'filterGroups' => [[
        'filters' => [[
          'propertyName' => 'email',
          'operator'     => 'EQ',
          'value'        => $email,
        ]]
      ]],
      'limit' => 1
    ]
  );

  if (is_wp_error($contact)) {
    $dbg('Contact search failed:', $contact->get_error_data());
    return hs_referrals_render_card($title, 0, 0, $debug);
  }

  if (empty($contact['results'][0]['id'])) {
    $dbg('No contact found for email:', ['email' => $email, 'response' => $contact]);
    return hs_referrals_render_card($title, 0, 0, $debug);
  }

  $contactId = $contact['results'][0]['id'];

  // =========================
  // (B) LOOK UP THE CORRECT ASSOCIATION typeId BY LABEL
  // GET /crm/associations/v4/{fromObjectType}/{toObjectType}/labels
  // =========================
  $labels = $hs_request(
    'GET',
    "https://api.hubapi.com/crm/associations/v4/contacts/{$CUSTOM_OBJECT_TYPE}/labels"
  );

  if (is_wp_error($labels)) {
    $dbg('Association labels lookup failed:', $labels->get_error_data());
    return hs_referrals_render_card($title, 0, 0, $debug);
  }

  $wantedTypeId = null;
  $wantedCategory = null;

  if (!empty($labels['results'])) {
    foreach ($labels['results'] as $l) {
      $lbl = isset($l['label']) ? trim((string)$l['label']) : '';
      if (strcasecmp($lbl, $labelNeed) === 0) {
        $wantedTypeId   = $l['typeId'] ?? null;
        $wantedCategory = $l['category'] ?? null; // e.g. USER_DEFINED / HUBSPOT_DEFINED
        break;
      }
    }
  }

  if (!$wantedTypeId) {
    $dbg('Could not find association label/typeId match:', ['neededLabel' => $labelNeed, 'labels' => $labels]);
    return hs_referrals_render_card($title, 0, 0, $debug);
  }

  // =========================
  // (C) LIST CONTACT -> REFERRAL ASSOCIATIONS
  // GET /crm/v4/objects/{objectType}/{objectId}/associations/{toObjectType}
  // =========================
  $assoc = $hs_request(
    'GET',
    "https://api.hubapi.com/crm/v4/objects/contacts/{$contactId}/associations/{$CUSTOM_OBJECT_TYPE}?limit=500"
  );

  if (is_wp_error($assoc)) {
    $dbg('Associations list failed:', $assoc->get_error_data());
    return hs_referrals_render_card($title, 0, 0, $debug);
  }

  if (empty($assoc['results'])) {
    $dbg('No associations returned:', $assoc);
    return hs_referrals_render_card($title, 0, 0, $debug);
  }

  // =========================
  // (D) COUNT ONLY ASSOCIATIONS WITH THE WANTED typeId (and category if present)
  // =========================
  $referrals = 0;

  foreach ($assoc['results'] as $row) {
    if (empty($row['associationTypes'])) continue;

    foreach ($row['associationTypes'] as $t) {
      $typeId   = $t['typeId'] ?? null;
      $category = $t['category'] ?? null;

      if ((string)$typeId === (string)$wantedTypeId) {
        // If HubSpot returned a category for the label, enforce it; otherwise just match typeId.
        if ($wantedCategory && $category && $category !== $wantedCategory) {
          continue;
        }
        $referrals++;
        break;
      }
    }
  }

  // Free Weeks Earned is not final yet, so keep it at 0 for now.
  // $weeks = (int) floor($referrals / $perWeek);
  $weeks = 0;

  // Cache
  if ($cacheMins > 0) {
    $cacheKey = 'hs_referrals_' . md5(strtolower($email) . '|' . strtolower($labelNeed));
    set_transient($cacheKey, ['referrals' => $referrals, 'weeks' => $weeks], $cacheMins * MINUTE_IN_SECONDS);
  }

  return hs_referrals_render_card($title, $referrals, $weeks, $debug);
}

/* =========================
 * UI RENDER
 * ========================= */
function hs_referrals_render_card($title, $referrals, $weeks, $debug = []) {

  $debugHtml = '';
  if (!empty($debug)) {
    $debugHtml = '<pre class="hs-referrals-debug">'.esc_html(implode("\n", $debug)).'</pre>';
  }

  return '
  <div class="hs-referrals-summary-card">
    <div class="hs-referrals-summary-title">'.esc_html($title).'</div>
    <div class="hs-referrals-summary-row"><strong>Number of Referrals:</strong> '.(int)$referrals.'</div>
    <div class="hs-referrals-summary-row"><strong>Free Weeks Earned:</strong> '.(int)$weeks.'</div>
    '.$debugHtml.'
  </div>

  <style>
    .hs-referrals-summary-card{
      border:1px solid #e5e7eb;
      border-radius:12px;
      padding:22px;
      background:#fff;
    }
    .hs-referrals-summary-title{
      font-size:22px;
      font-weight:700;
      margin-bottom:14px;
    }
    .hs-referrals-summary-row{
      font-size:15px;
      margin:8px 0;
    }
    .hs-referrals-debug{
      margin-top:14px;
      padding:12px;
      background:#f9fafb;
      border:1px solid #e5e7eb;
      border-radius:10px;
      font-size:12px;
      overflow:auto;
      white-space:pre-wrap;
    }
  </style>';
}


add_shortcode('referrals_summary', 'hs_referrals_summary_shortcode');
