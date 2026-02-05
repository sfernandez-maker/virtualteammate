add_shortcode('hubspot_invoice_viewer', function () {

    // =============================
    // DEMO MODE CONFIGURATION
    // =============================
    $DEMO_MODE_ENABLED = true; // Set to false to disable demo mode entirely
    $DEMO_USER_EMAIL = 'pebido9596@noihse.com'; // Change this to the email of the demo user
    
    // =============================
    // 1) CONFIG (INLINE TOKEN)
    // =============================
    $HUBSPOT_TOKEN = 'YOUR_HUBSPOT_TOKEN_HERE';

    // Cache durations (tune as needed)
    $CONTACT_CACHE_TTL  = 12 * HOUR_IN_SECONDS;
    $COMPANY_CACHE_TTL  = 12 * HOUR_IN_SECONDS;
    $INVOICES_CACHE_TTL = 5 * MINUTE_IN_SECONDS;
    $STALE_CACHE_TTL    = 24 * HOUR_IN_SECONDS;

    if (empty($HUBSPOT_TOKEN) || $HUBSPOT_TOKEN === 'PASTE_YOUR_PRIVATE_APP_TOKEN_HERE') {
        return '<div class="hubspot-invoices">
                    <h2>Your HubSpot Invoices</h2>
                    <p>HubSpot token is not configured.</p>
                </div>';
    }

    // =============================
    // 2) HELPERS
    // =============================
    $hubspot_request = function (string $url, array $body = null) use ($HUBSPOT_TOKEN) {
        $args = [
            'headers' => [
                'Authorization' => 'Bearer ' . $HUBSPOT_TOKEN,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'timeout'     => 10,
            'redirection' => 0,
        ];

        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
            $response = wp_remote_post($url, $args);
        } else {
            $response = wp_remote_get($url, $args);
        }

        if (is_wp_error($response)) {
            return ['ok' => false, 'error' => $response->get_error_message(), 'data' => null, 'code' => 0];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw  = wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);

        if ($code < 200 || $code >= 300) {
            $msg = 'HubSpot request failed.';
            if (is_array($data) && !empty($data['message'])) {
                $msg .= ' ' . $data['message'];
            }
            return ['ok' => false, 'error' => $msg, 'data' => $data, 'code' => $code];
        }

        return ['ok' => true, 'error' => null, 'data' => $data, 'code' => $code];
    };

    $format_hs_date = function ($value) {
        if ($value === null || $value === '') return '';

        if (is_numeric($value)) {
            $n = (int) $value;
            if ($n > 9999999999) {
                return date('M d, Y', (int) round($n / 1000));
            }
            return date('M d, Y', $n);
        }

        $ts = strtotime((string) $value);
        if ($ts === false) return '';
        return date('M d, Y', $ts);
    };

    // =============================
    // DEMO DATA GENERATOR
    // =============================
    $generate_demo_invoices = function() {
        $currentTime = time();
        return [
            [
                'id' => 'demo_001',
                'properties' => [
                    'hs_invoice_number' => 'INV-2025-001',
                    'hs_invoice_link' => 'https://example.com/demo-invoice-1',
                    'hs_title' => 'Invoice',
                    'hs_amount_billed' => '5500.00',
                    'hs_invoice_status' => 'PAID',
                    'hs_due_date' => strtotime('-30 days'),
                    'hs_payment_date' => strtotime('-25 days'),
                ]
            ],
            [
                'id' => 'demo_002',
                'properties' => [
                    'hs_invoice_number' => 'INV-2025-002',
                    'hs_invoice_link' => 'https://example.com/demo-invoice-2',
                    'hs_title' => 'Invoice',
                    'hs_amount_billed' => '2500.00',
                    'hs_invoice_status' => 'UNPAID',
                    'hs_due_date' => strtotime('+5 days'),
                    'hs_payment_date' => '',
                ]
            ],
            [
                'id' => 'demo_003',
                'properties' => [
                    'hs_invoice_number' => 'INV-2025-003',
                    'hs_invoice_link' => 'https://example.com/demo-invoice-3',
                    'hs_title' => 'Invoice',
                    'hs_amount_billed' => '1800.00',
                    'hs_invoice_status' => 'OVERDUE',
                    'hs_due_date' => strtotime('-10 days'),
                    'hs_payment_date' => '',
                ]
            ],
            [
                'id' => 'demo_004',
                'properties' => [
                    'hs_invoice_number' => 'INV-2024-125',
                    'hs_invoice_link' => 'https://example.com/demo-invoice-4',
                    'hs_title' => 'Invoice',
                    'hs_amount_billed' => '950.00',
                    'hs_invoice_status' => 'PAID',
                    'hs_due_date' => strtotime('-90 days'),
                    'hs_payment_date' => strtotime('-85 days'),
                ]
            ],
            [
                'id' => 'demo_005',
                'properties' => [
                    'hs_invoice_number' => 'INV-2025-004',
                    'hs_invoice_link' => 'https://example.com/demo-invoice-5',
                    'hs_title' => 'Invoice',
                    'hs_amount_billed' => '750.00',
                    'hs_invoice_status' => 'PENDING',
                    'hs_due_date' => strtotime('+15 days'),
                    'hs_payment_date' => '',
                ]
            ],
        ];
    };

    // =============================
    // 3) AUTH CHECK + EMAIL
    // =============================
    if (!is_user_logged_in()) {
        return '<div class="hubspot-invoices">
                    <h2>Your HubSpot Invoices</h2>
                    <p>You must be logged in to view your invoices.</p>
                </div>';
    }

    $current_user = wp_get_current_user();
    $email = $current_user->user_email;

    if (!$email) {
        return '<div class="hubspot-invoices">
                    <h2>Your HubSpot Invoices</h2>
                    <p>Unable to detect your email address.</p>
                </div>';
    }

    // =============================
    // CHECK IF THIS IS A DEMO USER
    // =============================
    $is_demo_user = $DEMO_MODE_ENABLED && (strtolower(trim($email)) === strtolower(trim($DEMO_USER_EMAIL)));

    if ($is_demo_user) {
        // Use demo data
        $contactName = 'Chris McShanag';
        $companyName = 'Virtualteammate';
        $invoices = $generate_demo_invoices();
        
        // Skip to display section
        goto display_output;
    }

    // =============================
    // 4) FIND HUBSPOT CONTACT WITH ASSOCIATIONS (CACHED)
    // =============================
    $contact_cache_key = 'hs_contact_full_' . md5(strtolower(trim($email)));
    $contactCached = get_transient($contact_cache_key);

    $contactId = '';
    $contactName = '';
    $companyName = '';
    $companyId = '';

    if (is_array($contactCached) && !empty($contactCached['id'])) {
        $contactId   = $contactCached['id'];
        $contactName = $contactCached['name'] ?? '';
        $companyName = $contactCached['company'] ?? '';
        $companyId   = $contactCached['company_id'] ?? '';
    } else {
        $contactSearchBody = [
            "filterGroups" => [[
                "filters" => [[
                    "propertyName" => "email",
                    "operator"     => "EQ",
                    "value"        => $email,
                ]]
            ]],
            "properties" => ["email", "firstname", "lastname", "company"],
            "associations" => ["companies"],
            "limit"      => 1,
        ];

        $contactResp = $hubspot_request('https://api.hubapi.com/crm/v3/objects/contacts/search', $contactSearchBody);

        if (!$contactResp['ok']) {
            return '<div class="hubspot-invoices">
                        <h2>Your HubSpot Invoices</h2>
                        <p>Error contacting HubSpot while searching for contact. ' . esc_html($contactResp['error']) . '</p>
                    </div>';
        }

        $contactBody = $contactResp['data'];
        if (empty($contactBody['results'][0]['id'])) {
            return '<div class="hubspot-invoices">
                        <h2>Your HubSpot Invoices</h2>
                        <p>No HubSpot contact found for your email (' . esc_html($email) . ').</p>
                    </div>';
        }

        $contact   = $contactBody['results'][0];
        $contactId = $contact['id'];

        $contactName = trim(
            ($contact['properties']['firstname'] ?? '') . ' ' .
            ($contact['properties']['lastname'] ?? '')
        );
        $companyName = $contact['properties']['company'] ?? '';

        if (!empty($contact['associations']['companies']['results'][0]['id'])) {
            $companyId = $contact['associations']['companies']['results'][0]['id'];
        }

        if (empty($companyId)) {
            $assocResp = $hubspot_request("https://api.hubapi.com/crm/v4/objects/contacts/{$contactId}/associations/companies");
            
            if ($assocResp['ok'] && !empty($assocResp['data']['results'][0]['toObjectId'])) {
                $companyId = $assocResp['data']['results'][0]['toObjectId'];
            }
        }

        if (!empty($companyId) && empty($companyName)) {
            $companyResp = $hubspot_request("https://api.hubapi.com/crm/v3/objects/companies/{$companyId}?properties=name");
            if ($companyResp['ok'] && !empty($companyResp['data']['properties']['name'])) {
                $companyName = $companyResp['data']['properties']['name'];
            }
        }

        set_transient($contact_cache_key, [
            'id'         => $contactId,
            'name'       => $contactName,
            'company'    => $companyName,
            'company_id' => $companyId,
        ], $CONTACT_CACHE_TTL);
    }

    if (defined('WP_DEBUG') && WP_DEBUG && current_user_can('administrator')) {
        error_log("Contact ID: {$contactId}, Company ID: {$companyId}, Company Name: {$companyName}");
    }

    // =============================
    // 5) GET INVOICES - SEARCH BY BOTH CONTACT AND COMPANY
    // =============================
    $invoices_cache_key = 'hs_invoices_combined_' . md5($contactId . '_' . $companyId);
    $stale_cache_key    = $invoices_cache_key . '_stale';

    $invoices = get_transient($invoices_cache_key);

    if (!is_array($invoices)) {
        $invoiceProperties = [
            'hs_invoice_number',
            'hs_invoice_link',
            'hs_title',
            'hs_amount_billed',
            'amount',
            'hs_invoice_status',
            'hs_due_date',
            'hs_payment_date',
            'hs_createdate',
            'hs_lastmodifieddate',
            'name',
            'hs_currency',
            'hs_line_items',
            'deal_name',
            'hs_note',
        ];

        $allInvoices = [];
        $invoiceIds = [];

        $contactInvoiceSearch = [
            "filterGroups" => [[
                "filters" => [[
                    "propertyName" => "associations.contact",
                    "operator"     => "EQ",
                    "value"        => $contactId
                ]]
            ]],
            "properties" => $invoiceProperties,
            "limit"      => 100,
        ];

        $contactInvResp = $hubspot_request('https://api.hubapi.com/crm/v3/objects/invoices/search', $contactInvoiceSearch);
        
        if ($contactInvResp['ok'] && !empty($contactInvResp['data']['results'])) {
            foreach ($contactInvResp['data']['results'] as $invoice) {
                $invoiceIds[$invoice['id']] = true;
                $allInvoices[] = $invoice;
            }
        }

        if (!empty($companyId)) {
            $companyInvoiceSearch = [
                "filterGroups" => [[
                    "filters" => [[
                        "propertyName" => "associations.company",
                        "operator"     => "EQ",
                        "value"        => $companyId
                    ]]
                ]],
                "properties" => $invoiceProperties,
                "limit"      => 100,
            ];

            $companyInvResp = $hubspot_request('https://api.hubapi.com/crm/v3/objects/invoices/search', $companyInvoiceSearch);
            
            if ($companyInvResp['ok'] && !empty($companyInvResp['data']['results'])) {
                foreach ($companyInvResp['data']['results'] as $invoice) {
                    if (!isset($invoiceIds[$invoice['id']])) {
                        $invoiceIds[$invoice['id']] = true;
                        $allInvoices[] = $invoice;
                    }
                }
            }
        }

        if (empty($allInvoices)) {
            if (!$contactInvResp['ok'] || (!empty($companyId) && !$companyInvResp['ok'])) {
                $stale = get_transient($stale_cache_key);
                if (is_array($stale)) {
                    $invoices = $stale;
                } else {
                    set_transient($invoices_cache_key, [], 60);
                    return '<div class="hubspot-invoices">
                                <h2>Your HubSpot Invoices</h2>
                                <p>Error retrieving invoices from HubSpot.</p>
                            </div>';
                }
            } else {
                $invoices = [];
            }
        } else {
            usort($allInvoices, function($a, $b) {
                $dateA = $a['properties']['hs_due_date'] ?? 0;
                $dateB = $b['properties']['hs_due_date'] ?? 0;
                return $dateB <=> $dateA;
            });

            $invoices = $allInvoices;

            set_transient($invoices_cache_key, $invoices, $INVOICES_CACHE_TTL);
            set_transient($stale_cache_key, $invoices, $STALE_CACHE_TTL);
        }
    }

    if (defined('WP_DEBUG') && WP_DEBUG && current_user_can('administrator')) {
        error_log('Total invoices found: ' . count($invoices));
    }

    // =============================
    // 6) DISPLAY OUTPUT
    // =============================
    display_output:
    ob_start();
    ?>
    <div class="hubspot-invoices">
        <h2>Your HubSpot Invoices</h2>

        <style>
            .hubspot-invoices a.hs-invoice-action{
                display:inline-block;
                padding:8px 12px;
                border-radius:4px;
                font-weight:600;
                text-decoration:none;
                border:1px solid #ddd;
                white-space:nowrap;
                box-sizing:border-box;
            }
            .hubspot-invoices a.hs-invoice-action--pay{
                background:#f59e0b;
                border-color:#f59e0b;
                color:#fff;
            }
            .hubspot-invoices a.hs-invoice-action--view{
                background:#16a34a;
                border-color:#16a34a;
                color:#fff;
            }

            .hubspot-invoices{
                width:100%;
                max-width:100%;
                box-sizing:border-box;
            }
            .hubspot-invoices .hs-table-wrap{
                width:100%;
                max-width:100%;
                overflow-x:auto;
                overflow-y:auto;
                max-height:600px;
                -webkit-overflow-scrolling:touch;
            }
            .hubspot-invoices table.hs-invoices-table{
                width:100%;
                border-collapse:collapse;
                min-width:760px;
            }
            .hubspot-invoices table.hs-invoices-table thead{
                position:sticky;
                top:0;
                background-color:#f5f5f5;
                z-index:10;
            }
            .hubspot-invoices table.hs-invoices-table td:nth-child(1),
            .hubspot-invoices table.hs-invoices-table th:nth-child(1),
            .hubspot-invoices table.hs-invoices-table td:nth-child(7),
            .hubspot-invoices table.hs-invoices-table th:nth-child(7){
                white-space:nowrap;
            }
            .hubspot-invoices table.hs-invoices-table td:nth-child(2),
            .hubspot-invoices table.hs-invoices-table th:nth-child(2){
                white-space:normal;
            }

            .demo-mode-badge {
                display: inline-block;
                background: #3b82f6;
                color: white;
                padding: 4px 12px;
                border-radius: 4px;
                font-size: 12px;
                font-weight: 600;
                margin-left: 10px;
            }

            @media (max-width: 768px){
                .hubspot-invoices h2{
                    font-size:20px;
                    line-height:1.25;
                    margin:0 0 10px 0;
                }
                .hubspot-invoices p{
                    margin:8px 0;
                }
                .hubspot-invoices table.hs-invoices-table th,
                .hubspot-invoices table.hs-invoices-table td{
                    padding:8px !important;
                }
            }

            @media (max-width: 480px){
                .hubspot-invoices a.hs-invoice-action{
                    width:100%;
                    text-align:center;
                }
            }
        </style>



        <?php if (!empty($contactName)): ?>
            <p><strong>Contact:</strong> <?php echo esc_html($contactName); ?></p>
        <?php endif; ?>

        <?php if (!empty($companyName)): ?>
            <p><strong>Company:</strong> <?php echo esc_html($companyName); ?></p>
        <?php endif; ?>

        <?php if (empty($invoices)): ?>
            <p>No invoices found for your account.</p>
            <?php if (current_user_can('administrator') && !$is_demo_user): ?>
                <p style="font-size:0.9em; color:#666;">
                    <em>Debug: Contact ID: <?php echo esc_html($contactId); ?>, Company ID: <?php echo esc_html($companyId ?: 'None'); ?></em>
                </p>
            <?php endif; ?>
        <?php else: ?>
            <div class="hs-table-wrap">
                <table class="hs-invoices-table" border="1" cellpadding="8" cellspacing="0" style="border-collapse:collapse; width:100%; margin-top:20px;">
                    <thead>
                        <tr style="background-color:#f5f5f5;">
                            <th style="padding:10px; text-align:left;">Invoice #</th>
                            <th style="padding:10px; text-align:left;">Description</th>
                            <th style="padding:10px; text-align:right;">Amount</th>
                            <th style="padding:10px; text-align:center;">Status</th>
                            <th style="padding:10px; text-align:center;">Due Date</th>
                            <th style="padding:10px; text-align:center;">Payment Date</th>
                            <th style="padding:10px; text-align:center;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $paidCount = 0;

                    foreach ($invoices as $inv):
                        $props = $inv['properties'] ?? [];

                        $invoiceNumber = '';
                        if (!empty($props['hs_invoice_number'])) {
                            $invoiceNumber = $props['hs_invoice_number'];
                        } elseif (!empty($props['name']) && preg_match('/INV-\d+/i', $props['name'])) {
                            $invoiceNumber = $props['name'];
                        } else {
                            foreach ($props as $propValue) {
                                if (is_string($propValue) && preg_match('/(INV-\d+)/i', $propValue, $matches)) {
                                    $invoiceNumber = $matches[1];
                                    break;
                                }
                            }
                        }
                        if (empty($invoiceNumber) && !empty($inv['id'])) {
                            $shortId = substr((string) $inv['id'], -4);
                            $invoiceNumber = 'INV-' . $shortId;
                        }

                        $invoiceLink = $props['hs_invoice_link'] ?? '';

                        $title = 'Invoice';
                        if (!empty($props['hs_title'])) {
                            $title = $props['hs_title'];
                        } elseif (!empty($props['deal_name'])) {
                            $title = $props['deal_name'];
                        } elseif (!empty($props['hs_note'])) {
                            $title = substr($props['hs_note'], 0, 50) . '...';
                        }

                        if (empty($props['hs_title']) && !empty($props['hs_line_items']) && is_string($props['hs_line_items'])) {
                            if (strlen($props['hs_line_items']) <= 20000) {
                                $lineItems = json_decode($props['hs_line_items'], true);
                                if (is_array($lineItems) && !empty($lineItems[0]['name'])) {
                                    $title = $lineItems[0]['name'];
                                    if (count($lineItems) > 1) {
                                        $title .= ' +' . (count($lineItems) - 1) . ' more';
                                    }
                                }
                            }
                        }

                        $total = $props['hs_amount_billed'] ?? $props['amount'] ?? '0.00';
                        if ($total === '' || (float)$total == 0.0) {
                            $totalFormatted = '<span style="color:#999;">$0.00</span>';
                        } else {
                            $totalFormatted = '$' . number_format((float) $total, 2);
                        }

                        $statusRaw   = (string) ($props['hs_invoice_status'] ?? 'UNKNOWN');
                        $statusUpper = strtoupper(trim($statusRaw));

                        $isPaid = in_array($statusUpper, ['PAID'], true);

                        $statusColor = 'gray';
                        $statusText  = ucfirst(strtolower((string) $statusRaw));

                        if ($isPaid) {
                            $statusColor = 'green';
                            $statusText  = 'Paid';
                            $paidCount++;
                        } elseif (in_array($statusUpper, ['UNPAID', 'OPEN', 'PENDING', 'SENT', 'OVERDUE', 'PARTIALLY_PAID'], true)) {
                            $statusColor = 'orange';
                            $statusText  = ucfirst(strtolower($statusUpper));
                        } elseif ($statusUpper === 'DRAFT') {
                            $statusColor = 'blue';
                            $statusText  = 'Draft';
                        }

                        $dueDateRaw     = $props['hs_due_date'] ?? '';
                        $paymentDateRaw = $props['hs_payment_date'] ?? '';

                        $dueDateFormatted     = $format_hs_date($dueDateRaw) ?: 'Not set';
                        $paymentDateFormatted = $format_hs_date($paymentDateRaw) ?: '--';

                        $invoiceNumberCell = esc_html($invoiceNumber);

                        $actionCell = '--';
                        if (!empty($invoiceLink)) {
                            $actionLabel = $isPaid ? 'View Here' : 'Pay Here';
                            $actionClass = $isPaid ? 'hs-invoice-action--view' : 'hs-invoice-action--pay';

                            $actionCell = sprintf(
                                '<a class="hs-invoice-action %s" href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
                                esc_attr($actionClass),
                                esc_url($invoiceLink),
                                esc_html($actionLabel)
                            );
                        }
                        ?>
                        <tr>
                            <td style="padding:10px;"><?php echo $invoiceNumberCell; ?></td>
                            <td style="padding:10px;"><?php echo esc_html($title); ?></td>
                            <td style="padding:10px; text-align:right;"><?php echo wp_kses_post($totalFormatted); ?></td>
                            <td style="padding:10px; text-align:center;">
                                <span style="color:<?php echo esc_attr($statusColor); ?>; font-weight:bold;">
                                    <?php echo esc_html($statusText); ?>
                                </span>
                            </td>
                            <td style="padding:10px; text-align:center;"><?php echo esc_html($dueDateFormatted); ?></td>
                            <td style="padding:10px; text-align:center;"><?php echo esc_html($paymentDateFormatted); ?></td>
                            <td style="padding:10px; text-align:center;"><?php echo wp_kses_post($actionCell); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div style="margin-top:20px; padding:10px; background-color:#f9f9f9; border-radius:5px;">
                <p><strong>Summary:</strong> <?php echo (int) count($invoices); ?> invoice(s) found • <?php echo (int) $paidCount; ?> paid</p>
            </div>
        <?php endif; ?>
    </div>
    <?php

    return ob_get_clean();

});
