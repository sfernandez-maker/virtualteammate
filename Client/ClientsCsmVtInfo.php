/**
 * VT Portal: "My CSM and Virtual Teammate" tab (Jonas + Emer sheets)
 *
 * UI rules:
 * - NO Primary Virtual Teammate section.
 * - Status is shown in the teammates table next to Client's Company.
 * - NO WDT, NO internal source text.
 * - CSM card is neat and readable.
 *
 * Matching:
 * - Match logged-in user by FIRST NAME + LAST NAME against sheet "Teammate Name" (Jonas)
 * - If no match, fallback to Emer tab where VT name column is usually "VT"
 * - Group all teammates by the matched company/client.
 *
 * Paste into Code Snippets and ACTIVATE.
 * Shortcode: [vt_my_csm_vt]
 */

if (!defined('ABSPATH')) { exit; }

/** =========================
 *  CONFIG
 *  ========================= */
define('VT_CSM_SHEET_ID', '1JNnmDUZoBGDqT6z_syR6dFOOnqiGQEHYdKbhlbKUB-Y');
define('VT_CSM_GID_JONAS', 508242569); // Jonas tab
define('VT_CSM_GID_EMER', 0);          // Emer tab

function vtportal_csm_directory(): array {
  return [
    'Jonas Orana'     => ['name'=>'Jonas Orana','email'=>'jorana@virtualteammate.com','phone'=>'+63 929 438 1119'],
    'Emerson Gerona'  => ['name'=>'Emerson Gerona','email'=>'egerona@virtualteammate.com','phone'=>'+63 962 2122 3708'],

    // variants
    'Jonas'    => ['name'=>'Jonas Orana','email'=>'jorana@virtualteammate.com','phone'=>'+63 929 438 1119'],
    'Emer'     => ['name'=>'Emerson Gerona','email'=>'egerona@virtualteammate.com','phone'=>'+63 962 2122 3708'],
    'Emerson'  => ['name'=>'Emerson Gerona','email'=>'egerona@virtualteammate.com','phone'=>'+63 962 2122 3708'],
  ];
}

/** =========================
 *  HELPERS
 *  ========================= */
function vtportal_norm_header(?string $s): string {
  $s = (string)$s;
  $s = trim($s);
  $s = str_replace(["’","‘","`","´"], "'", $s);
  $s = mb_strtolower($s);
  $s = preg_replace('/[^a-z0-9 ]+/', ' ', $s);
  $s = preg_replace('/\s+/', ' ', $s);
  return trim($s);
}

function vtportal_norm_name(?string $s): string {
  $s = (string)$s;
  $s = trim($s);
  $s = str_replace(["’","‘","`","´"], "'", $s);
  $s = mb_strtolower($s);
  $s = preg_replace('/[^a-z0-9 ]+/', ' ', $s);
  $s = preg_replace('/\s+/', ' ', $s);
  return trim($s);
}

function vtportal_is_email_like(string $s): bool {
  $s = trim($s);
  return ($s !== '' && strpos($s, '@') !== false);
}

function vtportal_sheet_csv_url(int $gid): string {
  return sprintf(
    'https://docs.google.com/spreadsheets/d/%s/export?format=csv&gid=%d',
    rawurlencode(VT_CSM_SHEET_ID),
    $gid
  );
}

