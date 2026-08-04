<?php
require __DIR__ . '/app/bootstrap.php';
require_admin();

/** Ensure a unique slug in `tours`. */
function unique_tour_slug(string $base, int $excludeId = 0): string
{
    $base = slugify($base);
    $slug = $base;
    $n = 2;
    while (db_val('SELECT 1 FROM tours WHERE slug=? AND id<>?', [$slug, $excludeId])) {
        $slug = $base . '-' . $n++;
    }
    return $slug;
}

$id   = (int) input('id', 0);
$tour = $id ? db_one('SELECT * FROM tours WHERE id=?', [$id]) : null;
if ($id && !$tour) { flash('error', 'That tour no longer exists.'); redirect('tours'); }

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $titleEn = trim((string) input('title_en', ''));
    $titleRu = trim((string) input('title_ru', ''));
    $status  = in_array(input('status'), ['draft', 'upcoming', 'past'], true) ? input('status') : 'draft';
    $start   = input('start_date') ?: null;
    $end     = input('end_date') ?: null;
    $descEn  = sanitize_html((string) input('description_en', ''));
    $descRu  = sanitize_html((string) input('description_ru', ''));

    if ($titleEn === '' && $titleRu === '') { $errors[] = 'Give the tour a title in English or Russian.'; }
    $dateOk = static fn($d) => $d === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
    if (!$dateOk($start) || !$dateOk($end)) { $errors[] = 'Those dates are not in a format we recognise.'; }
    if ($start && $end && $end < $start) { $errors[] = 'The end date falls before the start date.'; }

    $slug = trim((string) input('slug', ''));
    $slug = unique_tour_slug($slug !== '' ? $slug : ($titleEn ?: $titleRu), $id);

    // Poster
    $poster = $tour['poster'] ?? null;
    if (input('remove_poster')) { delete_upload($poster); $poster = null; }
    elseif ($async = input('async_poster')) { delete_upload($poster); $poster = $async; }
    elseif (!empty($_FILES['poster']['name'])) {
        [$ok, $res] = save_image($_FILES['poster'], 'posters', 8);
        if ($ok) { delete_upload($poster); $poster = $res; } else { $errors[] = 'Poster: ' . $res; }
    }

    if (!$errors) {
        if ($tour) {
            db_run('UPDATE tours SET slug=?, status=?, poster=?, title_en=?, title_ru=?, description_en_html=?, description_ru_html=?, start_date=?, end_date=? WHERE id=?',
                [$slug, $status, $poster, $titleEn, $titleRu, $descEn, $descRu, $start, $end, $id]);
        } else {
            db_run('INSERT INTO tours (slug, status, poster, title_en, title_ru, description_en_html, description_ru_html, start_date, end_date) VALUES (?,?,?,?,?,?,?,?,?)',
                [$slug, $status, $poster, $titleEn, $titleRu, $descEn, $descRu, $start, $end]);
            $id = (int) db_insert_id();
        }

        // Route points
        db_run('DELETE FROM tour_route_points WHERE tour_id=?', [$id]);
        $lats = (array) input('rp_lat', []);
        $lngs = (array) input('rp_lng', []);
        $lEn  = (array) input('rp_label_en', []);
        $lRu  = (array) input('rp_label_ru', []);
        $ord = 0;
        foreach ($lats as $i => $lat) {
            $lat = (string) $lat; $lng = (string) ($lngs[$i] ?? '');
            if (!is_numeric($lat) || !is_numeric($lng)) { continue; }
            db_run('INSERT INTO tour_route_points (tour_id, label_en, label_ru, lat, lng, sort_order) VALUES (?,?,?,?,?,?)',
                [$id, trim((string) ($lEn[$i] ?? '')) ?: null, trim((string) ($lRu[$i] ?? '')) ?: null, (float) $lat, (float) $lng, $ord++]);
        }

        // Categories (max 4)
        db_run('DELETE FROM tour_categories WHERE tour_id=?', [$id]);
        $catIds = array_slice(array_unique(array_map('intval', (array) input('category_id', []))), 0, 4);
        foreach ($catIds as $cid) {
            if ($cid && db_val('SELECT 1 FROM categories WHERE id=?', [$cid])) {
                db_run('INSERT IGNORE INTO tour_categories (tour_id, category_id) VALUES (?,?)', [$id, $cid]);
            }
        }

        // Guides
        db_run('DELETE FROM tour_guides WHERE tour_id=?', [$id]);
        $gOrder = 0;
        foreach ((array) input('guide_id', []) as $gid) {
            $gid = (int) $gid;
            if ($gid && db_val('SELECT 1 FROM guides WHERE id=?', [$gid])) {
                db_run('INSERT IGNORE INTO tour_guides (tour_id, guide_id, sort_order) VALUES (?,?,?)', [$id, $gid, $gOrder++]);
            }
        }

        flash('success', 'Tour saved.');
        redirect('tour-edit/' . $id);
    }

    flash('error', implode(' ', $errors));
}

