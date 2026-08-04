/* ==========================================================================
   Silk Naviora Admin — behaviour layer
   --------------------------------------------------------------------------
   Replaces the old layout-setup.js + admin-extra.js. Deliberately dependency
   free: no Bootstrap JS, no SweetAlert. Every widget is progressive — if this
   file fails to load, links still navigate and forms still submit.
   ========================================================================== */
(function () {
    "use strict";

    var root = document.documentElement;
    var THEME_KEY = "sn.theme";     // "light" | "dark" | "system"
    var MINI_KEY = "sn.sidebar.mini";
    var DESKTOP = "(min-width: 992px)";

    var on = function (el, ev, fn, opts) { el && el.addEventListener(ev, fn, opts); };
    var $ = function (sel, ctx) { return (ctx || document).querySelector(sel); };
    var $$ = function (sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); };
    var isDesktop = function () { return window.matchMedia(DESKTOP).matches; };

    /* ----------------------------------------------------------------------
       Theme — three explicit states.
       The old panel wired a two-button control to a blind toggle, so pressing
       the already-lit "Light" button switched you to dark. Here each button
       sets its own value and nothing infers intent.
       ---------------------------------------------------------------------- */
    var Theme = {
        get: function () {
            try { return localStorage.getItem(THEME_KEY) || "system"; }
            catch (e) { return "system"; }
        },
        apply: function (mode) {
            if (mode === "system") root.removeAttribute("data-theme");
            else root.setAttribute("data-theme", mode);
            $$(".theme-switch__btn").forEach(function (btn) {
                btn.setAttribute("aria-pressed", String(btn.dataset.setTheme === mode));
            });
        },
        set: function (mode) {
            try { localStorage.setItem(THEME_KEY, mode); } catch (e) {}
            this.apply(mode);
        },
        init: function () {
            this.apply(this.get());
            $$(".theme-switch__btn").forEach(function (btn) {
                on(btn, "click", function () { Theme.set(btn.dataset.setTheme); });
            });
        }
    };

    /* ----------------------------------------------------------------------
       Sidebar.
       Two independent behaviours that the old code conflated:
         · desktop  — collapse to icon rail (persisted)
         · mobile   — slide-over drawer (never persisted)
       Nothing calls preventDefault on a nav link, so navigation always works.
       ---------------------------------------------------------------------- */
    var Sidebar = {
        openDrawer: function () {
            document.body.classList.add("is-drawer-open");
            var toggle = $("#navToggle");
            toggle && toggle.setAttribute("aria-expanded", "true");
            var first = $(".sidebar .nav-item");
            first && first.focus({ preventScroll: true });
        },
        closeDrawer: function () {
            document.body.classList.remove("is-drawer-open");
            var toggle = $("#navToggle");
            if (toggle) {
                toggle.setAttribute("aria-expanded", "false");
                toggle.focus({ preventScroll: true });
            }
        },
        toggleDrawer: function () {
            document.body.classList.contains("is-drawer-open") ? this.closeDrawer() : this.openDrawer();
        },
        setMini: function (mini) {
            document.body.classList.toggle("is-mini", mini);
            try { localStorage.setItem(MINI_KEY, mini ? "1" : "0"); } catch (e) {}
            var btn = $("#navToggle");
            if (btn) {
                btn.setAttribute("aria-expanded", String(!mini));
                btn.setAttribute("title", mini ? "Expand sidebar" : "Collapse sidebar");
            }
        },
        init: function () {
            var stored = "0";
            try { stored = localStorage.getItem(MINI_KEY) || "0"; } catch (e) {}
            if (stored === "1" && isDesktop()) document.body.classList.add("is-mini");
            // Hand off from the anti-flash class set inline in <head>.
            root.classList.remove("pre-mini");

            on($("#navToggle"), "click", function () {
                isDesktop()
                    ? Sidebar.setMini(!document.body.classList.contains("is-mini"))
                    : Sidebar.toggleDrawer();
            });

            on($("#navScrim"), "click", function () { Sidebar.closeDrawer(); });

            on(document, "keydown", function (e) {
                if (e.key === "Escape" && document.body.classList.contains("is-drawer-open")) {
                    Sidebar.closeDrawer();
                }
            });

            // Tapping a destination on mobile should dismiss the drawer.
            $$(".sidebar .nav-item").forEach(function (link) {
                on(link, "click", function () {
                    if (!isDesktop()) document.body.classList.remove("is-drawer-open");
                });
            });

            // Crossing the breakpoint must never strand the user in a
            // half-open state.
            var mq = window.matchMedia(DESKTOP);
            var onChange = function () {
                document.body.classList.remove("is-drawer-open");
                if (!isDesktop()) document.body.classList.remove("is-mini");
                else {
                    var v = "0";
                    try { v = localStorage.getItem(MINI_KEY) || "0"; } catch (e) {}
                    document.body.classList.toggle("is-mini", v === "1");
                }
            };
            mq.addEventListener ? mq.addEventListener("change", onChange) : mq.addListener(onChange);
        }
    };

    /* ----------------------------------------------------------------------
       Dropdown menus
       ---------------------------------------------------------------------- */
    var Menus = {
        closeAll: function (except) {
            $$(".menu.is-open").forEach(function (menu) {
                if (menu === except) return;
                menu.classList.remove("is-open");
                var trigger = document.querySelector('[aria-controls="' + menu.id + '"]');
                trigger && trigger.setAttribute("aria-expanded", "false");
            });
        },
        init: function () {
            $$("[data-menu]").forEach(function (trigger) {
                var menu = document.getElementById(trigger.getAttribute("aria-controls"));
                if (!menu) return;

                on(trigger, "click", function (e) {
                    e.stopPropagation();
                    var open = menu.classList.contains("is-open");
                    Menus.closeAll(menu);
                    menu.classList.toggle("is-open", !open);
                    trigger.setAttribute("aria-expanded", String(!open));
                });

                on(menu, "click", function (e) { e.stopPropagation(); });
            });

            on(document, "click", function () { Menus.closeAll(); });
            on(document, "keydown", function (e) {
                if (e.key === "Escape") Menus.closeAll();
            });
        }
    };

    /* ----------------------------------------------------------------------
       Tabs — both page-level (link tabs) and in-page panel tabs
       ---------------------------------------------------------------------- */
    var Tabs = {
        init: function () {
            $$("[data-tabs]").forEach(function (group) {
                var tabs = $$("[data-tab-target]", group);
                tabs.forEach(function (tab) {
                    on(tab, "click", function (e) {
                        e.preventDefault();
                        var panel = document.getElementById(tab.dataset.tabTarget);
                        if (!panel) return;

                        tabs.forEach(function (t) {
                            t.classList.remove("is-active");
                            t.setAttribute("aria-selected", "false");
                        });
                        tab.classList.add("is-active");
                        tab.setAttribute("aria-selected", "true");

                        var scope = panel.parentElement;
                        $$(".tab-panel", scope).forEach(function (p) { p.classList.remove("is-active"); });
                        panel.classList.add("is-active");
                    });

                    // Arrow-key navigation between tabs.
                    on(tab, "keydown", function (e) {
                        var i = tabs.indexOf(tab);
                        var next = e.key === "ArrowRight" ? tabs[i + 1]
                                 : e.key === "ArrowLeft"  ? tabs[i - 1] : null;
                        if (next) { e.preventDefault(); next.focus(); next.click(); }
                    });
                });
            });
        }
    };

    /* ----------------------------------------------------------------------
       Toasts
       ---------------------------------------------------------------------- */
    var ICONS = {
        success: "ri-checkbox-circle-fill",
        danger: "ri-error-warning-fill",
        info: "ri-information-fill"
    };

    function toast(message, type, timeout) {
        type = type || "info";
        var region = $(".toast-region");
        if (!region) {
            region = document.createElement("div");
            region.className = "toast-region";
            region.setAttribute("role", "status");
            region.setAttribute("aria-live", "polite");
            document.body.appendChild(region);
        }
        var el = document.createElement("div");
        el.className = "toast toast--" + type;
        var icon = document.createElement("i");
        icon.className = "toast__icon " + (ICONS[type] || ICONS.info);
        icon.setAttribute("aria-hidden", "true");
        var body = document.createElement("div");
        body.textContent = message;
        el.appendChild(icon);
        el.appendChild(body);
        region.appendChild(el);

        setTimeout(function () {
            el.classList.add("is-leaving");
            setTimeout(function () { el.remove(); }, 220);
        }, timeout || 3800);
    }

    /* ----------------------------------------------------------------------
       Confirm dialog — replaces SweetAlert and window.confirm.
       Any form marked data-confirm gets an accessible, themed confirmation.
       ---------------------------------------------------------------------- */
    function confirmDialog(opts) {
        return new Promise(function (resolve) {
            var dlg = document.createElement("dialog");
            dlg.className = "dialog";
            dlg.innerHTML =
                '<form method="dialog">' +
                  '<div class="dialog__body">' +
                    '<div class="dialog__icon"><i class="ri-alert-line" aria-hidden="true"></i></div>' +
                    '<h2 class="dialog__title"></h2>' +
                    '<p class="dialog__text"></p>' +
                  '</div>' +
                  '<div class="dialog__foot">' +
                    '<button value="cancel" class="btn btn--secondary" type="submit"></button>' +
                    '<button value="ok" class="btn btn--danger" type="submit"></button>' +
                  '</div>' +
                '</form>';

            $(".dialog__title", dlg).textContent = opts.title || "Are you sure?";
            var text = $(".dialog__text", dlg);
            if (opts.text) { text.textContent = opts.text; } else { text.remove(); }
            $('[value="cancel"]', dlg).textContent = opts.cancelText || "Cancel";

            var okBtn = $('[value="ok"]', dlg);
            okBtn.textContent = opts.confirmText || "Delete";
            if (opts.tone === "primary") okBtn.className = "btn btn--primary";

            document.body.appendChild(dlg);

            // <dialog> is well supported; fall back to native confirm if not.
            if (typeof dlg.showModal !== "function") {
                dlg.remove();
                resolve(window.confirm(opts.title || "Are you sure?"));
                return;
            }

            dlg.showModal();
            okBtn.focus();
            on(dlg, "close", function () {
                var ok = dlg.returnValue === "ok";
                dlg.remove();
                resolve(ok);
            });
        });
    }

    function initConfirms() {
        $$("form[data-confirm]").forEach(function (form) {
            if (form.dataset.confirmBound) return;
            form.dataset.confirmBound = "1";
            on(form, "submit", function (e) {
                if (form.dataset.confirmed === "1") return;
                e.preventDefault();
                confirmDialog({
                    title: form.dataset.confirm || "Are you sure?",
                    text: form.dataset.confirmText || "",
                    confirmText: form.dataset.confirmLabel || "Delete",
                    tone: form.dataset.confirmTone || "danger"
                }).then(function (ok) {
                    if (!ok) return;
                    form.dataset.confirmed = "1";
                    // Preserve the button the user actually pressed.
                    var submitter = form.querySelector('[type="submit"]');
                    form.requestSubmit ? form.requestSubmit(submitter) : form.submit();
                });
            });
        });
    }

    /* ----------------------------------------------------------------------
       Client-side list filter.
       Instant narrowing of the rows already on the page — the panel had no
       search of any kind before this.
       ---------------------------------------------------------------------- */
    function initSearch() {
        $$("[data-search]").forEach(function (input) {
            var wrap = input.closest(".search");
            var scope = document.getElementById(input.dataset.search);
            if (!scope) return;

            var rows = $$("[data-search-text]", scope);
            var emptyMsg = document.getElementById(input.dataset.searchEmpty || "");
            var countEl = document.getElementById(input.dataset.searchCount || "");

            var run = function () {
                var q = input.value.trim().toLowerCase();
                var shown = 0;
                rows.forEach(function (row) {
                    var hit = !q || (row.dataset.searchText || "").toLowerCase().indexOf(q) !== -1;
                    row.classList.toggle("hide", !hit);
                    if (hit) shown++;
                });
                wrap && wrap.classList.toggle("has-value", input.value !== "");
                if (emptyMsg) emptyMsg.classList.toggle("hide", shown !== 0);
                if (countEl) countEl.textContent = String(shown);
            };

            on(input, "input", run);
            on($(".search__clear", wrap), "click", function () {
                input.value = "";
                run();
                input.focus();
            });
            run();
        });
    }

    /* ----------------------------------------------------------------------
       Bulk selection — triage many records at once instead of one at a time.
       ---------------------------------------------------------------------- */
    function initBulk() {
        $$("[data-bulk]").forEach(function (scope) {
            var bar = document.getElementById(scope.dataset.bulk);
            if (!bar) return;

            var master = $("[data-bulk-all]", scope);
            var boxes = function () { return $$("[data-bulk-item]", scope); };
            var countEl = $(".bulk-bar__count", bar);

            var sync = function () {
                var all = boxes();
                var picked = all.filter(function (b) { return b.checked; });
                picked.forEach(function (b) { b.closest("tr") && b.closest("tr").classList.add("is-selected"); });
                all.filter(function (b) { return !b.checked; })
                   .forEach(function (b) { b.closest("tr") && b.closest("tr").classList.remove("is-selected"); });

                bar.classList.toggle("is-visible", picked.length > 0);
                if (countEl) {
                    countEl.textContent = picked.length + (picked.length === 1 ? " selected" : " selected");
                }
                if (master) {
                    master.checked = picked.length > 0 && picked.length === all.length;
                    master.indeterminate = picked.length > 0 && picked.length < all.length;
                }
                // Mirror the selection into the bulk form as hidden inputs.
                var form = bar.querySelector("form");
                if (form) {
                    $$('input[name="ids[]"]', form).forEach(function (i) { i.remove(); });
                    picked.forEach(function (b) {
                        var h = document.createElement("input");
                        h.type = "hidden"; h.name = "ids[]"; h.value = b.value;
                        form.appendChild(h);
                    });
                }
            };

            on(master, "change", function () {
                boxes().forEach(function (b) {
                    // Only touch rows currently visible under the search filter.
                    var row = b.closest("tr");
                    if (row && row.classList.contains("hide")) return;
                    b.checked = master.checked;
                });
                sync();
            });

            scope.addEventListener("change", function (e) {
                if (e.target.matches("[data-bulk-item]")) sync();
            });

            sync();
        });
    }

    /* ----------------------------------------------------------------------
       Uploader — drag & drop with real progress, carrying over the existing
       ajax-upload.php contract.
       ---------------------------------------------------------------------- */
    function initUploads() {
        $$(".upload").forEach(function (wrap) {
            var input = $('input[type="file"]', wrap);
            var preview = $(".upload__preview", wrap);
            if (!input || !preview) return;

            var bar = $(".upload__bar span", wrap);
            var pct = $(".upload__pct", wrap);
            var removeFlag = document.getElementById(input.dataset.removeTarget || "");

            function clearPreview() {
                $$("img, video, .upload__file", preview).forEach(function (n) { n.remove(); });
            }

            function showRemove() {
                if ($(".upload__remove", preview)) return;
                var btn = document.createElement("button");
                btn.type = "button";
                btn.className = "upload__remove";
                btn.innerHTML = '<i class="ri-close-line" aria-hidden="true"></i>';
                btn.setAttribute("aria-label", "Remove " + (wrap.dataset.label || "file"));
                on(btn, "click", function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    input.value = "";
                    wrap.classList.remove("has-file");
                    clearPreview();
                    btn.remove();
                    var async = $("input.upload__async", wrap);
                    async && async.remove();
                    if (removeFlag) removeFlag.checked = true;
                    markDirty(wrap);
                });
                preview.appendChild(btn);
            }

            if (wrap.classList.contains("has-file")) showRemove();

            function render(url, kind, filename) {
                clearPreview();
                var node;
                if (kind === "image" && url) {
                    node = document.createElement("img");
                    node.src = url;
                    node.alt = "";
                } else if (kind === "video" && url) {
                    node = document.createElement("video");
                    node.src = url;
                    node.controls = true;
                } else {
                    node = document.createElement("div");
                    node.className = "upload__file";
                    node.innerHTML = '<i class="' + (kind === "pdf" ? "ri-file-pdf-2-line" : "ri-file-3-line") + '" aria-hidden="true"></i>';
                    var name = document.createElement("span");
                    name.textContent = filename;
                    node.appendChild(name);
                }
                preview.insertBefore(node, preview.firstChild);
                wrap.classList.add("has-file");
                showRemove();
            }

            ["dragenter", "dragover", "dragleave", "drop"].forEach(function (ev) {
                on(wrap, ev, function (e) { e.preventDefault(); e.stopPropagation(); });
            });
            ["dragenter", "dragover"].forEach(function (ev) {
                on(wrap, ev, function () { wrap.classList.add("is-dragover"); });
            });
            ["dragleave", "drop"].forEach(function (ev) {
                on(wrap, ev, function () { wrap.classList.remove("is-dragover"); });
            });
            on(wrap, "drop", function (e) {
                var files = e.dataTransfer && e.dataTransfer.files;
                if (files && files.length) {
                    input.files = files;
                    input.dispatchEvent(new Event("change", { bubbles: true }));
                }
            });

            on(input, "change", function () {
                var file = input.files && input.files[0];
                if (!file) return;

                markDirty(wrap);
                if (removeFlag) removeFlag.checked = false;

                var isImage = file.type.indexOf("image/") === 0;
                var isVideo = file.type.indexOf("video/") === 0;

                // Non-media (PDFs) post with the form as before — just preview.
                if (!isImage && !isVideo) {
                    render(null, file.type === "application/pdf" ? "pdf" : "file", file.name);
                    return;
                }

                wrap.classList.add("is-uploading");
                if (bar) bar.style.width = "0%";
                if (pct) pct.textContent = "0%";

                var xhr = new XMLHttpRequest();
                xhr.open("POST", (window.SN_BASE || "") + "/ajax-upload.php", true);

                xhr.upload.onprogress = function (e) {
                    if (!e.lengthComputable) return;
                    var p = Math.round((e.loaded / e.total) * 100);
                    if (bar) bar.style.width = p + "%";
                    if (pct) pct.textContent = p + "%";
                };

                xhr.onload = function () {
                    wrap.classList.remove("is-uploading");
                    var res = null;
                    try { res = JSON.parse(xhr.responseText); } catch (e) {}

                    if (xhr.status !== 200 || !res || !res.success) {
                        input.value = "";
                        toast((res && res.error) || "Upload failed. Please try again.", "danger");
                        return;
                    }
                    var hidden = $("input.upload__async", wrap);
                    if (!hidden) {
                        hidden = document.createElement("input");
                        hidden.type = "hidden";
                        hidden.className = "upload__async";
                        hidden.name = "async_" + input.name;
                        wrap.appendChild(hidden);
                    }
                    hidden.value = res.path;
                    render(res.url, isVideo ? "video" : "image", file.name);
                };

                xhr.onerror = function () {
                    wrap.classList.remove("is-uploading");
                    input.value = "";
                    toast("Upload failed: network error.", "danger");
                };

                var fd = new FormData();
                fd.append("file", file);
                fd.append("type", isVideo ? "video" : "image");
                xhr.send(fd);
            });
        });
    }

    /* ----------------------------------------------------------------------
       Unsaved-change guard. Long bilingual forms are easy to lose.
       ---------------------------------------------------------------------- */
    function markDirty(el) {
        var form = el.closest ? el.closest("form") : null;
        form && form.classList.add("is-dirty");
    }

    function initDirtyGuard() {
        $$("form[data-guard]").forEach(function (form) {
            var dirty = false;
            var mark = function () {
                if (dirty) return;
                dirty = true;
                form.classList.add("is-dirty");
            };
            on(form, "input", mark);
            on(form, "change", mark);
            on(form, "submit", function () {
                dirty = false;
                form.classList.remove("is-dirty");
            });
            // Quill writes to a hidden input outside the normal input events.
            new MutationObserver(mark).observe(form, {
                subtree: true, attributes: true, attributeFilter: ["value"]
            });
            on(window, "beforeunload", function (e) {
                if (!dirty) return;
                e.preventDefault();
                e.returnValue = "";
            });
        });
    }

    /* ----------------------------------------------------------------------
       Auto-submitting selects (e.g. tour status).
       The old version submitted silently and left the operator guessing.
       ---------------------------------------------------------------------- */
    function initAutoSubmit() {
        $$("[data-autosubmit]").forEach(function (select) {
            on(select, "change", function () {
                select.disabled = true;
                var form = select.form;
                // Re-enable so the value is included in the POST body.
                var hidden = document.createElement("input");
                hidden.type = "hidden";
                hidden.name = select.name;
                hidden.value = select.value;
                form.appendChild(hidden);
                form.submit();
            });
        });
    }

    /* ----------------------------------------------------------------------
       Copy-to-clipboard
       ---------------------------------------------------------------------- */
    function initCopy() {
        $$("[data-copy]").forEach(function (btn) {
            on(btn, "click", function () {
                var text = btn.dataset.copy;
                var done = function () { toast("Copied to clipboard", "success", 1800); };
                if (navigator.clipboard) navigator.clipboard.writeText(text).then(done);
                else {
                    var ta = document.createElement("textarea");
                    ta.value = text;
                    document.body.appendChild(ta);
                    ta.select();
                    try { document.execCommand("copy"); done(); } catch (e) {}
                    ta.remove();
                }
            });
        });
    }

    /* ----------------------------------------------------------------------
       Dismissible alerts + flash messages announced as toasts
       ---------------------------------------------------------------------- */
    function initAlerts() {
        $$(".alert__close").forEach(function (btn) {
            on(btn, "click", function () {
                var alert = btn.closest(".alert");
                alert && alert.remove();
            });
        });
    }

    /* ----------------------------------------------------------------------
       Slug helper — mirrors a title into a slug field until edited by hand.
       ---------------------------------------------------------------------- */
    function initSlug() {
        $$("[data-slug-from]").forEach(function (slug) {
            var source = document.getElementById(slug.dataset.slugFrom);
            if (!source) return;
            if (slug.value.trim() !== "") slug.dataset.touched = "1";
            on(slug, "input", function () { slug.dataset.touched = "1"; });
            on(source, "input", function () {
                if (slug.dataset.touched === "1") return;
                slug.value = source.value
                    .toLowerCase()
                    .replace(/[^a-z0-9\s-]/g, "")
                    .trim()
                    .replace(/\s+/g, "-")
                    .replace(/-+/g, "-");
            });
        });
    }

    /* ---------------------------------------------------------------------- */
    function init() {
        Theme.init();
        Sidebar.init();
        Menus.init();
        Tabs.init();
        initConfirms();
        initSearch();
        initBulk();
        initUploads();
        initDirtyGuard();
        initAutoSubmit();
        initCopy();
        initAlerts();
        initSlug();
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }

    // Small public surface for page-level scripts.
    window.SN = {
        toast: toast,
        confirm: confirmDialog,
        refresh: function () { initConfirms(); initUploads(); }
    };
})();
