/**
 * Request To Join modal + AJAX email sender (wp_mail)
 * Trigger rule: ONLY clicks on elements whose visible label is exactly "Request To Join"
 * are allowed to open the modal. Tabs like Discussion/Media/Files/Members/About are ignored.
 *
 * Sends to:
 * - people@virtualteammate.com
 * - sfernandez@virtualteammate.com
 *
 * Subject:
 * - Request To Join Teammate Community
 *
 * From:
 * - alert@seoechelon.com
 */

add_action('wp_footer', function () {
	?>
	<style>
		:root{
			--rtj-primary: #F6B945;          /* Send Request */
			--rtj-primary-hover: #E7A82C;
			--rtj-secondary: #3919BA;        /* Cancel + Close (X) */
			--rtj-secondary-hover: #2E1493;
			--rtj-soft: rgba(246,185,69,0.22);
		}

		#requestToJoinDialog{
			width: min(560px, 92vw);
			border: 0;
			border-radius: 14px;
			padding: 0;
			box-shadow: 0 10px 40px rgba(0,0,0,.25);
			font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
			z-index: 999999;
		}
		#requestToJoinDialog::backdrop{ background: rgba(0,0,0,.55); }

		.rtj-modal-header{
			display:flex;
			align-items:center;
			justify-content:space-between;
			padding:16px 18px;
			border-bottom:1px solid rgba(0,0,0,.08);
		}
		.rtj-modal-header h2{ margin:0; font-size:18px; }

		/* Close button: background #3919BA, X white */
		.rtj-icon-btn{
			border:0;
			background: var(--rtj-secondary);
			color:#fff;
			font-size:18px;
			width:36px;
			height:36px;
			display:inline-flex;
			align-items:center;
			justify-content:center;
			border-radius:10px;
			cursor:pointer;
			line-height:1;
		}
		.rtj-icon-btn:hover{ background: var(--rtj-secondary-hover); }

		.rtj-form{ padding:18px; }
		.rtj-grid{
			display:grid;
			grid-template-columns:1fr 1fr;
			gap:12px;
		}
		@media (max-width:560px){ .rtj-grid{ grid-template-columns:1fr; } }

		.rtj-form label{ display:block; font-size:13px; margin-bottom:6px; }
		.rtj-form input, .rtj-form textarea{
			width:100%;
			padding:10px 12px;
			border:1px solid rgba(0,0,0,.15);
			border-radius:10px;
			font-size:14px;
			outline:none;
			box-sizing:border-box;
		}
		.rtj-form textarea{ min-height:110px; resize:vertical; }

		.rtj-form input:focus, .rtj-form textarea:focus{
			border-color: var(--rtj-primary);
			box-shadow: 0 0 0 3px var(--rtj-soft);
		}

		.rtj-message{ margin-top:12px; }

		.rtj-actions{
			display:flex;
			justify-content:flex-end;
			gap:10px;
			margin-top:14px;
		}

		/* Cancel: #3919BA, font always white */
		.rtj-secondary{
			border:0;
			background: var(--rtj-secondary);
			color:#fff;
			padding:10px 14px;
			border-radius:10px;
			font-weight:700;
			cursor:pointer;
		}
		.rtj-secondary:hover{ background: var(--rtj-secondary-hover); }

		/* Send Request: #F6B945, font always white */
		.rtj-primary{
			border:0;
			padding:10px 14px;
			border-radius:10px;
			font-weight:700;
			cursor:pointer;
			background: var(--rtj-primary);
			color:#fff;
		}
		.rtj-primary:hover{ background: var(--rtj-primary-hover); }
		.rtj-primary:disabled{ opacity:0.75; cursor:not-allowed; }

		.rtj-status{ margin-top:10px; font-size:13px; line-height:1.4; }
		.rtj-status.ok{ color:#0a7a2f; }
		.rtj-status.err{ color:#b00020; }

		/* Honeypot */
		.rtj-hp{ position:absolute; left:-9999px; top:-9999px; }
	</style>

	<dialog id="requestToJoinDialog" aria-labelledby="rtjTitle">
		<div class="rtj-modal-header">
			<h2 id="rtjTitle">Request To Join Teammate Community</h2>
			<button type="button" class="rtj-icon-btn" id="rtjCloseBtn" aria-label="Close">✕</button>
		</div>

		<form id="rtjForm" class="rtj-form" novalidate>
			<div class="rtj-hp">
				<label>Leave this field empty</label>
				<input type="text" name="company_website" tabindex="-1" autocomplete="off">
			</div>

			<div class="rtj-grid">
				<div>
					<label for="rtjFirstName">First Name</label>
					<input id="rtjFirstName" name="firstName" autocomplete="given-name" required />
				</div>

				<div>
					<label for="rtjLastName">Last Name</label>
					<input id="rtjLastName" name="lastName" autocomplete="family-name" required />
				</div>

				<div>
					<label for="rtjEmail">Email</label>
					<input id="rtjEmail" name="email" type="email" autocomplete="email" required />
				</div>

				<div>
					<label for="rtjPhone">Phone Number</label>
					<input id="rtjPhone" name="phone" type="tel" autocomplete="tel" required />
				</div>
			</div>

			<div class="rtj-message">
				<label for="rtjMessage">Short Message</label>
				<textarea id="rtjMessage" name="message" required></textarea>
			</div>

			<div class="rtj-actions">
				<button type="button" class="rtj-secondary" id="rtjCancelBtn">Cancel</button>
				<button type="submit" class="rtj-primary" id="rtjSendBtn">Send Request</button>
			</div>

			<p id="rtjStatus" class="rtj-status" role="status" aria-live="polite"></p>
		</form>
	</dialog>
	<?php
});

add_action('wp_enqueue_scripts', function () {
	wp_register_script('rtj-modal', false, [], null, true);
	wp_enqueue_script('rtj-modal');

	wp_localize_script('rtj-modal', 'RTJ', [
		'ajaxUrl' => admin_url('admin-ajax.php'),
		'nonce'   => wp_create_nonce('rtj_nonce'),
	]);

	$js = <<<JS
(function() {
  const dialog = document.getElementById("requestToJoinDialog");
  const closeBtn = document.getElementById("rtjCloseBtn");
  const cancelBtn = document.getElementById("rtjCancelBtn");
  const form = document.getElementById("rtjForm");
  const statusEl = document.getElementById("rtjStatus");
  const sendBtn = document.getElementById("rtjSendBtn");

  if (!dialog || !form) return;

  // Extra safety: common tab/nav containers to always ignore
  const navSelectors = [
    ".bp-navs",
    ".item-list-tabs",
    "#group-navigation",
    ".bb-single-nav",
    ".bb-tabs",
    ".bb-group-navigation",
    ".groups-nav",
    ".bp-single-group-nav",
    "[role='tablist']"
  ].join(",");

  function setStatus(message, kind) {
    statusEl.className = "rtj-status" + (kind ? " " + kind : "");
    statusEl.textContent = message || "";
  }

  function openModal() {
    setStatus("");
    form.reset();
    if (typeof dialog.showModal === "function") dialog.showModal();
    else alert("Your browser does not support this dialog. Please upgrade your browser.");
  }

  function closeModal() {
    if (dialog.open) dialog.close();
  }

  function getClickable(e) {
    return e.target.closest("a, button, input[type='button'], input[type='submit']");
  }

  function getLabel(el) {
    // For inputs, use value; for buttons/links, use text
    const t = (el.tagName === "INPUT") ? (el.value || "") : (el.textContent || "");
    return t.trim().toLowerCase().replace(/\\s+/g, " ");
  }

  // Capture listener so we can stop default behavior of the join button
  document.addEventListener("click", function(e) {
    const el = getClickable(e);
    if (!el) return;

    // Never open from navigation/tabs area
    if (el.closest(navSelectors)) return;
    if (el.getAttribute("role") === "tab") return;

    // STRICT allowlist: only open when the label is exactly "request to join"
    const label = getLabel(el);
    if (label !== "request to join") return;

    // It's a Request To Join trigger -> open modal and prevent the page's default action
    e.preventDefault();
    e.stopPropagation();
    openModal();
  }, true);

  closeBtn.addEventListener("click", closeModal);
  cancelBtn.addEventListener("click", closeModal);

  dialog.addEventListener("click", (e) => {
    const rect = dialog.getBoundingClientRect();
    const inside =
      rect.top <= e.clientY && e.clientY <= rect.bottom &&
      rect.left <= e.clientX && e.clientX <= rect.right;
    if (!inside) closeModal();
  });

  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    setStatus("");

    const fd = new FormData(form);
    const required = ["firstName", "lastName", "email", "phone", "message"];
    const missing = required.filter((k) => !String(fd.get(k) || "").trim());
    if (missing.length) {
      setStatus("Please complete all fields before sending.", "err");
      return;
    }

    sendBtn.disabled = true;
    const oldText = sendBtn.textContent;
    sendBtn.textContent = "Sending...";

    try {
      fd.append("action", "rtj_request_to_join");
      fd.append("nonce", RTJ.nonce);

      const res = await fetch(RTJ.ajaxUrl, {
        method: "POST",
        credentials: "same-origin",
        body: fd
      });

      const payload = await res.json().catch(() => ({}));
      if (!res.ok || payload.success !== true) {
        throw new Error(payload?.data?.error || "Request failed.");
      }

      setStatus("Request sent successfully.", "ok");
      setTimeout(closeModal, 900);
    } catch (err) {
      setStatus(String(err.message || err), "err");
    } finally {
      sendBtn.disabled = false;
      sendBtn.textContent = oldText;
    }
  });
})();
JS;

	wp_add_inline_script('rtj-modal', $js, 'after');
});

