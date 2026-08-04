<?php
/**
 * Silk Naviora Admin — server-side UI components.
 *
 * Every page composes from these helpers rather than hand-rolling markup.
 * That is what keeps 29 screens looking and behaving like one product, and
 * it lets us enforce rules centrally — most importantly that an icon-only
 * control can never ship without an accessible name.
 */

if (!function_exists('ui_attrs')) {

/** Render an attribute list from an associative array. */
function ui_attrs(array $attrs): string
{
    $out = [];
    foreach ($attrs as $k => $v) {
        if ($v === null || $v === false) { continue; }
        if ($v === true) { $out[] = e($k); continue; }
        $out[] = e($k) . '="' . e((string) $v) . '"';
    }
    return $out ? ' ' . implode(' ', $out) : '';
}

/** Join class names, skipping empties. */
function ui_cx(...$classes): string
{
    return implode(' ', array_filter(array_map('trim', $classes), static fn($c) => $c !== ''));
}

/* ==========================================================================
   Page header — the single <h1>. Never the operator's name.
   ========================================================================== */

/**
 * $opts: sub, actions (HTML), back => ['href' => …, 'label' => …]
 */
function ui_page_head(string $title, array $opts = []): void
{
    ?>
    <div class="page-head">
        <div class="page-head__text">
            <?php if (!empty($opts['back'])): ?>
                <a href="<?= e($opts['back']['href']) ?>" class="back-link">
                    <i class="ri-arrow-left-line" aria-hidden="true"></i>
                    <?= e($opts['back']['label'] ?? 'Back') ?>
                </a>
            <?php endif; ?>
            <h1 class="page-head__title"><?= e($title) ?></h1>
            <?php if (!empty($opts['sub'])): ?>
                <p class="page-head__sub"><?= e($opts['sub']) ?></p>
            <?php endif; ?>
        </div>
        <?php if (!empty($opts['actions'])): ?>
            <div class="page-head__actions"><?= $opts['actions'] ?></div>
        <?php endif; ?>
    </div>
    <?php
}

/* ==========================================================================
   Buttons
   ========================================================================== */

/**
 * $opts: variant (primary|secondary|ghost|danger|danger-ghost), size (sm|lg),
 *        icon, href, type, name, value, class, attrs, block
 */
function ui_btn(string $label, array $opts = []): string
{
    $classes = ui_cx(
        'btn',
        'btn--' . ($opts['variant'] ?? 'secondary'),
        !empty($opts['size']) ? 'btn--' . $opts['size'] : '',
        !empty($opts['block']) ? 'btn--block' : '',
        $opts['class'] ?? ''
    );

    $inner = '';
    if (!empty($opts['icon'])) {
        $inner .= '<i class="' . e($opts['icon']) . '" aria-hidden="true"></i>';
    }
    $inner .= '<span>' . e($label) . '</span>';

    $attrs = ($opts['attrs'] ?? []) + ['class' => $classes];

    if (!empty($opts['href'])) {
        $attrs['href'] = $opts['href'];
        return '<a' . ui_attrs($attrs) . '>' . $inner . '</a>';
    }

    $attrs['type'] = $opts['type'] ?? 'submit';
    if (!empty($opts['name']))  { $attrs['name'] = $opts['name']; }
    if (isset($opts['value']))  { $attrs['value'] = $opts['value']; }
    return '<button' . ui_attrs($attrs) . '>' . $inner . '</button>';
}

/**
 * Icon-only control. $label is mandatory and becomes both the accessible
 * name and the tooltip — this is the guardrail that stops the panel drifting
 * back to a wall of unlabelled glyphs.
 */
function ui_icon_btn(string $icon, string $label, array $opts = []): string
{
    if (trim($label) === '') {
        throw new InvalidArgumentException('ui_icon_btn() requires a non-empty accessible label.');
    }

    $classes = ui_cx(
        'btn btn--icon',
        'btn--' . ($opts['variant'] ?? 'ghost'),
        'btn--' . ($opts['size'] ?? 'sm'),
        $opts['class'] ?? ''
    );

    $attrs = ($opts['attrs'] ?? []) + [
        'class'      => $classes,
        'title'      => $label,
        'aria-label' => $label,
    ];

    $inner = '<i class="' . e($icon) . '" aria-hidden="true"></i>';

    if (!empty($opts['href'])) {
        $attrs['href'] = $opts['href'];
        return '<a' . ui_attrs($attrs) . '>' . $inner . '</a>';
    }

    $attrs['type'] = $opts['type'] ?? 'submit';
    if (!empty($opts['name'])) { $attrs['name'] = $opts['name']; }
    if (isset($opts['value'])) { $attrs['value'] = $opts['value']; }
    return '<button' . ui_attrs($attrs) . '>' . $inner . '</button>';
}

/* ==========================================================================
   Status & badges — colour is never the only signal; there is always a word.
   ========================================================================== */

function ui_status(string $label, string $tone = 'muted'): string
{
    return '<span class="status status--' . e($tone) . '">'
         . '<span class="status__dot" aria-hidden="true"></span>'
         . '<span>' . e($label) . '</span></span>';
}

function ui_badge(string $label, string $tone = '', array $opts = []): string
{
    $classes = ui_cx('badge', $tone !== '' ? 'badge--' . $tone : '', $opts['class'] ?? '');
    $icon = !empty($opts['icon']) ? '<i class="' . e($opts['icon']) . '" aria-hidden="true"></i>' : '';
    return '<span class="' . $classes . '">' . $icon . e($label) . '</span>';
}

function ui_count_badge(int $n, string $tone = ''): string
{
    return ui_badge((string) $n, $tone ?: 'outline');
}

/* ==========================================================================
   Empty state — always says what happened AND what to do next.
   ========================================================================== */

function ui_empty(string $icon, string $title, string $body = '', string $actions = ''): void
{
    ?>
    <div class="empty">
        <div class="empty__icon"><i class="<?= e($icon) ?>" aria-hidden="true"></i></div>
        <p class="empty__title"><?= e($title) ?></p>
        <?php if ($body !== ''): ?><p class="empty__body"><?= e($body) ?></p><?php endif; ?>
        <?php if ($actions !== ''): ?><div class="empty__actions"><?= $actions ?></div><?php endif; ?>
    </div>
    <?php
}

/* ==========================================================================
   Filters & search
   ========================================================================== */

/**
 * Segmented filter. $items: [value => ['label' => …, 'count' => ?int]]
 * $current is the active value; $href is a callable(value): string.
 */
function ui_seg(array $items, string $current, callable $href, string $label = 'Filter'): void
{
    ?>
    <div class="seg" role="tablist" aria-label="<?= e($label) ?>">
        <?php foreach ($items as $value => $item):
            $active = (string) $current === (string) $value; ?>
            <a href="<?= e($href((string) $value)) ?>"
               class="seg__item<?= $active ? ' is-active' : '' ?>"
               role="tab"
               aria-selected="<?= $active ? 'true' : 'false' ?>">
                <span><?= e($item['label']) ?></span>
                <?php if (isset($item['count'])): ?>
                    <span class="seg__count"><?= (int) $item['count'] ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
    <?php
}

/**
 * Client-side search box. $scopeId is the element whose [data-search-text]
 * descendants get filtered.
 */
function ui_search(string $scopeId, string $placeholder = 'Search…', array $opts = []): void
{
    $id = 'search-' . $scopeId;
    ?>
    <div class="search">
        <i class="ri-search-line search__icon" aria-hidden="true"></i>
        <label class="sr-only" for="<?= e($id) ?>"><?= e($placeholder) ?></label>
        <input type="search" id="<?= e($id) ?>" class="input search__input"
               placeholder="<?= e($placeholder) ?>"
               autocomplete="off"
               data-search="<?= e($scopeId) ?>"
               <?= !empty($opts['empty_id']) ? 'data-search-empty="' . e($opts['empty_id']) . '"' : '' ?>
               <?= !empty($opts['count_id']) ? 'data-search-count="' . e($opts['count_id']) . '"' : '' ?>>
        <button type="button" class="search__clear" aria-label="Clear search">
            <i class="ri-close-line" aria-hidden="true"></i>
        </button>
    </div>
    <?php
}

/* ==========================================================================
   Tabs — used for Pages and Settings, which are single destinations with
   sibling views rather than separate sidebar entries.
   ========================================================================== */

/** $items: [['href' => …, 'label' => …, 'active' => bool, 'icon' => ?string]] */
function ui_tab_nav(array $items, string $label = 'Sections'): void
{
    ?>
    <nav class="tabs" aria-label="<?= e($label) ?>">
        <?php foreach ($items as $item): ?>
            <a href="<?= e($item['href']) ?>"
               class="tab<?= !empty($item['active']) ? ' is-active' : '' ?>"
               <?= !empty($item['active']) ? 'aria-current="page"' : '' ?>>
                <?php if (!empty($item['icon'])): ?>
                    <i class="<?= e($item['icon']) ?>" aria-hidden="true"></i>
                <?php endif; ?>
                <?= e($item['label']) ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <?php
}

/**
 * EN / RU panes. $render is called with the language code.
 * Replaces the old Bootstrap-dependent lang_tabs().
 */
function ui_lang_tabs(string $id, callable $render): void
{
    $langs = ['en' => 'English', 'ru' => 'Русский'];
    ?>
    <div data-tabs>
        <div class="tabs tabs--inline" role="tablist" aria-label="Content language">
            <?php $first = true; foreach ($langs as $code => $label): ?>
                <button type="button"
                        class="tab<?= $first ? ' is-active' : '' ?>"
                        role="tab"
                        aria-selected="<?= $first ? 'true' : 'false' ?>"
                        data-tab-target="<?= e($id . '-' . $code) ?>">
                    <?= e($label) ?>
                </button>
                <?php $first = false; endforeach; ?>
        </div>
        <div>
            <?php $first = true; foreach (array_keys($langs) as $code): ?>
                <div class="tab-panel<?= $first ? ' is-active' : '' ?>"
                     id="<?= e($id . '-' . $code) ?>" role="tabpanel">
                    <?php $render($code); ?>
                </div>
                <?php $first = false; endforeach; ?>
        </div>
    </div>
    <?php
}

/* ==========================================================================
   Form primitives
   ========================================================================== */

/**
 * $opts: type, value, placeholder, hint, required, optional, attrs,
 *        prefix (input-group addon after), rows (textarea)
 */
function ui_field(string $name, string $label, array $opts = []): void
{
    $type  = $opts['type'] ?? 'text';
    $id    = $opts['id'] ?? ('f-' . preg_replace('/[^a-z0-9]+/i', '-', $name));
    $hintId = !empty($opts['hint']) ? $id . '-hint' : null;
    ?>
    <div class="field">
        <label class="label" for="<?= e($id) ?>">
            <?= e($label) ?>
            <?php if (!empty($opts['required'])): ?><span class="label__req" aria-hidden="true">*</span><?php endif; ?>
            <?php if (!empty($opts['optional'])): ?><span class="label__opt">(optional)</span><?php endif; ?>
        </label>

        <?php if ($type === 'textarea'): ?>
            <textarea class="textarea" id="<?= e($id) ?>" name="<?= e($name) ?>"
                      rows="<?= (int) ($opts['rows'] ?? 4) ?>"
                      placeholder="<?= e($opts['placeholder'] ?? '') ?>"
                      <?= !empty($opts['required']) ? 'required' : '' ?>
                      <?= $hintId ? 'aria-describedby="' . e($hintId) . '"' : '' ?>
                      <?= ui_attrs($opts['attrs'] ?? []) ?>><?= e((string) ($opts['value'] ?? '')) ?></textarea>
        <?php else: ?>
            <input class="input" type="<?= e($type) ?>" id="<?= e($id) ?>" name="<?= e($name) ?>"
                   value="<?= e((string) ($opts['value'] ?? '')) ?>"
                   placeholder="<?= e($opts['placeholder'] ?? '') ?>"
                   <?= !empty($opts['required']) ? 'required' : '' ?>
                   <?= $hintId ? 'aria-describedby="' . e($hintId) . '"' : '' ?>
                   <?= ui_attrs($opts['attrs'] ?? []) ?>>
        <?php endif; ?>

        <?php if (!empty($opts['hint'])): ?>
            <p class="hint" id="<?= e($hintId) ?>"><?= $opts['hint_html'] ?? e($opts['hint']) ?></p>
        <?php endif; ?>
    </div>
    <?php
}

/** $options: [value => label] */
function ui_select(string $name, string $label, array $options, $value = '', array $opts = []): void
{
    $id = $opts['id'] ?? ('f-' . preg_replace('/[^a-z0-9]+/i', '-', $name));
    ?>
    <div class="field">
        <label class="label" for="<?= e($id) ?>"><?= e($label) ?></label>
        <select class="select" id="<?= e($id) ?>" name="<?= e($name) ?>" <?= ui_attrs($opts['attrs'] ?? []) ?>>
            <?php foreach ($options as $v => $l): ?>
                <option value="<?= e((string) $v) ?>" <?= (string) $value === (string) $v ? 'selected' : '' ?>>
                    <?= e((string) $l) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if (!empty($opts['hint'])): ?><p class="hint"><?= e($opts['hint']) ?></p><?php endif; ?>
    </div>
    <?php
}

/**
 * Drag & drop uploader.
 * $opts: accept, hint, current (preview URL), kind (image|video|file|pdf),
 *        remove_name (checkbox that flags deletion), label, dark (preview bg)
 */
function ui_upload(string $name, string $label, array $opts = []): void
{
    $id       = 'u-' . preg_replace('/[^a-z0-9]+/i', '-', $name);
    $removeId = $id . '-remove';
    $current  = $opts['current'] ?? '';
    $kind     = $opts['kind'] ?? 'image';
    $hasFile  = $current !== '' || !empty($opts['has_file']);
    ?>
    <div class="field">
        <label class="label" for="<?= e($id) ?>"><?= e($label) ?></label>

        <?php if (!empty($opts['remove_name'])): ?>
            <input type="checkbox" name="<?= e($opts['remove_name']) ?>" id="<?= e($removeId) ?>" value="1" class="hide">
        <?php endif; ?>

        <div class="upload<?= $hasFile ? ' has-file' : '' ?>" data-label="<?= e(strtolower($label)) ?>">
            <i class="ri-upload-cloud-2-line upload__icon" aria-hidden="true"></i>
            <span class="upload__text">Drop a file or click to browse</span>
            <?php if (!empty($opts['hint'])): ?>
                <span class="upload__hint"><?= e($opts['hint']) ?></span>
            <?php endif; ?>

            <input type="file" id="<?= e($id) ?>" name="<?= e($name) ?>"
                   accept="<?= e($opts['accept'] ?? 'image/*') ?>"
                   <?= !empty($opts['remove_name']) ? 'data-remove-target="' . e($removeId) . '"' : '' ?>>

            <div class="upload__preview<?= !empty($opts['dark']) ? ' upload__preview--dark' : '' ?>">
                <?php if ($current !== '' && $kind === 'image'): ?>
                    <img src="<?= e($current) ?>" alt="">
                <?php elseif ($hasFile && $kind === 'video'): ?>
                    <div class="upload__file">
                        <i class="ri-film-line" aria-hidden="true"></i>
                        <span>Video uploaded</span>
                    </div>
                <?php elseif ($hasFile): ?>
                    <div class="upload__file">
                        <i class="ri-file-pdf-2-line" aria-hidden="true"></i>
                        <span><?= e($opts['file_name'] ?? 'File uploaded') ?></span>
                    </div>
                <?php endif; ?>
            </div>

            <div class="upload__progress">
                <div class="upload__bar"><span></span></div>
                <div class="upload__pct">0%</div>
            </div>
        </div>
    </div>
    <?php
}

/** Rich text editor bound to a hidden input of the same name. */
function ui_editor(string $name, ?string $value = '', string $placeholder = ''): void
{
    ?>
    <div class="editor-wrap quill-wrap">
        <div class="quill-editor" data-target="<?= e($name) ?>" data-placeholder="<?= e($placeholder) ?>"><?= render_html($value) ?></div>
        <input type="hidden" name="<?= e($name) ?>" value="">
    </div>
    <?php
}

/** Asset bundles for the rich text editor. */
function quill_vendor_css(): array { return ['libs/quill/quill.snow.css']; }
function quill_vendor_js(): array  { return ['libs/quill/quill.js', 'js/quill-init.js']; }

/** Asset bundles for the multi-select control. */
function choices_vendor_css(): array { return ['libs/choices.js/public/assets/styles/choices.min.css']; }
function choices_vendor_js(): array  { return ['libs/choices.js/public/assets/scripts/choices.min.js']; }

/* ==========================================================================
   Sticky action bar — the save button is always within reach.
   ========================================================================== */

/** $opts: cancel_href, cancel_label, note, extra (HTML before the buttons) */
function ui_sticky_actions(string $saveLabel = 'Save changes', array $opts = []): void
{
    ?>
    <div class="sticky-actions">
        <span class="dirty-dot" aria-hidden="true"></span>
        <span class="sticky-actions__note">
            <?= e($opts['note'] ?? 'Unsaved changes are lost if you leave this page.') ?>
        </span>
        <div class="sticky-actions__end">
            <?= $opts['extra'] ?? '' ?>
            <?php if (!empty($opts['cancel_href'])): ?>
                <?= ui_btn($opts['cancel_label'] ?? 'Cancel', [
                    'href' => $opts['cancel_href'],
                    'variant' => 'secondary',
                ]) ?>
            <?php endif; ?>
            <?= ui_btn($saveLabel, ['variant' => 'primary', 'icon' => 'ri-check-line', 'type' => 'submit']) ?>
        </div>
    </div>
    <?php
}

/* ==========================================================================
   Misc
   ========================================================================== */

/**
 * Absolute URL of the public website.
 *
 * The admin is a separate app, so a root-relative "/privacy" resolves to the
 * admin's own copy — which is why the old "View Live" buttons reopened the
 * editor instead of the live page. An explicit `site_url` setting wins;
 * otherwise we derive it from UPLOAD_URL, which already points at the site.
 */
function public_site_url(string $path = ''): string
{
    static $base = null;
    if ($base === null) {
        $configured = trim((string) setting('site_url', ''));
        if ($configured !== '') {
            $base = rtrim($configured, '/');
        } elseif (defined('UPLOAD_URL') && preg_match('~^https?://~', UPLOAD_URL)) {
            $base = rtrim(preg_replace('~/uploads/?$~', '', UPLOAD_URL), '/');
        } else {
            $base = '';
        }
    }
    return $base . ($path !== '' ? '/' . ltrim($path, '/') : '/');
}

/** Thumbnail with a graceful placeholder. */
function ui_thumb(?string $url, string $fallbackIcon = 'ri-image-line', string $modifier = ''): string
{
    $cls = ui_cx('thumb', $modifier);
    if ($url) {
        return '<span class="' . $cls . '"><img src="' . e($url) . '" alt="" loading="lazy"></span>';
    }
    return '<span class="' . $cls . '"><i class="' . e($fallbackIcon) . '" aria-hidden="true"></i></span>';
}

/** Initials avatar. */
function ui_avatar(?string $name, string $modifier = ''): string
{
    $initial = mb_strtoupper(mb_substr(trim((string) $name) ?: '?', 0, 1, 'UTF-8'), 'UTF-8');
    return '<span class="' . ui_cx('avatar', $modifier) . '" aria-hidden="true">' . e($initial) . '</span>';
}

/** Relative time for recent items, absolute beyond a week. */
function ui_time(?string $datetime): string
{
    if (!$datetime) { return '—'; }
    $ts = strtotime($datetime);
    if (!$ts) { return e($datetime); }

    $diff = time() - $ts;
    if ($diff < 60)     { $label = 'just now'; }
    elseif ($diff < 3600)  { $m = (int) ($diff / 60);   $label = $m . ' min ago'; }
    elseif ($diff < 86400) { $h = (int) ($diff / 3600); $label = $h . ($h === 1 ? ' hour ago' : ' hours ago'); }
    elseif ($diff < 604800){ $d = (int) ($diff / 86400);$label = $d . ($d === 1 ? ' day ago' : ' days ago'); }
    else { $label = date('M j, Y', $ts); }

    return '<time datetime="' . e(date('c', $ts)) . '" title="' . e(date('D, j M Y, H:i', $ts)) . '">'
         . e($label) . '</time>';
}

/** A hidden POST form — used for single-action buttons inside a list row. */
function ui_action_form(string $action, array $fields, string $inner, array $opts = []): string
{
    $attrs = [
        'method' => 'post',
        'action' => $action,
        'class'  => ui_cx('d-inline-flex', $opts['class'] ?? ''),
    ];
    if (!empty($opts['confirm'])) {
        $attrs['data-confirm']       = $opts['confirm'];
        $attrs['data-confirm-text']  = $opts['confirm_text'] ?? null;
        $attrs['data-confirm-label'] = $opts['confirm_label'] ?? null;
    }
    $html = '<form' . ui_attrs($attrs) . '>' . csrf_field();
    foreach ($fields as $k => $v) {
        $html .= '<input type="hidden" name="' . e($k) . '" value="' . e((string) $v) . '">';
    }
    return $html . $inner . '</form>';
}

} // function_exists guard
