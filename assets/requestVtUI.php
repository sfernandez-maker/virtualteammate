/**
 * Shortcode: [vt_simple_pool_v10 count="5" pick="random" tab_myvt="My Virtual Teammates" tab_reports="End of Day/Week Reports"]
 *
 * v10 (updated):
 * - Subtext rephrased
 * - "Browse the Talent Pool" button is gold
 * - Ratings 4.7–5.0
 * - Instruction line updated to: "Wait for the confirmation from your CSM regarding the availability of your chosen VT."
 */

if (!defined('ABSPATH')) exit;

add_shortcode('vt_simple_pool_v10', function ($atts) {

  $atts = shortcode_atts([
    'count'       => 5,
    'pick'        => 'random', // random | first
    'tab_myvt'    => 'My Virtual Teammates',
    'tab_reports' => 'End of Day/Week Reports',
  ], $atts, 'vt_simple_pool_v10');

  $count = max(3, min(12, intval($atts['count'])));
  $pick  = strtolower(trim($atts['pick']));
  $tab_myvt = trim($atts['tab_myvt']);
  $tab_reports = trim($atts['tab_reports']);

  $talent_pool_url = 'https://virtualteammate.com/talent-pool/';

  // Get all VA posts dynamically

$all_va_posts = get_posts(array(

    'post_type' => 'vt-list-by-category',

    'posts_per_page' => -1,

    'post_status' => 'publish'

));

$vts = array();

foreach ($all_va_posts as $post) {

    // Get profile picture

    $profile_pic = get_field('profile_picture', $post->ID);

    $avatar_url = '';

    

    if (!empty($profile_pic)) {

        if (is_array($profile_pic) && isset($profile_pic['url'])) {

            $avatar_url = $profile_pic['url'];

        } elseif (is_string($profile_pic) && !empty($profile_pic)) {

            $avatar_url = $profile_pic;

        } elseif (is_numeric($profile_pic)) {

            $avatar_url = wp_get_attachment_url($profile_pic);

        }

    }

    

    // Get name and department

    $va_name = get_field('name', $post->ID);

    $department = get_field('department', $post->ID);

    

    if ($va_name && $department && $avatar_url) {

        $vts[] = array(

            'profile' => get_permalink($post->ID),

            'name' => $va_name,

            'dept' => $department,

            'img' => $avatar_url

        );

    }

}

  if ($pick === 'random') shuffle($vts);

  // Ratings (deterministic per VT)
  $allowed = [4.7, 4.8, 4.9, 5.0];
  foreach ($vts as &$vt) {
    $idx = abs(crc32($vt['profile'])) % count($allowed);
    $vt['rating'] = $allowed[$idx];
  }
  unset($vt);

  $uid = 'vtsp_' . wp_generate_uuid4();

  static $assets_printed = false;
  if (!$assets_printed) {
    $assets_printed = true;
    add_action('wp_footer', function () {
      ?>
      <style>
        .vtsp{background:#fff;border:1px solid rgba(15,23,42,.10);border-radius:14px;padding:14px;
          font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial;color:#0f172a}
        .vtsp__row{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:10px}
        .vtsp__title{margin:0;font-size:14px;font-weight:650;letter-spacing:.01em}
        .vtsp__sub{margin:4px 0 0 0;font-size:12.5px;color:rgba(15,23,42,.62);font-weight:400}

        /* Gold button */
        .vtsp__browse{
          margin-left:auto;font-size:12.5px;font-weight:750;
          color:#1f1600;text-decoration:none;white-space:nowrap;
          border:1px solid rgba(0,0,0,.08);
          background:#f5b301;
          border-radius:999px;padding:9px 12px;
          transition:transform .12s ease, filter .12s ease;
        }
        .vtsp__browse:hover{transform:translateY(-1px);filter:brightness(.97)}

        .vtsp__note{
          margin:10px 0 12px 0;padding:10px 12px;border-radius:12px;border:1px solid rgba(15,23,42,.10);
          background: rgba(2,6,23,.02);font-size:12.5px;color: rgba(15,23,42,.70);line-height:1.5;font-weight:450;
        }
        .vtsp__note ul{margin:8px 0 0 18px;padding:0}
        .vtsp__note li{margin:4px 0}

        .vtsp__grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px}
        @media (max-width:980px){.vtsp__grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
        @media (max-width:560px){.vtsp__grid{grid-template-columns:repeat(2,minmax(0,1fr))}}

        .vtsp__card{display:block;text-decoration:none;border:1px solid rgba(15,23,42,.10);
          background:#fff;border-radius:12px;padding:10px;transition:transform .12s ease,border-color .12s ease,background .12s ease;}
        .vtsp__card:hover{transform:translateY(-1px);border-color:rgba(15,23,42,.18);background:rgba(2,6,23,.02)}
        .vtsp__imgWrap{width:100%;aspect-ratio:1/1;border-radius:10px;overflow:hidden;background:rgba(2,6,23,.03);
          margin-bottom:8px;display:flex;align-items:center;justify-content:center;}
        .vtsp__img{width:100%;height:100%;object-fit:cover;display:block}
        .vtsp__dept{margin:0;font-size:12.5px;font-weight:700;line-height:1.25;color:#0f172a}
        .vtsp__name{margin:4px 0 0 0;font-size:12px;color:rgba(15,23,42,.62);font-weight:500;line-height:1.25}

        .vtsp__rating{margin:6px 0 0 0;display:flex;align-items:center;gap:6px}
        .vtsp__stars{display:inline-flex;gap:2px;line-height:1}
        .vtsp__star{font-size:12px;color:#f5b301}
        .vtsp__score{font-size:11.5px;color:rgba(15,23,42,.62);font-weight:600}
      </style>

      <script>
        (function(){
          function norm(s){
            s = (s || '').toString().toLowerCase().trim();
            try { s = s.normalize('NFKD').replace(/[\u0300-\u036f]/g,''); } catch(e) {}
            s = s.replace(/^https?:\/\/(www\.)?/,'');
            s = s.replace(/[^a-z0-9]+/g,'');
            return s;
          }
          function findTabContentByExactTitle(titleText){
            if (!titleText) return null;
            const wanted = (titleText || '').trim().toLowerCase();
            const titles = Array.from(document.querySelectorAll('.elementor-tab-title'));
            const t = titles.find(el => (el.textContent || '').trim().toLowerCase() === wanted);
            if (!t) return null;
            const tabId = t.getAttribute('id');
            if (!tabId) return null;
            return document.querySelector('.elementor-tab-content[aria-labelledby="'+CSS.escape(tabId)+'"]');
          }
          function collectExistingIds(tabTitleA, tabTitleB){
            const roots = [];
            const a = findTabContentByExactTitle(tabTitleA);
            const b = findTabContentByExactTitle(tabTitleB);
            if (a) roots.push(a);
            if (b) roots.push(b);
            const ids = new Set();
            roots.forEach(root => {
              root.querySelectorAll('a[href]').forEach(link => {
                const href = link.getAttribute('href') || '';
                if (href) ids.add(norm(href));
                const txt = (link.textContent || '').trim();
                if (txt) ids.add(norm(txt));
              });
              const raw = (root.textContent || '').split(/\n+/).map(x => x.trim()).filter(Boolean);
              raw.forEach(line => ids.add(norm(line)));
            });
            return ids;
          }
          function filterOneSection(section){
            const limit = parseInt(section.getAttribute('data-limit') || '5', 10);
            const tabA  = section.getAttribute('data-tab-a') || '';
            const tabB  = section.getAttribute('data-tab-b') || '';
            const existing = collectExistingIds(tabA, tabB);
            const cards = Array.from(section.querySelectorAll('[data-vt-card]'));
            cards.forEach(card => {
              const profile = card.getAttribute('data-profile') || '';
              const name    = card.getAttribute('data-name') || '';
              const dup = (profile && existing.has(norm(profile))) || (name && existing.has(norm(name)));
              if (dup) {
                card.style.display = 'none';
                card.setAttribute('aria-hidden', 'true');
              } else {
                card.style.display = '';
                card.removeAttribute('aria-hidden');
              }
            });
            let shown = 0;
            cards.forEach(card => {
              if (card.style.display === 'none') return;
              shown++;
              if (shown > limit) {
                card.style.display = 'none';
                card.setAttribute('aria-hidden', 'true');
              }
            });
          }
          function run(){ document.querySelectorAll('.vtsp[data-vtsp]').forEach(filterOneSection); }
          document.addEventListener('DOMContentLoaded', run);
          const mo = new MutationObserver(() => run());
          mo.observe(document.body, {subtree:true, childList:true});
          document.addEventListener('click', (e) => {
            const t = e.target;
            if (t && t.closest && t.closest('.elementor-tab-title')) run();
          });
        })();
      </script>
      <?php
    }, 60);
  }

  $render_stars = function($rating){
    $out = '';
    for ($i=0; $i<5; $i++) $out .= '<span class="vtsp__star" aria-hidden="true">★</span>';
    return $out;
  };

  ob_start();
  ?>
  <section class="vtsp"
           id="<?php echo esc_attr($uid); ?>"
           data-vtsp
           data-limit="<?php echo esc_attr($count); ?>"
           data-tab-a="<?php echo esc_attr($tab_myvt); ?>"
           data-tab-b="<?php echo esc_attr($tab_reports); ?>"
           aria-label="Virtual Teammate Recommendations">

    <div class="vtsp__row">
      <div>
        <h3 class="vtsp__title">Recommended Virtual Teammates</h3>
        <p class="vtsp__sub">Here are the top recommended candidates that best suit your needs.</p>
      </div>

      <a class="vtsp__browse" href="<?php echo esc_url($talent_pool_url); ?>">
        Browse the Talent Pool →
      </a>
    </div>

    <div class="vtsp__note">
      <div>After you pick a VT:</div>
      <ul>
        <li>Your Client Manager will handle the interview, onboarding, training, and ongoing management.</li>
        <li>Wait for the confirmation from your CSM regarding the availability of your chosen VT.</li>
        <li>Once approved, the VT will appear in your My Virtual Teammates tab right away.</li>
      </ul>
    </div>

    <div class="vtsp__grid">
      <?php foreach ($vts as $vt): ?>
        <a class="vtsp__card"
           data-vt-card
           data-profile="<?php echo esc_attr($vt['profile']); ?>"
           data-name="<?php echo esc_attr($vt['name']); ?>"
           href="<?php echo esc_url($vt['profile']); ?>"
           aria-label="<?php echo esc_attr('Open profile: ' . $vt['name']); ?>">
          <div class="vtsp__imgWrap">
            <img class="vtsp__img" src="<?php echo esc_url($vt['img']); ?>" alt="<?php echo esc_attr($vt['name']); ?>">
          </div>
          <p class="vtsp__dept"><?php echo esc_html($vt['dept']); ?></p>
          <p class="vtsp__name"><?php echo esc_html($vt['name']); ?></p>

          <div class="vtsp__rating" aria-label="<?php echo esc_attr('Rating ' . number_format($vt['rating'], 1) . ' out of 5'); ?>">
            <span class="vtsp__stars"><?php echo $render_stars($vt['rating']); ?></span>
            <span class="vtsp__score"><?php echo esc_html(number_format($vt['rating'], 1)); ?></span>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php
  return ob_get_clean();
});