<?php
/**
 * Small reusable form widgets for the admin pages.
 */

/** A Quill editor bound to a hidden input named $name (value re-sanitized on output). */
function editor_field(string $name, ?string $value = '', string $placeholder = ''): void
{
    ?>
    <div class="quill-wrap">
        <div class="quill-editor" data-target="<?= e($name) ?>" data-placeholder="<?= e($placeholder) ?>"><?= render_html($value) ?></div>
        <input type="hidden" name="<?= e($name) ?>" value="">
    </div>
    <?php
}

/**
 * Render EN/RU Bootstrap tabs. $render is called with the lang code ('en'|'ru')
 * to output the fields for that pane.
 *   lang_tabs('tour', function ($l) { ... });
 */
function lang_tabs(string $id, callable $render): void
{
    $langs = ['en' => 'English', 'ru' => 'Русский'];
    ?>
    <ul class="nav nav-tabs nav-tabs-custom mb-3" role="tablist">
        <?php $first = true; foreach ($langs as $code => $label): ?>
            <li class="nav-item">
                <a class="nav-link <?= $first ? 'active' : '' ?>" data-bs-toggle="tab"
                   href="#<?= e($id . '-' . $code) ?>" role="tab"><?= e($label) ?></a>
            </li>
            <?php $first = false; endforeach; ?>
    </ul>
    <div class="tab-content">
        <?php $first = true; foreach (array_keys($langs) as $code): ?>
            <div class="tab-pane fade <?= $first ? 'show active' : '' ?>" id="<?= e($id . '-' . $code) ?>" role="tabpanel">
                <?php $render($code); ?>
            </div>
            <?php $first = false; endforeach; ?>
    </div>
    <?php
}

/** Quill asset bundle for $page['vendor_css'] / $page['vendor_js']. */
function quill_vendor_css(): array { return ['libs/quill/quill.snow.css']; }
function quill_vendor_js(): array  { return ['libs/quill/quill.js', 'js/quill-init.js']; }
