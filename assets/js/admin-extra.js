document.addEventListener('DOMContentLoaded', () => {
    const dndWraps = document.querySelectorAll('.dnd-upload-wrap');
    
    dndWraps.forEach(wrap => {
        const fileInput = wrap.querySelector('input[type="file"]');
        const previewContainer = wrap.querySelector('.dnd-preview-container');
        let removeBtn = wrap.querySelector('.dnd-remove-btn');
        const loader = wrap.querySelector('.dnd-loader');
        
        if (!fileInput) return;

        // Create remove button if not exists
        if (!removeBtn && previewContainer) {
            removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'dnd-remove-btn';
            removeBtn.innerHTML = '<i class="ri-close-line"></i>';
            previewContainer.appendChild(removeBtn);
            
            removeBtn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                
                // Clear input
                fileInput.value = '';
                wrap.classList.remove('has-preview');
                
                // If there's an existing file deletion checkbox/input, trigger it if needed
                const removeCheckbox = document.getElementById(fileInput.getAttribute('data-remove-target'));
                if (removeCheckbox) {
                    removeCheckbox.checked = true;
                    // Trigger change just in case
                    removeCheckbox.dispatchEvent(new Event('change'));
                }
            });
        }

        // Handle drag events
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            wrap.addEventListener(eventName, preventDefaults, false);
        });

        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }

        ['dragenter', 'dragover'].forEach(eventName => {
            wrap.addEventListener(eventName, () => wrap.classList.add('dragover'), false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            wrap.addEventListener(eventName, () => wrap.classList.remove('dragover'), false);
        });

        wrap.addEventListener('drop', (e) => {
            let dt = e.dataTransfer;
            let files = dt.files;
            if (files && files.length > 0) {
                fileInput.files = files; // Assign files to input
                fileInput.dispatchEvent(new Event('change')); // Trigger change event manually
            }
        });

        fileInput.addEventListener('change', function() {
            const file = this.files[0];
            if (!file) {
                return; 
            }

            // Show loader
            wrap.classList.add('is-loading');

            // Inject progress bar into loader
            loader.innerHTML = `
                <div class="dnd-progress-container">
                    <div class="dnd-progress-bar"></div>
                </div>
                <div class="dnd-progress-text">0%</div>
            `;
            const pBar = loader.querySelector('.dnd-progress-bar');
            const pText = loader.querySelector('.dnd-progress-text');

            const removeCheckbox = document.getElementById(fileInput.getAttribute('data-remove-target'));
            if (removeCheckbox) {
                removeCheckbox.checked = false; // Uncheck removal if they selected a new file
            }

            const isImage = file.type.startsWith('image/');
            const isVideo = file.type.startsWith('video/');
            
            if (isImage || isVideo) {
                const xhr = new XMLHttpRequest();
                xhr.open('POST', '/ajax-upload.php', true);
                
                xhr.upload.onprogress = (e) => {
                    if (e.lengthComputable) {
                        const percentLoaded = Math.round((e.loaded / e.total) * 100);
                        if (pBar) pBar.style.width = percentLoaded + '%';
                        if (pText) pText.textContent = percentLoaded + '%';
                    }
                };

                xhr.onload = () => {
                    if (xhr.status === 200) {
                        try {
                            const res = JSON.parse(xhr.responseText);
                            if (res.success) {
                                // Add hidden input for the async path
                                let hiddenInput = wrap.querySelector('input.dnd-async-path');
                                if (!hiddenInput) {
                                    hiddenInput = document.createElement('input');
                                    hiddenInput.type = 'hidden';
                                    hiddenInput.className = 'dnd-async-path';
                                    hiddenInput.name = 'async_' + fileInput.name;
                                    wrap.appendChild(hiddenInput);
                                }
                                hiddenInput.value = res.path;

                                // Force 100% UI
                                if (pBar) pBar.style.width = '100%';
                                if (pText) pText.textContent = '100%';
                                
                                setTimeout(() => {
                                    updatePreview(res.url, isImage, isVideo, file.name);
                                    wrap.classList.remove('is-loading');
                                    wrap.classList.add('has-preview');
                                }, 300);
                            } else {
                                alert('Upload failed: ' + res.error);
                                wrap.classList.remove('is-loading');
                                fileInput.value = ''; // clear
                            }
                        } catch(e) {
                            alert('Upload failed: Invalid server response.');
                            wrap.classList.remove('is-loading');
                            fileInput.value = '';
                        }
                    } else {
                        alert('Upload failed: Server error ' + xhr.status);
                        wrap.classList.remove('is-loading');
                        fileInput.value = '';
                    }
                };

                xhr.onerror = () => {
                    alert('Upload failed: Network error.');
                    wrap.classList.remove('is-loading');
                    fileInput.value = '';
                };

                const formData = new FormData();
                formData.append('file', file);
                formData.append('type', isVideo ? 'video' : 'image');
                xhr.send(formData);
            } else {
                // Not image/video, just show filename immediately
                if (pBar) pBar.style.width = '100%';
                if (pText) pText.textContent = '100%';
                setTimeout(() => {
                    updatePreview(null, false, false, file.name);
                    wrap.classList.remove('is-loading');
                    wrap.classList.add('has-preview');
                }, 400);
            }
        });

        function updatePreview(src, isImage, isVideo, filename) {
            // Clear existing
            previewContainer.innerHTML = '';
            
            // Create a wrapper for the preview content to allow attaching the close button neatly
            const inner = document.createElement('div');
            inner.className = 'dnd-preview-inner';
            
            // Add remove button inside the inner container so it anchors to the content, not the far corner
            if (removeBtn) {
                inner.appendChild(removeBtn);
            }

            if (isImage && src) {
                const img = document.createElement('img');
                img.src = src;
                img.className = 'dnd-preview-img';
                
                // If it was already loaded from server and has custom classes (like rounded-circle), copy them
                const existingImg = wrap.querySelector('.dnd-preview-img');
                if (existingImg && existingImg.classList.contains('rounded-circle')) {
                    img.classList.add('rounded-circle');
                    // Retain specific inline styles if necessary
                    img.style.width = existingImg.style.width;
                    img.style.height = existingImg.style.height;
                    img.style.objectFit = 'cover';
                }
                
                inner.appendChild(img);
            } else if (isVideo && src) {
                const vid = document.createElement('video');
                vid.src = src;
                vid.className = 'dnd-preview-video';
                vid.controls = true;
                inner.appendChild(vid);
            } else if (filename) {
                // Better layout for files/videos
                const icon = document.createElement('i');
                icon.className = isVideo ? 'ri-movie-line text-primary' : 'ri-file-text-line text-primary';
                icon.style.fontSize = '3rem';
                icon.style.lineHeight = '1';
                inner.appendChild(icon);
                
                const span = document.createElement('span');
                span.className = 'dnd-filename fw-bold text-dark mt-2';
                span.textContent = filename;
                inner.appendChild(span);
                
                const success = document.createElement('span');
                success.className = 'text-success fs-13 mt-1 fw-medium';
                success.innerHTML = '<i class="ri-check-line"></i> Ready to upload';
                inner.appendChild(success);
            }
            
            previewContainer.appendChild(inner);
        }
    });
});
