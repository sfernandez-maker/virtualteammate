/**
 * Refer a Friend (Elementor-friendly) shortcode + AJAX email sender
 * Usage: add this to functions.php (child theme) or Code Snippets plugin
 * Then place shortcode: [refer_a_friend]
 */

add_action('wp_enqueue_scripts', function () {
  // Ensure jQuery exists (WP includes it; this just declares dependency if needed)
  wp_enqueue_script('jquery');
});

add_shortcode('refer_a_friend', function () {
  if (!is_user_logged_in()) {
    return '<div style="padding:12px 14px;border-radius:12px;background:#f7f7f8;">Please log in to refer a friend.</div>';
  }

  $user = wp_get_current_user();
  $first = get_user_meta($user->ID, 'first_name', true);
  $last  = get_user_meta($user->ID, 'last_name', true);
  $email = $user->user_email;

  $nonce = wp_create_nonce('raf_nonce');

  ob_start(); ?>
  <div class="raf-wrap">
    <div class="raf-card">
      <div class="raf-head">
        <h3 class="raf-title">Refer a Friend</h3>
        <p class="raf-sub">Invite someone you trust. We’ll receive your referral details and follow up.</p>
      </div>

      <form class="raf-form" id="rafForm">
        <!-- Referrer fields (scraped/prefilled from account/user meta) -->
        <input type="hidden" name="ref_first_name" id="raf_ref_first_name" value="<?php echo esc_attr($first); ?>">
        <input type="hidden" name="ref_last_name"  id="raf_ref_last_name"  value="<?php echo esc_attr($last); ?>">
        <input type="hidden" name="ref_user_email" id="raf_ref_user_email"  value="<?php echo esc_attr($email); ?>">

        <div class="raf-grid">
          <div class="raf-field">
            <label for="raf_friend_name">Friend’s name</label>
            <input type="text" id="raf_friend_name" name="friend_name" placeholder="e.g., Alex Rivera" required>
          </div>

          <div class="raf-field">
            <label for="raf_friend_email">Friend’s email</label>
            <input type="email" id="raf_friend_email" name="friend_email" placeholder="e.g., alex@email.com" required>
          </div>
        </div>

        <div class="raf-field">
          <label for="raf_note">Optional note</label>
          <textarea id="raf_note" name="note" rows="3" placeholder="Anything we should know? (optional)"></textarea>
        </div>

        <div class="raf-actions">
          <button type="submit" class="raf-btn" id="rafSubmitBtn">Send Referral</button>
          <div class="raf-status" id="rafStatus" aria-live="polite"></div>
        </div>

        <input type="hidden" name="action" value="raf_send_referral">
        <input type="hidden" name="nonce" value="<?php echo esc_attr($nonce); ?>">
      </form>
    </div>
  </div>

  <style>
    .raf-wrap{display:flex;justify-content:center;align-items:flex-start;width:100%}
    .raf-card{
      width:min(760px, 100%);
      border-radius:18px;
      background:rgba(255,255,255,0.92);
      box-shadow:0 12px 40px rgba(0,0,0,0.08);
      padding:22px 22px 18px;
    }
    .raf-head{margin-bottom:14px}
    .raf-title{margin:0;font-size:22px;line-height:1.15;font-weight:700;letter-spacing:-0.02em}
    .raf-sub{margin:8px 0 0;font-size:14px;opacity:.78}

    .raf-form{margin-top:14px}
    .raf-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    @media (max-width: 640px){.raf-grid{grid-template-columns:1fr}}

    .raf-field label{display:block;font-size:13px;font-weight:600;margin:0 0 8px}
    .raf-field input,.raf-field textarea{
      width:100%;
      border:0;
      outline:none;
      border-radius:14px;
      background:rgba(20,20,30,0.05);
      padding:12px 12px;
      font-size:14px;
      transition:box-shadow .15s ease, background .15s ease;
    }
    .raf-field input:focus,.raf-field textarea:focus{
      background:rgba(20,20,30,0.07);
      box-shadow:0 0 0 3px rgba(112,119,255,0.25);
    }
    .raf-actions{display:flex;align-items:center;gap:12px;margin-top:14px;flex-wrap:wrap}
    .raf-btn{
      appearance:none;border:0;cursor:pointer;
      padding:12px 16px;border-radius:14px;
      background:#7077FF;color:#fff;
      font-weight:700;font-size:14px;
      transition:background .15s ease, transform .05s ease, opacity .15s ease;
    }
    .raf-btn:hover{background:#F6B945}
    .raf-btn:active{transform:translateY(1px)}
    .raf-btn[disabled]{opacity:.65;cursor:not-allowed}
    .raf-status{font-size:13px;opacity:.85}
    .raf-status.ok{opacity:1}
    .raf-status.err{opacity:1}
  </style>

  <script>
    (function($){
      const $form = $('#rafForm');
      const $btn  = $('#rafSubmitBtn');
      const $st   = $('#rafStatus');

      function setStatus(msg, kind){
        $st.removeClass('ok err').addClass(kind || '');
        $st.text(msg || '');
      }

      $form.on('submit', function(e){
        e.preventDefault();
        setStatus('Sending…');
        $btn.prop('disabled', true);

        $.ajax({
          url: '<?php echo esc_js(admin_url('admin-ajax.php')); ?>',
          method: 'POST',
          data: $form.serialize(),
          success: function(res){
            if (res && res.success){
              setStatus('Referral sent successfully.', 'ok');
              $form[0].reset();
              // Restore hidden referrer fields after reset
              $('#raf_ref_first_name').val('<?php echo esc_js($first); ?>');
              $('#raf_ref_last_name').val('<?php echo esc_js($last); ?>');
              $('#raf_ref_user_email').val('<?php echo esc_js($email); ?>');
            } else {
              setStatus((res && res.data) ? res.data : 'Something went wrong. Please try again.', 'err');
            }
          },
          error: function(){
            setStatus('Network error. Please try again.', 'err');
          },
          complete: function(){
            $btn.prop('disabled', false);
          }
        });
      });
    })(jQuery);
  </script>
  <?php
  return ob_get_clean();
});

add_action('wp_ajax_raf_send_referral', 'raf_send_referral');
function raf_send_referral() {
  if (!is_user_logged_in()) {
    wp_send_json_error('You must be logged in.');
  }

  $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
  if (!wp_verify_nonce($nonce, 'raf_nonce')) {
    wp_send_json_error('Security check failed. Please refresh and try again.');
  }

  $ref_first = isset($_POST['ref_first_name']) ? sanitize_text_field($_POST['ref_first_name']) : '';
  $ref_last  = isset($_POST['ref_last_name'])  ? sanitize_text_field($_POST['ref_last_name'])  : '';
  $ref_email = isset($_POST['ref_user_email']) ? sanitize_email($_POST['ref_user_email'])      : '';

  // Server-side source of truth (prevents spoofing)
  $user = wp_get_current_user();
  $ref_email = $user->user_email;
  $ref_first = get_user_meta($user->ID, 'first_name', true);
  $ref_last  = get_user_meta($user->ID, 'last_name', true);

  $friend_name  = isset($_POST['friend_name'])  ? sanitize_text_field($_POST['friend_name']) : '';
  $friend_email = isset($_POST['friend_email']) ? sanitize_email($_POST['friend_email'])     : '';
  $note         = isset($_POST['note'])         ? sanitize_textarea_field($_POST['note'])    : '';

  if (empty($friend_name) || empty($friend_email) || !is_email($friend_email)) {
    wp_send_json_error('Please enter a valid friend name and email.');
  }

  $to = 'seoechelon@gmail.com';
  $subject = 'New Referral Submission';

  $body_lines = [
    "A new referral has been submitted.",
    "",
    "Referrer:",
    " - Name: {$ref_first} {$ref_last}",
    " - Email: {$ref_email}",
    "",
    "Friend:",
    " - Name: {$friend_name}",
    " - Email: {$friend_email}",
  ];

  if (!empty($note)) {
    $body_lines[] = "";
    $body_lines[] = "Note:";
    $body_lines[] = $note;
  }

  $body = implode("\n", $body_lines);

  $headers = [];
  if (is_email($ref_email)) {
    $headers[] = 'Reply-To: ' . $ref_email;
  }

  $sent = wp_mail($to, $subject, $body, $headers);

  if (!$sent) {
    wp_send_json_error('Email failed to send. Please contact support or try again later.');
  }

  wp_send_json_success(true);
}