add_action('wp_ajax_rtj_request_to_join', 'rtj_handle_request_to_join');
add_action('wp_ajax_nopriv_rtj_request_to_join', 'rtj_handle_request_to_join');

function rtj_handle_request_to_join() {
	$nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
	if (!wp_verify_nonce($nonce, 'rtj_nonce')) {
		wp_send_json_error(['error' => 'Security check failed. Please refresh and try again.'], 403);
	}

	$hp = isset($_POST['company_website']) ? trim((string) wp_unslash($_POST['company_website'])) : '';
	if ($hp !== '') {
		wp_send_json_error(['error' => 'Spam detected.'], 400);
	}

	$first = isset($_POST['firstName']) ? sanitize_text_field(wp_unslash($_POST['firstName'])) : '';
	$last  = isset($_POST['lastName'])  ? sanitize_text_field(wp_unslash($_POST['lastName']))  : '';
	$email = isset($_POST['email'])     ? sanitize_email(wp_unslash($_POST['email']))          : '';
	$phone = isset($_POST['phone'])     ? sanitize_text_field(wp_unslash($_POST['phone']))     : '';
	$msg   = isset($_POST['message'])   ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';

	if ($first === '' || $last === '' || $email === '' || $phone === '' || $msg === '') {
		wp_send_json_error(['error' => 'All fields are required.'], 400);
	}
	if (!is_email($email)) {
		wp_send_json_error(['error' => 'Please enter a valid email address.'], 400);
	}

	$to      = ['people@virtualteammate.com', 'sfernandez@virtualteammate.com'];
	$subject = 'Request To Join Teammate Community';

	$body = "Request To Join - Teammate Community\n\n"
	      . "First Name: {$first}\n"
	      . "Last Name: {$last}\n"
	      . "Email: {$email}\n"
	      . "Phone Number: {$phone}\n\n"
	      . "Short Message:\n{$msg}\n";

	$from_email = 'alert@seoechelon.com';
	$from_name  = 'Teammate Community';

	$headers = [
		'Content-Type: text/plain; charset=UTF-8',
		"From: {$from_name} <{$from_email}>",
		"Reply-To: {$email}",
	];

	$sent = wp_mail($to, $subject, $body, $headers);

	if (!$sent) {
		wp_send_json_error(['error' => 'Email could not be sent. Please try again later.'], 500);
	}

	wp_send_json_success(['ok' => true]);
}
