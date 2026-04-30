<?php
/**
 * Template Pack Management Page
 *
 * Allows admins to install, activate, and delete LEM template packs.
 * Template packs are ZIP files that override single-event.php and/or
 * event-ticket-block.php with custom designs.
 */
if (!defined('ABSPATH')) exit;
if (!current_user_can('manage_options')) {
    wp_die(__('You do not have sufficient permissions to access this page.'));
}

$installed    = LEM_Template_Manager::get_installed_templates();
$active_slug  = LEM_Template_Manager::get_active_slug();
$nonce        = wp_create_nonce('lem_templates_nonce');
$dl_nonce     = wp_create_nonce('lem_download_pack');

// Discover bundled packs (those shipped inside the plugin under template-packs/)
$bundled_packs = [];
$bundled_base  = LEM_PLUGIN_DIR . 'template-packs/';
if (is_dir($bundled_base)) {
    foreach ((array) glob($bundled_base . '*', GLOB_ONLYDIR) as $dir) {
        $jf   = trailingslashit($dir) . 'template.json';
        $meta = file_exists($jf) ? json_decode(file_get_contents($jf), true) : null;
        if ($meta && !empty($meta['slug'])) {
            $bundled_packs[] = $meta;
        }
    }
}
?>
<div class="wrap">
    <h1>Templates</h1>
    <?php require __DIR__ . '/admin-subnav.php'; ?>
    <p style="color:#666;max-width:640px;">Install a template pack to customise the event watch page and paywall. Each pack overrides only the files it ships — anything not included falls back to the built-in default.</p>

    <?php if (empty($installed)): ?>
        <p><em>No templates installed yet.</em></p>
    <?php else: ?>
    <div class="lem-template-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1.25rem;margin:1.5rem 0;">
        <?php foreach ($installed as $tpl):
            $slug      = esc_attr($tpl['slug']);
            $is_active = ($tpl['slug'] === $active_slug);
            $is_builtin = !empty($tpl['built_in']);
        ?>
        <div class="lem-template-card" id="lem-tpl-card-<?php echo $slug; ?>"
             style="background:#fff;border:1px solid <?php echo $is_active ? '#7f5af0' : '#e0e0e8'; ?>;border-radius:8px;padding:1.25rem;box-shadow:<?php echo $is_active ? '0 0 0 2px rgba(127,90,240,.25)' : 'none'; ?>;">

            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.5rem;">
                <strong style="font-size:1rem;"><?php echo esc_html($tpl['name']); ?></strong>
                <?php if ($is_active): ?>
                    <span style="background:#7f5af0;color:#fff;font-size:.72rem;font-weight:600;padding:.2rem .55rem;border-radius:20px;letter-spacing:.03em;">Active</span>
                <?php endif; ?>
            </div>

            <?php if (!empty($tpl['description'])): ?>
                <p style="color:#555;font-size:.875rem;margin:.35rem 0 .6rem;"><?php echo esc_html($tpl['description']); ?></p>
            <?php endif; ?>

            <div style="font-size:.8rem;color:#888;margin-bottom:1rem;">
                <?php if (!empty($tpl['author'])): ?>
                    By <?php
                        if (!empty($tpl['author_url'])) {
                            echo '<a href="' . esc_url($tpl['author_url']) . '" target="_blank" rel="noopener">' . esc_html($tpl['author']) . '</a>';
                        } else {
                            echo esc_html($tpl['author']);
                        }
                    ?>
                    &nbsp;&middot;&nbsp;
                <?php endif; ?>
                v<?php echo esc_html($tpl['version']); ?>
            </div>

            <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                <?php if (!$is_active): ?>
                    <button class="button button-primary lem-tpl-activate"
                            data-slug="<?php echo $slug; ?>">Activate</button>
                <?php else: ?>
                    <button class="button" disabled>Active</button>
                <?php endif; ?>

                <?php if (!$is_builtin): ?>
                    <button class="button lem-tpl-delete"
                            data-slug="<?php echo $slug; ?>"
                            data-name="<?php echo esc_attr($tpl['name']); ?>"
                            style="color:#c0392b;border-color:#c0392b;">Delete</button>
                <?php endif; ?>
            </div>

            <div class="lem-tpl-msg" id="lem-tpl-msg-<?php echo $slug; ?>"
                 style="margin-top:.6rem;font-size:.85rem;display:none;"></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <hr style="margin:2rem 0;">

    <h2 style="font-size:1.1rem;">Download Template Packs</h2>
    <p style="color:#555;max-width:560px;font-size:.9rem;">Download a bundled pack as a ZIP, then install it using the uploader below. The <strong>Starter</strong> pack is a fully annotated starting point for building your own templates.</p>

    <?php if (empty($bundled_packs)): ?>
        <p><em>No bundled packs found.</em></p>
    <?php else: ?>
    <div style="display:flex;flex-wrap:wrap;gap:1rem;margin:1rem 0 0;">
        <?php foreach ($bundled_packs as $pack):
            $dl_url = add_query_arg([
                'action' => 'lem_download_pack',
                'pack'   => sanitize_key($pack['slug']),
                'nonce'  => $dl_nonce,
            ], admin_url('admin-post.php'));
        ?>
        <div style="background:#fff;border:1px solid #e0e0e8;border-radius:8px;padding:1.1rem 1.25rem;min-width:220px;max-width:300px;">
            <div style="font-weight:600;font-size:.95rem;margin-bottom:.25rem;"><?php echo esc_html($pack['name']); ?></div>
            <?php if (!empty($pack['description'])): ?>
                <p style="font-size:.82rem;color:#666;margin:.2rem 0 .85rem;line-height:1.45;"><?php echo esc_html($pack['description']); ?></p>
            <?php endif; ?>
            <a href="<?php echo esc_url($dl_url); ?>"
               class="button button-secondary"
               style="text-decoration:none;display:inline-flex;align-items:center;gap:.4rem;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Download <?php echo esc_html($pack['slug']); ?>.zip
            </a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <hr style="margin:2rem 0;">

    <h2 style="font-size:1.1rem;">Install a Template Pack</h2>
    <p style="color:#555;max-width:560px;font-size:.9rem;">
        Upload a <code>.zip</code> file purchased from a template vendor.
        The ZIP must contain a single top-level folder named after the template slug, with a <code>template.json</code> manifest inside.
    </p>

    <form id="lem-template-upload-form" enctype="multipart/form-data" style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;margin-top:.75rem;">
        <input type="file" name="template_zip" id="lem-template-zip-input" accept=".zip"
               style="border:1px solid #ccd;padding:.4rem .6rem;border-radius:4px;background:#fafafa;" required>
        <button type="submit" class="button button-primary" id="lem-template-upload-btn">
            Upload &amp; Install
        </button>
        <span class="spinner" id="lem-template-spinner" style="float:none;margin:0;"></span>
    </form>
    <div id="lem-template-upload-result" style="margin-top:.75rem;font-size:.9rem;"></div>

    <hr style="margin:2rem 0;">

    <h2 style="font-size:1.1rem;">Template Pack Format</h2>
    <p style="color:#555;font-size:.9rem;">Template packs are ZIP files with the following structure. Only the files you include will override the defaults — you don't need to ship both.</p>
    <pre style="background:#f4f4f8;padding:1rem;border-radius:6px;font-size:.82rem;max-width:480px;line-height:1.6;">{slug}/
  template.json          <span style="color:#888;">← required metadata</span>
  single-event.php       <span style="color:#888;">← optional: event watch page</span>
  event-ticket-block.php <span style="color:#888;">← optional: paywall / ticket block</span>
  assets/
    style.css            <span style="color:#888;">← optional: loaded after base CSS</span>
    script.js            <span style="color:#888;">← optional: loaded in footer</span></pre>

    <h3 style="font-size:.95rem;margin-top:1.25rem;">template.json schema</h3>
    <pre style="background:#f4f4f8;padding:1rem;border-radius:6px;font-size:.82rem;max-width:480px;line-height:1.6;">{
  "name":        "Premium Dark",
  "slug":        "premium-dark",
  "version":     "1.0.0",
  "description": "A sleek dark event watch page.",
  "author":      "Your Name",
  "author_url":  "https://example.com",
  "type":        ["event_page", "paywall"]
}</pre>
</div><!-- /.wrap -->

