/**
 * Value Creation Calculator (Dual: Actual vs Scenario)
 * Shortcode: [roi_savings_calculator]
 *
 * LEFT (Actual):
 * - DOM detection for Hired VTs (portal)
 * - Resolves company with fallback order:
 *   1) Contact email -> associated company (API)
 *   2) Hired VT name(s) -> VT ACCOUNTS sheet (gid=1424706697) -> Client's Company -> company search (API)
 *   3) Email -> CLIENTS sheet (gid=632565396) -> Client Company -> company search (API)
 *   4) Username -> CLIENTS sheet -> Client Company -> company search (API)
 *   5) If still no company_id: contract search by company token OR hired VT token
 * - Pulls VT Contract records (custom object)
 * - ✅ ALSO pulls associated Deals and merges fields:
 *     vt_start_date, bill_rate, role, selected_vt_name, date___end_of_service
 * - Front-end shows ONLY hired VTs; others are skipped (strict)
 * - EOS shown for hired VTs with EOS stage/date
 *
 * RIGHT (Scenario):
 * - Bi-weekly table collapsed
 * - Cost breakdown collapsed
 *
 * REQUIRED in wp-config.php:
 * define('HUBSPOT_PRIVATE_APP_TOKEN', 'pat-xxxxx...');
 */

