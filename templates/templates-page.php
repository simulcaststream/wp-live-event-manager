<?php
/**
 * Template Pack Management Page
 *
 * Allows admins to install, activate, and delete LEM template packs.
 */
if (!defined('ABSPATH')) {
    exit;
}
if (!current_user_can('manage_options')) {
    wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'live-event-manager'));
}

$installed     = LEM_Template_Manager::get_installed_templates();
$active_slug   = LEM_Template_Manager::get_active_slug();
$bundled_packs = array_values(LEM_Template_Manager::get_bundled_template_packs());
$nonce         = wp_create_nonce('lem_templates_nonce');
$dl_nonce      = wp_create_nonce('lem_download_pack');
$schema_file   = LEM_Template_Manager::get_schema_path();
$schema_link   = file_exists($schema_file)
    ? LEM_PLUGIN_URL . 'docs/template-pack.schema.json'
    : '';

LEM_Template_Manager::maybe_render_incompatibility_notice();
?>
<div class="wrap lem-templates-wrap">
    <h1><?php esc_html_e('Templates', 'live-event-manager'); ?></h1>
    <?php require __DIR__ . '/admin-subnav.php'; ?>
    <p class="lem-templates-intro">
        <?php esc_html_e('Install a template pack to customise the event watch page and paywall. Each pack overrides only the files it ships — anything not included falls back to the built-in default.', 'live-event-manager'); ?>
    </p>

    <?php if (empty($installed)) : ?>
        <p><em><?php esc_html_e('No templates installed yet.', 'live-event-manager'); ?></em></p>
    <?php else : ?>
    <div class="lem-template-grid">
        <?php
        foreach ($installed as $tpl) :
            $slug       = esc_attr($tpl['slug']);
            $is_active  = ($tpl['slug'] === $active_slug);
            $is_builtin = !empty($tpl['built_in']);
            $preview    = !empty($tpl['preview_url']) ? $tpl['preview_url'] : LEM_Template_Manager::get_preview_url($tpl);
            $card_class = 'lem-template-card' . ($is_active ? ' is-active' : '');
            ?>
        <div class="<?php echo esc_attr($card_class); ?>" id="lem-tpl-card-<?php echo $slug; ?>">

            <?php if ($preview !== '') : ?>
                <img class="lem-template-card__preview" src="<?php echo esc_url($preview); ?>" alt="" loading="lazy" />
            <?php endif; ?>

            <div class="lem-template-card__header">
                <strong class="lem-template-card__title"><?php echo esc_html($tpl['name']); ?></strong>
                <?php if ($is_active) : ?>
                    <span class="lem-template-card__badge"><?php esc_html_e('Active', 'live-event-manager'); ?></span>
                <?php endif; ?>
            </div>

            <?php if (!empty($tpl['description'])) : ?>
                <p class="lem-template-card__desc"><?php echo esc_html($tpl['description']); ?></p>
            <?php endif; ?>

            <div class="lem-template-card__meta">
                <?php if (!empty($tpl['author'])) : ?>
                    <?php esc_html_e('By', 'live-event-manager'); ?>
                    <?php
                    if (!empty($tpl['author_url'])) {
                        printf(
                            '<a href="%1$s" target="_blank" rel="noopener">%2$s</a>',
                            esc_url($tpl['author_url']),
                            esc_html($tpl['author'])
                        );
                    } else {
                        echo esc_html($tpl['author']);
                    }
                    ?>
                    &nbsp;&middot;&nbsp;
                <?php endif; ?>
                <?php
                printf(
                    /* translators: %s: template pack version */
                    esc_html__('v%s', 'live-event-manager'),
                    esc_html($tpl['version'] ?? '1.0.0')
                );
                ?>
                <?php if (!empty($tpl['license'])) : ?>
                    &nbsp;&middot;&nbsp;<?php echo esc_html($tpl['license']); ?>
                <?php endif; ?>
            </div>

            <?php if (!empty($tpl['docs_url']) || !empty($tpl['support_url'])) : ?>
                <div class="lem-template-card__links">
                    <?php if (!empty($tpl['docs_url'])) : ?>
                        <a href="<?php echo esc_url($tpl['docs_url']); ?>" target="_blank" rel="noopener"><?php esc_html_e('Documentation', 'live-event-manager'); ?></a>
                    <?php endif; ?>
                    <?php if (!empty($tpl['support_url'])) : ?>
                        <a href="<?php echo esc_url($tpl['support_url']); ?>" target="_blank" rel="noopener"><?php esc_html_e('Support', 'live-event-manager'); ?></a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="lem-template-card__actions">
                <?php if (!$is_active) : ?>
                    <button type="button" class="button button-primary lem-tpl-activate" data-slug="<?php echo $slug; ?>">
                        <?php esc_html_e('Activate', 'live-event-manager'); ?>
                    </button>
                <?php else : ?>
                    <button type="button" class="button" disabled><?php esc_html_e('Active', 'live-event-manager'); ?></button>
                <?php endif; ?>

                <?php if (!$is_builtin) : ?>
                    <button type="button" class="button lem-tpl-delete"
                            data-slug="<?php echo $slug; ?>"
                            data-name="<?php echo esc_attr($tpl['name']); ?>">
                        <?php esc_html_e('Delete', 'live-event-manager'); ?>
                    </button>
                <?php endif; ?>
            </div>

            <div class="lem-tpl-msg" id="lem-tpl-msg-<?php echo $slug; ?>" role="status"></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <hr class="lem-templates-section" />

    <section class="lem-templates-section">
        <h2><?php esc_html_e('Download Template Packs', 'live-event-manager'); ?></h2>
        <p>
            <?php
            echo wp_kses(
                __('Download a bundled pack as a ZIP, then install it using the uploader below. The <strong>Starter</strong> pack is a fully annotated starting point for building your own templates.', 'live-event-manager'),
                array('strong' => array())
            );
            ?>
        </p>

        <?php if (empty($bundled_packs)) : ?>
            <p><em><?php esc_html_e('No bundled packs found.', 'live-event-manager'); ?></em></p>
        <?php else : ?>
        <div class="lem-bundled-grid">
            <?php
            foreach ($bundled_packs as $pack) :
                $dl_url = add_query_arg(
                    array(
                        'action' => 'lem_download_pack',
                        'pack'   => sanitize_key($pack['slug']),
                        'nonce'  => $dl_nonce,
                    ),
                    admin_url('admin-post.php')
                );
                ?>
            <div class="lem-bundled-card">
                <div class="lem-bundled-card__title"><?php echo esc_html($pack['name']); ?></div>
                <?php if (!empty($pack['description'])) : ?>
                    <p class="lem-bundled-card__desc"><?php echo esc_html($pack['description']); ?></p>
                <?php endif; ?>
                <a href="<?php echo esc_url($dl_url); ?>" class="button button-secondary">
                    <?php
                    printf(
                        /* translators: %s: template slug */
                        esc_html__('Download %s.zip', 'live-event-manager'),
                        esc_html($pack['slug'])
                    );
                    ?>
                </a>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <hr class="lem-templates-section" />

    <section class="lem-templates-section">
        <h2><?php esc_html_e('Install a Template Pack', 'live-event-manager'); ?></h2>
        <p>
            <?php
            echo wp_kses(
                __('Upload a <code>.zip</code> file from a template vendor. The ZIP must contain a single top-level folder named after the template slug, with a <code>template.json</code> manifest inside.', 'live-event-manager'),
                array('code' => array())
            );
            ?>
        </p>

        <form id="lem-template-upload-form" class="lem-template-upload-form" enctype="multipart/form-data">
            <input type="file" name="template_zip" id="lem-template-zip-input" accept=".zip" required />
            <button type="submit" class="button button-primary" id="lem-template-upload-btn">
                <?php esc_html_e('Upload & Install', 'live-event-manager'); ?>
            </button>
            <span class="spinner" id="lem-template-spinner"></span>
        </form>
        <div id="lem-template-upload-result" class="lem-template-upload-result" role="status"></div>
    </section>

    <hr class="lem-templates-section" />

    <section class="lem-templates-section lem-template-format">
        <h2><?php esc_html_e('Template Pack Format', 'live-event-manager'); ?></h2>
        <p>
            <?php esc_html_e('Template packs are ZIP files. Only files you include override the defaults. The default install set always includes the manifest, core PHP templates, and optional assets.', 'live-event-manager'); ?>
        </p>
        <pre>{slug}/
  template.json          <span class="comment"><?php esc_html_e('required metadata', 'live-event-manager'); ?></span>
  single-event.php       <span class="comment"><?php esc_html_e('optional: event watch page', 'live-event-manager'); ?></span>
  event-ticket-block.php <span class="comment"><?php esc_html_e('optional: paywall / ticket block', 'live-event-manager'); ?></span>
  page-events.php
  confirmation-page.php
  device-swap-form.php
  gated-video-block.php
  assets/
    style.css            <span class="comment"><?php esc_html_e('optional: loaded after base CSS', 'live-event-manager'); ?></span>
    script.js            <span class="comment"><?php esc_html_e('optional: footer script', 'live-event-manager'); ?></span></pre>

        <h3><?php esc_html_e('template.json', 'live-event-manager'); ?></h3>
        <?php if ($schema_link !== '') : ?>
            <p>
                <?php
                printf(
                    /* translators: %s: URL to JSON schema file */
                    wp_kses(
                        __('Canonical schema: <a href="%s" target="_blank" rel="noopener">template-pack.schema.json</a>', 'live-event-manager'),
                        array('a' => array('href' => array(), 'target' => array(), 'rel' => array()))
                    ),
                    esc_url($schema_link)
                );
                ?>
            </p>
        <?php endif; ?>
        <pre>{
  "lem_format":  1,
  "name":        "Premium Dark",
  "slug":        "obsidian",
  "version":     "1.0.0",
  "description": "A sleek dark event watch page.",
  "author":      "Your Name",
  "author_url":  "https://example.com",
  "requires_lem": "&gt;=1.0.0",
  "preview_url": "https://example.com/preview.png",
  "docs_url":    "https://example.com/docs",
  "support_url": "https://example.com/support",
  "license":     "proprietary",
  "type":        ["event_page", "paywall"],
  "files":       []
}</pre>
        <p class="description">
            <?php esc_html_e('Reserved keys (ignored by core, for marketplace plugins): marketplace, product_id, license_required, update_url, update_id.', 'live-event-manager'); ?>
        </p>
    </section>
