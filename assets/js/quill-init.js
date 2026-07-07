/*
 * Quill setup for the admin content editors.
 * Features: standard formatting + spoiler blot, real image upload (no base64),
 * and per-image size presets + alignment. Each .quill-editor syncs its HTML
 * into the sibling hidden <input> on edit and on form submit.
 */
(function () {
    if (!window.Quill) return;

    var CSRF = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    // --- Spoiler inline blot (<span class="spoiler">) ---
    var Inline = Quill.import('blots/inline');
    function SpoilerBlot() { Inline.apply(this, arguments); }
    SpoilerBlot.prototype = Object.create(Inline.prototype);
    SpoilerBlot.prototype.constructor = SpoilerBlot;
    SpoilerBlot.blotName = 'spoiler';
    SpoilerBlot.tagName = 'span';
    SpoilerBlot.className = 'spoiler';
    Quill.register(SpoilerBlot, true);

    // --- Keep width/style on images/videos so size presets persist ---
    var Image = Quill.import('formats/image');
    var Video = Quill.import('formats/video');
    var MEDIA_ATTRS = ['alt', 'height', 'width', 'style'];

    function buildFormats(node) {
        return MEDIA_ATTRS.reduce(function (f, a) {
            if (node.hasAttribute(a)) f[a] = node.getAttribute(a);
            return f;
        }, {});
    }

    function buildFormat(name, value, proto) {
        if (MEDIA_ATTRS.indexOf(name) > -1) {
            if (value) this.domNode.setAttribute(name, value);
            else this.domNode.removeAttribute(name);
        } else {
            Object.getPrototypeOf(proto).format.call(this, name, value);
        }
    }

    Image.formats = buildFormats;
    var imgProto = Image.prototype;
    imgProto.format = function (name, value) { buildFormat.call(this, name, value, imgProto); };

    Video.formats = buildFormats;
    var vidProto = Video.prototype;
    vidProto.format = function (name, value) { buildFormat.call(this, name, value, vidProto); };

    var toolbar = [
        [{ header: [2, 3, 4, false] }],
        ['bold', 'italic', 'underline', 'strike'],
        ['blockquote', 'code-block'],
        [{ list: 'ordered' }, { list: 'bullet' }],
        [{ align: [] }],
        ['link', 'image', 'video'],
        ['spoiler'],
        ['clean']
    ];

    document.querySelectorAll('.quill-editor').forEach(function (el) {
        var name = el.getAttribute('data-target');
        var hidden = el.parentElement.querySelector('input[name="' + name + '"]');

        var quill = new Quill(el, {
            theme: 'snow',
            placeholder: el.getAttribute('data-placeholder') || '',
            modules: {
                toolbar: {
                    container: toolbar,
                    handlers: { spoiler: spoilerHandler, image: imageHandler }
                }
            }
        });

        // Label the custom spoiler button.
        var spBtn = el.previousElementSibling && el.previousElementSibling.querySelector('.ql-spoiler');
        if (spBtn) { spBtn.innerHTML = '<i class="ri-eye-off-line"></i>'; spBtn.title = 'Spoiler'; }

        function sync() {
            var html = quill.root.innerHTML;
            hidden.value = (html === '<p><br></p>' || quill.getText().trim() === '' && !quill.root.querySelector('img,iframe')) ? '' : html;
        }
        quill.on('text-change', sync);
        sync();
        var form = el.closest('form');
        if (form) form.addEventListener('submit', sync);

        attachImageTools(quill, el);

        function spoilerHandler() {
            var range = quill.getSelection();
            if (!range || range.length === 0) return;
            var fmt = quill.getFormat(range);
            quill.format('spoiler', !fmt.spoiler);
        }

        function imageHandler() {
            var input = document.createElement('input');
            input.type = 'file';
            input.accept = 'image/*';
            input.onchange = function () {
                var file = input.files[0];
                if (!file) return;
                var fd = new FormData();
                fd.append('file', file);
                fd.append('_csrf', CSRF);
                fetch('/upload-media.php', { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.url) {
                            var range = quill.getSelection(true);
                            quill.insertEmbed(range.index, 'image', data.url, 'user');
                            quill.setSelection(range.index + 1);
                        } else {
                            alert(data.error || 'Upload failed');
                        }
                    })
                    .catch(function () { alert('Upload failed'); });
            };
            input.click();
        }
    });

    // Floating toolbar to size/align a clicked image.
    function attachImageTools(quill, el) {
        var wrap = el.closest('.quill-wrap');
        if (!wrap) return;
        var bar = document.createElement('div');
        bar.className = 'quill-img-tools';
        bar.style.display = 'none';
        bar.innerHTML =
            '<button type="button" data-w="25%">25%</button>' +
            '<button type="button" data-w="50%">50%</button>' +
            '<button type="button" data-w="75%">75%</button>' +
            '<button type="button" data-w="100%">100%</button>' +
            '<span class="sep"></span>' +
            '<button type="button" data-a="">L</button>' +
            '<button type="button" data-a="center">C</button>' +
            '<button type="button" data-a="right">R</button>';
        wrap.appendChild(bar);
        var current = null;

        quill.root.addEventListener('click', function (e) {
            if (e.target && (e.target.tagName === 'IMG' || e.target.tagName === 'IFRAME' || e.target.classList.contains('ql-video'))) {
                current = e.target;
                var blot = Quill.find(current);
                if (blot) quill.setSelection(quill.getIndex(blot), 1);
                var r = current.getBoundingClientRect();
                var wr = wrap.getBoundingClientRect();
                bar.style.left = (r.left - wr.left) + 'px';
                bar.style.top = Math.max(0, r.top - wr.top - 34) + 'px';
                bar.style.display = 'flex';
            } else {
                bar.style.display = 'none';
            }
        });

        bar.addEventListener('click', function (e) {
            var t = e.target.closest('button');
            if (!t || !current) return;
            if (t.dataset.w) {
                current.setAttribute('style', 'width: ' + t.dataset.w);
            } else if (t.dataset.a !== undefined) {
                var blot = Quill.find(current);
                if (blot) {
                    quill.setSelection(quill.getIndex(blot), 1);
                    quill.format('align', t.dataset.a || false);
                }
            }
            quill.update();
            var ev = new Event('text-change');
            quill.root.dispatchEvent(ev);
            // force sync via the hidden input
            var hidden = el.parentElement.querySelector('input');
            if (hidden) hidden.value = quill.root.innerHTML;
        });
    }
})();