if (!function_exists('roi_savings_calculator_shortcode')) {

  // -----------------------------
  // Helpers (server)
  // -----------------------------
  function roix_norm_str($s) {
    $s = (string)$s;
    $s = str_replace(["\xEF\xBB\xBF", "’", "‘"], ["", "'", "'"], $s);
    $s = strtolower(trim($s));
    $s = preg_replace('/\s+/', ' ', $s);
    return $s;
  }

  // first + last initial (handles "Analeah G." <-> "Analeah Gutierrez")
  function roix_name_key($name) {
    $name = roix_norm_str($name);
    $name = preg_replace('/[^a-z0-9\s\.]/', ' ', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    $parts = array_values(array_filter(explode(' ', trim($name))));
    if (!$parts) return '';

    $first = $parts[0];
    $lastInitial = '';

    for ($i=1; $i<count($parts); $i++) {
      $p = str_replace('.', '', $parts[$i]);
      $p = trim($p);
      if (strlen($p) === 1) $lastInitial = $p;
    }
    if (!$lastInitial && count($parts) >= 2) {
      $last = str_replace('.', '', $parts[count($parts)-1]);
      $lastInitial = substr($last, 0, 1);
    }
    return $first . '|' . ($lastInitial ?: '');
  }

  function roix_company_key($name) {
    $name = roix_norm_str($name);
    $name = preg_replace('/[^a-z0-9\s]/', ' ', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    return trim($name);
  }

  function roix_fetch_sheet_csv($spreadsheet_id, $gid) {
    $cache_key = 'roix_gsheet_' . md5($spreadsheet_id . '|' . $gid);
    $cached = get_transient($cache_key);
    if (is_string($cached) && $cached !== '') return $cached;

    $url = "https://docs.google.com/spreadsheets/d/{$spreadsheet_id}/gviz/tq?tqx=out:csv&gid={$gid}";
    $res = wp_remote_get($url, ['timeout' => 25]);
    if (is_wp_error($res)) return '';
    $code = wp_remote_retrieve_response_code($res);
    if ($code < 200 || $code >= 300) return '';

    $body = (string) wp_remote_retrieve_body($res);
    if ($body === '') return '';

    set_transient($cache_key, $body, 30 * MINUTE_IN_SECONDS);
    return $body;
  }

  function roix_parse_csv($csv) {
    if (!$csv) return [];
    $csv = (string)$csv;
    $csv = str_replace("\xEF\xBB\xBF", '', $csv);
    $lines = preg_split("/\r\n|\n|\r/", trim($csv));
    $rows = [];
    foreach ($lines as $line) {
      if (trim($line) === '') continue;
      if (stripos(trim($line), 'sep=') === 0) continue;
      $rows[] = str_getcsv($line);
    }
    return $rows;
  }

  function roix_sheet_maps() {
    $spreadsheet_id = '1sRpFUHuf5QR3avOnzRErDXGREwzdcJSwu7SfGU9_Nqk';

    $rowsClients = roix_parse_csv(roix_fetch_sheet_csv($spreadsheet_id, '632565396'));
    $rowsVtAcct  = roix_parse_csv(roix_fetch_sheet_csv($spreadsheet_id, '1424706697'));

    $email_to_company = [];
    $username_to_company = [];
    $vtkey_to_company = [];

    $header_index = function($headerRow) {
      $map = [];
      foreach ($headerRow as $i => $h) {
        $k = roix_norm_str($h);
        $k = str_replace(["’","‘"],["'","'"], $k);
        $k = preg_replace('/[^\w\s\']+/', '', $k);
        $k = preg_replace('/\s+/', ' ', $k);
        $k = trim($k);
        if ($k !== '') $map[$k] = $i;
      }
      return $map;
    };

    // CLIENTS sheet: CLIENT COMPANY | CLIENT GMAIL | USERNAME | ...
    if (count($rowsClients) >= 2) {
      $hmap = $header_index($rowsClients[0]);

      $idx_company = $hmap['client company'] ?? $hmap["client's company"] ?? $hmap['company'] ?? 0;
      $idx_email   = $hmap['client gmail'] ?? $hmap['client email'] ?? $hmap['email'] ?? 1;
      $idx_user    = $hmap['username'] ?? 2;

      for ($r=1; $r<count($rowsClients); $r++) {
        $row = $rowsClients[$r];
        if (!is_array($row) || count($row) < 2) continue;

        $company = isset($row[$idx_company]) ? trim($row[$idx_company]) : '';
        $email   = isset($row[$idx_email])   ? trim($row[$idx_email])   : '';
        $user    = isset($row[$idx_user])    ? trim($row[$idx_user])    : '';

        if ($company !== '') {
          if ($email !== '') $email_to_company[strtolower($email)] = $company;
          if ($user  !== '') $username_to_company[strtolower($user)] = $company;
        }
      }
    }

    // VT ACCOUNTS: CLIENT'S COMPANY | VT NAME | ...
    if (count($rowsVtAcct) >= 2) {
      $hmap = $header_index($rowsVtAcct[0]);

      $idx_company = $hmap["client's company"] ?? $hmap['clients company'] ?? $hmap['client company'] ?? $hmap['client'] ?? 0;
      $idx_vtname  = $hmap['vt name'] ?? $hmap['virtual teammate'] ?? $hmap['selected vt name'] ?? $hmap['name'] ?? 1;

      for ($r=1; $r<count($rowsVtAcct); $r++) {
        $row = $rowsVtAcct[$r];
        if (!is_array($row) || count($row) < 2) continue;

        $company = isset($row[$idx_company]) ? trim($row[$idx_company]) : '';
        $vtname  = isset($row[$idx_vtname])  ? trim($row[$idx_vtname])  : '';

        if ($company !== '' && $vtname !== '') {
          $k = roix_name_key($vtname);
          if ($k) $vtkey_to_company[$k] = $company;
        }
      }
    }

    return [
      'email_to_company'    => $email_to_company,
      'username_to_company' => $username_to_company,
      'vtkey_to_company'    => $vtkey_to_company,
    ];
  }

  // -----------------------------
  // AJAX
  // -----------------------------
  add_action('wp_ajax_roix_get_contracts', 'roix_get_contracts_ajax');
  function roix_get_contracts_ajax() {
    if (!is_user_logged_in()) {
      wp_send_json_error(['message' => 'Unauthorized'], 401);
    }
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field($_POST['nonce']), 'roix_nonce')) {
      wp_send_json_error(['message' => 'Bad nonce'], 403);
    }
    if (!defined('HUBSPOT_PRIVATE_APP_TOKEN') || !HUBSPOT_PRIVATE_APP_TOKEN) {
      wp_send_json_error(['message' => 'Missing API token'], 500);
    }

    $user = wp_get_current_user();
    $wp_email = isset($user->user_email) ? trim($user->user_email) : '';
    $wp_login = isset($user->user_login) ? trim($user->user_login) : '';
    $wp_disp  = isset($user->display_name) ? trim($user->display_name) : '';

    $dom_email = isset($_POST['client_email']) ? sanitize_email($_POST['client_email']) : '';

    $hired_names = [];
    if (isset($_POST['hired_names']) && is_array($_POST['hired_names'])) {
      foreach ($_POST['hired_names'] as $n) {
        $n = sanitize_text_field($n);
        if ($n !== '') $hired_names[] = $n;
      }
    }

    $token = HUBSPOT_PRIVATE_APP_TOKEN;

    $hs = function($method, $url, $body = null) use ($token) {
      $args = [
        'method'  => $method,
        'timeout' => 25,
        'headers' => [
          'Authorization' => 'Bearer ' . $token,
          'Content-Type'  => 'application/json',
        ],
      ];
      if ($body !== null) $args['body'] = wp_json_encode($body);

      $res = wp_remote_request($url, $args);
      if (is_wp_error($res)) return ['ok' => false, 'err' => $res->get_error_message()];
      $code = wp_remote_retrieve_response_code($res);
      $raw  = wp_remote_retrieve_body($res);
      $json = json_decode($raw, true);

      if ($code < 200 || $code >= 300) {
        return ['ok' => false, 'code' => $code, 'body' => $json ?: $raw];
      }
      return ['ok' => true, 'code' => $code, 'body' => $json ?: []];
    };

    $custom_object_type = '2-31153232';

    // Stage map (id -> label)
    $stage_map = [];
    $pipe_key = 'roix_pipe_map_' . $custom_object_type;
    $cached = get_transient($pipe_key);
    if (is_array($cached)) {
      $stage_map = $cached;
    } else {
      $p = $hs('GET', "https://api.hubapi.com/crm/v3/pipelines/{$custom_object_type}");
      if ($p['ok'] && !empty($p['body']['results'])) {
        foreach ($p['body']['results'] as $pipeline) {
          if (!empty($pipeline['stages'])) {
            foreach ($pipeline['stages'] as $st) {
              if (!empty($st['id']) && !empty($st['label'])) {
                $stage_map[(string)$st['id']] = (string)$st['label'];
              }
            }
          }
        }
        set_transient($pipe_key, $stage_map, 6 * HOUR_IN_SECONDS);
      }
    }

    $norm_date = function($v) {
      if (!$v) return '';
      $ts = strtotime((string)$v);
      if ($ts) return gmdate('Y-m-d', $ts);
      if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v)) return (string)$v;
      return '';
    };

    // Sheet maps
    $maps = roix_sheet_maps();
    $email_to_company = $maps['email_to_company'];
    $username_to_company = $maps['username_to_company'];
    $vtkey_to_company = $maps['vtkey_to_company'];

    $emails = [];
    if ($dom_email) $emails[] = strtolower($dom_email);
    if ($wp_email)  $emails[] = strtolower($wp_email);
    $emails = array_values(array_unique(array_filter($emails)));

    $company_id = '';
    $company_name = '';
    $company_guess = '';
    $resolved_by = '';

    // 1) Email -> Contact -> Company association
    $try_contact_email = function($email) use ($hs, &$company_id, &$company_name) {
      if (!$email) return false;

      $contact_search = $hs('POST', 'https://api.hubapi.com/crm/v3/objects/contacts/search', [
        'filterGroups' => [[
          'filters' => [[
            'propertyName' => 'email',
            'operator'     => 'EQ',
            'value'        => $email
          ]]
        ]],
        'properties' => ['email'],
        'limit'      => 1
      ]);

      if (!$contact_search['ok'] || empty($contact_search['body']['results'][0]['id'])) return false;
      $contact_id = $contact_search['body']['results'][0]['id'];

      $assoc = $hs('GET', "https://api.hubapi.com/crm/v4/objects/contacts/{$contact_id}/associations/companies");
      if (!$assoc['ok'] || empty($assoc['body']['results'][0]['toObjectId'])) return false;

      $company_id = (string)$assoc['body']['results'][0]['toObjectId'];

      $c = $hs('GET', "https://api.hubapi.com/crm/v3/objects/companies/{$company_id}?properties=name,domain");
      if ($c['ok'] && !empty($c['body']['properties']['name'])) {
        $company_name = (string)$c['body']['properties']['name'];
      }
      return true;
    };

    foreach ($emails as $em) {
      if ($try_contact_email($em)) { $resolved_by = 'email_assoc'; break; }
    }

    // 2) Hired VT name(s) -> VT ACCOUNTS sheet -> company_guess
    if (!$company_id && !$company_guess && $hired_names) {
      $hits = [];
      foreach ($hired_names as $hn) {
        $k = roix_name_key($hn);
        if ($k && isset($vtkey_to_company[$k])) $hits[] = $vtkey_to_company[$k];
      }
      if ($hits) {
        $freq = array_count_values($hits);
        arsort($freq);
        $company_guess = (string) array_key_first($freq);
        $resolved_by = 'vt_sheet';
      }
    }

    // 3) Email -> CLIENTS sheet -> company_guess
    if (!$company_id && !$company_guess && $emails) {
      foreach ($emails as $em) {
        if (isset($email_to_company[$em])) {
          $company_guess = (string)$email_to_company[$em];
          $resolved_by = 'email_sheet';
          break;
        }
      }
    }

    // 4) Username -> CLIENTS sheet -> company_guess
    if (!$company_id && !$company_guess && $wp_login) {
      $lk = strtolower($wp_login);
      if (isset($username_to_company[$lk])) {
        $company_guess = (string)$username_to_company[$lk];
        $resolved_by = 'username_sheet';
      }
    }

    // company_guess -> company search -> company_id
    if (!$company_id && $company_guess) {
      $needle = roix_company_key($company_guess);
      $tokenWord = $needle;
      if (strpos($needle, ' ') !== false) {
        $parts = preg_split('/\s+/', $needle);
        $tokenWord = $parts ? $parts[0] : $needle;
      }

      $company_search = $hs('POST', 'https://api.hubapi.com/crm/v3/objects/companies/search', [
        'filterGroups' => [[
          'filters' => [[
            'propertyName' => 'name',
            'operator'     => 'CONTAINS_TOKEN',
            'value'        => $tokenWord
          ]]
        ]],
        'properties' => ['name'],
        'limit'      => 25
      ]);

      if ($company_search['ok'] && !empty($company_search['body']['results'])) {
        $best = null;
        $bestScore = -1;
        foreach ($company_search['body']['results'] as $r) {
          $n = isset($r['properties']['name']) ? (string)$r['properties']['name'] : '';
          if (!$n) continue;
          $nk = roix_company_key($n);
          $score = 0;
          if ($nk === $needle) $score += 100;
          if (strpos($nk, $needle) !== false || strpos($needle, $nk) !== false) $score += 50;
          if (strpos($nk, $tokenWord) !== false) $score += 10;
          if ($score > $bestScore) { $bestScore = $score; $best = $r; }
        }
        if ($best && !empty($best['id'])) {
          $company_id = (string)$best['id'];
          $company_name = !empty($best['properties']['name']) ? (string)$best['properties']['name'] : $company_guess;
        }
      }
    }

    // Contract IDs
    $contract_ids = [];

    if ($company_id) {
      $assoc_contracts = $hs('GET', "https://api.hubapi.com/crm/v4/objects/companies/{$company_id}/associations/{$custom_object_type}");
      if ($assoc_contracts['ok'] && !empty($assoc_contracts['body']['results'])) {
        foreach ($assoc_contracts['body']['results'] as $r) {
          if (!empty($r['toObjectId'])) $contract_ids[] = (string)$r['toObjectId'];
        }
      }
    }

    // 5) Last resort: contract search by company token OR VT token
    if (!$contract_ids) {
      $company_token = '';
      if ($company_guess) {
        $ck = roix_company_key($company_guess);
        if ($ck !== '') $company_token = (strpos($ck, ' ') !== false) ? (preg_split('/\s+/', $ck)[0] ?? $ck) : $ck;
      }

      $vt_token = '';
      if ($hired_names) {
        $first = roix_norm_str($hired_names[0]);
        $first = preg_split('/\s+/', $first);
        $vt_token = $first ? (string)$first[0] : '';
      }

      $filterGroups = [];
      if ($company_token !== '') {
        $filterGroups[] = [[
          'propertyName' => 'hs_name',
          'operator'     => 'CONTAINS_TOKEN',
          'value'        => $company_token
        ]];
      }
      if ($vt_token !== '') {
        $filterGroups[] = [[
          'propertyName' => 'selected_vt_name',
          'operator'     => 'CONTAINS_TOKEN',
          'value'        => $vt_token
        ]];
      }

      if (!$filterGroups) {
        $fallback_key = $wp_disp ?: $wp_login;
        $fk = roix_company_key($fallback_key);
        if ($fk !== '') {
          $tok = (strpos($fk, ' ') !== false) ? (preg_split('/\s+/', $fk)[0] ?? $fk) : $fk;
          $filterGroups[] = [[
            'propertyName' => 'hs_name',
            'operator'     => 'CONTAINS_TOKEN',
            'value'        => $tok
          ]];
        }
      }

      if ($filterGroups) {
        $payloadGroups = [];
        foreach ($filterGroups as $g) {
          $payloadGroups[] = ['filters' => $g];
        }

        $contract_search = $hs('POST', "https://api.hubapi.com/crm/v3/objects/{$custom_object_type}/search", [
          'filterGroups' => $payloadGroups,
          'properties'   => ['hs_name','selected_vt_name'],
          'limit'        => 100
        ]);

        if ($contract_search['ok'] && !empty($contract_search['body']['results'])) {
          foreach ($contract_search['body']['results'] as $r) {
            if (!empty($r['id'])) $contract_ids[] = (string)$r['id'];
          }
        }
      }
      if (!$resolved_by && $contract_ids) $resolved_by = 'contract_search';
    }

    $contract_ids = array_values(array_unique(array_filter($contract_ids)));

    // Batch read VT Contract object
    $contracts = [];
    if ($contract_ids) {
      $props = [
        'hs_name',
        'hs_pipeline_stage',
        'bill_rate',
        'contract_start_date',
        'vt_start_date',
        'date___end_of_service',
        'vt_end_date',
        'role',
        'selected_vt_name',
      ];

      $batch = $hs('POST', "https://api.hubapi.com/crm/v3/objects/{$custom_object_type}/batch/read", [
        'properties' => $props,
        'inputs'     => array_map(function($id){ return ['id' => $id]; }, $contract_ids)
      ]);

      if ($batch['ok'] && !empty($batch['body']['results'])) {
        foreach ($batch['body']['results'] as $row) {
          $p = isset($row['properties']) ? $row['properties'] : [];
          $stage_id = isset($p['hs_pipeline_stage']) ? (string)$p['hs_pipeline_stage'] : '';
          $stage_label = $stage_id && isset($stage_map[$stage_id]) ? $stage_map[$stage_id] : $stage_id;

          $start = $norm_date($p['vt_start_date'] ?? '');
          if (!$start) $start = $norm_date($p['contract_start_date'] ?? '');

          $end_eos = $norm_date($p['date___end_of_service'] ?? '');
          if (!$end_eos) $end_eos = $norm_date($p['vt_end_date'] ?? '');

          $contracts[] = [
            'source'           => 'contract',
            'id'               => (string)($row['id'] ?? ''),
            'name'             => (string)($p['hs_name'] ?? ''),
            'stage_id'         => $stage_id,
            'stage_label'      => (string)$stage_label,
            'bill_rate'        => (string)($p['bill_rate'] ?? ''),
            'role'             => (string)($p['role'] ?? ''),
            'selected_vt_name' => (string)($p['selected_vt_name'] ?? ''),
            'start_date'       => $start,
            'eos_end_date'     => $end_eos,
          ];
        }
      }

      // Keep only First Day Complete + End of Service
      $contracts = array_values(array_filter($contracts, function($c){
        $lab = strtolower((string)$c['stage_label']);
        return (strpos($lab, 'first day complete') !== false) || (strpos($lab, 'end of service') !== false);
      }));
    }

    // ✅ Deals fallback merge (company_id only)
    $deal_rows = [];
    if ($company_id) {
      $assoc_deals = $hs('GET', "https://api.hubapi.com/crm/v4/objects/companies/{$company_id}/associations/deals");
      $deal_ids = [];
      if ($assoc_deals['ok'] && !empty($assoc_deals['body']['results'])) {
        foreach ($assoc_deals['body']['results'] as $r) {
          if (!empty($r['toObjectId'])) $deal_ids[] = (string)$r['toObjectId'];
        }
      }
      $deal_ids = array_values(array_unique(array_filter($deal_ids)));

      if ($deal_ids) {
        $deal_props = [
          'dealname',
          'dealstage',
          'vt_start_date',
          'bill_rate',
          'role',
          'selected_vt_name',
          'date___end_of_service',
        ];

        $deal_batch = $hs('POST', "https://api.hubapi.com/crm/v3/objects/deals/batch/read", [
          'properties' => $deal_props,
          'inputs'     => array_map(function($id){ return ['id' => $id]; }, $deal_ids)
        ]);

        if ($deal_batch['ok'] && !empty($deal_batch['body']['results'])) {
          foreach ($deal_batch['body']['results'] as $row) {
            $p = isset($row['properties']) ? $row['properties'] : [];

            $start = $norm_date($p['vt_start_date'] ?? '');
            $end_eos = $norm_date($p['date___end_of_service'] ?? '');

            // ✅ Do NOT treat closed-won as EOS. EOS only if EOS date exists.
            $stage_label = ($end_eos) ? 'End of Service' : 'First Day Complete';

            $deal_rows[] = [
              'source'           => 'deal',
              'id'               => (string)($row['id'] ?? ''),
              'name'             => (string)($p['dealname'] ?? ''),
              'stage_id'         => (string)($p['dealstage'] ?? ''),
              'stage_label'      => $stage_label,
              'bill_rate'        => (string)($p['bill_rate'] ?? ''),
              'role'             => (string)($p['role'] ?? ''),
              'selected_vt_name' => (string)($p['selected_vt_name'] ?? ''),
              'start_date'       => $start,
              'eos_end_date'     => $end_eos,
            ];
          }
        }

        // Keep meaningful rows
        $deal_rows = array_values(array_filter($deal_rows, function($d){
          return !empty($d['selected_vt_name']) || !empty($d['start_date']) || !empty($d['role']) || !empty($d['bill_rate']);
        }));
      }
    }

    $merged = array_merge($contracts, $deal_rows);

    wp_send_json_success([
      'company_id'    => $company_id,
      'company_name'  => $company_name ?: $company_guess,
      'company_guess' => $company_guess,
      'resolved_by'   => $resolved_by,
      'contracts'     => $merged,
    ]);
  }

  // -----------------------------
  // Shortcode UI (continues in Part 2)
  // -----------------------------
  function roi_savings_calculator_shortcode() {
    $uid = 'roi_savings_' . substr(md5(uniqid('', true)), 0, 8);
    $nonce = wp_create_nonce('roix_nonce');

    $monthly_value_ft = 5250;
    $monthly_value_pt = 2625;
    $max_months_cap   = 240;

    ob_start();
?>
    <div class="roiX" id="<?php echo esc_attr($uid); ?>"
         data-roi-savings-dual
         data-roix-nonce="<?php echo esc_attr($nonce); ?>"
         data-roix-ft="<?php echo esc_attr($monthly_value_ft); ?>"
         data-roix-pt="<?php echo esc_attr($monthly_value_pt); ?>"
         data-roix-maxm="<?php echo esc_attr($max_months_cap); ?>">

      <!-- GLOBAL TOGGLE -->
      <div class="roiX__globalBar">
        <button type="button" class="roiX__globalToggle" data-el="globalToggleBtn" aria-expanded="true">
          <span class="roiX__globalIcon" aria-hidden="true">▾</span>
          <span class="roiX__globalText">Value Creation Calculator</span>
          <span class="roiX__globalHint">Close</span>
        </button>
      </div>

      <div class="roiX__shell" data-el="globalBody" style="display:block;">

        <!-- LEFT -->
        <section class="roiX__panel roiX__panel--actual" data-panel="actual" aria-label="Actual value calculator">
          <div class="roiX__badge roiX__badge--wide">Actual VTs Hired</div>

          <div class="roiX__title">Value Creation (Actual)</div>
          <div class="roiX__sub">
            Uses your hired VTs and their start dates to compute Lifetime value created (including ended services).
          </div>

          <div class="roiX__pillRow" aria-live="polite">
            <span class="roiX__pill" data-el="vaPill">Hired Virtual Teammates: Detecting...</span>
          </div>

          <div class="roiX__card" style="margin-top:12px;">
            <button type="button" class="roiX__calcToggle" data-el="calcToggleBtn" aria-expanded="true">
              <span class="roiX__calcToggleIcon" aria-hidden="true">▾</span>
              <span class="roiX__calcToggleText">Value Created</span>
              <span class="roiX__calcToggleHint">Close</span>
            </button>

            <div class="roiX__intro" data-el="calcIntro" aria-live="polite" style="display:none;">
              <div class="roiX__introTitle">Quick guide</div>
              <ul class="roiX__introList">
                <li><strong>Open</strong> to see totals.</li>
                <li><strong>Lifetime</strong> includes ended services too.</li>
              </ul>
            </div>

            <div class="roiX__calcBody" data-el="calcBody" style="display:block;">
              <div class="roiX__lifetimeHero" data-el="lifetimeHero" aria-live="polite"></div>
              <div class="roiX__dash roiX__dash--hero" data-el="actualDash" aria-live="polite"></div>

              <div class="roiX__detailsWrap">
                <button type="button" class="roiX__miniToggle" data-el="detailsToggle" aria-expanded="false">
                  <span class="roiX__miniIcon" aria-hidden="true">▸</span>
                  <span class="roiX__miniText">Teammate details</span>
                  <span class="roiX__miniHint">Open</span>
                </button>
                <div class="roiX__detailsBody" data-el="detailsBody" style="display:none;">
                  <div class="roiX__detailsList" data-el="detailsList"></div>
                </div>
              </div>

              <div class="roiX__foot roiX__foot--actual">Calculated based on your hired VTs and contract/deal dates.</div>
            </div>
          </div>
        </section>

        <!-- RIGHT -->
        <section class="roiX__panel roiX__panel--scenario" data-panel="scenario" aria-label="Scenario planner calculator">
          <div class="roiX__badge roiX__badge--wide">Scenario Planner</div>

          <div class="roiX__title">Value Creation (Scenario)</div>
          <div class="roiX__sub">
            Bi-weekly comparison (US vs VT). VT count starts from your hired VTs.
          </div>

          <div class="roiX__card" style="margin-top:12px;">
            <button type="button" class="roiX__calcToggle" data-el="calcToggleBtn" aria-expanded="false">
              <span class="roiX__calcToggleIcon" aria-hidden="true">▸</span>
              <span class="roiX__calcToggleText">Open scenario calculator</span>
              <span class="roiX__calcToggleHint">Open</span>
            </button>

            <div class="roiX__intro roiX__intro--pretty" data-el="calcIntro" aria-live="polite" style="display:block;">
              <div class="roiX__introTitle">Quick guide</div>
              <ul class="roiX__introList roiX__introList--icons">
                <li><strong>Pick a job category</strong> <span class="roiX__soft">(reporting consistency)</span></li>
                <li><strong>Choose Pro or Specialist</strong> <span class="roiX__soft">(FT or PT)</span></li>
                <li><strong>VT count starts from your hired VTs</strong></li>
              </ul>
            </div>

            <div class="roiX__calcBody" data-el="calcBody" style="display:none;">

              <div class="roiX__topRow">
                <div class="roiX__field" style="flex:1 1 auto;">
                  <label class="roiX__label">Job Category</label>
                  <div class="roiX__control">
                    <select class="roiX__select" data-el="jobSelect" aria-label="Job category">
                      <option value="personal_assistant">Personal Assistant</option>
                      <option value="administrative_assistant">Administrative Assistant</option>
                      <option value="executive_assistant">Executive Assistant</option>
                      <option value="client_services_rep">Client Services Rep</option>
                      <option value="client_services_specialist">Client Services Specialist</option>
                      <option value="receptionist">Receptionist</option>
                      <option value="accountant">Accountant</option>
                      <option value="billing_coordinator">Billing Coordinator</option>
                      <option value="bookkeeper">Bookkeeper</option>
                      <option value="copywriter">Copywriter</option>
                      <option value="marketing_manager">Marketing Manager</option>
                      <option value="marketing_coordinator">Marketing Coordinator</option>
                      <option value="graphic_designer">Graphic Designer</option>
                      <option value="social_media_coordinator">Social Media Coordinator</option>
                      <option value="social_media_manager">Social Media Manager</option>
                      <option value="business_development">Business Development</option>
                      <option value="account_manager">Account Manager</option>
                      <option value="sales_manager">Sales Manager</option>
                      <option value="sales_rep">Sales Rep</option>
                      <option value="medical_scheduling_coordinator">Medical Scheduling Coordinator</option>
                      <option value="medical_receptionist">Medical Receptionist</option>
                      <option value="medical_admin">Medical Admin</option>
                      <option value="medical_insurance_verification_pre_cert">Medical Insurance Verification and Pre Cert</option>
                      <option value="medical_biller">Medical Biller</option>
                      <option value="medical_scribe">Medical Scribe</option>
                      <option value="healthcare_referral_coordinator">Healthcare Referral Coordinator</option>
                      <option value="telemedicine_services_assistant">Telemedicine Services Assistant</option>
                      <option value="dental_biller">Dental Biller</option>
                      <option value="dental_admin">Dental Admin</option>
                      <option value="dental_scribe">Dental Scribe</option>
                      <option value="dental_referral_coordinator">Dental Referral Coordinator</option>
                      <option value="dental_billing_specialist">Dental Billing Specialist</option>
                      <option value="dental_insurance_coordinator">Dental Insurance Coordinator</option>
                      <option value="data_analyst">Data Analyst</option>
                      <option value="database_administrator">Database Administrator</option>
                      <option value="bi_developer">BI Developer</option>
                      <option value="quality_control_inspector">Quality Control Inspector</option>
                      <option value="quality_assurance_analyst">Quality Assurance Analyst</option>
                      <option value="quality_assurance_manager">Quality Assurance Manager</option>
                    </select>
                  </div>
                </div>
                <div class="roiX__actions">
                  <a href="#" class="roiX__link" data-el="resetRates">Reset</a>
                </div>
              </div>

              <div class="roiX__grid2" style="margin-top:10px;">
                <div class="roiX__field">
                  <label class="roiX__label">Tier</label>
                  <div class="roiX__control">
                    <select class="roiX__select" data-el="tierSelect" aria-label="Tier">
  <option value="pro">Pro</option>
  <option value="specialist">Specialist</option>
</select>

                  </div>
                </div>

                <div class="roiX__field">
                  <label class="roiX__label">Schedule</label>
                  <div class="roiX__control">
                    <select class="roiX__select" data-el="schedSelect" aria-label="Schedule">
                      <option value="ft">Full-time (FT)</option>
                      <option value="pt">Part-time (PT)</option>
                    </select>
                  </div>
                </div>
              </div>

              <div class="roiX__field" style="margin-top:10px;">
                <label class="roiX__label">Number of Virtual Teammates</label>
                <div class="roiX__slider">
                  <input data-el="vtCount" type="range" min="0" max="50" value="0" step="1" aria-label="Number of Virtual Teammates">
                  <div class="roiX__sliderMeta roiX__sliderMeta--vt">
                    <span class="roiX__meta">0</span><span class="roiX__meta">5</span><span class="roiX__meta">10</span><span class="roiX__meta">20</span><span class="roiX__meta">30</span><span class="roiX__meta">50</span>
                    <span class="roiX__bubble"><span data-el="vtCountVal">0 VTs</span></span>
                  </div>
                </div>
              </div>

              <!-- ✅ Collapsed: Bi-weekly model table -->
              <div class="roiX__detailsWrap" style="margin-top:10px;">
                <button type="button" class="roiX__miniToggle" data-el="biToggle" aria-expanded="false">
                  <span class="roiX__miniIcon" aria-hidden="true">▸</span>
                  <span class="roiX__miniText">Bi-weekly model (US vs VT)</span>
                  <span class="roiX__miniHint">Open</span>
                </button>

                <div class="roiX__detailsBody" data-el="biBody" style="display:none;">
                  <div class="roiX__biGrid" data-el="biGrid" aria-live="polite"></div>
                </div>
              </div>

              <div class="roiX__results" aria-live="polite">
                <div class="roiX__kpi">
                  <div class="roiX__kpiLabel">Estimated Bi-weekly Value Creation</div>
                  <div class="roiX__kpiValue" data-el="biSavings">$0.00</div>
                </div>

                <div class="roiX__kpi">
                  <div class="roiX__kpiLabel">Estimated Annual Value Creation</div>
                  <div class="roiX__kpiValue" data-el="annualSavings">$0.00</div>
                </div>

                <!-- ✅ Collapsed: Est. US/VT bi-weekly cost rows -->
                <div class="roiX__detailsWrap" style="margin-top:10px;">
                  <button type="button" class="roiX__miniToggle" data-el="costToggle" aria-expanded="false">
                    <span class="roiX__miniIcon" aria-hidden="true">▸</span>
                    <span class="roiX__miniText">Cost breakdown</span>
                    <span class="roiX__miniHint">Open</span>
                  </button>

                  <div class="roiX__detailsBody" data-el="costBody" style="display:none;">
                    <div class="roiX__break" style="margin-top:0; padding-top:0; border-top:0;">
                      <div class="roiX__breakRow"><span>Est. US bi-weekly cost</span><span data-el="usBi">$0.00</span></div>
                      <div class="roiX__breakRow"><span>Est. VT bi-weekly cost</span><span data-el="vtBi">$0.00</span></div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="roiX__foot roiX__foot--scenario"><span>Bi-weekly clarity. Faster decisions.</span></div>
            </div>
          </div>
        </section>

      </div>
    </div>

    <style>
      #<?php echo esc_attr($uid); ?>{
        --p:#3919BA; --a:#F6B845; --t:#1f1f1f; --m:#6B6B6B; --line:rgba(0,0,0,0.08);
        --eosBg: rgba(220,38,38,0.14);
        --eosBd: rgba(220,38,38,0.40);
        --eosTx: #7f1d1d;
        font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial;
      }

      #<?php echo esc_attr($uid); ?> .roiX__globalBar{ max-width:1140px; margin:14px auto 0; padding:0 10px; box-sizing:border-box; }
      #<?php echo esc_attr($uid); ?> .roiX__globalToggle{
        width:100%; display:flex; align-items:center; gap:10px;
        padding:12px 14px; border-radius:16px;
        border:1px solid rgba(57,25,186,0.22);
        cursor:pointer; text-align:left;
        box-shadow:0 18px 40px rgba(0,0,0,0.10);
      }
      #<?php echo esc_attr($uid); ?> .roiX__globalToggle[aria-expanded="false"]{ background:linear-gradient(180deg, #ffffff, #fbfbff); color:#1f1f1f; }
      #<?php echo esc_attr($uid); ?> .roiX__globalToggle[aria-expanded="true"]{
        background:linear-gradient(180deg, rgba(57,25,186,0.98), rgba(57,25,186,0.86));
        color:#fff; border-color:rgba(57,25,186,0.35);
        box-shadow:0 18px 40px rgba(57,25,186,0.20);
      }
      #<?php echo esc_attr($uid); ?> .roiX__globalIcon{
        width:24px; height:24px; display:inline-flex; align-items:center; justify-content:center;
        border-radius:10px; background:#fff; color:var(--p); font-weight:950;
      }
      #<?php echo esc_attr($uid); ?> .roiX__globalText{ font-weight:950; font-size:13px; letter-spacing:0.2px; }
      #<?php echo esc_attr($uid); ?> .roiX__globalHint{ margin-left:auto; font-size:11px; font-weight:900; opacity:0.95; }

      #<?php echo esc_attr($uid); ?> .roiX__shell{
        max-width:1140px; margin:12px auto 14px; padding:10px;
        display:flex; gap:16px; align-items:stretch; flex-wrap:nowrap;
        overflow-x:auto; box-sizing:border-box;
      }
      #<?php echo esc_attr($uid); ?> .roiX__panel{
        flex:1 1 50%; min-width:360px;
        border:1px solid var(--line); border-radius:16px;
        background:linear-gradient(180deg,#fff,#fbfbff);
        box-shadow:0 14px 34px rgba(0,0,0,0.08);
        padding:14px; position:relative; overflow:hidden;
      }
      #<?php echo esc_attr($uid); ?> .roiX__panel:before{
        content:""; position:absolute; inset:-2px;
        background:
          radial-gradient(140px 140px at 15% 10%, rgba(57,25,186,0.10), transparent 60%),
          radial-gradient(180px 180px at 85% 25%, rgba(246,184,69,0.12), transparent 60%);
        pointer-events:none;
      }
      #<?php echo esc_attr($uid); ?> .roiX__panel > *{ position:relative; z-index:1; }

      #<?php echo esc_attr($uid); ?> .roiX__badge{
        display:inline-flex; align-items:center; justify-content:center;
        border-radius:14px;
        background:linear-gradient(180deg, rgba(57,25,186,0.98), rgba(57,25,186,0.78));
        color:#fff; font-weight:950;
        box-shadow:0 16px 30px rgba(57,25,186,0.18);
        margin:0 0 10px;
      }
      #<?php echo esc_attr($uid); ?> .roiX__badge--wide{ padding:10px 14px; font-size:13px; letter-spacing:0.2px; }
      #<?php echo esc_attr($uid); ?> .roiX__title{ color:var(--p); font-weight:950; font-size:16px; }
      #<?php echo esc_attr($uid); ?> .roiX__sub{ margin-top:6px; color:var(--m); font-size:12.5px; line-height:1.35; }

      #<?php echo esc_attr($uid); ?> .roiX__pillRow{ margin-top:12px; width:100%; }
      #<?php echo esc_attr($uid); ?> .roiX__pill{
        display:inline-flex; align-items:center; justify-content:center;
        padding:9px 12px; border-radius:999px;
        background:linear-gradient(180deg,#fff,#f7f7ff);
        border:1px solid var(--line);
        color:var(--t); font-weight:900; box-shadow:0 10px 20px rgba(0,0,0,0.06);
        font-size:12px; width:100%;
      }

      #<?php echo esc_attr($uid); ?> .roiX__card{
        border-radius:16px; border:1px solid rgba(0,0,0,0.08);
        background:linear-gradient(180deg,#fff,#fbfbff);
        padding:12px; box-shadow:0 14px 34px rgba(0,0,0,0.07);
      }

      #<?php echo esc_attr($uid); ?> .roiX__calcToggle{
        width:100%; display:flex; align-items:center; gap:10px;
        padding:12px 12px; border-radius:14px;
        border:1px solid rgba(57,25,186,0.22);
        cursor:pointer; text-align:left;
        box-shadow:0 18px 40px rgba(0,0,0,0.10);
      }
      #<?php echo esc_attr($uid); ?> .roiX__calcToggle[aria-expanded="false"]{
        background:var(--a); color:#1f1f1f;
        border-color:rgba(246,184,69,0.70);
        box-shadow:0 18px 40px rgba(246,184,69,0.18);
      }
      #<?php echo esc_attr($uid); ?> .roiX__calcToggle[aria-expanded="true"]{
        background:var(--p); color:#fff;
        border-color:rgba(57,25,186,0.35);
        box-shadow:0 18px 40px rgba(57,25,186,0.20);
      }
      #<?php echo esc_attr($uid); ?> .roiX__calcToggleIcon{
        width:24px; height:24px; display:inline-flex; align-items:center; justify-content:center;
        border-radius:10px; background:#fff; color:var(--p); font-weight:950;
      }
      #<?php echo esc_attr($uid); ?> .roiX__calcToggleText{ font-weight:950; font-size:13px; }
      #<?php echo esc_attr($uid); ?> .roiX__calcToggleHint{ margin-left:auto; font-size:11px; font-weight:900; opacity:0.95; }

      #<?php echo esc_attr($uid); ?> .roiX__intro{
        margin-top:10px; padding:12px 12px; border-radius:16px;
        border:1px dashed rgba(57,25,186,0.20);
        background:linear-gradient(180deg, rgba(57,25,186,0.04), rgba(246,184,69,0.05));
      }
      #<?php echo esc_attr($uid); ?> .roiX__intro--pretty{
        border-style:solid;
        border-color:rgba(246,184,69,0.35);
        background:linear-gradient(180deg, rgba(246,184,69,0.14), rgba(57,25,186,0.03));
      }
      #<?php echo esc_attr($uid); ?> .roiX__introTitle{ font-weight:950; color:#1f1f1f; font-size:13px; margin-bottom:6px; }
      #<?php echo esc_attr($uid); ?> .roiX__introList{
        margin:0; padding-left:18px;
        color:rgba(0,0,0,0.70);
        font-size:12px; line-height:1.35;
        font-weight:850;
      }
      #<?php echo esc_attr($uid); ?> .roiX__introList--icons{ list-style:none; padding-left:0; }
      #<?php echo esc_attr($uid); ?> .roiX__introList--icons li{
        display:flex; align-items:flex-start; gap:10px;
        padding:6px 0;
      }
      #<?php echo esc_attr($uid); ?> .roiX__introList--icons li:before{
        content:"✓";
        width:20px; height:20px; border-radius:999px;
        display:inline-flex; align-items:center; justify-content:center;
        background:rgba(57,25,186,0.10);
        border:1px solid rgba(57,25,186,0.18);
        color:var(--p);
        font-weight:950;
        flex:0 0 auto;
        margin-top:1px;
      }
      #<?php echo esc_attr($uid); ?> .roiX__soft{ color:rgba(0,0,0,0.55); font-weight:850; }

      #<?php echo esc_attr($uid); ?> .roiX__topRow{ display:flex; gap:10px; align-items:flex-end; margin-top:10px; }
      #<?php echo esc_attr($uid); ?> .roiX__actions{ margin-left:auto; padding-bottom:2px; }
      #<?php echo esc_attr($uid); ?> .roiX__link{ color:var(--p); font-weight:950; font-size:12px; text-decoration:none; }
      #<?php echo esc_attr($uid); ?> .roiX__link:hover{ text-decoration:underline; }

      /* Lifetime hero */
      #<?php echo esc_attr($uid); ?> .roiX__lifetimeHero{
        margin-top:10px;
        padding:14px 12px;
        border-radius:16px;
        border:1px solid rgba(246,184,69,0.55);
        background:linear-gradient(180deg, rgba(246,184,69,0.20), rgba(57,25,186,0.06));
        box-shadow:0 16px 40px rgba(246,184,69,0.14);
        text-align:center;
      }
      #<?php echo esc_attr($uid); ?> .roiX__lhLabel{
        font-size:11px; font-weight:950; letter-spacing:.2px;
        color:rgba(0,0,0,0.65);
        text-transform:uppercase;
      }
      #<?php echo esc_attr($uid); ?> .roiX__lhVal{
        margin-top:6px;
        font-size:28px;
        font-weight:950;
        color:#1f1f1f;
        font-variant-numeric: tabular-nums;
        line-height:1.05;
      }
      #<?php echo esc_attr($uid); ?> .roiX__lhSub{
        margin-top:6px;
        font-size:11px;
        font-weight:900;
        color:rgba(0,0,0,0.58);
      }

      /* Dial (bigger + centered + animation support) */
      #<?php echo esc_attr($uid); ?> .roiX__dash{
        margin-top:12px;
        border-radius:16px;
        border:1px solid rgba(246,184,69,0.28);
        background:linear-gradient(180deg, #fff, #fffaf0);
        padding:12px;
        box-shadow:0 14px 34px rgba(0,0,0,0.05);
      }
      #<?php echo esc_attr($uid); ?> .roiX__dash--hero{
        padding:18px 14px;
        border-color:rgba(57,25,186,0.14);
        background:
          radial-gradient(220px 220px at 15% 0%, rgba(57,25,186,0.10), transparent 60%),
          radial-gradient(220px 220px at 90% 20%, rgba(246,184,69,0.18), transparent 60%),
          linear-gradient(180deg, #fff, #fffaf0);
      }
      /* ✅ Center the dial block */
      #<?php echo esc_attr($uid); ?> .roiX__dashRow{
        display:flex;
        flex-direction:column;
        align-items:center;
        justify-content:center;
        gap:10px;
        text-align:center;
      }
      #<?php echo esc_attr($uid); ?> .roiX__gauge{
        width:210px; height:210px; flex:0 0 auto;
        border-radius:999px; display:flex; align-items:center; justify-content:center;
        border:1px solid rgba(0,0,0,0.08);
        background:radial-gradient(circle at 35% 35%, rgba(57,25,186,0.10), transparent 55%),
                   radial-gradient(circle at 70% 70%, rgba(246,184,69,0.16), transparent 55%);
        box-shadow:0 22px 52px rgba(0,0,0,0.10);
        position:relative;
        transform:translateZ(0);
      }
      #<?php echo esc_attr($uid); ?> .roiX__gauge svg{ width:190px; height:190px; display:block; }
      #<?php echo esc_attr($uid); ?> .roiX__gCenter{ position:absolute; text-align:center; transform:translateY(6px); }
      #<?php echo esc_attr($uid); ?> .roiX__gVal{ font-weight:950; font-size:14px; color:#1f1f1f; }
      #<?php echo esc_attr($uid); ?> .roiX__gLbl{ font-weight:900; font-size:10.5px; color:rgba(0,0,0,0.55); margin-top:2px; }
      #<?php echo esc_attr($uid); ?> .roiX__dashStats{ width:100%; }
      #<?php echo esc_attr($uid); ?> .roiX__dashTitle{ font-weight:950; font-size:13px; color:#1f1f1f; }
      #<?php echo esc_attr($uid); ?> .roiX__dashSub{ margin-top:4px; font-weight:900; font-size:11px; color:rgba(0,0,0,0.58); line-height:1.35; }

      /* ✅ Sleek animation: stroke transition */
      #<?php echo esc_attr($uid); ?> .roiX__gProg{
        transition: stroke-dasharray 900ms cubic-bezier(.2,.9,.2,1);
        will-change: stroke-dasharray;
      }

      #<?php echo esc_attr($uid); ?> .roiX__chips{ display:flex; flex-wrap:wrap; gap:8px; margin-top:12px; justify-content:center; }
      #<?php echo esc_attr($uid); ?> .roiX__chip{
        display:inline-flex; align-items:center; gap:8px;
        padding:8px 10px; border-radius:999px;
        border:1px solid rgba(0,0,0,0.08);
        background:#fff;
        box-shadow:0 10px 20px rgba(0,0,0,0.05);
        font-weight:950; font-size:11px; color:#1f1f1f;
      }
      #<?php echo esc_attr($uid); ?> .roiX__chip--p{ border-color:rgba(57,25,186,0.18); }
      #<?php echo esc_attr($uid); ?> .roiX__chip--a{ border-color:rgba(246,184,69,0.38); }

      /* Right layout */
      #<?php echo esc_attr($uid); ?> .roiX__grid2{ display:flex; gap:10px; margin-top:10px; }
      #<?php echo esc_attr($uid); ?> .roiX__grid2 > div{ flex:1 1 50%; }

      #<?php echo esc_attr($uid); ?> .roiX__slider{
        border-radius:14px; border:1px solid rgba(57,25,186,0.10);
        background:#fff; padding:10px 12px;
      }
      #<?php echo esc_attr($uid); ?> input[type="range"]{
        width:100%; -webkit-appearance:none; height:10px; border-radius:999px;
        background:linear-gradient(90deg, var(--p), var(--a)); outline:none;
      }
      #<?php echo esc_attr($uid); ?> input[type="range"]::-webkit-slider-thumb{
        -webkit-appearance:none; width:20px; height:20px; border-radius:50%;
        background:#fff; border:3px solid rgba(57,25,186,0.85); cursor:pointer;
      }
      #<?php echo esc_attr($uid); ?> .roiX__sliderMeta{
        margin-top:8px; display:grid;
        grid-template-columns:repeat(6, 1fr) auto;
        gap:6px; align-items:center;
        font-size:11px; color:rgba(0,0,0,0.45); font-weight:900;
      }
      #<?php echo esc_attr($uid); ?> .roiX__bubble{
        justify-self:end; color:#2a2a2a; font-weight:950;
        background:linear-gradient(180deg, rgba(57,25,186,0.08), rgba(57,25,186,0.04));
        border:1px solid rgba(57,25,186,0.16);
        padding:6px 10px; border-radius:999px; white-space:nowrap;
      }

      #<?php echo esc_attr($uid); ?> .roiX__results{
        margin-top:10px; border-radius:16px;
        border:1px solid rgba(246,184,69,0.22);
        background:linear-gradient(180deg, #fff, #fffaf0);
        padding:12px;
      }
      #<?php echo esc_attr($uid); ?> .roiX__kpi{ display:flex; justify-content:space-between; align-items:baseline; padding:6px 0; }
      #<?php echo esc_attr($uid); ?> .roiX__kpiLabel{ font-size:12px; font-weight:900; color:var(--m); }
      #<?php echo esc_attr($uid); ?> .roiX__kpiValue{ font-size:20px; font-weight:950; color:#1f1f1f; font-variant-numeric: tabular-nums; }
      #<?php echo esc_attr($uid); ?> .roiX__break{ margin-top:10px; padding-top:10px; border-top:1px dashed rgba(0,0,0,0.12); }
      #<?php echo esc_attr($uid); ?> .roiX__breakRow{ display:flex; justify-content:space-between; padding:4px 0; font-size:12px; color:#333; font-weight:850; }

      #<?php echo esc_attr($uid); ?> .roiX__biGrid{ margin-top:12px; display:grid; grid-template-columns:1fr 1fr; gap:10px; }
      #<?php echo esc_attr($uid); ?> .roiX__biCard{
        border-radius:16px; border:1px solid rgba(0,0,0,0.08);
        background:linear-gradient(180deg,#fff,#fbfbff);
        box-shadow:0 12px 24px rgba(0,0,0,0.05);
        padding:10px 10px;
      }
      #<?php echo esc_attr($uid); ?> .roiX__biHead{ display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:8px; }
      #<?php echo esc_attr($uid); ?> .roiX__biTier{ font-weight:950; font-size:12px; color:#1f1f1f; display:inline-flex; align-items:center; gap:8px; }
      #<?php echo esc_attr($uid); ?> .roiX__biPill{
        font-size:10.5px; font-weight:950;
        padding:5px 9px; border-radius:999px;
        border:1px solid rgba(246,184,69,0.40);
        background:rgba(246,184,69,0.16);
        color:#3a2b00; white-space:nowrap;
      }
      #<?php echo esc_attr($uid); ?> .roiX__biTbl{
        width:100%; border-collapse:separate; border-spacing:0; overflow:hidden;
        border-radius:12px; border:1px solid rgba(15,23,42,.10);
      }
      #<?php echo esc_attr($uid); ?> .roiX__biTbl th, #<?php echo esc_attr($uid); ?> .roiX__biTbl td{
        padding:7px 8px; font-size:11px; border-bottom:1px solid rgba(15,23,42,.08);
      }
      #<?php echo esc_attr($uid); ?> .roiX__biTbl th{ text-align:left; font-weight:900; color:rgba(15,23,42,.78); background:rgba(15,23,42,.03); }
      #<?php echo esc_attr($uid); ?> .roiX__biTbl td{ text-align:right; font-weight:950; color:#0f172a; font-variant-numeric: tabular-nums; }
      #<?php echo esc_attr($uid); ?> .roiX__biTbl tr:last-child td, #<?php echo esc_attr($uid); ?> .roiX__biTbl tr:last-child th{ border-bottom:none; }

      /* Details mini toggle */
      #<?php echo esc_attr($uid); ?> .roiX__detailsWrap{ margin-top:12px; }
      #<?php echo esc_attr($uid); ?> .roiX__miniToggle{
        width:100%; display:flex; align-items:center; gap:10px;
        padding:10px 12px; border-radius:14px;
        border:1px solid rgba(0,0,0,0.10);
        background:linear-gradient(180deg,#fff,#fbfbff);
        cursor:pointer;
        box-shadow:0 10px 22px rgba(0,0,0,0.05);
      }
      #<?php echo esc_attr($uid); ?> .roiX__miniIcon{
        width:22px; height:22px; display:inline-flex; align-items:center; justify-content:center;
        border-radius:10px; background:rgba(57,25,186,0.08); color:var(--p); font-weight:950;
      }
      #<?php echo esc_attr($uid); ?> .roiX__miniText{ font-weight:950; font-size:12px; color:#1f1f1f; }
      #<?php echo esc_attr($uid); ?> .roiX__miniHint{ margin-left:auto; font-size:11px; font-weight:900; color:rgba(0,0,0,0.55); }
      #<?php echo esc_attr($uid); ?> .roiX__detailsBody{
        margin-top:10px; padding:10px 12px; border-radius:16px;
        border:1px solid rgba(57,25,186,0.12);
        background:linear-gradient(180deg, rgba(57,25,186,0.03), rgba(246,184,69,0.03));
      }
      #<?php echo esc_attr($uid); ?> .roiX__detailsList{ display:flex; flex-direction:column; gap:8px; }
      #<?php echo esc_attr($uid); ?> .roiX__dRow{
        border-radius:14px; border:1px solid rgba(0,0,0,0.08);
        background:#fff; padding:10px 10px;
        box-shadow:0 8px 18px rgba(0,0,0,0.04);
      }
      #<?php echo esc_attr($uid); ?> .roiX__dTop{ display:flex; gap:10px; align-items:center; justify-content:space-between; }
      #<?php echo esc_attr($uid); ?> .roiX__dName{ font-weight:950; font-size:12px; color:#111; }
      #<?php echo esc_attr($uid); ?> .roiX__dPill{
        font-size:10px; font-weight:950; padding:4px 8px; border-radius:999px;
        border:1px solid rgba(246,184,69,0.40); background:rgba(246,184,69,0.14); color:#3a2b00;
      }
      #<?php echo esc_attr($uid); ?> .roiX__dPill--eos{
        border-color: var(--eosBd);
        background: var(--eosBg);
        color: var(--eosTx);
      }

      #<?php echo esc_attr($uid); ?> .roiX__dMeta{
        margin-top:6px; display:grid; grid-template-columns:1fr 1fr; gap:6px;
        font-size:11px; color:rgba(0,0,0,0.65); font-weight:850;
      }
      #<?php echo esc_attr($uid); ?> .roiX__dMeta b{ color:#111; font-weight:950; }

      #<?php echo esc_attr($uid); ?> .roiX__foot{ margin-top:10px; text-align:center; line-height:1.35; }
      #<?php echo esc_attr($uid); ?> .roiX__foot--actual{ font-size:10.25px; font-weight:600; color:rgba(0,0,0,0.52); }
      #<?php echo esc_attr($uid); ?> .roiX__foot--scenario{ font-size:10px; font-weight:650; color:rgba(0,0,0,0.58); }

      @media (max-width: 980px){
        #<?php echo esc_attr($uid); ?> .roiX__shell{ flex-wrap:wrap; overflow-x:visible; }
        #<?php echo esc_attr($uid); ?> .roiX__panel{ min-width:0; flex:1 1 100%; }
        #<?php echo esc_attr($uid); ?> .roiX__biGrid{ grid-template-columns:1fr; }
      }
    </style>

    <script>
      (function(){
        var wrap = document.getElementById(<?php echo json_encode($uid); ?>);
        if(!wrap) return;

        function clamp(n,a,b){ return Math.max(a, Math.min(b, n)); }
        function pickText(el){ return (el && el.textContent) ? el.textContent.trim() : ''; }

function fmtUSD(n){
  var v = Number(n) || 0;
  var sign = v < 0 ? "-" : "";
  v = Math.abs(v);

  // ✅ No decimals for easier readability
  var s = v.toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 0});
  return sign + "$" + s;
}


        function setToggle(btn, expanded, bodyEl){
          if(!btn) return;
          btn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
          if(bodyEl) bodyEl.style.display = expanded ? '' : 'none';

          var icon = btn.querySelector('.roiX__calcToggleIcon,.roiX__globalIcon,.roiX__miniIcon');
          if(icon) icon.textContent = expanded ? '▾' : '▸';

          var hint = btn.querySelector('.roiX__calcToggleHint,.roiX__globalHint,.roiX__miniHint');
          if(hint) hint.textContent = expanded ? 'Close' : 'Open';
        }

        /* GLOBAL SHOW/HIDE */
        (function initGlobalToggle(){
          var gBtn  = wrap.querySelector('[data-el="globalToggleBtn"]');
          var gBody = wrap.querySelector('[data-el="globalBody"]');
          if(!gBtn || !gBody) return;

          setToggle(gBtn, true, gBody);
          gBtn.addEventListener('click', function(){
            var next = gBtn.getAttribute('aria-expanded') !== 'true';
            setToggle(gBtn, next, gBody);
          });
        })();

        // ✅ Working portal detection (unchanged)
        function getVAsFromSelectedVas(){
          var tab = document.querySelector('#e-n-tab-content-1386190152');
          if(!tab) return [];
          var selected = tab.querySelector('div.selected-vas');
          if(!selected) return [];
          var cards = selected.querySelectorAll('li.va-card');
          if(!cards || !cards.length) return [];
          var list = [];
          for (var i=0; i<cards.length; i++){
            var nameEl = cards[i].querySelector('div.va-name');
            var nm = pickText(nameEl) || 'VT';
            list.push({ name: nm });
          }
          return list;
        }

        function getPortalEmail(){
          var el = document.querySelector('#user_email');
          var v = '';
          if(el){
            v = (el.value || el.getAttribute('value') || '').trim();
            if(!v) v = (el.textContent || '').trim();
          }
          return v;
        }

        // ✅ Match keys: first|initial and first|lastname (more forgiving)
        function nameKeys(name){
          name = (name || '').toLowerCase().trim();
          name = name.replace(/\s+/g,' ');
          name = name.replace(/[^a-z0-9\s\.]/g,'');
          var parts = name.split(' ').filter(Boolean);
          if(!parts.length) return [];

          var first = parts[0] || '';
          var last = (parts.length >= 2) ? parts[parts.length-1] : '';
          last = (last || '').replace(/\./g,'');

          var initial = '';
          for(var i=1;i<parts.length;i++){
            var p = (parts[i] || '').replace(/\./g,'').trim();
            if(p.length === 1){ initial = p; break; }
          }
          if(!initial && last) initial = last.charAt(0);

          var keys = [];
          if(first && initial) keys.push(first + '|' + initial);
          if(first && last) keys.push(first + '|' + last);

          var seen = {};
          return keys.filter(function(k){ if(seen[k]) return false; seen[k]=true; return true; });
        }

        function monthsDiff(startYmd, endYmd, cap){
          function parseYmd(s){
            if(!s) return null;
            var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s);
            if(!m) return null;
            return {y:+m[1], mo:+m[2], d:+m[3]};
          }
          var a = parseYmd(startYmd);
          var b = parseYmd(endYmd);
          if(!a || !b) return 0;

          var months = (b.y - a.y) * 12 + (b.mo - a.mo);
          if (b.d < a.d) months -= 1;
          months = Math.max(0, months);
          if (cap) months = clamp(months, 0, cap);
          return months;
        }

        // Scenario constants (preserved)
        var BIWEEK = {
  pro: { label: 'Pro', vt: { ft: 750, pt: 400 }, us: { ft: 1800, pt: 960 } },
  specialist: { label: 'Specialist', vt: { ft: 1000, pt: 600 }, us: { ft: 2475, pt: 1320 } }
};


        function pickMilestone(value){
          var m = [10000, 25000, 50000, 100000, 250000, 500000, 1000000, 2000000, 5000000, 10000000];
          for(var i=0;i<m.length;i++){ if(value <= m[i]) return m[i]; }
          return m[m.length-1];
        }

        function renderGauge(value){
          var max = pickMilestone(value);
          var pct = (max > 0) ? (value / max) : 0;
          pct = Math.max(0, Math.min(1, pct));

          var r = 72; // bigger radius
          var c = 2 * Math.PI * r;
          var dash = (pct * c);
          var gap  = c - dash;

          // We'll animate by first setting dasharray to "0 c", then next frame set to final.
          return {
            html:
              '<div class="roiX__dashRow">' +
                '<div class="roiX__gauge">' +
                  '<svg viewBox="0 0 200 200" role="img" aria-label="Lifetime value dial">' +
                    '<defs>' +
                      '<linearGradient id="'+<?php echo json_encode($uid); ?>+'_grad" x1="0" y1="0" x2="1" y2="1">' +
                        '<stop offset="0%" stop-color="var(--p)"></stop>' +
                        '<stop offset="100%" stop-color="var(--a)"></stop>' +
                      '</linearGradient>' +
                    '</defs>' +
                    '<circle cx="100" cy="100" r="'+r+'" fill="none" stroke="rgba(0,0,0,0.08)" stroke-width="16"></circle>' +
                    '<circle class="roiX__gProg" data-final="'+dash+' '+gap+'" cx="100" cy="100" r="'+r+'" fill="none" stroke="url(#'+<?php echo json_encode($uid); ?>+'_grad)" stroke-width="16" stroke-linecap="round"' +
                      ' stroke-dasharray="0 '+c+'" transform="rotate(-90 100 100)"></circle>' +
                  '</svg>' +
                  '<div class="roiX__gCenter">' +
                    '<div class="roiX__gVal">'+fmtUSD(value)+'</div>' +
                    '<div class="roiX__gLbl">Lifetime value</div>' +
                  '</div>' +
                '</div>' +
                '<div class="roiX__dashStats">' +
                  '<div class="roiX__dashTitle">Lifetime value created</div>' +
                  '<div class="roiX__dashSub">Progress to the next milestone: <strong>'+fmtUSD(max)+'</strong></div>' +
                '</div>' +
              '</div>',
            max: max,
            pct: pct
          };
        }

        var hiredList = [];
        var hiredCount = 0;
        var contracts = [];

        function fetchContracts(){
          var nonce = wrap.getAttribute('data-roix-nonce');
          var form = new FormData();
          form.append('action', 'roix_get_contracts');
          form.append('nonce', nonce);

          var pe = getPortalEmail();
          if (pe) form.append('client_email', pe);

          for (var i=0; i<hiredList.length; i++){
            form.append('hired_names[]', hiredList[i].name || '');
          }

          return fetch(<?php echo json_encode(admin_url('admin-ajax.php')); ?>, {
            method: 'POST',
            credentials: 'same-origin',
            body: form
          })
          .then(function(r){ return r.json(); })
          .then(function(j){
            if(!j || !j.success) return {contracts: []};
            return j.data || {contracts: []};
          })
          .catch(function(){ return {contracts: []}; });
        }

        function normalizeBillRateLabel(br){
          var t = (br || '').toLowerCase();
          if(t.indexOf('part') !== -1 || t.indexOf(' pt') !== -1 || t === 'pt') return 'Part-time';
          if(t.indexOf('full') !== -1 || t.indexOf(' ft') !== -1 || t === 'ft') return 'Full-time';
          return (br || '--');
        }

        // ✅ STRICT hired-only filtering + lifetime months rules
        function computeActual(){
          var ftBase = Number(wrap.getAttribute('data-roix-ft')) || 5250;
          var ptBase = Number(wrap.getAttribute('data-roix-pt')) || 2625;
          var capM  = Number(wrap.getAttribute('data-roix-maxm')) || 240;

          // hiredKey -> portalName
          var hiredKeyToName = {};
          for(var i=0;i<hiredList.length;i++){
            var ks = nameKeys(hiredList[i].name);
            for(var k=0;k<ks.length;k++){
              hiredKeyToName[ks[k]] = hiredList[i].name;
            }
          }

          var today = new Date();
          var todayYmd = today.getUTCFullYear() + '-' +
                         String(today.getUTCMonth()+1).padStart(2,'0') + '-' +
                         String(today.getUTCDate()).padStart(2,'0');

          var matched = [];
          var matchedPortalNames = {};

          var monthlyTotal = 0;
          var lifetimeTotal = 0;

          for(var c=0;c<contracts.length;c++){
            var ct = contracts[c] || {};
            var stageLabel = (ct.stage_label || '').toLowerCase();

            // EOS if stage says EOS OR if EOS date exists
            var isEOS = (stageLabel.indexOf('end of service') !== -1) || !!(ct.eos_end_date);

            // best guess VT name from record
            var vtNameRaw = ct.selected_vt_name || ct.name || 'VT';

            // find portalName by matching any key
            var keys = nameKeys(vtNameRaw);
            var portalName = '';
            for(var kk=0; kk<keys.length; kk++){
              if(hiredKeyToName[keys[kk]]){
                portalName = hiredKeyToName[keys[kk]];
                break;
              }
            }

            // ✅ STRICT: skip anything not in hired list
            if(!portalName) continue;

            var start = ct.start_date || '';
            if(!start) continue;

            var end = isEOS ? (ct.eos_end_date || '') : todayYmd;
            if(!end) end = todayYmd;

            var months = monthsDiff(start, end, capM);

            // ✅ Lifetime months fix:
            // - Active and < 1 month => treat as 1
            // - EOS and < 1 month => keep 0
            if(!isEOS && months === 0) months = 1;

            var br = (ct.bill_rate || '').toLowerCase();
            var isPT = br.indexOf('pt') !== -1 || br.indexOf('part') !== -1;
            var isFT = br.indexOf('ft') !== -1 || br.indexOf('full') !== -1;
            var monthlyBase = (isPT && !isFT) ? ptBase : ftBase;

            lifetimeTotal += (monthlyBase * months);

            // monthly total only for active onboarded (First Day Complete)
            if(!isEOS && stageLabel.indexOf('first day complete') !== -1){
              monthlyTotal += monthlyBase;
            }

            matched.push({
              portalName: portalName,
              role: ct.role || '--',
              bill_rate_label: normalizeBillRateLabel(ct.bill_rate || ''),
              isEOS: isEOS,
              start: start || '--',
              end: isEOS ? (ct.eos_end_date || '--') : 'Still Working',
              months: months,
              statusLabel: isEOS ? 'Inactive' : 'Active Onboarded VT'
            });

            matchedPortalNames[portalName] = true;
          }

          // missing hired (still show as Not detected)
          var missing = [];
          for(var h=0; h<hiredList.length; h++){
            var pn = hiredList[h].name;
            if(!matchedPortalNames[pn]) missing.push({ portalName: pn });
          }

          return {
            monthly: monthlyTotal,
            annual: monthlyTotal * 12,
            lifetime: lifetimeTotal,
            matched: matched,
            missing: missing
          };
        }

        function animateGauge(panelEl){
          if(!panelEl) return;
          var prog = panelEl.querySelector('.roiX__gProg');
          if(!prog) return;
          var fin = prog.getAttribute('data-final');
          if(!fin) return;

          // set to 0 first, then animate to final
          prog.setAttribute('stroke-dasharray', prog.getAttribute('stroke-dasharray') || '0 1');
          requestAnimationFrame(function(){
            prog.setAttribute('stroke-dasharray', fin);
          });
        }

        function renderActual(panelEl){
          function q(name){ return panelEl.querySelector('[data-el="'+name+'"]'); }
          var pill = q('vaPill');
          var hero = q('lifetimeHero');
          var dash = q('actualDash');
          var detailsList = q('detailsList');

          if(pill) pill.textContent = "Hired Virtual Teammates: " + hiredCount;

          if(hiredCount <= 0){
            if(hero) hero.innerHTML =
              '<div class="roiX__lhLabel">Lifetime value created</div>' +
              '<div class="roiX__lhVal">$0.00</div>' +
              '<div class="roiX__lhSub">Add hired VTs to see totals.</div>';
            if(dash) dash.innerHTML = '';
            if(detailsList) detailsList.innerHTML = '';
            return;
          }

          var totals = computeActual();

          if(hero){
            hero.innerHTML =
              '<div class="roiX__lhLabel">Lifetime value created</div>' +
              '<div class="roiX__lhVal">'+ fmtUSD(totals.lifetime) +'</div>' +
              '<div class="roiX__lhSub">Includes active and ended services.</div>';
          }

          if(dash){
            var g = renderGauge(totals.lifetime);
            dash.innerHTML =
              g.html +
              '<div class="roiX__chips">' +
                '<span class="roiX__chip roiX__chip--p">Monthly: <b>'+fmtUSD(totals.monthly)+'</b></span>' +
                '<span class="roiX__chip roiX__chip--a">Annual: <b>'+fmtUSD(totals.annual)+'</b></span>' +
              '</div>';

            animateGauge(panelEl);
          }

          if(detailsList){
            var html = '';

            // matched hired-only rows
            if(totals.matched.length){
              for(var i=0;i<totals.matched.length;i++){
                var r = totals.matched[i];
                var badgeText = r.isEOS ? 'EOS' : 'Active';
                var pillClass = r.isEOS ? 'roiX__dPill roiX__dPill--eos' : 'roiX__dPill';

                html += '' +
                  '<div class="roiX__dRow">' +
                    '<div class="roiX__dTop">' +
                      '<div class="roiX__dName">'+ (r.portalName) +'</div>' +
                      '<div class="'+pillClass+'">'+ badgeText +'</div>' +
                    '</div>' +
                    '<div class="roiX__dMeta">' +
                      '<div>Role: <b>'+ (r.role || '--') +'</b></div>' +
                      '<div>Bill rate: <b>'+ (r.bill_rate_label || '--') +'</b></div>' +
                      '<div>Start: <b>'+ (r.start || '--') +'</b></div>' +
                      '<div>End: <b>'+ (r.end || '--') +'</b></div>' +
                      '<div>Months: <b>'+ (String(r.months)) +'</b></div>' +
                      '<div>Status: <b>'+ (r.statusLabel || '--') +'</b></div>' +
                    '</div>' +
                  '</div>';
              }
            }

            // missing hired-only
            if(totals.missing.length){
              for(var m=0;m<totals.missing.length;m++){
                html += '' +
                  '<div class="roiX__dRow">' +
                    '<div class="roiX__dTop">' +
                      '<div class="roiX__dName">'+ totals.missing[m].portalName +'</div>' +
                      '<div class="roiX__dPill roiX__dPill--eos">Not detected</div>' +
                    '</div>' +
                    '<div class="roiX__dMeta">' +
                      '<div>Role: <b>--</b></div>' +
                      '<div>Bill rate: <b>--</b></div>' +
                      '<div>Start: <b>--</b></div>' +
                      '<div>End: <b>--</b></div>' +
                      '<div>Months: <b>--</b></div>' +
                      '<div>Status: <b>--</b></div>' +
                    '</div>' +
                  '</div>';
              }
            }

            if(!html){
              html = '<div style="font-weight:900;color:rgba(0,0,0,0.65);font-size:12px;">No records found for hired VTs.</div>';
            }

            detailsList.innerHTML = html;
          }
        }

        function renderScenarioCompare(biGridEl){
          if(!biGridEl) return;

          function card(tierKey){
            var t = BIWEEK[tierKey];
            var ftSave = t.us.ft - t.vt.ft;
            var ptSave = t.us.pt - t.vt.pt;

            return '' +
              '<div class="roiX__biCard">' +
                '<div class="roiX__biHead">' +
                  '<div class="roiX__biTier">'+t.label+'</div>' +
                  '<span class="roiX__biPill">Bi-weekly</span>' +
                '</div>' +
                '<table class="roiX__biTbl" role="table" aria-label="'+t.label+' bi-weekly comparison">' +
                  '<thead><tr><th></th><th>FT</th><th>PT</th></tr></thead>' +
                  '<tbody>' +
                    '<tr><th>VT</th><td>'+fmtUSD(t.vt.ft)+'</td><td>'+fmtUSD(t.vt.pt)+'</td></tr>' +
                    '<tr><th>US</th><td>'+fmtUSD(t.us.ft)+'</td><td>'+fmtUSD(t.us.pt)+'</td></tr>' +
                    '<tr><th>Value</th><td>'+fmtUSD(ftSave)+'</td><td>'+fmtUSD(ptSave)+'</td></tr>' +
                  '</tbody>' +
                '</table>' +
              '</div>';
          }

          biGridEl.innerHTML = card('pro') + card('specialist');
        }

        function renderScenario(panelEl){
          function q(name){ return panelEl.querySelector('[data-el="'+name+'"]'); }
          var tierSelect = q('tierSelect');
          var schedSelect = q('schedSelect');
          var vtCountEl = q('vtCount');
          var vtCountValEl = q('vtCountVal');
          var biGridEl = q('biGrid');

          var biSavingsEl = q('biSavings');
          var annualEl = q('annualSavings');
          var usBiEl = q('usBi');
          var vtBiEl = q('vtBi');

          renderScenarioCompare(biGridEl);

          var vtCount = vtCountEl ? clamp(parseInt(vtCountEl.value, 10) || 0, 0, 50) : 0;
          if(vtCountValEl) vtCountValEl.textContent = vtCount + " VTs";

          var tierKey = tierSelect ? (tierSelect.value || 'pro') : 'pro';
          var sched = schedSelect ? (schedSelect.value || 'ft') : 'ft';

          var t = BIWEEK[tierKey] || BIWEEK.pro;
          var vtBi = (t.vt[sched] || 0) * vtCount;
          var usBi = (t.us[sched] || 0) * vtCount;

          var biSave = usBi - vtBi;
          var annual = biSave * 26;

          if(biSavingsEl) biSavingsEl.textContent = fmtUSD(biSave);
          if(annualEl) annualEl.textContent = fmtUSD(annual);
          if(usBiEl) usBiEl.textContent = fmtUSD(usBi);
          if(vtBiEl) vtBiEl.textContent = fmtUSD(vtBi);
        }

        function initPanel(panelEl, mode){
          if(!panelEl) return;
          function q(name){ return panelEl.querySelector('[data-el="'+name+'"]'); }

          var calcToggleBtn = q('calcToggleBtn');
          var calcBody = q('calcBody');
          var calcIntro = q('calcIntro');

          if(calcToggleBtn && calcBody){
            var startExpanded = (calcToggleBtn.getAttribute('aria-expanded') === 'true');
            setToggle(calcToggleBtn, startExpanded, calcBody);
            if(calcIntro) calcIntro.style.display = startExpanded ? 'none' : 'block';

            calcToggleBtn.addEventListener('click', function(){
              var next = calcToggleBtn.getAttribute('aria-expanded') !== 'true';
              setToggle(calcToggleBtn, next, calcBody);
              if(calcIntro) calcIntro.style.display = next ? 'none' : 'block';
            });
          }

          if(mode === 'actual'){
            var dBtn = q('detailsToggle');
            var dBody = q('detailsBody');
            if(dBtn && dBody){
              setToggle(dBtn, false, dBody);
              dBtn.addEventListener('click', function(){
                var next = dBtn.getAttribute('aria-expanded') !== 'true';
                setToggle(dBtn, next, dBody);
              });
            }
          }

          if(mode === 'scenario'){
            var vtCountEl = q('vtCount');
            var tierSelect = q('tierSelect');
            var schedSelect = q('schedSelect');
            var jobSelect = q('jobSelect');

            if(jobSelect) jobSelect.addEventListener('change', function(){ renderScenario(panelEl); });
            if(tierSelect) tierSelect.addEventListener('change', function(){ renderScenario(panelEl); });
            if(schedSelect) schedSelect.addEventListener('change', function(){ renderScenario(panelEl); });
            if(vtCountEl) vtCountEl.addEventListener('input', function(){ renderScenario(panelEl); });

            // ✅ Collapsible: Bi-weekly model table (default collapsed)
            var biBtn = q('biToggle');
            var biBody = q('biBody');
            if(biBtn && biBody){
              setToggle(biBtn, false, biBody);
              biBtn.addEventListener('click', function(){
                var next = biBtn.getAttribute('aria-expanded') !== 'true';
                setToggle(biBtn, next, biBody);
              });
            }

            // ✅ Collapsible: Cost breakdown (default collapsed)
            var costBtn = q('costToggle');
            var costBody = q('costBody');
            if(costBtn && costBody){
              setToggle(costBtn, false, costBody);
              costBtn.addEventListener('click', function(){
                var next = costBtn.getAttribute('aria-expanded') !== 'true';
                setToggle(costBtn, next, costBody);
              });
            }
          }
        }

        var leftPanel = wrap.querySelector('[data-panel="actual"]');
        var rightPanel = wrap.querySelector('[data-panel="scenario"]');

        initPanel(leftPanel, 'actual');
        initPanel(rightPanel, 'scenario');

        function boot(){
          hiredList = getVAsFromSelectedVas() || [];
          hiredCount = hiredList.length || 0;

          // Right default vtCount = hiredCount (only if still 0)
          var vtCountEl = rightPanel ? rightPanel.querySelector('[data-el="vtCount"]') : null;
          if(vtCountEl){
            var current = parseInt(vtCountEl.value, 10) || 0;
            if(current === 0 && hiredCount > 0){
              vtCountEl.value = String(clamp(hiredCount, 0, 50));
            }
          }
          if(rightPanel) renderScenario(rightPanel);

          fetchContracts().then(function(data){
            contracts = (data && data.contracts) ? data.contracts : [];
            if(leftPanel) renderActual(leftPanel);
          });
        }

        boot();
        setTimeout(boot, 900);
        setTimeout(boot, 1800);

      })();
    </script>
<?php
    return ob_get_clean();
  }

  add_shortcode('roi_savings_calculator', 'roi_savings_calculator_shortcode');
}
