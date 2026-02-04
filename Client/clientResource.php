/**
 * VTM Resource Hub (Covers + Background Image, No Copy Buttons) - Shortcode
 * Paste into Code Snippets as PHP, then use shortcode: [vtm_resource_hub_consolidated]
 *
 * Uses provided cover images for each downloadable file.
 * - Playbook title updated to "Virtual Teammate Client Playbook"
 * - Section background: subtle purple tint + provided background image
 * - No Copy Link buttons
 * - Each download card displays its cover image + type badge + CTA
 */

add_shortcode('vtm_resource_hub_consolidated', function () {

  // Button colors
  $primary_bg   = '#F6B945';
  $primary_text = '#FFFFFF';

  // Hover font color for primary buttons
  $hover_text_gray = '#6B7280';

  // Background image (provided)
  $bg_image = 'https://clientvtm.wpenginepowered.com/wp-content/uploads/2025/09/vtm_referral_models-removebg-preview-2.png';

  // Neutral tokens
  $text  = '#111827';
  $muted = '#6B7280';

  $playbook = [
    'title' => 'Virtual Teammate Client Playbook',
    'desc'  => 'Start here to align expectations, simplify communication, and establish a smooth workflow—so your Virtual Teammate can deliver faster.',
    'url'   => 'https://clientvtm.wpenginepowered.com/client-playbook/',
  ];

  // Downloads + cover images (provided)
  $downloads = [
    [
      'title' => 'Outcome-Based Responsibilities (OBR) Worksheet',
      'desc'  => 'Define outcomes and accountability clearly—then reuse it for every role.',
      'url'   => 'https://baa78665-b905-489f-a89a-dea3af4d293d.filesusr.com/ugd/739bab_d2efa46efff347d49c2bd8e129a541af.pdf',
      'type'  => 'PDF',
      'cta'   => 'Download',
      'cover' => 'https://clientvtm.wpenginepowered.com/wp-content/uploads/2026/01/Outcome-Based-Responsibilities-OBR-Worksheet.png',
    ],
    [
      'title' => 'OBR Guide (Healthcare)',
      'desc'  => 'Examples and best practices tailored for healthcare workflows.',
      'url'   => 'https://baa78665-b905-489f-a89a-dea3af4d293d.filesusr.com/ugd/e9e902_13ea922a5746474094b89ac726b0ee2c.pdf',
      'type'  => 'PDF',
      'cta'   => 'Download',
      'cover' => 'https://clientvtm.wpenginepowered.com/wp-content/uploads/2026/01/OBR-Guide-Healthcare.jpg',
    ],
    [
      'title' => 'OBR Guide (Business)',
      'desc'  => 'A simple blueprint for outcomes, KPIs, and ownership across teams.',
      'url'   => 'https://baa78665-b905-489f-a89a-dea3af4d293d.filesusr.com/ugd/e9e902_cdd544927f544dad96b408d9590f53ba.pdf',
      'type'  => 'PDF',
      'cta'   => 'Download',
      'cover' => 'https://clientvtm.wpenginepowered.com/wp-content/uploads/2026/01/OBR-Guide-Business.png',
    ],
    [
      'title' => 'A Decade of Change',
      'desc'  => 'A quick read on modern staffing and what it means for your business today.',
      'url'   => 'https://baa78665-b905-489f-a89a-dea3af4d293d.filesusr.com/ugd/3a288b_ef4040c0dc39463aa55f3da4defc39ed.pdf',
      'type'  => 'PDF',
      'cta'   => 'Download',
      'cover' => 'https://clientvtm.wpenginepowered.com/wp-content/uploads/2026/01/A-Decade-of-Change.jpg',
    ],
    [
      'title' => 'Minutes to Millions Workbook',
      'desc'  => 'Delegate, systematize operations, and reclaim strategic focus to scale.',
      'url'   => 'https://clientvtm.wpenginepowered.com/wp-content/uploads/2025/12/BBYT-Workbook.pdf.pdf%20',
      'type'  => 'PDF',
      'cta'   => 'Download',
      'cover' => 'https://clientvtm.wpenginepowered.com/wp-content/uploads/2026/01/Minutes-to-Millions-Workbook.jpg',
    ],
    [
      'title' => 'Time Clarity Worksheet',
      'desc'  => 'Find time drains, prioritize high-value work, and structure your day with clarity.',
      'url'   => 'https://clientvtm.wpenginepowered.com/wp-content/uploads/2025/11/VTM%20-%20Time%20Clarity%20(1).pdf',
      'type'  => 'PDF',
      'cta'   => 'Download',
      'cover' => 'https://clientvtm.wpenginepowered.com/wp-content/uploads/2026/01/Time-Clarity-Worksheet.jpg',
    ],
  ];

  // Minimal inline SVG icons (no external libs)
  $icon_playbook = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" aria-hidden="true"><path d="M4 5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16H6a2 2 0 0 1-2-2V5Z" stroke="currentColor" stroke-width="2"/><path d="M6 7h10M6 11h10M6 15h8" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
  $icon_pdf      = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" aria-hidden="true"><path d="M7 3h7l3 3v15a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z" stroke="currentColor" stroke-width="2"/><path d="M14 3v4a2 2 0 0 0 2 2h4" stroke="currentColor" stroke-width="2"/></svg>';

  ob_start();
  ?>
  <style>
    .vtm-hub6, .vtm-hub6 * { box-sizing: border-box; }
    .vtm-hub6 { color: <?php echo esc_attr($text); ?>; }

    /* Whole block background: subtle purple + image */
    .vtm-hub6-stage{
      position: relative;
      border-radius: 22px;
      overflow: hidden;
      background:
        radial-gradient(1200px 600px at 15% 10%, rgba(112,119,255,0.22), rgba(112,119,255,0.00) 55%),
        radial-gradient(900px 500px at 85% 35%, rgba(147,51,234,0.16), rgba(147,51,234,0.00) 60%),
        linear-gradient(180deg, rgba(255,255,255,0.70), rgba(255,255,255,0.55));
    }

    .vtm-hub6-stage::after{
      content:"";
      position:absolute;
      inset: 0;
      background-image: url('<?php echo esc_url($bg_image); ?>');
      background-repeat: no-repeat;
      background-position: right 24px bottom 0px;
      background-size: min(520px, 46vw);
      opacity: 0.22;
      pointer-events: none;
      mix-blend-mode: multiply;
    }

    .vtm-hub6-wrap{
      position: relative;
      z-index: 1;
      max-width: 1120px;
      margin: 0 auto;
      padding: 18px 14px;
    }

    /* Playbook hero */
    .vtm-hero6{
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap: 14px;
      padding: 16px;
      border-radius: 18px;
      background: rgba(255,255,255,0.82);
      box-shadow: 0 18px 48px rgba(17,24,39,0.10);
    }

    .vtm-kicker6{
      display:flex;
      align-items:center;
      gap: 10px;
      margin: 0 0 8px 0;
      font-size: 12.5px;
      font-weight: 900;
      letter-spacing: 0.02em;
      color: rgba(17,24,39,0.78);
    }
    .vtm-dot6{
      width:10px;height:10px;border-radius:999px;
      background: <?php echo esc_attr($primary_bg); ?>;
      box-shadow: 0 0 0 4px rgba(246,185,69,0.22);
      flex: 0 0 auto;
    }

    .vtm-hero6 h3{
      margin: 0 0 6px 0;
      font-size: 15px;
      font-weight: 950;
      letter-spacing: -0.01em;
      line-height: 1.2;
    }
    .vtm-hero6 p{
      margin: 0;
      font-size: 13.5px;
      line-height: 1.55;
      color: <?php echo esc_attr($muted); ?>;
      max-width: 82ch;
    }

    .vtm-hero6-actions{
      display:flex;
      gap: 10px;
      flex-wrap: wrap;
      justify-content:flex-end;
      align-items:center;
      flex: 0 0 auto;
      margin-top: 2px;
    }

    /* Primary button */
    .vtm-btn6{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap: 8px;
      padding: 10px 12px;
      border-radius: 12px;
      border: none;
      text-decoration:none;
      cursor:pointer;
      font-size: 13px;
      font-weight: 900;
      line-height: 1;
      white-space: nowrap;
      transition: transform .15s ease, filter .15s ease, box-shadow .15s ease, background .15s ease, color .15s ease;
      user-select:none;
    }
    .vtm-btn6-primary{
      background: <?php echo esc_attr($primary_bg); ?>;
      color: <?php echo esc_attr($primary_text); ?>;
      box-shadow: 0 16px 34px rgba(246,185,69,0.34);
    }
    .vtm-btn6-primary:hover{
      transform: translateY(-1px);
      filter: brightness(0.98);
      color: <?php echo esc_attr($hover_text_gray); ?>; /* requested */
      box-shadow: 0 20px 44px rgba(246,185,69,0.42);
    }

    /* Downloads section */
    .vtm-section6{
      margin-top: 14px;
      padding: 14px;
      border-radius: 18px;
      background: rgba(255,255,255,0.82);
      box-shadow: 0 18px 48px rgba(17,24,39,0.10);
    }

    .vtm-head6 h3{
      margin: 0;
      font-size: 14px;
      font-weight: 950;
      letter-spacing: -0.01em;
      line-height: 1.2;
    }
    .vtm-sub6{
      margin: 6px 0 0 0;
      font-size: 13px;
      line-height: 1.5;
      color: <?php echo esc_attr($muted); ?>;
      max-width: 92ch;
    }

    /* Cards with cover images */
    .vtm-grid6{
      display:grid;
      grid-template-columns: repeat(12, 1fr);
      gap: 12px;
      margin-top: 12px;
    }

    .vtm-card6{
      grid-column: span 4;
      background: rgba(255,255,255,0.94);
      border-radius: 16px;
      box-shadow: 0 14px 34px rgba(17,24,39,0.10);
      overflow: hidden;
      display:flex;
      flex-direction:column;
      min-height: 290px;
      transition: transform .15s ease, box-shadow .15s ease;
    }
    .vtm-card6:hover{
      transform: translateY(-1px);
      box-shadow: 0 18px 44px rgba(17,24,39,0.12);
    }

    .vtm-cover6{
      position: relative;
      width: 100%;
      aspect-ratio: 16 / 9;
      background: rgba(17,24,39,0.03);
      overflow: hidden;
    }
    .vtm-cover6 img{
      width: 100%;
      height: 100%;
      object-fit: cover;
      display:block;
      transform: scale(1.01);
    }

    .vtm-badge6{
      position:absolute;
      left: 10px;
      top: 10px;
      display:inline-flex;
      align-items:center;
      gap: 6px;
      font-size: 11px;
      font-weight: 950;
      letter-spacing: 0.08em;
      color: rgba(17,24,39,0.78);
      background: rgba(255,255,255,0.86);
      padding: 7px 10px;
      border-radius: 999px;
      backdrop-filter: blur(6px);
    }

    .vtm-card6-body{
      padding: 12px 14px 14px 14px;
      display:flex;
      flex-direction:column;
      justify-content:space-between;
      flex: 1 1 auto;
      gap: 10px;
    }

    .vtm-card6-title{
      margin: 0;
      font-size: 14px;
      font-weight: 950;
      letter-spacing: -0.01em;
      line-height: 1.25;
      color: <?php echo esc_attr($text); ?>;
    }

    .vtm-card6-desc{
      margin: 0;
      font-size: 13px;
      line-height: 1.55;
      color: <?php echo esc_attr($muted); ?>;
    }

    .vtm-card6-actions{
      display:flex;
      align-items:center;
      justify-content:flex-start;
      margin-top: 4px;
    }

    /* Responsive */
    @media (max-width: 980px){
      .vtm-card6{ grid-column: span 6; }
      .vtm-hub6-stage::after{ background-size: min(460px, 62vw); opacity: 0.18; }
    }
    @media (max-width: 640px){
      .vtm-hero6{ flex-direction: column; }
      .vtm-hero6-actions{ justify-content:flex-start; width: 100%; }
      .vtm-card6{ grid-column: span 12; }
      .vtm-hub6-stage::after{ background-position: right -30px bottom -10px; opacity: 0.16; }
    }
  </style>

  <div class="vtm-hub6" aria-label="Client Resource Hub">
    <div class="vtm-hub6-stage">
      <div class="vtm-hub6-wrap">

        <!-- Playbook (single placement) -->
        <div class="vtm-hero6">
          <div>
            <div class="vtm-kicker6">
              <span class="vtm-dot6" aria-hidden="true"></span>
              Recommended first step
            </div>
            <h3><?php echo esc_html($playbook['title']); ?></h3>
            <p><?php echo esc_html($playbook['desc']); ?></p>
          </div>

          <div class="vtm-hero6-actions">
            <a class="vtm-btn6 vtm-btn6-primary" href="<?php echo esc_url($playbook['url']); ?>" target="_blank" rel="noopener noreferrer">
              <?php echo $icon_playbook; ?> Open Playbook
            </a>
          </div>
        </div>

        <!-- Downloads -->
        <section class="vtm-section6" aria-label="Downloads">
          <div class="vtm-head6">
            <h3>Downloads</h3>
            <p class="vtm-sub6">
              Fast implementation tools: define measurable outcomes (OBR), build repeatable delegation systems (workbooks),
              and remove time drains (time clarity).
            </p>
          </div>

          <div class="vtm-grid6" role="list">
            <?php foreach ($downloads as $item): ?>
              <article class="vtm-card6" role="listitem" aria-label="<?php echo esc_attr($item['title']); ?>">
                <div class="vtm-cover6">
                  <img src="<?php echo esc_url($item['cover']); ?>" alt="<?php echo esc_attr($item['title']); ?> cover" loading="lazy">
                  <div class="vtm-badge6"><?php echo $icon_pdf; ?> <?php echo esc_html($item['type']); ?></div>
                </div>

                <div class="vtm-card6-body">
                  <div>
                    <h4 class="vtm-card6-title"><?php echo esc_html($item['title']); ?></h4>
                    <p class="vtm-card6-desc"><?php echo esc_html($item['desc']); ?></p>
                  </div>

                  <div class="vtm-card6-actions">
                    <a class="vtm-btn6 vtm-btn6-primary"
                       href="<?php echo esc_url($item['url']); ?>"
                       target="_blank"
                       rel="noopener noreferrer">
                      <?php echo esc_html($item['cta']); ?>
                    </a>
                  </div>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        </section>

      </div>
    </div>
  </div>
  <?php

  return ob_get_clean();
});