function vtportal_fetch_sheet_rows(int $gid): array {
  $url = vtportal_sheet_csv_url($gid);

  $resp = wp_remote_get($url, [
    'timeout' => 25,
    'headers' => ['Accept' => 'text/csv'],
  ]);

  if (is_wp_error($resp)) {
    return ['_error' => $resp->get_error_message()];
  }

  $code = wp_remote_retrieve_response_code($resp);
  $body = wp_remote_retrieve_body($resp);

  if ($code < 200 || $code >= 300 || !$body) {
    return ['_error' => "Failed to fetch sheet (HTTP {$code}). Ensure public sharing (Anyone with link -> Viewer)."];
  }

  if (stripos($body, '<html') !== false) {
    return ['_error' => 'Google returned HTML (not CSV). Even if "public", some workspace settings block CSV export.'];
  }

  $lines = preg_split("/\r\n|\n|\r/", $body);
  $lines = array_values(array_filter($lines, fn($l) => trim($l) !== ''));

  if (count($lines) < 2) {
    return ['_error' => 'Sheet returned too few rows.'];
  }

  $header_raw = str_getcsv(array_shift($lines));
  $headers_norm = [];
  foreach ($header_raw as $h) {
    $headers_norm[] = vtportal_norm_header($h);
  }

  $rows = [];
  foreach ($lines as $line) {
    $cols = str_getcsv($line);
    $row = [];
    foreach ($headers_norm as $i => $hn) {
      $row[$hn] = $cols[$i] ?? '';
    }
    $rows[] = $row;
  }

  return [
    '_headers_norm' => $headers_norm,
    '_rows' => $rows,
  ];
}

function vtportal_find_col(array $headers_norm, array $needles_norm): ?string {
  foreach ($headers_norm as $h) {
    foreach ($needles_norm as $n) {
      if ($n !== '' && str_contains($h, $n)) return $h;
    }
  }
  return null;
}

function vtportal_get_user_first_last(int $user_id): array {
  $user = wp_get_current_user();

  $first = get_user_meta($user_id, 'first_name', true);
  $last  = get_user_meta($user_id, 'last_name', true);

  $first = is_string($first) ? trim($first) : '';
  $last  = is_string($last)  ? trim($last)  : '';

  // fallback: display_name split
  if ($first === '' || $last === '') {
    $dn = trim((string)($user->display_name ?? ''));
    if ($dn !== '') {
      $parts = preg_split('/\s+/', $dn);
      if (count($parts) >= 2) {
        if ($first === '') $first = $parts[0];
        if ($last === '')  $last  = $parts[count($parts)-1];
      }
    }
  }

  return [$first, $last];
}

function vtportal_name_variants(string $first, string $last): array {
  $firstN = vtportal_norm_name($first);
  $lastN  = vtportal_norm_name($last);

  $v = [];
  if ($firstN !== '' && $lastN !== '') {
    $v[] = trim($firstN . ' ' . $lastN);  // First Last
    $v[] = trim($lastN . ' ' . $firstN);  // Last First
  }
  return array_values(array_unique(array_filter($v, fn($x) => $x !== '')));
}

function vtportal_find_row_by_name(array $rows, string $col_name, array $variants): ?array {
  foreach ($rows as $r) {
    $sheet_name = vtportal_norm_name($r[$col_name] ?? '');
    if ($sheet_name === '') continue;

    if (in_array($sheet_name, $variants, true)) return $r;

    // tolerant match (middle name etc.)
    $needle = $variants[0] ?? '';
    if ($needle !== '' && str_contains($sheet_name, $needle)) return $r;
  }
  return null;
}

function vtportal_build_teammates_by_company(
  array $rows,
  string $company_value,
  ?string $col_company,
  ?string $col_name,
  ?string $col_email,
  ?string $col_role,
  ?string $col_status
): array {
  if (!$col_company || trim($company_value) === '') return [];

  $out = [];
  $seen = [];

  foreach ($rows as $r) {
    $row_company = trim((string)($r[$col_company] ?? ''));
    if (vtportal_norm_name($row_company) !== vtportal_norm_name($company_value)) continue;

    $name   = $col_name   ? trim((string)($r[$col_name] ?? '')) : '';
    $email  = $col_email  ? trim((string)($r[$col_email] ?? '')) : '';
    $role   = $col_role   ? trim((string)($r[$col_role] ?? '')) : '';
    $status = $col_status ? trim((string)($r[$col_status] ?? '')) : '';

    // Email sanity (some Emer sheets have non-email codes)
    if ($email !== '' && !vtportal_is_email_like($email)) $email = '';

    if ($name === '' && $email === '') continue;

    $key = vtportal_norm_name($name . '|' . $email);
    if (isset($seen[$key])) continue;
    $seen[$key] = true;

    $out[] = [
      'name'    => $name,
      'email'   => $email,
      'role'    => $role,
      'status'  => $status,
      'company' => $company_value,
    ];
  }

  usort($out, fn($a,$b) => strcmp(vtportal_norm_name($a['name'] ?? ''), vtportal_norm_name($b['name'] ?? '')));
  return $out;
}