<script>
(function () {
    var nonce   = <?php echo wp_json_encode($nonce); ?>;
    var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;

    // ── Activate ────────────────────────────────────────────────────────────
    document.querySelectorAll('.lem-tpl-activate').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var slug    = btn.dataset.slug;
            var msgEl   = document.getElementById('lem-tpl-msg-' + slug);
            btn.disabled = true;

            fetch(ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'lem_activate_template', nonce: nonce, slug: slug })
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    // Reload to refresh all Active badges cleanly
                    window.location.reload();
                } else {
                    msgEl.textContent = data.data || 'Activation failed.';
                    msgEl.style.color = '#c0392b';
                    msgEl.style.display = 'block';
                    btn.disabled = false;
                }
            })
            .catch(function () {
                msgEl.textContent = 'Request failed. Please try again.';
                msgEl.style.color = '#c0392b';
                msgEl.style.display = 'block';
                btn.disabled = false;
            });
        });
    });

    // ── Delete ──────────────────────────────────────────────────────────────
    document.querySelectorAll('.lem-tpl-delete').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var slug = btn.dataset.slug;
            var name = btn.dataset.name;
            if (!confirm('Delete the "' + name + '" template? This cannot be undone.')) return;

            var msgEl = document.getElementById('lem-tpl-msg-' + slug);
            btn.disabled = true;

            fetch(ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'lem_delete_template', nonce: nonce, slug: slug })
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    var card = document.getElementById('lem-tpl-card-' + slug);
                    if (card) {
                        card.style.transition = 'opacity .25s';
                        card.style.opacity    = '0';
                        setTimeout(function () { card.remove(); }, 270);
                    }
                } else {
                    msgEl.textContent = data.data || 'Delete failed.';
                    msgEl.style.color = '#c0392b';
                    msgEl.style.display = 'block';
                    btn.disabled = false;
                }
            })
            .catch(function () {
                msgEl.textContent = 'Request failed. Please try again.';
                msgEl.style.color = '#c0392b';
                msgEl.style.display = 'block';
                btn.disabled = false;
            });
        });
    });

    // ── Upload ──────────────────────────────────────────────────────────────
    var uploadForm    = document.getElementById('lem-template-upload-form');
    var uploadBtn     = document.getElementById('lem-template-upload-btn');
    var uploadSpinner = document.getElementById('lem-template-spinner');
    var uploadResult  = document.getElementById('lem-template-upload-result');

    uploadForm.addEventListener('submit', function (e) {
        e.preventDefault();

        var fileInput = document.getElementById('lem-template-zip-input');
        if (!fileInput.files.length) {
            uploadResult.textContent = 'Please choose a .zip file first.';
            uploadResult.style.color = '#c0392b';
            return;
        }

        var formData = new FormData();
        formData.append('action',       'lem_upload_template');
        formData.append('nonce',        nonce);
        formData.append('template_zip', fileInput.files[0]);

        uploadBtn.disabled          = true;
        uploadSpinner.style.display = 'inline-block';
        uploadResult.textContent    = '';

        fetch(ajaxUrl, { method: 'POST', body: formData })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            uploadSpinner.style.display = 'none';
            uploadBtn.disabled          = false;
            if (data.success) {
                uploadResult.textContent = data.data.message || 'Installed successfully.';
                uploadResult.style.color = '#27ae60';
                fileInput.value = '';
                // Reload to show the new card in the grid
                setTimeout(function () { window.location.reload(); }, 900);
            } else {
                uploadResult.textContent = data.data || 'Installation failed.';
                uploadResult.style.color = '#c0392b';
            }
        })
        .catch(function () {
            uploadSpinner.style.display = 'none';
            uploadBtn.disabled          = false;
            uploadResult.textContent    = 'Request failed. Please try again.';
            uploadResult.style.color    = '#c0392b';
        });
    });
}());
</script>