</div>

<script>
(function () {
    var nonce   = <?php echo wp_json_encode($nonce); ?>;
    var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
    var i18n = {
        activateFailed: <?php echo wp_json_encode(__('Activation failed.', 'live-event-manager')); ?>,
        deleteFailed:   <?php echo wp_json_encode(__('Delete failed.', 'live-event-manager')); ?>,
        requestFailed:  <?php echo wp_json_encode(__('Request failed. Please try again.', 'live-event-manager')); ?>,
        deleteConfirm:  <?php echo wp_json_encode(__('Delete the "%s" template? This cannot be undone.', 'live-event-manager')); ?>,
        chooseZip:      <?php echo wp_json_encode(__('Please choose a .zip file first.', 'live-event-manager')); ?>,
        installFailed:  <?php echo wp_json_encode(__('Installation failed.', 'live-event-manager')); ?>
    };

    document.querySelectorAll('.lem-tpl-activate').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var slug  = btn.dataset.slug;
            var msgEl = document.getElementById('lem-tpl-msg-' + slug);
            btn.disabled = true;

            fetch(ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'lem_activate_template', nonce: nonce, slug: slug })
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    window.location.reload();
                } else {
                    msgEl.textContent = data.data || i18n.activateFailed;
                    msgEl.style.color = '#c0392b';
                    msgEl.style.display = 'block';
                    btn.disabled = false;
                }
            })
            .catch(function () {
                msgEl.textContent = i18n.requestFailed;
                msgEl.style.color = '#c0392b';
                msgEl.style.display = 'block';
                btn.disabled = false;
            });
        });
    });

    document.querySelectorAll('.lem-tpl-delete').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var slug = btn.dataset.slug;
            var name = btn.dataset.name;
            if (!confirm(i18n.deleteConfirm.replace('%s', name))) {
                return;
            }

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
                        card.style.opacity = '0';
                        setTimeout(function () { card.remove(); }, 270);
                    }
                } else {
                    msgEl.textContent = data.data || i18n.deleteFailed;
                    msgEl.style.color = '#c0392b';
                    msgEl.style.display = 'block';
                    btn.disabled = false;
                }
            })
            .catch(function () {
                msgEl.textContent = i18n.requestFailed;
                msgEl.style.color = '#c0392b';
                msgEl.style.display = 'block';
                btn.disabled = false;
            });
        });
    });

    var uploadForm    = document.getElementById('lem-template-upload-form');
    var uploadBtn     = document.getElementById('lem-template-upload-btn');
    var uploadSpinner = document.getElementById('lem-template-spinner');
    var uploadResult  = document.getElementById('lem-template-upload-result');

    uploadForm.addEventListener('submit', function (e) {
        e.preventDefault();

        var fileInput = document.getElementById('lem-template-zip-input');
        if (!fileInput.files.length) {
            uploadResult.textContent = i18n.chooseZip;
            uploadResult.style.color = '#c0392b';
            return;
        }

        var formData = new FormData();
        formData.append('action', 'lem_upload_template');
        formData.append('nonce', nonce);
        formData.append('template_zip', fileInput.files[0]);

        uploadBtn.disabled = true;
        uploadSpinner.style.display = 'inline-block';
        uploadResult.textContent = '';

        fetch(ajaxUrl, { method: 'POST', body: formData })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            uploadSpinner.style.display = 'none';
            uploadBtn.disabled = false;
            if (data.success) {
                uploadResult.textContent = (data.data && data.data.message) ? data.data.message : '';
                uploadResult.style.color = '#27ae60';
                fileInput.value = '';
                setTimeout(function () { window.location.reload(); }, 900);
            } else {
                uploadResult.textContent = data.data || i18n.installFailed;
                uploadResult.style.color = '#c0392b';
            }
        })
        .catch(function () {
            uploadSpinner.style.display = 'none';
            uploadBtn.disabled = false;
            uploadResult.textContent = i18n.requestFailed;
            uploadResult.style.color = '#c0392b';
        });
    });
}());
</script>
