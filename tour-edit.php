<?php
require __DIR__ . '/app/bootstrap.php';
require_admin();
require __DIR__ . '/partials/widgets.php';

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
if ($id && !$tour) { flash('error', 'Tour not found.'); redirect('tours'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $titleEn = trim((string) input('title_en', ''));
    $titleRu = trim((string) input('title_ru', ''));
    $status  = in_array(input('status'), ['draft', 'upcoming', 'past'], true) ? input('status') : 'draft';
    $start   = input('start_date') ?: null;
    $end     = input('end_date') ?: null;
    $descEn  = sanitize_html((string) input('description_en', ''));
    $descRu  = sanitize_html((string) input('description_ru', ''));

    $errors = [];
    if ($titleEn === '' && $titleRu === '') { $errors[] = 'A title (EN or RU) is required.'; }
    $dateOk = static fn($d) => $d === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
    if (!$dateOk($start) || !$dateOk($end)) { $errors[] = 'Invalid date format.'; }
    if ($start && $end && $end < $start) { $errors[] = 'End date is before start date.'; }

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

        // Categories
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

$points = $id ? db_all('SELECT * FROM tour_route_points WHERE tour_id=? ORDER BY sort_order', [$id]) : [];
$allGuides = db_all('SELECT id, full_name FROM guides ORDER BY full_name');
$selGuides = $id ? array_column(db_all('SELECT guide_id FROM tour_guides WHERE tour_id=? ORDER BY sort_order', [$id]), 'guide_id') : [];
$allCategories = db_all('SELECT id, title_en FROM categories ORDER BY sort_order, id');
$selCategories = $id ? array_column(db_all('SELECT category_id FROM tour_categories WHERE tour_id=?', [$id]), 'category_id') : [];
$googleKey = setting('google_maps_api_key', '');

// When a submit fails validation, re-render with what the user just typed
// (never lose their work) instead of the stored row.
$reposted = $_SERVER['REQUEST_METHOD'] === 'POST';
$pick = static function (string $postKey, $stored) use ($reposted) {
    return $reposted ? (string) ($_POST[$postKey] ?? '') : (string) ($stored ?? '');
};
if ($reposted) {
    $selGuides = array_map('intval', (array) ($_POST['guide_id'] ?? []));
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
    'title' => $tour ? 'Edit tour' : 'New tour',
    'section' => 'Tours', 'active' => 'tours',
    'vendor_css' => array_merge(quill_vendor_css(), ['libs/choices.js/public/assets/styles/choices.min.css']),
];
require __DIR__ . '/partials/head.php';
?>
<form method="post" enctype="multipart/form-data" action="<?= url('tour-edit' . ($id ? '?id=' . $id : '')) ?>">
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-12 col-lg-8">
            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">Title &amp; description</h5></div>
                <div class="card-body">
                    <?php lang_tabs('tour', function ($l) use ($tour, $pick) { ?>
                        <div class="mb-3">
                            <label class="form-label">Title (<?= strtoupper($l) ?>)</label>
                            <input type="text" name="title_<?= $l ?>" class="form-control" value="<?= e($pick("title_$l", $tour["title_$l"] ?? '')) ?>">
                        </div>
                        <label class="form-label">Description (<?= strtoupper($l) ?>)</label>
                        <?php editor_field("description_$l", $pick("description_$l", $tour["description_{$l}_html"] ?? ''), 'Describe the trip…'); ?>
                    <?php }); ?>
                </div>
            </div>

            <!-- Route -->
            <div class="card">
                <div class="card-header d-flex align-items-center">
                    <h5 class="card-title mb-0">Route</h5>
                    <button type="button" class="btn btn-sm btn-light ms-auto" id="addPoint"><i class="ri-add-line"></i> Add point</button>
                </div>
                <div class="card-body">
                    <?php if ($googleKey): ?>
                        <div id="gmap" style="width:100%;height:320px;border-radius:8px;background:var(--bs-secondary-bg)" class="mb-3"></div>
                        <p class="text-muted fs-12">Click the map to drop pins. Drag rows? edit coordinates below. Pins connect in order.</p>
                    <?php else: ?>
                        <div class="alert alert-warning fs-13 py-2">Add a <a href="<?= url('settings') ?>">Google Maps API Key</a> to pick points on a map. You can still enter coordinates manually below.</div>
                    <?php endif; ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0" id="routeTable">
                            <thead><tr><th style="width:32px">#</th><th>Label (EN)</th><th>Label (RU)</th><th style="width:120px">Lat</th><th style="width:120px">Lng</th><th></th></tr></thead>
                            <tbody id="routeRows"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-4">
            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">Publish</h5></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <?php foreach (['draft' => 'Draft', 'upcoming' => 'Upcoming', 'past' => 'Past'] as $v => $l): ?>
                                <option value="<?= $v ?>" <?= $pick('status', $tour['status'] ?? 'draft') === $v ? 'selected' : '' ?>><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label">Start date</label>
                            <input type="date" name="start_date" class="form-control" value="<?= e($pick('start_date', $tour['start_date'] ?? '')) ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">End date</label>
                            <input type="date" name="end_date" class="form-control" value="<?= e($pick('end_date', $tour['end_date'] ?? '')) ?>">
                        </div>
                        <div class="form-text">Leave end date empty for a single-day tour.</div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Slug <span class="text-muted fs-12">(optional)</span></label>
                        <input type="text" name="slug" class="form-control" value="<?= e($pick('slug', $tour['slug'] ?? '')) ?>" placeholder="auto from title">
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">Poster (4:3)</h5></div>
                <div class="card-body text-center">
                    <?php $hasPoster = $tour && $tour['poster']; ?>
                    <label class="dnd-upload-wrap <?= $hasPoster ? 'has-preview' : '' ?>">
                        <i class="ri-upload-cloud-2-line dnd-upload-icon"></i>
                        <div class="dnd-upload-text">Drag and drop or press to upload</div>
                        <div class="dnd-upload-subtext">JPG, PNG, WebP</div>
                        <input type="file" name="poster" accept="image/*" id="posterInput" data-remove-target="rmPoster">
                        <div class="dnd-preview-container">
                            <?php if ($hasPoster): ?>
                                <img src="<?= e(upload_url($tour['poster'])) ?>" class="dnd-preview-img">
                            <?php endif; ?>
                        </div>
                        <div class="dnd-loader">
                            <div class="spinner-border text-primary" role="status"></div>
                        </div>
                    </label>
                    <?php if ($hasPoster): ?>
                        <div class="form-check mt-2 text-start d-none">
                            <input class="form-check-input" type="checkbox" name="remove_poster" id="rmPoster" value="1">
                            <label class="form-check-label fs-13" for="rmPoster">Remove poster</label>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">Categories (Max 4)</h5></div>
                <div class="card-body">
                    <?php if (!$allCategories): ?>
                        <p class="text-muted fs-13 mb-0">No categories. <a href="<?= url('category-edit') ?>">Create one</a>.</p>
                    <?php else: ?>
                        <select name="category_id[]" id="categorySelect" multiple>
                            <?php foreach ($allCategories as $c): ?>
                                <option value="<?= (int) $c['id'] ?>" <?= in_array($c['id'], $selCategories) ? 'selected' : '' ?>><?= e($c['title_en']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">Guides</h5></div>
                <div class="card-body">
                    <?php if (!$allGuides): ?>
                        <p class="text-muted fs-13 mb-0">No guides yet. <a href="<?= url('guide-edit') ?>">Create one</a> to attach.</p>
                    <?php else: ?>
                        <select name="guide_id[]" id="guideSelect" multiple>
                            <?php foreach ($allGuides as $g): ?>
                                <option value="<?= (int) $g['id'] ?>" <?= in_array($g['id'], $selGuides) ? 'selected' : '' ?>><?= e($g['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>
            </div>

            <div class="d-grid gap-2">
                <button class="btn btn-primary"><i class="ri-save-line me-1"></i> Save tour</button>
                <a href="<?= url('tours') ?>" class="btn btn-light">Cancel</a>
            </div>
        </div>
    </div>
</form>

<template id="routeRowTpl">
    <tr class="route-row">
        <td class="idx text-muted"></td>
        <td><input type="text" name="rp_label_en[]" class="form-control form-control-sm"></td>
        <td><input type="text" name="rp_label_ru[]" class="form-control form-control-sm"></td>
        <td><input type="text" name="rp_lat[]" class="form-control form-control-sm rp-lat"></td>
        <td><input type="text" name="rp_lng[]" class="form-control form-control-sm rp-lng"></td>
        <td><button type="button" class="btn btn-sm btn-light text-danger rp-remove"><i class="ri-close-line"></i></button></td>
    </tr>
</template>

<?php
$existingPoints = array_map(static fn($p) => [
    'en' => $p['label_en'], 'ru' => $p['label_ru'], 'lat' => $p['lat'], 'lng' => $p['lng'],
], $points);

$vendorJs = quill_vendor_js();
$vendorJs[] = 'libs/choices.js/public/assets/scripts/choices.min.js';
if ($googleKey) {
    // Google script loaded via inline injection below.
}
$page['vendor_js'] = $vendorJs;

$page['inline_js'] = '
var POINTS = ' . json_encode($existingPoints) . ';
var GKEY = ' . json_encode($googleKey) . ';
var rowsBody = document.getElementById("routeRows");
var rowTpl = document.getElementById("routeRowTpl");
var gmap = null, mapReady = false;
var directionsService = null;
var markers = [];
var routeLines = [];

function renumber(){ rowsBody.querySelectorAll(".route-row").forEach(function(r,i){ r.querySelector(".idx").textContent = i+1; }); }
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
document.getElementById("addPoint").addEventListener("click", function(){
  var c = mapReady && gmap ? gmap.getCenter() : {lat: 41.3111, lng: 69.2797};
  addRow({lat: c.lat ? c.lat().toFixed(6) : c.lat, lng: c.lng ? c.lng().toFixed(6) : c.lng}); drawMap();
});

var drawTimeout = null;
function drawMap(){
  clearTimeout(drawTimeout);
  drawTimeout = setTimeout(_doDrawMap, 400);
}

function _doDrawMap(){
  if(!mapReady || !gmap || !window.google) return;
  
  markers.forEach(function(m){ m.setMap(null); }); markers = [];
  routeLines.forEach(function(l){ l.setMap(null); }); routeLines = [];
  
  var pts = collect();
  var bounds = new google.maps.LatLngBounds();
  
  pts.forEach(function(p,i){ 
    var pos = {lat: p[0], lng: p[1]};
    var pm = new google.maps.Marker({
      position: pos,
      map: gmap,
      label: (i+1).toString(),
      draggable: true
    });
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
        directionsService.route({
          origin: startObj,
          destination: endObj,
          travelMode: google.maps.TravelMode.DRIVING
        }, function(response, status){
          var lineSymbol = { path: "M 0,-1 0,1", strokeOpacity: 1, scale: 3 };
          function drawDashed(p1, p2) {
            var pl = new google.maps.Polyline({
              path: [p1, p2], strokeOpacity: 0,
              icons: [{icon: lineSymbol, offset: "0", repeat: "15px"}],
              strokeColor: "#63ab45", strokeWeight: 4, map: gmap
            });
            routeLines.push(pl);
          }
          if(status === "OK" && response.routes && response.routes.length > 0) {
            var path = response.routes[0].overview_path;
            if (path.length > 0) {
              var firstC = path[0];
              var lastC = path[path.length - 1];
              var dStartRoad = getDist(startObj, {lat: firstC.lat(), lng: firstC.lng()});
              var dEndRoad = getDist(endObj, {lat: lastC.lat(), lng: lastC.lng()});
              var dTotal = getDist(startObj, endObj);

              if (dTotal > 0 && (dStartRoad + dEndRoad > dTotal * 0.8)) {
                drawDashed(startObj, endObj);
                return;
              }

              var polyline = new google.maps.Polyline({
                path: path,
                strokeColor: "#63ab45",
                strokeOpacity: 0.9,
                strokeWeight: 4,
                map: gmap
              });
              routeLines.push(polyline);

              if (dStartRoad > 10) drawDashed(startObj, firstC);
              if (dEndRoad > 10) drawDashed(lastC, endObj);
            }
          } else {
            drawDashed(startObj, endObj);
          }
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
  s.async = true;
  s.defer = true;
  document.head.appendChild(s);
}

// Guides & Categories multi-select
if (document.getElementById("guideSelect") && window.Choices) {
  new Choices("#guideSelect", {removeItemButton:true, shouldSort:false, placeholderValue:"Attach guides…"});
}
if (document.getElementById("categorySelect") && window.Choices) {
  new Choices("#categorySelect", {removeItemButton:true, shouldSort:false, maxItemCount: 4, placeholderValue:"Select up to 4 categories…"});
}

// poster preview
var pi=document.getElementById("posterInput");
pi && pi.addEventListener("change", function(){var f=pi.files[0];if(!f)return;var p=document.getElementById("posterPreview");p.src=URL.createObjectURL(f);p.style.display="";});
';
require __DIR__ . '/partials/foot.php';
?>
