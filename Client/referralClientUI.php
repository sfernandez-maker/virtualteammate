/**
 * VTM Referrals Tab UI (Elementor-friendly)
 * Usage in Elementor Shortcode widget:
 *   [vtm_referrals_tab email="deeann@onptbiz.com" chrome_store_url="https://chromewebstore.google.com/..."]
 */

add_shortcode('vtm_referrals_tab', function ($atts) {
  $atts = shortcode_atts([
    'email'            => '',
    'chrome_store_url' => '#',
  ], $atts, 'vtm_referrals_tab');

  $email = sanitize_email($atts['email']);
  $chrome_store_url = esc_url($atts['chrome_store_url']);

  $uid = 'vtmref_' . (function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid());

  ob_start();
  ?>
  <div id="<?php echo esc_attr($uid); ?>" class="vtm-referrals">
    <div class="vtm-referrals__header">
      <div>
        <h2 class="vtm-referrals__title">Referrals</h2>
        <p class="vtm-referrals__subtitle">
          Install the VTM Referral Chrome Extension to access your referral dashboard, copy your link, and share faster.
        </p>
      </div>

      <div class="vtm-referrals__actions">
        <a class="vtm-btn vtm-btn--primary" href="<?php echo $chrome_store_url; ?>" target="_blank" rel="noopener">
          Download Chrome Extension
        </a>
        <button class="vtm-btn vtm-btn--ghost" type="button" data-action="scroll-install">
          View Install Steps
        </button>
      </div>
    </div>

    <div class="vtm-grid">
      <!-- LEFT: Instructions -->
      <section class="vtm-card" aria-labelledby="<?php echo esc_attr($uid); ?>_installTitle" data-install-card>
        <div class="vtm-card__head">
          <h3 class="vtm-card__title" id="<?php echo esc_attr($uid); ?>_installTitle">How to install the VTM Referral Chrome Extension</h3>
          <p class="vtm-card__desc">
            Complete this once. After installation, the extension will be available whenever you are logged in to your VTM Ambassador account.
          </p>
        </div>

        <ol class="vtm-steps">
          <li class="vtm-step">
            <div class="vtm-step__num">1</div>
            <div class="vtm-step__body">
              <div class="vtm-step__title">Open Chrome’s Extensions page</div>
              <div class="vtm-step__text">
                Click the <strong>Extensions</strong> icon (puzzle piece) near the address bar, then select
                <strong>Manage extensions</strong>.
                <div class="vtm-step__hint">Tip: You can also type <code>chrome://extensions</code> in the address bar.</div>
              </div>
            </div>
          </li>

          <li class="vtm-step">
            <div class="vtm-step__num">2</div>
            <div class="vtm-step__body">
              <div class="vtm-step__title">Find the extension in the Chrome Web Store</div>
              <div class="vtm-step__text">
                Open the <strong>Chrome Web Store</strong> and search for:
                <span class="vtm-pill">VTM Referral Portal Access</span>
                <div class="vtm-step__hint">
                  Confirm you are installing the official VTM extension before adding it to Chrome.
                </div>
              </div>
            </div>
          </li>

          <li class="vtm-step">
            <div class="vtm-step__num">3</div>
            <div class="vtm-step__body">
              <div class="vtm-step__title">Install and pin it for easy access</div>
              <div class="vtm-step__text">
                Click <strong>Add to Chrome</strong>, then confirm <strong>Add extension</strong>.
                After installation, click the puzzle icon again and click the <strong>Pin</strong> icon next to the extension so it stays visible.
              </div>
            </div>
          </li>
        </ol>

        <div class="vtm-divider"></div>

        <div class="vtm-card__head" style="padding-top:0;">
          <h3 class="vtm-card__title">How to use the extension</h3>
          <p class="vtm-card__desc">Recommended workflow.</p>
        </div>

        <ul class="vtm-bullets">
          <li><strong>Log in first:</strong> Sign in to your <strong>VTM Ambassador</strong> account on the VTM website.</li>
          <li><strong>Open the extension:</strong> Click the extension icon to open your referral dashboard.</li>
          <li><strong>View your code/link:</strong> Your referral code appears inside the extension once you are logged in.</li>
          <li><strong>Share:</strong> Share your referral link on social media or via message/email. You can edit the text and add an image before posting.</li>
        </ul>

        <div class="vtm-note" role="note" aria-label="Important note">
          <div class="vtm-note__icon">⚠️</div>
          <div class="vtm-note__text">
            <strong>Important:</strong> The extension mirrors your VTM session. If you log out of the VTM website, the extension logs out automatically.
          </div>
        </div>
      </section>

      <!-- RIGHT: Summary + Video -->
      <section class="vtm-card" aria-labelledby="<?php echo esc_attr($uid); ?>_summaryTitle">
        <div class="vtm-card__head">
          <h3 class="vtm-card__title" id="<?php echo esc_attr($uid); ?>_summaryTitle">Your referral summary</h3>
          <p class="vtm-card__desc">A snapshot of your referral activity and performance.</p>
        </div>

        <div class="vtm-summary">
          <?php echo do_shortcode('[referrals_summary email="' . esc_attr($email) . '"]'); ?>
        </div>

        <div class="vtm-divider"></div>

        <div class="vtm-card__head" style="padding-top:0;">
          <h3 class="vtm-card__title">Video walkthrough</h3>
          <p class="vtm-card__desc">
            If you would like a guided, step-by-step demonstration, watch the walkthrough below to follow the installation and sharing process.
          </p>
        </div>

        <div class="vtm-video" data-video-host>
          <div class="vtm-video__placeholder" data-video-placeholder>
            <div class="vtm-video__placeholderTitle">Video loading…</div>
            <div class="vtm-muted">If the video does not appear, please refresh the page and try again.</div>
          </div>
        </div>
      </section>
    </div>
  </div>

  <style>
    #<?php echo esc_attr($uid); ?>{
      --vtm-primary:#7077FF;
      --vtm-accent:#F6B945;
      --vtm-text:#0F172A;
      --vtm-muted:#64748B;
      --vtm-border:rgba(15,23,42,.12);
      --vtm-bg:rgba(255,255,255,.92);
      --vtm-shadow:0 12px 30px rgba(15,23,42,.08);
      --vtm-radius:16px;
      font-family: inherit;
      color: var(--vtm-text);
    }

    #<?php echo esc_attr($uid); ?> .vtm-referrals__header{
      display:flex; gap:18px; align-items:flex-start; justify-content:space-between;
      padding:18px; background:var(--vtm-bg); border:1px solid var(--vtm-border);
      border-radius:var(--vtm-radius); box-shadow:var(--vtm-shadow);
      margin-bottom:16px;
    }
    #<?php echo esc_attr($uid); ?> .vtm-referrals__title{ margin:0 0 6px; font-size:22px; line-height:1.2; letter-spacing:-.02em; }
    #<?php echo esc_attr($uid); ?> .vtm-referrals__subtitle{ margin:0; color:var(--vtm-muted); max-width:70ch; }
    #<?php echo esc_attr($uid); ?> .vtm-referrals__actions{ display:flex; gap:10px; flex-wrap:wrap; justify-content:flex-end; }

    #<?php echo esc_attr($uid); ?> .vtm-grid{
      display:grid; grid-template-columns: 1.15fr .85fr; gap:16px;
    }
    @media (max-width: 980px){
      #<?php echo esc_attr($uid); ?> .vtm-referrals__header{ flex-direction:column; }
      #<?php echo esc_attr($uid); ?> .vtm-referrals__actions{ justify-content:flex-start; }
      #<?php echo esc_attr($uid); ?> .vtm-grid{ grid-template-columns: 1fr; }
    }

    #<?php echo esc_attr($uid); ?> .vtm-card{
      background:var(--vtm-bg); border:1px solid var(--vtm-border);
      border-radius:var(--vtm-radius); box-shadow:var(--vtm-shadow);
      padding:16px;
    }
    #<?php echo esc_attr($uid); ?> .vtm-card__head{ margin-bottom:12px; }
    #<?php echo esc_attr($uid); ?> .vtm-card__title{ margin:0 0 6px; font-size:16px; letter-spacing:-.01em; }
    #<?php echo esc_attr($uid); ?> .vtm-card__desc{ margin:0; color:var(--vtm-muted); }

    #<?php echo esc_attr($uid); ?> .vtm-btn{
      appearance:none; border:1px solid transparent; border-radius:12px;
      padding:10px 12px; font-weight:700; cursor:pointer;
      transition: transform .08s ease, background .15s ease, border-color .15s ease, color .15s ease;
      text-decoration:none; display:inline-flex; align-items:center; justify-content:center;
      line-height:1;
    }
    #<?php echo esc_attr($uid); ?> .vtm-btn:active{ transform: translateY(1px); }
    #<?php echo esc_attr($uid); ?> .vtm-btn--primary{ background:var(--vtm-primary); color:#fff; }
    #<?php echo esc_attr($uid); ?> .vtm-btn--primary:hover{ background:var(--vtm-accent); color:#fff; }
    #<?php echo esc_attr($uid); ?> .vtm-btn--ghost{
      background:transparent; color:var(--vtm-text);
      border-color: var(--vtm-border);
    }
    #<?php echo esc_attr($uid); ?> .vtm-btn--ghost:hover{ border-color: rgba(15,23,42,.22); }

    #<?php echo esc_attr($uid); ?> .vtm-steps{ margin:12px 0 0; padding:0; list-style:none; display:grid; gap:10px; }
    #<?php echo esc_attr($uid); ?> .vtm-step{
      display:flex; gap:12px; padding:12px;
      border:1px solid var(--vtm-border); border-radius:14px; background:rgba(255,255,255,.6);
    }
    #<?php echo esc_attr($uid); ?> .vtm-step__num{
      width:32px; height:32px; border-radius:10px;
      background:rgba(112,119,255,.12); color:var(--vtm-primary);
      display:flex; align-items:center; justify-content:center; font-weight:900;
      flex:0 0 auto;
    }
    #<?php echo esc_attr($uid); ?> .vtm-step__title{ font-weight:800; margin:0 0 4px; }
    #<?php echo esc_attr($uid); ?> .vtm-step__text{ color:var(--vtm-muted); }
    #<?php echo esc_attr($uid); ?> .vtm-step__hint{ margin-top:6px; font-size:13px; color:var(--vtm-muted); }

    #<?php echo esc_attr($uid); ?> .vtm-pill{
      display:inline-flex; padding:2px 10px; border-radius:999px;
      background:rgba(246,185,69,.18); border:1px solid rgba(246,185,69,.35);
      font-weight:800; color:var(--vtm-text);
    }

    #<?php echo esc_attr($uid); ?> .vtm-bullets{ margin:10px 0 0; padding-left:18px; }
    #<?php echo esc_attr($uid); ?> .vtm-bullets li{ margin:8px 0; color:var(--vtm-muted); }
    #<?php echo esc_attr($uid); ?> .vtm-bullets strong{ color:var(--vtm-text); }

    #<?php echo esc_attr($uid); ?> .vtm-note{
      margin-top:12px; display:flex; gap:10px; align-items:flex-start;
      border:1px solid rgba(246,185,69,.45);
      background:rgba(246,185,69,.12);
      border-radius:14px; padding:12px;
    }
    #<?php echo esc_attr($uid); ?> .vtm-note__icon{ font-size:16px; line-height:1.2; }
    #<?php echo esc_attr($uid); ?> .vtm-note__text strong{ font-weight:900; }

    #<?php echo esc_attr($uid); ?> .vtm-divider{ height:1px; background:var(--vtm-border); margin:14px 0; }

    #<?php echo esc_attr($uid); ?> .vtm-video{
      border:1px solid var(--vtm-border);
      border-radius:14px;
      background:#fff;
      overflow:hidden;
      min-height: 220px;
    }
    #<?php echo esc_attr($uid); ?> .vtm-video__placeholder{ padding:18px; }
    #<?php echo esc_attr($uid); ?> .vtm-video__placeholderTitle{ font-weight:900; margin-bottom:6px; }
    #<?php echo esc_attr($uid); ?> .vtm-muted{ font-size:13px; color:var(--vtm-muted); }

    #<?php echo esc_attr($uid); ?> .vtm-summary{
      border:1px solid var(--vtm-border);
      border-radius:14px;
      padding:12px;
      background:rgba(255,255,255,.6);
      overflow:auto;
    }
  </style>

  <script>
    (function(){
      const root = document.getElementById('<?php echo esc_js($uid); ?>');
      if(!root) return;

      // Scroll-to-install
      const btn = root.querySelector('[data-action="scroll-install"]');
      const installCard = root.querySelector('[data-install-card]');
      btn && btn.addEventListener('click', () => {
        installCard && installCard.scrollIntoView({ behavior:'smooth', block:'start' });
      });

      // Video mounting
      const videoHost = root.querySelector('[data-video-host]');
      const placeholder = root.querySelector('[data-video-placeholder]');

      function mountPrestoVideo(){
        if(!videoHost) return false;

        let presto = document.querySelector('presto-player');
        if(presto){
          const clone = presto.cloneNode(true);
          videoHost.innerHTML = '';
          videoHost.appendChild(clone);
          return true;
        }

        const maybeOverlay = document.querySelector('presto-muted-overlay') || document.querySelector('div > div > presto-muted-overlay > div');
        if(maybeOverlay){
          const iframe = maybeOverlay.querySelector('iframe');
          const video = maybeOverlay.querySelector('video');
          if(iframe || video){
            const node = (iframe || video).cloneNode(true);
            videoHost.innerHTML = '';
            videoHost.appendChild(node);
            return true;
          }
        }

        return false;
      }

      const mounted = mountPrestoVideo();
      if(mounted && placeholder) placeholder.remove();

      setTimeout(() => {
        const ok = mountPrestoVideo();
        if(ok && placeholder) placeholder.remove();
      }, 1200);

      let retried = false;
      window.addEventListener('scroll', () => {
        if(retried) return;
        retried = true;
        const ok = mountPrestoVideo();
        if(ok && placeholder) placeholder.remove();
      }, { passive:true });
    })();
  </script>
  <?php
  return ob_get_clean();
});

