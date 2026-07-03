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

$page = ['title' => $guide ? 'Edit guide' : 'New guide', 'section' => 'Guides', 'active' => 'guides'];
require __DIR__ . '/partials/head.php';
?>
<form method="post" enctype="multipart/form-data" action="<?= url('guide-edit' . ($id ? '?id=' . $id : '')) ?>">
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-12 col-lg-8">
            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">Details</h5></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Full name <span class="text-danger">*</span></label>
                        <input type="text" name="full_name" class="form-control" value="<?= e($guide['full_name'] ?? '') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Brief description (EN)</label>
                        <textarea name="bio_en" class="form-control" rows="3"><?= e($guide['bio_en'] ?? '') ?></textarea>
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Brief description (RU)</label>
                        <textarea name="bio_ru" class="form-control" rows="3"><?= e($guide['bio_ru'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Socials -->
            <div class="card">
                <div class="card-header d-flex align-items-center">
                    <h5 class="card-title mb-0">Social links</h5>
                    <button type="button" class="btn btn-sm btn-light ms-auto" id="addSocial"><i class="ri-add-line"></i> Add</button>
                </div>
                <div class="card-body">
                    <div id="socialRows" class="d-flex flex-column gap-2"></div>
                    <p class="text-muted fs-12 mb-0 mt-2">Added one by one. “Other” asks for a name, an icon and a link.</p>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-4">
            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">Photo (square)</h5></div>
                <div class="card-body text-center">
                    <?php $hasImg = $guide && $guide['image']; ?>
                    <label class="dnd-upload-wrap <?= $hasImg ? 'has-preview' : '' ?>">
                        <i class="ri-upload-cloud-2-line dnd-upload-icon"></i>
                        <div class="dnd-upload-text">Drag and drop or press to upload</div>
                        <div class="dnd-upload-subtext">JPG, PNG, WebP</div>
                        <input type="file" name="image" accept="image/*" id="imgInput" data-remove-target="rmImg">
                        <div class="dnd-preview-container">
                            <?php if ($hasImg): ?>
                                <img src="<?= e(upload_url($guide['image'])) ?>" class="dnd-preview-img">
                            <?php endif; ?>
                        </div>
                        <div class="dnd-loader">
                            <div class="spinner-border text-primary" role="status"></div>
                        </div>
                    </label>
                    <?php if ($hasImg): ?>
                        <div class="form-check mt-2 text-start d-none">
                            <input class="form-check-input" type="checkbox" name="remove_image" id="rmImg" value="1">
                            <label class="form-check-label fs-13" for="rmImg">Remove current photo</label>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="d-grid gap-2">
                <button class="btn btn-primary"><i class="ri-save-line me-1"></i> Save guide</button>
                <a href="<?= url('guides') ?>" class="btn btn-light">Cancel</a>
            </div>
        </div>
    </div>
</form>

<!-- Row template -->
<template id="socialTpl">
    <div class="repeat-row border rounded p-2 d-flex flex-wrap align-items-start gap-2">
        <select name="social_type[]" class="form-select form-select-sm social-type" style="max-width:200px">
            <?php foreach (SOCIAL_TYPES as $val => $label): ?>
                <option value="<?= $val ?>"><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" name="social_value[]" class="form-control form-control-sm social-value" style="min-width:160px;flex:1" placeholder="username">
        <input type="text" name="social_custom_name[]" class="form-control form-control-sm social-cname" style="max-width:160px;display:none" placeholder="Name">
        <input type="file" name="social_custom_icon[]" class="form-control form-control-sm social-cicon" style="max-width:170px;display:none" accept="image/*">
        <input type="hidden" name="social_icon_keep[]" value="">
        <button type="button" class="btn btn-sm btn-light text-danger remove-social"><i class="ri-close-line"></i></button>
    </div>
</template>

<?php
$existing = array_map(static function ($s) {
    return [
        'type'  => $s['type'],
        'value' => $s['value'],
        'cname' => $s['custom_name'],
        'icon'  => $s['custom_icon'] ? upload_url($s['custom_icon']) : '',
        'keep'  => $s['custom_icon'] ?? '',
    ];
}, $socials);

$page['inline_js'] = '
var EXISTING = ' . json_encode($existing) . ';
var rows = document.getElementById("socialRows");
var tpl = document.getElementById("socialTpl");

function wireRow(row, data){
  var typeSel = row.querySelector(".social-type");
  var cname = row.querySelector(".social-cname");
  var cicon = row.querySelector(".social-cicon");
  var keep  = row.querySelector("input[name=\"social_icon_keep[]\"]");
  function toggle(){
    var other = typeSel.value === "other";
    cname.style.display = other ? "" : "none";
    cicon.style.display = other ? "" : "none";
  }
  typeSel.addEventListener("change", toggle);
  if (data){
    typeSel.value = data.type;
    row.querySelector(".social-value").value = data.value || "";
    cname.value = data.cname || "";
    keep.value = data.keep || "";
  }
  toggle();
  row.querySelector(".remove-social").addEventListener("click", function(){ row.remove(); });
}
function addRow(data){
  var node = tpl.content.firstElementChild.cloneNode(true);
  rows.appendChild(node);
  wireRow(node, data);
}
EXISTING.forEach(addRow);
document.getElementById("addSocial").addEventListener("click", function(){ addRow(null); });

// image preview
var imgInput = document.getElementById("imgInput");
imgInput && imgInput.addEventListener("change", function(){
  var f = imgInput.files[0]; if(!f) return;
  var p = document.getElementById("imgPreview");
  p.src = URL.createObjectURL(f); p.style.display = "";
});
';
require __DIR__ . '/partials/foot.php';
?>
