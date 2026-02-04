add_shortcode('share_group_popup', function () {
    ob_start();
    $share_link = 'https://clientvtm.wpenginepowered.com/group-teammate-community-about/';
    ?>

    <style>
        /* ===============================
           SHARE BUTTON (NO HOVER, BLACK)
        =============================== */
        .vt-share-trigger,
        .vt-share-trigger * {
            color: #000 !important;
            fill: #000 !important;
        }

        .vt-share-trigger {
            background: transparent !important;
            border: none !important;
            padding: 0 !important;
            cursor: pointer;
        }

        .vt-share-trigger:hover,
        .vt-share-trigger:focus,
        .vt-share-trigger:active {
            background: transparent !important;
            color: #000 !important;
            fill: #000 !important;
            box-shadow: none !important;
            outline: none !important;
        }

        /* ===============================
           OVERLAY
        =============================== */
        .vt-share-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 9998;
        }

        /* ===============================
           MODAL
        =============================== */
        .vt-share-modal {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: #fff;
            width: 550px;
            max-width: 95%;
            padding: 60px;
            border-radius: 8px;
            z-index: 9999;
            text-align: center;
            box-shadow: 0 24px 70px rgba(0,0,0,0.25);
        }

        /* ===============================
           CLOSE BUTTON (BLACK, NO HOVER)
        =============================== */
        .vt-share-close {
            position: absolute;
            top: 14px;
            right: 16px;
            background: none;
            border: none;
            font-size: 50px;
            cursor: pointer;
            color: #000 !important;
            fill: #000 !important;
            outline: none !important;
            box-shadow: none !important;
        }

        .vt-share-close:hover,
        .vt-share-close:focus,
        .vt-share-close:active {
            color: #000 !important;
            fill: #000 !important;
            background: none !important;
            outline: none !important;
            box-shadow: none !important;
        }

        /* ===============================
           MODAL TITLE
        =============================== */
        .vt-share-title {
            font-size: 30px;
            font-weight: 600;
            margin-bottom: 50px;
        }

        /* ===============================
           SHARE ICONS
        =============================== */
        .vt-share-icons {
            display: flex;
            justify-content: center;
            gap: 32px;
        }

        .vt-share-icons a,
        .vt-share-icons button {
            color: #000 !important;
            fill: #000 !important;
            text-decoration: none;
            display: inline-flex;
            background: none;
            border: none;
            cursor: pointer;
        }

        .vt-share-icons svg {
            width: 24px;
            height: 24px;
            fill: currentColor;
        }
    </style>

    <!-- SHARE BUTTON -->
    <button class="vt-share-trigger" onclick="vtOpenShare()" aria-label="Share Group">
        <svg viewBox="0 0 24 24" fill="currentColor" width="28" height="28">
            <path fill-rule="evenodd" d="M14.5,14 C14.628,14 14.752,14.049 14.845,14.138 C14.944,14.232 15,14.363 15,14.5 L15,17.293 L20.293,12 L15,6.707 L15,9.5 C15,9.633 14.947,9.761 14.853,9.854 C14.759,9.947 14.632,10 14.5,10 C14.494,9.998 14.491,10 14.486,10 C13.667,10 7.407,10.222 4.606,16.837 C7.276,14.751 10.496,13.795 14.188,13.989 C14.324,13.996 14.426,14.001 14.476,14.001 C14.484,14 14.492,14 14.5,14 M3.5,19 C3.414,19 3.328,18.979 3.25,18.933 C3.052,18.819 2.957,18.585 3.019,18.365 C5.304,10.189 11.981,9.145 14,9.017 L14,5.5 C14,5.298 14.122,5.115 14.309,5.038 C14.496,4.963 14.71,5.004 14.854,5.146 L21.354,11.646 C21.549,11.842 21.549,12.158 21.354,12.354 L14.854,18.854 C14.71,18.997 14.495,19.038 14.309,18.962 C14.122,18.885 14,18.702 14,18.5 L14,14.981 C9.957,14.791 6.545,16.102 3.857,18.85 C3.761,18.948 3.631,19 3.5,19"></path>
        </svg>
    </button>

    <!-- OVERLAY -->
    <div class="vt-share-overlay" id="vtShareOverlay" onclick="vtCloseShare()"></div>

    <!-- MODAL -->
    <div class="vt-share-modal" id="vtShareModal">
        <button class="vt-share-close" onclick="vtCloseShare()">×</button>
        <div class="vt-share-title">Share Group</div>

        <div class="vt-share-icons">
            <!-- Facebook -->
            <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode($share_link); ?>" target="_blank">
                <svg viewBox="0 0 24 24"><path d="M22 12a10 10 0 1 0-11.6 9.9v-7h-2v-2.9h2V9.8c0-2 1.2-3.1 3-3.1.9 0 1.8.1 1.8.1v2h-1c-1 0-1.3.6-1.3 1.2v1.9h2.3l-.4 2.9h-1.9v7A10 10 0 0 0 22 12z"/></svg>
            </a>

            <!-- LinkedIn -->
            <a href="https://www.linkedin.com/sharing/share-offsite/?url=<?php echo urlencode($share_link); ?>" target="_blank">
                <svg viewBox="0 0 24 24"><path d="M4.98 3.5a2.48 2.48 0 1 0 0 5 2.48 2.48 0 0 0 0-5zM3 8.98h4v12H3zM9 8.98h3.8v1.64h.05c.53-1 1.83-2.05 3.77-2.05 4.03 0 4.78 2.65 4.78 6.1v7.31h-4v-6.48c0-1.55-.03-3.54-2.16-3.54-2.17 0-2.5 1.69-2.5 3.43v6.59H9z"/></svg>
            </a>

            <!-- Twitter -->
            <a href="https://twitter.com/intent/tweet?url=<?php echo urlencode($share_link); ?>" target="_blank">
                <svg viewBox="0 0 24 24"><path d="M18.3 3H21l-6.2 7.1L22 21h-6.2l-4.9-6-5.2 6H3l6.6-7.6L2 3h6.3l4.4 5.4z"/></svg>
            </a>

            <!-- Copy Link -->
            <button onclick="vtCopyLink()" title="Copy Link">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 18H8V7h11v16z"/>
                </svg>
            </button>
        </div>
    </div>

    <script>
        function vtOpenShare() {
            document.getElementById('vtShareModal').style.display = 'block';
            document.getElementById('vtShareOverlay').style.display = 'block';
        }

        function vtCloseShare() {
            document.getElementById('vtShareModal').style.display = 'none';
            document.getElementById('vtShareOverlay').style.display = 'none';
        }

        function vtCopyLink() {
            navigator.clipboard.writeText("<?php echo $share_link; ?>")
                .then(() => { alert('Link copied to clipboard!'); })
                .catch(() => { alert('Failed to copy link.'); });
        }
    </script>

    <?php
    return ob_get_clean();
});