/** =========================
 *  AJAX LOOKUP (Jonas first, then Emer)
 *  ========================= */
add_action('wp_ajax_vtportal_lookup_csm', function () {
  if (!is_user_logged_in()) {
    wp_send_json_error(['message' => 'Not logged in.'], 401);
  }

  $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
  if (!wp_verify_nonce($nonce, 'vtportal_lookup_csm')) {
    wp_send_json_error(['message' => 'Invalid nonce.'], 403);
  }

  $user = wp_get_current_user();
  $user_id = (int)$user->ID;
  [$first_name, $last_name] = vtportal_get_user_first_last($user_id);
  $user_email = (string)$user->user_email;
  $variants = vtportal_name_variants($first_name, $last_name);

  $directory = vtportal_csm_directory();

  // ---------- Try JONAS ----------
  $jonas = vtportal_fetch_sheet_rows(VT_CSM_GID_JONAS);
  if (!isset($jonas['_error'])) {
    $h = $jonas['_headers_norm'];
    $r = $jonas['_rows'];

    $col_company = vtportal_find_col($h, [vtportal_norm_header("client's company"), vtportal_norm_header("clients company"), vtportal_norm_header("client company")]);
    $col_status  = vtportal_find_col($h, [vtportal_norm_header("status")]);
    $col_name    = vtportal_find_col($h, [vtportal_norm_header("teammate name")]);
    $col_email   = vtportal_find_col($h, [vtportal_norm_header("vtm email")]); // handles "VTM EMail"
    $col_role    = vtportal_find_col($h, [vtportal_norm_header("job role")]);  // handles "Job ROLE"
    $col_csm     = vtportal_find_col($h, [vtportal_norm_header("csm")]);

    if ($col_company && $col_name) {
      $found = vtportal_find_row_by_name($r, $col_name, $variants);
      if ($found) {
        $company = trim((string)($found[$col_company] ?? ''));
        $status  = $col_status ? trim((string)($found[$col_status] ?? '')) : '';

        $csm_name_raw = $col_csm ? trim((string)($found[$col_csm] ?? '')) : '';
        $csm_key = $csm_name_raw !== '' ? $csm_name_raw : 'Jonas Orana';
        $csm = $directory[$csm_key] ?? ($directory['Jonas Orana'] ?? ['name'=>$csm_key,'email'=>'','phone'=>'']);

        $all = vtportal_build_teammates_by_company($r, $company, $col_company, $col_name, $col_email, $col_role, $col_status);

        $self_name = $col_name ? trim((string)($found[$col_name] ?? '')) : '';
        $self_email = $col_email ? trim((string)($found[$col_email] ?? '')) : '';
        if ($self_email !== '' && !vtportal_is_email_like($self_email)) $self_email = '';
        $self_key = vtportal_norm_name($self_name . '|' . $self_email);

        $others = array_values(array_filter($all, function($t) use ($self_key){
          $tkey = vtportal_norm_name(($t['name'] ?? '') . '|' . ($t['email'] ?? ''));
          return $tkey !== $self_key;
        }));

        wp_send_json_success([
          'ok' => true,
          'account' => [
            'first_name' => (string)$first_name,
            'last_name'  => (string)$last_name,
            'email'      => $user_email,
            'company'    => $company,
            'status'     => $status,
          ],
          'csm' => $csm,
          'teammates' => $others,
        ]);
      }
    }
  }

  // ---------- Try EMER (fallback) ----------
  $emer = vtportal_fetch_sheet_rows(VT_CSM_GID_EMER);
  if (isset($emer['_error'])) {
    wp_send_json_success(['ok'=>false, 'message'=>'Emer sheet error: '.$emer['_error']]);
  }

  $h2 = $emer['_headers_norm'];
  $r2 = $emer['_rows'];

  // Emer headers you described: Client, VY/VA, VT, CSM
  $col2_company = vtportal_find_col($h2, [vtportal_norm_header("client"), vtportal_norm_header("company")]);
  $col2_name    = vtportal_find_col($h2, [vtportal_norm_header("vt"), vtportal_norm_header("vt name"), vtportal_norm_header("virtual teammate")]);

  // Treat VY/VA as an "email-ish" column if it contains emails; otherwise it will render blank
  $col2_email   = vtportal_find_col($h2, [vtportal_norm_header("vy"), vtportal_norm_header("va"), vtportal_norm_header("email")]);

  $col2_csm     = vtportal_find_col($h2, [vtportal_norm_header("csm")]);

  if (!$col2_company || !$col2_name) {
    wp_send_json_success([
      'ok' => false,
      'message' => 'Emer tab columns not detected. Expected at least: Client + VT + CSM.',
    ]);
  }

  $found2 = vtportal_find_row_by_name($r2, $col2_name, $variants);
  if (!$found2) {
    wp_send_json_success([
      'ok' => false,
      'message' => 'No match found in Jonas or Emer tabs for your First Name + Last Name.',
    ]);
  }

  $company2 = trim((string)($found2[$col2_company] ?? ''));

  $csm_name_raw2 = $col2_csm ? trim((string)($found2[$col2_csm] ?? '')) : '';
  $csm_key2 = $csm_name_raw2 !== '' ? $csm_name_raw2 : 'Emerson Gerona';
  $csm2 = $directory[$csm_key2] ?? ($directory['Emerson Gerona'] ?? ['name'=>$csm_key2,'email'=>'','phone'=>'']);

  // Emer typically has no Status/Role; keep as empty and UI will show "—"
  $all2 = vtportal_build_teammates_by_company($r2, $company2, $col2_company, $col2_name, $col2_email, null, null);

  $self_name2 = $col2_name ? trim((string)($found2[$col2_name] ?? '')) : '';
  $self_email2 = $col2_email ? trim((string)($found2[$col2_email] ?? '')) : '';
  if ($self_email2 !== '' && !vtportal_is_email_like($self_email2)) $self_email2 = '';
  $self_key2 = vtportal_norm_name($self_name2 . '|' . $self_email2);

  $others2 = array_values(array_filter($all2, function($t) use ($self_key2){
    $tkey = vtportal_norm_name(($t['name'] ?? '') . '|' . ($t['email'] ?? ''));
    return $tkey !== $self_key2;
  }));

  wp_send_json_success([
    'ok' => true,
    'account' => [
      'first_name' => (string)$first_name,
      'last_name'  => (string)$last_name,
      'email'      => $user_email,
      'company'    => $company2,
      'status'     => '', // Emer tab usually doesn't have Status
    ],
    'csm' => $csm2,
    'teammates' => $others2,
  ]);
});