$points        = $id ? db_all('SELECT * FROM tour_route_points WHERE tour_id=? ORDER BY sort_order', [$id]) : [];
$allGuides     = db_all('SELECT id, full_name FROM guides ORDER BY sort_order, full_name');
$selGuides     = $id ? array_column(db_all('SELECT guide_id FROM tour_guides WHERE tour_id=? ORDER BY sort_order', [$id]), 'guide_id') : [];
$allCategories = db_all('SELECT id, title_en FROM categories ORDER BY sort_order, id');
$selCategories = $id ? array_column(db_all('SELECT category_id FROM tour_categories WHERE tour_id=?', [$id]), 'category_id') : [];
$googleKey     = setting('google_maps_api_key', '');

// When validation fails, re-render what was typed rather than the stored row —
// nobody should lose a long bilingual description to a bad date.
$reposted = $_SERVER['REQUEST_METHOD'] === 'POST';
$pick = static function (string $postKey, $stored) use ($reposted) {
    return $reposted ? (string) ($_POST[$postKey] ?? '') : (string) ($stored ?? '');
};
if ($reposted) {
    $selGuides     = array_map('intval', (array) ($_POST['guide_id'] ?? []));
    $selCategories = array_map('intval', (array) ($_POST['category_id'] ?? []));
    $points = [];
    foreach ((array) ($_POST['rp_lat'] ?? []) as $i => $lat) {
        $points[] = [
            'label_en' => $_POST['rp_label_en'][$i] ?? '',
            'label_ru' => $_POST['rp_label_ru'][$i] ?? '',
            'lat'      => $lat,
            'lng'      => $_POST['rp_lng'][$i] ?? '',
        ];
    }
}

$page = [
    'title'    => $tour ? 'Edit tour' : 'New tour',
    'subtitle' => $tour ? ($tour['title_en'] ?: $tour['title_ru'] ?: '') : 'Add a trip to the catalogue.',
    'active'   => 'tours',
    'back'     => ['href' => url('tours'), 'label' => 'All tours'],
    'vendor_css' => array_merge(quill_vendor_css(), choices_vendor_css()),
];
if ($tour) {
    $page['actions'] = ui_btn('View on site', [
        'href'  => public_site_url('tour.php?slug=' . rawurlencode($tour['slug'])),
        'icon'  => 'ri-external-link-line',
        'attrs' => ['target' => '_blank', 'rel' => 'noopener'],
    ]);
}
require __DIR__ . '/partials/head.php';
?>

