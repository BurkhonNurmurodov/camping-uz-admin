<?php
require __DIR__ . '/app/bootstrap.php';
require_admin();

const SOCIAL_TYPES = [
    'whatsapp'  => 'WhatsApp (username)',
    'instagram' => 'Instagram (username)',
    'telegram'  => 'Telegram (username)',
    'facebook'  => 'Facebook (username)',
    'twitter'   => 'X / Twitter (username)',
    'linkedin'  => 'LinkedIn (profile URL)',
    'other'     => 'Other…',
];

$id    = (int) input('id', 0);
$guide = $id ? db_one('SELECT * FROM guides WHERE id = ?', [$id]) : null;
if ($id && !$guide) {
    flash('error', 'Guide not found.');
    redirect('guides');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $name = trim((string) input('full_name', ''));
    $bioEn = trim((string) input('bio_en', ''));
    $bioRu = trim((string) input('bio_ru', ''));

    $errors = [];
    if ($name === '') {
        $errors[] = 'Full name is required.';
    }

    // Image
    $imagePath = $guide['image'] ?? null;
    if (input('remove_image')) {
        delete_upload($imagePath);
        $imagePath = null;
    } elseif ($async = input('async_image')) {
        delete_upload($imagePath);
        $imagePath = $async;
    } elseif (!empty($_FILES['image']['name'])) {
        [$ok, $res] = save_image($_FILES['image'], 'guides', 8);
        if ($ok) { delete_upload($imagePath); $imagePath = $res; }
        else { $errors[] = 'Image: ' . $res; }
    }

    if (!$errors) {
        if ($guide) {
            db_run('UPDATE guides SET full_name=?, image=?, bio_en=?, bio_ru=? WHERE id=?',
                [$name, $imagePath, $bioEn, $bioRu, $id]);
        } else {
            db_run('INSERT INTO guides (full_name, image, bio_en, bio_ru) VALUES (?,?,?,?)',
                [$name, $imagePath, $bioEn, $bioRu]);
            $id = (int) db_insert_id();
        }

        // --- Socials: rebuild from posted rows ---
        $oldIcons = array_column(db_all('SELECT custom_icon FROM guide_socials WHERE guide_id=?', [$id]), 'custom_icon');
        $types  = (array) input('social_type', []);
        $values = (array) input('social_value', []);
        $cnames = (array) input('social_custom_name', []);
        $keeps  = (array) input('social_icon_keep', []);
        $files  = $_FILES['social_custom_icon'] ?? null;

        db_run('DELETE FROM guide_socials WHERE guide_id=?', [$id]);

        $newIcons = [];
        $order = 0;
        foreach ($types as $i => $type) {
            $type  = array_key_exists($type, SOCIAL_TYPES) ? $type : 'other';
            $value = trim((string) ($values[$i] ?? ''));
            if ($value === '' && $type !== 'other') {
                continue;
            }
            $customName = $type === 'other' ? trim((string) ($cnames[$i] ?? '')) : null;
            if ($type === 'other' && ($value === '' || $customName === '')) {
                continue;
            }

            $icon = null;
            if ($type === 'other') {
                $keep = trim((string) ($keeps[$i] ?? ''));
                if ($files && !empty($files['name'][$i])) {
                    $one = [
                        'name' => $files['name'][$i], 'type' => $files['type'][$i],
                        'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i],
                        'size' => $files['size'][$i],
                    ];
                    [$iok, $ires] = save_image($one, 'guides', 2);
                    if ($iok) { $icon = $ires; }
                } elseif ($keep !== '') {
                    $icon = $keep;
                }
                if ($icon) { $newIcons[] = $icon; }
            }

            db_run(
                'INSERT INTO guide_socials (guide_id, type, value, custom_name, custom_icon, sort_order) VALUES (?,?,?,?,?,?)',
                [$id, $type, $value, $customName, $icon, $order++]
            );
        }

        // Drop icon files no longer referenced.
        foreach (array_diff(array_filter($oldIcons), $newIcons) as $gone) {
            delete_upload($gone);
        }

        flash('success', 'Guide saved.');
        redirect('guides');
    }

    flash('error', implode(' ', $errors));
}

$socials = $id ? db_all('SELECT * FROM guide_socials WHERE guide_id=? ORDER BY sort_order', [$id]) : [];

// Preserve what was typed when validation fails.
$reposted = $_SERVER['REQUEST_METHOD'] === 'POST';
$pick = static fn(string $k, $stored) => $reposted ? (string) ($_POST[$k] ?? '') : (string) ($stored ?? '');
if ($reposted) {
    $socials = [];
    foreach ((array) ($_POST['social_type'] ?? []) as $i => $type) {
        $socials[] = [
            'type'        => $type,
            'value'       => $_POST['social_value'][$i] ?? '',
            'custom_name' => $_POST['social_custom_name'][$i] ?? '',
            'custom_icon' => $_POST['social_icon_keep'][$i] ?? '',
        ];
    }
}

$page = [
    'title'    => $guide ? 'Edit guide' : 'New guide',
    'subtitle' => $guide ? ($guide['full_name'] ?? '') : 'Add someone who leads your trips.',
    'active'   => 'guides',
    'back'     => ['href' => url('guides'), 'label' => 'All guides'],
];
require __DIR__ . '/partials/head.php';
?>