/** =========================
 *  SHORTCODE UI
 *  ========================= */
add_shortcode('vt_my_csm_vt', function () {
  if (!is_user_logged_in()) {
    return '<div class="vtportal-shell"><div class="vtportal-card">Please log in to view your CSM and Virtual Teammates.</div></div>';
  }

  $nonce = wp_create_nonce('vtportal_lookup_csm');
  $ajax  = admin_url('admin-ajax.php');

  ob_start();
  ?>
  <div class="vtportal-shell" id="vtportal-csmvt" data-ajax="<?php echo esc_attr($ajax); ?>" data-nonce="<?php echo esc_attr($nonce); ?>">
    <div class="vtportal-header">
      <div>
        <div class="vtportal-title">My CSM and Virtual Teammates</div>
        <div class="vtportal-subtitle">Your Customer Success Manager and your other teammates under the same client/company.</div>
      </div>
      <div class="vtportal-actions">
        <button type="button" class="vtportal-btn vtportal-btn--primary" id="vtportal-refresh">Refresh</button>
      </div>
    </div>

    <div class="vtportal-grid">
      <div class="vtportal-card vtportal-card--accent">
        <div class="vtportal-card-title">Your CSM</div>

        <div class="vtportal-csm-skeleton" id="vtportal-csm-loading">
          <div class="sk-line sk-title"></div>
          <div class="sk-line"></div>
          <div class="sk-line"></div>
        </div>

        <div class="vtportal-csm" id="vtportal-csm" style="display:none;"></div>
        <div class="vtportal-empty" id="vtportal-csm-empty" style="display:none;"></div>
      </div>

      <div class="vtportal-card">
        <div class="vtportal-card-title">Account</div>
        <div class="vtportal-kv" id="vtportal-account">
          <div class="vtportal-loading">Loading…</div>
        </div>
      </div>
    </div>

    <div class="vtportal-card vtportal-card--wide">
      <div class="vtportal-wide-head">
        <div>
          <div class="vtportal-card-title">Other Virtual Teammates</div>
          <div class="vtportal-subtitle">Teammates shown here share the same client/company as you.</div>
        </div>
      </div>

      <div class="vtportal-tablewrap">
        <table class="vtportal-table" aria-label="Virtual Teammates">
          <thead>
            <tr>
              <th>Teammate Name</th>
              <th>VTM Email</th>
              <th>Job Role</th>
              <th>Client’s Company</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody id="vtportal-team">
            <tr><td colspan="5" class="vtportal-loadingcell">Loading teammates…</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <style>
    .vtportal-shell{ font-family: inherit; background: transparent; max-width: 1100px; margin: 0 auto; }
    .vtportal-header{ display:flex; gap:16px; align-items:flex-end; justify-content:space-between; margin-bottom: 14px; }
    .vtportal-title{ font-size: 22px; font-weight: 700; letter-spacing: -0.2px; margin: 0 0 4px 0; }
    .vtportal-subtitle{ font-size: 13px; opacity: 0.85; line-height: 1.4; }
    .vtportal-actions{ display:flex; gap:10px; align-items:center; }

    .vtportal-grid{ display:grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px; }
    @media (max-width: 860px){
      .vtportal-grid{ grid-template-columns: 1fr; }
      .vtportal-header{ align-items:flex-start; flex-direction: column; }
    }

    .vtportal-card{
      background: rgba(255,255,255,0.92);
      border-radius: 16px;
      padding: 16px;
      box-shadow: 0 10px 30px rgba(0,0,0,0.06);
      border: 1px solid rgba(0,0,0,0.06);
    }
    .vtportal-card--accent{ border-left: 6px solid #7077FF; }
    .vtportal-card-title{ font-size: 14px; font-weight: 800; margin-bottom: 10px; }

    .vtportal-btn{
      border: 0; border-radius: 12px; padding: 10px 14px; font-weight: 800; cursor: pointer;
      transition: transform .08s ease, background .15s ease, color .15s ease;
      user-select:none; white-space: nowrap;
    }
    .vtportal-btn:active{ transform: translateY(1px); }
    .vtportal-btn--primary{ background: #7077FF; color: #fff; }
    .vtportal-btn--primary:hover{ background: #F6B945; color: #fff; }
    .vtportal-btn--ghost{ background: rgba(0,0,0,0.04); color: #1a1a1a; }
    .vtportal-btn--ghost:hover{ background: rgba(246,185,69,0.25); color:#1a1a1a; }

    .vtportal-loading, .vtportal-loadingcell{ opacity: 0.85; font-size: 13px; }

    .vtportal-empty{
      font-size: 13px; opacity: 0.95; line-height: 1.5;
      background: rgba(246,185,69,0.12);
      border-radius: 12px; padding: 10px 12px;
      white-space: pre-wrap;
    }

    .vtportal-kv{
      display:grid; grid-template-columns: 120px 1fr;
      gap: 8px 12px; font-size: 13px; align-items: center;
    }
    .vtportal-kv .k{ opacity: 0.75; }
    .vtportal-kv .v{ font-weight: 650; word-break: break-word; }

    .vtportal-csm-card{
      display:flex; align-items:flex-start; justify-content:space-between; gap: 12px;
      padding: 12px; border-radius: 14px;
      background: rgba(112,119,255,0.08);
      border: 1px solid rgba(112,119,255,0.16);
    }
    .vtportal-csm-left{ display:flex; flex-direction:column; gap:6px; min-width: 0; }
    .vtportal-csm-name{ font-weight: 900; font-size: 15px; letter-spacing: -0.1px; }
    .vtportal-csm-meta{ display:flex; flex-direction:column; gap:4px; font-size: 13px; font-weight: 650; opacity: 0.92; }
    .vtportal-csm-meta a{ color:#1a1a1a; text-decoration:none; font-weight:800; }
    .vtportal-csm-meta a:hover{ text-decoration:underline; }
    .vtportal-csm-actions{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; justify-content:flex-end; }

    .vtportal-csm-skeleton{ display:flex; flex-direction:column; gap:10px; }
    .sk-line{ height: 12px; border-radius: 8px; background: rgba(0,0,0,0.06); overflow:hidden; position: relative; }
    .sk-title{ height: 16px; width: 65%; }
    .sk-line::after{
      content:''; position:absolute; top:0; left:-40%;
      width:40%; height:100%; background: rgba(255,255,255,0.55);
      animation: sk 1.2s infinite;
    }
    @keyframes sk { from { left:-40%; } to { left:110%; } }

    .vtportal-tablewrap{ overflow:auto; border-radius: 12px; border: 1px solid rgba(0,0,0,0.06); }
    .vtportal-table{ width:100%; border-collapse: separate; border-spacing: 0; background: #fff; min-width: 980px; }
    .vtportal-table th{
      text-align:left; font-size: 12px; padding: 12px;
      background: rgba(0,0,0,0.03);
      border-bottom: 1px solid rgba(0,0,0,0.06);
      white-space: nowrap;
      font-weight: 800;
    }
    .vtportal-table td{
      padding: 12px; font-size: 13px;
      border-bottom: 1px solid rgba(0,0,0,0.06);
      vertical-align: middle; white-space: nowrap;
      font-weight: 600;
    }
    .vtportal-table tr:last-child td{ border-bottom: 0; }

    .vtportal-pill{
      display:inline-flex; align-items:center; gap:6px;
      padding: 6px 10px; border-radius: 999px;
      font-weight: 900; font-size: 12px;
      border: 1px solid rgba(0,0,0,0.08);
      background: rgba(0,0,0,0.03);
    }
    .vtportal-pill--status{
      background: rgba(246,185,69,0.18);
      border: 1px solid rgba(246,185,69,0.35);
    }
  </style>

  <script>
    (function(){
      const root = document.getElementById('vtportal-csmvt');
      if (!root) return;

      const ajaxUrl = root.getAttribute('data-ajax');
      const nonce = root.getAttribute('data-nonce');

      const elAccount = document.getElementById('vtportal-account');
      const elCsmLoading = document.getElementById('vtportal-csm-loading');
      const elCsm = document.getElementById('vtportal-csm');
      const elCsmEmpty = document.getElementById('vtportal-csm-empty');
      const elTeam = document.getElementById('vtportal-team');
      const btnRefresh = document.getElementById('vtportal-refresh');

      function esc(s){
        return String(s ?? '').replace(/[&<>"']/g, m => ({
          '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
        })[m]);
      }

      function setLoading(){
        elCsmLoading.style.display = '';
        elCsm.style.display = 'none';
        elCsmEmpty.style.display = 'none';
        elCsmEmpty.textContent = '';

        elAccount.innerHTML = '<div class="vtportal-loading">Loading…</div>';
        elTeam.innerHTML = '<tr><td colspan="5" class="vtportal-loadingcell">Loading teammates…</td></tr>';
      }

      async function lookup(){
        setLoading();

        const form = new FormData();
        form.append('action', 'vtportal_lookup_csm');
        form.append('nonce', nonce);

        let data;
        try{
          const res = await fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body: form });
          data = await res.json();
        } catch(e){
          elCsmLoading.style.display = 'none';
          elCsmEmpty.style.display = '';
          elCsmEmpty.textContent = 'Network error. Please refresh and try again.';
          elTeam.innerHTML = '<tr><td colspan="5">Unable to load teammates.</td></tr>';
          return;
        }

        const payload = data && data.success ? (data.data || {}) : {};
        if (!payload.ok){
          elCsmLoading.style.display = 'none';
          elCsmEmpty.style.display = '';
          elCsmEmpty.textContent = esc(payload.message || 'No data found for this account.');
          elTeam.innerHTML = '<tr><td colspan="5">No teammates found.</td></tr>';
          elAccount.innerHTML = `<div class="vtportal-loading">${esc(payload.message || 'No data found.')}</div>`;
          return;
        }

        const account = payload.account || {};
        const csm = payload.csm || null;
        const teammates = payload.teammates || [];

        // Account
        const statusText = (account.status && String(account.status).trim() !== '') ? account.status : '—';
        elAccount.innerHTML = `
          <div class="k">Name</div><div class="v">${esc((account.first_name||'') + ' ' + (account.last_name||''))}</div>
          <div class="k">Email</div><div class="v">${esc(account.email || '—')}</div>
          <div class="k">Client</div><div class="v">${esc(account.company || '—')}</div>
          <div class="k">Status</div><div class="v"><span class="vtportal-pill vtportal-pill--status">${esc(statusText)}</span></div>
        `;

        // CSM
        elCsmLoading.style.display = 'none';
        if (csm && csm.name){
          const mail = csm.email ? `mailto:${csm.email}?subject=${encodeURIComponent('Support Request - ' + (account.company||'My Account'))}` : '';
          const tel = csm.phone ? `tel:${String(csm.phone).replace(/\\s+/g,'')}` : '';
          elCsm.innerHTML = `
            <div class="vtportal-csm-card">
              <div class="vtportal-csm-left">
                <div class="vtportal-csm-name">${esc(csm.name)}</div>
                <div class="vtportal-csm-meta">
                  <div>${csm.email ? `Email: <a href="mailto:${esc(csm.email)}">${esc(csm.email)}</a>` : 'Email: —'}</div>
                  <div>${csm.phone ? `Phone: <a href="${esc(tel)}">${esc(csm.phone)}</a>` : 'Phone: —'}</div>
                </div>
              </div>
              <div class="vtportal-csm-actions">
                ${csm.email ? `<a class="vtportal-btn vtportal-btn--primary" href="${esc(mail)}">Email</a>` : ``}
                ${csm.phone ? `<a class="vtportal-btn vtportal-btn--ghost" href="${esc(tel)}">Call</a>` : ``}
              </div>
            </div>
          `;
          elCsm.style.display = '';
        } else {
          elCsm.style.display = 'none';
          elCsmEmpty.style.display = '';
          elCsmEmpty.textContent = 'CSM not found for this account.';
        }

        // Teammates table
        if (!teammates.length){
          elTeam.innerHTML = `<tr><td colspan="5">No other teammates found for this client/company.</td></tr>`;
        } else {
          elTeam.innerHTML = teammates.map(t => {
            const st = (t.status && String(t.status).trim() !== '') ? t.status : '—';
            return `
              <tr>
                <td>${esc(t.name || '—')}</td>
                <td>${esc(t.email || '—')}</td>
                <td>${esc(t.role || '—')}</td>
                <td>${esc(t.company || account.company || '—')}</td>
                <td><span class="vtportal-pill vtportal-pill--status">${esc(st)}</span></td>
              </tr>
            `;
          }).join('');
        }
      }

      btnRefresh && btnRefresh.addEventListener('click', lookup);
      lookup();
    })();
  </script>
  <?php
  return ob_get_clean();
});