<form method="post" enctype="multipart/form-data" data-guard
      action="<?= url('tour-edit' . ($id ? '?id=' . $id : '')) ?>">
    <?= csrf_field() ?>

    <div class="split split--main-aside">
        <div class="stack">
            <section class="card">
                <div class="card__head">
                    <div>
                        <h2 class="card__title">Title &amp; description</h2>
                        <p class="card__sub">Shown on the tour page. At least one language is required.</p>
                    </div>
                </div>
                <div class="card__body">
                    <?php ui_lang_tabs('tour', function ($l) use ($tour, $pick) { ?>
                        <?php ui_field("title_$l", 'Title (' . strtoupper($l) . ')', [
                            'value'       => $pick("title_$l", $tour["title_$l"] ?? ''),
                            'placeholder' => $l === 'en' ? 'e.g. Chimgan Weekend Escape' : 'например, Выходные в Чимгане',
                            'id'          => "title_$l",
                        ]); ?>
                        <div class="field">
                            <label class="label">Description (<?= strtoupper($l) ?>)</label>
                            <?php ui_editor("description_$l", $pick("description_$l", $tour["description_{$l}_html"] ?? ''), 'Describe the trip…'); ?>
                        </div>
                    <?php }); ?>
                </div>
            </section>

            <section class="card">
                <div class="card__head">
                    <div>
                        <h2 class="card__title">Route</h2>
                        <p class="card__sub">Points are joined in order to draw the route map.</p>
                    </div>
                    <div class="card__head-actions">
                        <?= ui_btn('Add point', ['icon' => 'ri-map-pin-add-line', 'size' => 'sm', 'type' => 'button', 'attrs' => ['id' => 'addPoint']]) ?>
                    </div>
                </div>
                <div class="card__body">
                    <?php if ($googleKey): ?>
                        <div id="gmap" class="map-canvas mb-3"></div>
                        <p class="hint mb-3">
                            <i class="ri-information-line" aria-hidden="true"></i>
                            Click the map to drop a pin, or drag an existing pin to move it. You can also type coordinates below.
                        </p>
                    <?php else: ?>
                        <div class="alert alert--warning">
                            <i class="alert__icon ri-map-pin-line" aria-hidden="true"></i>
                            <div class="alert__body">
                                No Google Maps key yet, so the map picker is unavailable — you can still enter coordinates by hand.
                                <a href="<?= url('integrations') ?>">Add a key in Integrations</a>.
                            </div>
                        </div>
                    <?php endif; ?>

                    <div id="routeRows" class="stack stack--sm"></div>

                    <p class="hint" id="routeEmpty">No route points yet. Add one to start drawing the route.</p>
                </div>
            </section>
        </div>

        <aside class="stack">
            <section class="card">
                <div class="card__head"><h2 class="card__title">Publishing</h2></div>
                <div class="card__body">
                    <?php ui_select('status', 'Status', [
                        'draft'    => 'Draft — hidden from the site',
                        'upcoming' => 'Upcoming — visible and bookable',
                        'past'     => 'Past — shown in the archive',
                    ], $pick('status', $tour['status'] ?? 'draft')); ?>

                    <div class="form-grid form-grid--2 mb-3">
                        <?php ui_field('start_date', 'Start date', ['type' => 'date', 'value' => $pick('start_date', $tour['start_date'] ?? '')]); ?>
                        <?php ui_field('end_date', 'End date', ['type' => 'date', 'value' => $pick('end_date', $tour['end_date'] ?? ''), 'optional' => true]); ?>
                    </div>
                    <p class="hint mb-3">Leave the end date empty for a single-day trip.</p>

                    <?php ui_field('slug', 'URL slug', [
                        'value'       => $pick('slug', $tour['slug'] ?? ''),
                        'placeholder' => 'created from the title',
                        'optional'    => true,
                        'hint'        => 'Used in the public web address. Leave blank to generate it automatically.',
                        'id'          => 'slugField',
                        'attrs'       => ['data-slug-from' => 'title_en'],
                    ]); ?>
                </div>
            </section>

            <section class="card">
                <div class="card__head"><h2 class="card__title">Poster</h2></div>
                <div class="card__body">
                    <?php ui_upload('poster', 'Cover image', [
                        'accept'      => 'image/*',
                        'hint'        => '4:3 works best · JPG, PNG or WebP · max 8 MB',
                        'current'     => ($tour && $tour['poster']) ? upload_url($tour['poster']) : '',
                        'remove_name' => 'remove_poster',
                    ]); ?>
                </div>
            </section>

            <section class="card">
                <div class="card__head">
                    <div>
                        <h2 class="card__title">Categories</h2>
                        <p class="card__sub">Up to four</p>
                    </div>
                </div>
                <div class="card__body">
                    <?php if (!$allCategories): ?>
                        <p class="t-sm t-muted mb-0">
                            No categories exist yet. <a href="<?= url('category-edit') ?>">Create one</a> to tag this tour.
                        </p>
                    <?php else: ?>
                        <label class="sr-only" for="categorySelect">Categories</label>
                        <select name="category_id[]" id="categorySelect" multiple>
                            <?php foreach ($allCategories as $c): ?>
                                <option value="<?= (int) $c['id'] ?>" <?= in_array((int) $c['id'], $selCategories, true) ? 'selected' : '' ?>>
                                    <?= e($c['title_en']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card">
                <div class="card__head"><h2 class="card__title">Guides</h2></div>
                <div class="card__body">
                    <?php if (!$allGuides): ?>
                        <p class="t-sm t-muted mb-0">
                            No guides yet. <a href="<?= url('guide-edit') ?>">Add a guide</a> to attach one here.
                        </p>
                    <?php else: ?>
                        <label class="sr-only" for="guideSelect">Guides</label>
                        <select name="guide_id[]" id="guideSelect" multiple>
                            <?php foreach ($allGuides as $g): ?>
                                <option value="<?= (int) $g['id'] ?>" <?= in_array((int) $g['id'], $selGuides, true) ? 'selected' : '' ?>>
                                    <?= e($g['full_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>
            </section>
        </aside>
    </div>

    <?php ui_sticky_actions($tour ? 'Save tour' : 'Create tour', ['cancel_href' => url('tours')]); ?>
</form>

<template id="routeRowTpl">
    <div class="repeat-row route-row">
        <span class="repeat-row__index idx" aria-hidden="true"></span>
        <input type="text" name="rp_label_en[]" class="input" style="flex:1 1 150px" placeholder="Label (EN)" aria-label="Point label in English">
        <input type="text" name="rp_label_ru[]" class="input" style="flex:1 1 150px" placeholder="Label (RU)" aria-label="Point label in Russian">
        <input type="text" name="rp_lat[]" class="input rp-lat" style="flex:0 1 118px" placeholder="Latitude" aria-label="Latitude" inputmode="decimal">
        <input type="text" name="rp_lng[]" class="input rp-lng" style="flex:0 1 118px" placeholder="Longitude" aria-label="Longitude" inputmode="decimal">
        <button type="button" class="btn btn--icon btn--sm btn--danger-ghost rp-remove" aria-label="Remove this point" title="Remove this point">
            <i class="ri-close-line" aria-hidden="true"></i>
        </button>
    </div>
</template>

<?php
$existingPoints = array_map(static fn($p) => [
    'en' => $p['label_en'], 'ru' => $p['label_ru'], 'lat' => $p['lat'], 'lng' => $p['lng'],
], $points);

$page['vendor_js'] = array_merge(quill_vendor_js(), choices_vendor_js());

$page['inline_js'] = '
var POINTS = ' . json_encode($existingPoints) . ';
var GKEY = ' . json_encode($googleKey) . ';
var rowsBody = document.getElementById("routeRows");
var rowTpl = document.getElementById("routeRowTpl");
var routeEmpty = document.getElementById("routeEmpty");
var gmap = null, mapReady = false;
var directionsService = null;
var markers = [];
var routeLines = [];

function renumber(){
  var rows = rowsBody.querySelectorAll(".route-row");
  rows.forEach(function(r,i){ r.querySelector(".idx").textContent = i+1; });
  routeEmpty.classList.toggle("hide", rows.length > 0);
}
function collect(){ var pts=[]; rowsBody.querySelectorAll(".route-row").forEach(function(r){
  var lat=parseFloat(r.querySelector(".rp-lat").value), lng=parseFloat(r.querySelector(".rp-lng").value);
  if(!isNaN(lat)&&!isNaN(lng)) pts.push([lat,lng]); }); return pts; }
function addRow(data){
  var node = rowTpl.content.firstElementChild.cloneNode(true);
  rowsBody.appendChild(node);
  if(data){ node.querySelector("input[name=\"rp_label_en[]\"]").value=data.en||"";
    node.querySelector("input[name=\"rp_label_ru[]\"]").value=data.ru||"";
    node.querySelector(".rp-lat").value=data.lat||""; node.querySelector(".rp-lng").value=data.lng||""; }
  node.querySelector(".rp-remove").addEventListener("click", function(){ node.remove(); renumber(); drawMap(); });
  node.querySelector(".rp-lat").addEventListener("input", drawMap);
  node.querySelector(".rp-lng").addEventListener("input", drawMap);
  renumber();
}
POINTS.forEach(addRow);
renumber();
document.getElementById("addPoint").addEventListener("click", function(){
  var c = mapReady && gmap ? gmap.getCenter() : {lat: 41.3111, lng: 69.2797};
  addRow({lat: c.lat ? c.lat().toFixed(6) : c.lat, lng: c.lng ? c.lng().toFixed(6) : c.lng}); drawMap();
});

var drawTimeout = null;
function drawMap(){ clearTimeout(drawTimeout); drawTimeout = setTimeout(_doDrawMap, 400); }

function _doDrawMap(){
  if(!mapReady || !gmap || !window.google) return;

  markers.forEach(function(m){ m.setMap(null); }); markers = [];
  routeLines.forEach(function(l){ l.setMap(null); }); routeLines = [];

  var pts = collect();
  var bounds = new google.maps.LatLngBounds();

  pts.forEach(function(p,i){
    var pos = {lat: p[0], lng: p[1]};
    var pm = new google.maps.Marker({ position: pos, map: gmap, label: (i+1).toString(), draggable: true });
    pm.addListener("dragend", function(e){
      var c = e.latLng;
      var rows = rowsBody.querySelectorAll(".route-row");
      if(rows[i]){
        rows[i].querySelector(".rp-lat").value = c.lat().toFixed(6);
        rows[i].querySelector(".rp-lng").value = c.lng().toFixed(6);
        drawMap();
      }
    });
    markers.push(pm);
    bounds.extend(pos);
  });

  if(pts.length>1){
    for(let i=0; i<pts.length-1; i++){
      (function(start, end){
        function getDist(p1, p2) {
          var R = 6371e3, lat1 = p1.lat*Math.PI/180, lat2 = p2.lat*Math.PI/180;
          var dLat = (p2.lat-p1.lat)*Math.PI/180, dLng = (p2.lng-p1.lng)*Math.PI/180;
          var a = Math.sin(dLat/2)*Math.sin(dLat/2) + Math.cos(lat1)*Math.cos(lat2)*Math.sin(dLng/2)*Math.sin(dLng/2);
          return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
        }
        var startObj = {lat: start[0], lng: start[1]};
        var endObj = {lat: end[0], lng: end[1]};
        directionsService.route({ origin: startObj, destination: endObj, travelMode: google.maps.TravelMode.DRIVING },
        function(response, status){
          var lineSymbol = { path: "M 0,-1 0,1", strokeOpacity: 1, scale: 3 };
          function drawDashed(p1, p2) {
            routeLines.push(new google.maps.Polyline({
              path: [p1, p2], strokeOpacity: 0,
              icons: [{icon: lineSymbol, offset: "0", repeat: "15px"}],
              strokeColor: "#bb9157", strokeWeight: 4, map: gmap
            }));
          }
          if(status === "OK" && response.routes && response.routes.length > 0) {
            var path = response.routes[0].overview_path;
            if (path.length > 0) {
              var firstC = path[0];
              var lastC = path[path.length - 1];
              var dStartRoad = getDist(startObj, {lat: firstC.lat(), lng: firstC.lng()});
              var dEndRoad = getDist(endObj, {lat: lastC.lat(), lng: lastC.lng()});
              var dTotal = getDist(startObj, endObj);
              if (dTotal > 0 && (dStartRoad + dEndRoad > dTotal * 0.8)) { drawDashed(startObj, endObj); return; }
              routeLines.push(new google.maps.Polyline({
                path: path, strokeColor: "#0d2444", strokeOpacity: 0.9, strokeWeight: 4, map: gmap
              }));
              if (dStartRoad > 10) drawDashed(startObj, firstC);
              if (dEndRoad > 10) drawDashed(lastC, endObj);
            }
          } else { drawDashed(startObj, endObj); }
        });
      })(pts[i], pts[i+1]);
    }
    gmap.fitBounds(bounds);
  } else if (pts.length === 1) {
    gmap.setCenter({lat: pts[0][0], lng: pts[0][1]});
    gmap.setZoom(10);
  }
}

if (GKEY) {
  window.initMap = function() {
    var c = POINTS.length ? {lat: parseFloat(POINTS[0].lat), lng: parseFloat(POINTS[0].lng)} : {lat: 41.3111, lng: 69.2797};
    gmap = new google.maps.Map(document.getElementById("gmap"), {
      center: c, zoom: 6, streetViewControl: false, mapTypeControl: false
    });
    directionsService = new google.maps.DirectionsService();
    mapReady = true;
    drawMap();
    gmap.addListener("click", function(e){
      var co = e.latLng;
      addRow({lat: co.lat().toFixed(6), lng: co.lng().toFixed(6)});
      drawMap();
    });
  };
  var s = document.createElement("script");
  s.src = "https://maps.googleapis.com/maps/api/js?key="+encodeURIComponent(GKEY)+"&callback=initMap";
  s.async = true; s.defer = true;
  document.head.appendChild(s);
}

if (document.getElementById("guideSelect") && window.Choices) {
  new Choices("#guideSelect", {removeItemButton:true, shouldSort:false, placeholderValue:"Attach guides…"});
}
if (document.getElementById("categorySelect") && window.Choices) {
  new Choices("#categorySelect", {removeItemButton:true, shouldSort:false, maxItemCount:4, placeholderValue:"Choose up to 4…"});
}
';
require __DIR__ . '/partials/foot.php';
?>