<form method="post" enctype="multipart/form-data" data-guard
      action="<?= url('guide-edit' . ($id ? '?id=' . $id : '')) ?>">
    <?= csrf_field() ?>

    <div class="split split--main-aside">
        <div class="stack">
            <section class="card">
                <div class="card__head"><h2 class="card__title">Details</h2></div>
                <div class="card__body">
                    <?php ui_field('full_name', 'Full name', [
                        'value'       => $pick('full_name', $guide['full_name'] ?? ''),
                        'required'    => true,
                        'placeholder' => 'e.g. Aziz Karimov',
                    ]); ?>

                    <?php ui_field('bio_en', 'Short description (EN)', [
                        'type'  => 'textarea',
                        'rows'  => 3,
                        'value' => $pick('bio_en', $guide['bio_en'] ?? ''),
                        'hint'  => 'One or two sentences shown under their name.',
                    ]); ?>

                    <?php ui_field('bio_ru', 'Short description (RU)', [
                        'type'  => 'textarea',
                        'rows'  => 3,
                        'value' => $pick('bio_ru', $guide['bio_ru'] ?? ''),
                    ]); ?>
                </div>
            </section>

            <section class="card">
                <div class="card__head">
                    <div>
                        <h2 class="card__title">Social links</h2>
                        <p class="card__sub">Choose “Other…” to add a custom name and icon.</p>
                    </div>
                    <div class="card__head-actions">
                        <?= ui_btn('Add link', ['icon' => 'ri-add-line', 'size' => 'sm', 'type' => 'button', 'attrs' => ['id' => 'addSocial']]) ?>
                    </div>
                </div>
                <div class="card__body">
                    <div id="socialRows" class="stack stack--sm"></div>
                    <p class="hint" id="socialEmpty">No social links yet.</p>
                </div>
            </section>
        </div>

        <aside class="stack">
            <section class="card">
                <div class="card__head"><h2 class="card__title">Photo</h2></div>
                <div class="card__body">
                    <?php ui_upload('image', 'Portrait', [
                        'accept'      => 'image/*',
                        'hint'        => 'Square works best · JPG, PNG or WebP · max 8 MB',
                        'current'     => ($guide && $guide['image']) ? upload_url($guide['image']) : '',
                        'remove_name' => 'remove_image',
                    ]); ?>
                </div>
            </section>
        </aside>
    </div>

    <?php ui_sticky_actions($guide ? 'Save guide' : 'Create guide', ['cancel_href' => url('guides')]); ?>
</form>

<template id="socialTpl">
    <div class="repeat-row">
        <label class="sr-only">Network</label>
        <select name="social_type[]" class="select social-type" style="flex:0 1 190px" aria-label="Network">
            <?php foreach (SOCIAL_TYPES as $val => $label): ?>
                <option value="<?= $val ?>"><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" name="social_value[]" class="input social-value" style="flex:1 1 160px" placeholder="username or URL" aria-label="Username or URL">
        <input type="text" name="social_custom_name[]" class="input social-cname" style="flex:0 1 150px" placeholder="Display name" aria-label="Custom network name">
        <input type="file" name="social_custom_icon[]" class="input social-cicon" style="flex:0 1 170px;padding-top:6px" accept="image/*" aria-label="Custom icon">
        <input type="hidden" name="social_icon_keep[]" value="">
        <button type="button" class="btn btn--icon btn--sm btn--danger-ghost remove-social" aria-label="Remove this link" title="Remove this link">
            <i class="ri-close-line" aria-hidden="true"></i>
        </button>
    </div>
</template>

<?php
$existing = array_map(static function ($s) {
    return [
        'type'  => $s['type'],
        'value' => $s['value'],
        'cname' => $s['custom_name'],
        'keep'  => $s['custom_icon'] ?? '',
    ];
}, $socials);

$page['inline_js'] = '
var EXISTING = ' . json_encode($existing) . ';
var rows = document.getElementById("socialRows");
var tpl = document.getElementById("socialTpl");
var emptyNote = document.getElementById("socialEmpty");

function syncEmpty(){ emptyNote.classList.toggle("hide", rows.children.length > 0); }

function wireRow(row, data){
  var typeSel = row.querySelector(".social-type");
  var cname = row.querySelector(".social-cname");
  var cicon = row.querySelector(".social-cicon");
  var keep  = row.querySelector("input[name=\"social_icon_keep[]\"]");
  function toggle(){
    var other = typeSel.value === "other";
    cname.classList.toggle("hide", !other);
    cicon.classList.toggle("hide", !other);
  }
  typeSel.addEventListener("change", toggle);
  if (data){
    typeSel.value = data.type;
    row.querySelector(".social-value").value = data.value || "";
    cname.value = data.cname || "";
    keep.value = data.keep || "";
  }
  toggle();
  row.querySelector(".remove-social").addEventListener("click", function(){ row.remove(); syncEmpty(); });
}
function addRow(data){
  var node = tpl.content.firstElementChild.cloneNode(true);
  rows.appendChild(node);
  wireRow(node, data);
  syncEmpty();
}
EXISTING.forEach(addRow);
syncEmpty();
document.getElementById("addSocial").addEventListener("click", function(){ addRow(null); });
';
require __DIR__ . '/partials/foot.php';
?>
