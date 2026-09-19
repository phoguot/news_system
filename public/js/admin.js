/* Admin CMS — JS nền cho giao diện mới (adapted từ cms-app.js của mockup,
 * bỏ điều hướng SPA showPage() vì hệ thống là MVC — mỗi trang một route thật).
 * Giữ: toggle sidebar mobile, đếm ký tự textarea, tab nhóm Cài đặt.
 * Soạn thảo: WYSIWYG cho textarea[data-wysiwyg] (nội dung bài viết/dịch vụ) —
 * toolbar + contenteditable, đồng bộ HTML vào textarea gốc khi gõ/gửi form.
 * upload: hiện tên +ảnh xem trước của các file vừa chọn (chưa tải lên).
 * Đã bỏ (control không tồn tại trong view — dead handler): tab lọc media
 * (trang media không có tab), animation biểu đồ cột (widget view theo FR-41
 * bị gỡ khỏi dashboard).
 * Đã thay: toolbar chèn thẻ HTML thủ công (data-open/data-close) → editor
 * WYSIWYG thật; JS tắt thì textarea hiện ra nguyên vẹn, admin gõ HTML tay —
 * server vẫn purge khi lưu nên không phải dependency cứng. */

// ========== SIDEBAR TOGGLE (mobile) ==========
const sidebar = document.getElementById('sidebar');
const menuToggle = document.getElementById('menuToggle');

if (sidebar && menuToggle) {
    const overlay = document.createElement('div');
    overlay.className = 'sidebar-overlay';
    document.body.appendChild(overlay);

    const closeSidebar = () => {
        sidebar.classList.remove('open');
        overlay.classList.remove('active');
    };

    menuToggle.addEventListener('click', () => {
        if (sidebar.classList.contains('open')) {
            closeSidebar();
        } else {
            sidebar.classList.add('open');
            overlay.classList.add('active');
        }
    });

    overlay.addEventListener('click', closeSidebar);
}

// ========== THU GỌN SIDEBAR (desktop) ==========
// Nút #sidebarCollapse bật/tắt class `sidebar-collapsed` trên <html> (dạng
// chỉ-icon), nhớ trạng thái qua localStorage. Áp sớm ở <head> để không nháy.
const sidebarCollapse = document.getElementById('sidebarCollapse');
if (sidebarCollapse) {
    const syncCollapseLabel = () => {
        const collapsed = document.documentElement.classList.contains('sidebar-collapsed');
        const label = collapsed ? 'Mở rộng menu' : 'Thu gọn menu';
        sidebarCollapse.setAttribute('aria-label', label);
        sidebarCollapse.setAttribute('title', label);
    };
    syncCollapseLabel();

    sidebarCollapse.addEventListener('click', () => {
        const collapsed = document.documentElement.classList.toggle('sidebar-collapsed');
        try {
            localStorage.setItem('adminSidebar', collapsed ? 'collapsed' : 'expanded');
        } catch (e) { /* localStorage bị chặn — vẫn đổi được trong phiên */ }
        syncCollapseLabel();
    });
}

// ========== BỘ LỌC THU GỌN (trang danh sách — 14/09) ==========
// Nút ".js-filter-toggle" đóng/mở panel ".js-filter-panel" chứa điều kiện lọc
// (hiện dùng ở trang Bài viết). Đóng khi click ra ngoài hoặc nhấn Escape.
// Lọc vẫn là form GET thuần — JS chỉ bật/ẩn, không phải dependency cứng.
const filterToggle = document.querySelector('.js-filter-toggle');
const filterPanel = document.querySelector('.js-filter-panel');

if (filterToggle && filterPanel) {
    const setFilterPanel = (open) => {
        filterPanel.classList.toggle('open', open);
        filterToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) {
            const first = filterPanel.querySelector('input:not([type=hidden]), select');
            if (first) {
                first.focus();
            }
        }
    };

    filterToggle.addEventListener('click', () => {
        setFilterPanel(!filterPanel.classList.contains('open'));
    });

    document.addEventListener('click', (ev) => {
        if (filterPanel.classList.contains('open')
            && !filterPanel.contains(ev.target) && !filterToggle.contains(ev.target)) {
            setFilterPanel(false);
        }
    });

    document.addEventListener('keydown', (ev) => {
        if (ev.key === 'Escape' && filterPanel.classList.contains('open')) {
            setFilterPanel(false);
            filterToggle.focus();
        }
    });
}

// ========== TAB NHÓM CÀI ĐẶT ==========
// Một form phẳng duy nhất: tab chỉ ẩn/hiện panel bằng CSS — mọi field vẫn submit đủ.
const settingsTabs = Array.from(document.querySelectorAll('.settings-tab[data-panel]'));
const settingsPanels = Array.from(document.querySelectorAll('.settings-panel[data-panel]'));

function activateSettingsTab(name) {
    settingsTabs.forEach((t) => t.classList.toggle('active', t.dataset.panel === name));
    settingsPanels.forEach((p) => p.classList.toggle('active', p.dataset.panel === name));
}

settingsTabs.forEach((tab) => {
    tab.addEventListener('click', () => activateSettingsTab(tab.dataset.panel));
});

if (settingsTabs.length && settingsPanels.length) {
    // Mở tab đầu; panel nào có lỗi validate thì nhảy tới panel đó để admin không bỏ sót
    let target = settingsPanels[0].dataset.panel;
    settingsPanels.forEach((p) => {
        if (p.querySelector('.field-error')) {
            target = p.dataset.panel;
        }
    });
    activateSettingsTab(target);
}

// ========== ĐẾM KÝ TỰ (input/textarea có maxlength) ==========
document.querySelectorAll('.form-group').forEach((group) => {
    const area = group.querySelector('textarea[maxlength], input[maxlength]');
    const counter = group.querySelector('.char-count');
    if (!area || !counter) {
        return;
    }
    const max = area.getAttribute('maxlength');
    const tick = () => {
        counter.textContent = area.value.length + '/' + max;
    };
    area.addEventListener('input', tick);
    tick();
});

// ========== SOẠN THẢO WYSIWYG (textarea[data-wysiwyg]) ==========
// Biến textarea thường thành ô soạn thảo giàu định dạng: toolbar + vùng
// contenteditable đúng design system Admin. Textarea gốc được GIỮ lại (ẩn)
// và đồng bộ innerHTML vào mỗi lần gõ/gửi form — luồng submit, InputFilter
// và HtmlPurifier phía server không đổi gì.
const RTE_TOOLS = [
    { cmd: 'bold', label: '<b>B</b>', title: 'Chữ đậm' },
    { cmd: 'italic', label: '<i>I</i>', title: 'Chữ nghiêng' },
    { cmd: 'underline', label: '<u>U</u>', title: 'Gạch chân' },
    { sep: true },
    { cmd: 'formatBlock', value: '<h2>', label: 'H2', title: 'Tiêu đề 2' },
    { cmd: 'formatBlock', value: '<h3>', label: 'H3', title: 'Tiêu đề 3' },
    { cmd: 'formatBlock', value: '<p>', label: '¶', title: 'Đoạn văn' },
    { cmd: 'formatBlock', value: '<blockquote>', label: '&ldquo;&rdquo;', title: 'Trích dẫn' },
    { sep: true },
    { cmd: 'insertUnorderedList', label: '&bull;', title: 'Danh sách đầu dòng' },
    { cmd: 'insertOrderedList', label: '1.', title: 'Danh sách số' },
    { sep: true },
    { action: 'link', label: '&#128279;', title: 'Chèn liên kết' },
    { cmd: 'unlink', label: '&#9398;', title: 'Bỏ liên kết' },
    { action: 'image', label: '&#128247;', title: 'Chèn ảnh từ thư viện media' },
    { sep: true },
    { cmd: 'undo', label: '&#8630;', title: 'Hoàn tác' },
    { cmd: 'redo', label: '&#8631;', title: 'Làm lại' },
];

function rteEscape(s) {
    return String(s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function rteInit(textarea) {
    const editor = document.createElement('div');
    editor.className = 'rte-editor';
    editor.contentEditable = 'true';
    editor.innerHTML = textarea.value;
    if (textarea.placeholder) {
        editor.dataset.placeholder = textarea.placeholder;
    }

    const toolbar = document.createElement('div');
    toolbar.className = 'rte-toolbar';
    RTE_TOOLS.forEach((tool) => {
        if (tool.sep) {
            const sep = document.createElement('span');
            sep.className = 'rte-sep';
            toolbar.appendChild(sep);
            return;
        }
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'rte-btn';
        btn.innerHTML = tool.label;
        btn.title = tool.title;
        btn.setAttribute('aria-label', tool.title);
        if (tool.cmd) {
            btn.dataset.cmd = tool.cmd;
            if (tool.value) {
                btn.dataset.value = tool.value;
            }
        }
        if (tool.action) {
            btn.dataset.action = tool.action;
        }
        toolbar.appendChild(btn);
    });

    // Textarea ẩn vẫn là nguồn submit; `required` client bị bỏ vì điều khiển
    // ẩn không focus được sẽ chặn form — Service đã validate content (intent
    // publish → lỗi "không được để trống"; nút Lưu nháp formnovalidate).
    textarea.classList.add('rte-hidden');
    textarea.required = false;
    textarea.tabIndex = -1;
    textarea.setAttribute('aria-hidden', 'true');
    textarea.insertAdjacentElement('beforebegin', toolbar);
    textarea.insertAdjacentElement('beforebegin', editor);

    const sync = () => {
        textarea.value = editor.innerHTML === '<br>' ? '' : editor.innerHTML;
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
    };
    editor.addEventListener('input', sync);

    // Dán từ ngoài vào: lấy text thuần — tránh HTML rác/inline style vào nội dung.
    editor.addEventListener('paste', (ev) => {
        const text = (ev.clipboardData || window.clipboardData).getData('text/plain');
        ev.preventDefault();
        document.execCommand('insertText', false, text);
    });

    // Ghi nhớ vị trí con trỏ trong editor để modal (chèn link/ảnh) không làm
    // mất vùng chọn khi quay lại.
    let savedRange = null;
    const saveSel = () => {
        const sel = window.getSelection();
        if (sel && sel.rangeCount && editor.contains(sel.anchorNode)) {
            savedRange = sel.getRangeAt(0).cloneRange();
        }
    };
    const restoreSel = () => {
        if (!savedRange) {
            return;
        }
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(savedRange);
    };
    document.addEventListener('selectionchange', saveSel);

    const exec = (cmd, value) => {
        editor.focus();
        restoreSel();
        document.execCommand(cmd, false, value || null);
        sync();
    };

    const runLink = () => {
        const url = window.prompt('Nhập URL liên kết:', 'https://');
        if (!url || url === 'https://') {
            return;
        }
        editor.focus();
        restoreSel();
        const sel = window.getSelection();
        if (sel && !sel.isCollapsed) {
            document.execCommand('createLink', false, url);
        } else {
            document.execCommand(
                'insertHTML', false,
                '<a href="' + rteEscape(url) + '">' + rteEscape(url) + '</a>'
            );
        }
        sync();
    };

    const runImage = () => rteOpenMediaModal(rteMediaChoices(textarea.form), (item) => {
        editor.focus();
        restoreSel();
        document.execCommand(
            'insertHTML', false,
            '<img src="' + rteEscape(item.src) + '" alt="' + rteEscape(item.label) + '" loading="lazy">'
        );
        sync();
    }, 'Chưa có ảnh nào trong danh sách media của form này — tải ảnh lên ở Thư viện media trước.');

    toolbar.addEventListener('click', (ev) => {
        const btn = ev.target.closest('.rte-btn');
        if (!btn) {
            return;
        }
        if (btn.dataset.action === 'link') {
            runLink();
        } else if (btn.dataset.action === 'image') {
            runImage();
        } else if (btn.dataset.cmd) {
            exec(btn.dataset.cmd, btn.dataset.value);
        }
    });

    // Nhận diện trạng thái đậm/nghiêng/lists để sáng nút tương ứng.
    const states = [['bold', 'bold'], ['italic', 'italic'], ['underline', 'underline'],
        ['insertUnorderedList', 'insertUnorderedList'], ['insertOrderedList', 'insertOrderedList']];
    document.addEventListener('selectionchange', () => {
        const sel = window.getSelection();
        if (!sel || !sel.rangeCount || !editor.contains(sel.anchorNode)) {
            return;
        }
        states.forEach(([cmd, name]) => {
            const btn = toolbar.querySelector('[data-cmd="' + cmd + '"]');
            if (btn) {
                btn.classList.toggle('active', document.queryCommandState(name));
            }
        });
    });

    // Đồng bộ lần cuối trước khi gửi (Enter/Ctrl+Enter, JS muộn, v.v.).
    if (textarea.form) {
        textarea.form.addEventListener('submit', sync);
    }
}

// Ảnh cho popup chọn (field media + nút 📷 editor): đọc từ JSON nhúng trong
// form (`<script class="media-lib">` do helper MediaPicker render — 1 node
// shared/request) — không call API, không phụ thuộc select box.
function rteMediaChoices(scope) {
    const root = scope || document;
    const seen = {};
    const items = [];
    let data = [];
    const node = root.querySelector ? root.querySelector('script.media-lib') : null;
    if (node) {
        try {
            data = JSON.parse(node.textContent || '[]');
        } catch (err) {
            data = [];
        }
    }
    (Array.isArray(data) ? data : []).forEach((row) => {
        const src = String((row && row.src) || '');
        if (!src || seen[src]) {
            return;
        }
        seen[src] = true;
        items.push({
            value: String((row && row.id) || ''),
            src,
            label: String((row && row.label) || ''),
        });
    });
    return items;
}

function rteOpenMediaModal(items, onPick, emptyMsg) {
    const modal = document.createElement('div');
    modal.className = 'rte-modal rte-modal-anim';
    const card = document.createElement('div');
    card.className = 'rte-modal-card';
    const head = document.createElement('div');
    head.className = 'rte-modal-head';
    head.innerHTML = '<span>Chọn ảnh từ thư viện media</span>';
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'rte-modal-close';
    close.setAttribute('aria-label', 'Đóng');
    close.textContent = '×';
    head.appendChild(close);
    card.appendChild(head);

    const body = document.createElement('div');
    if (items.length === 0) {
        body.className = 'rte-modal-empty';
        body.textContent = emptyMsg || 'Chưa có ảnh nào trong thư viện media.';
    } else {
        body.className = 'rte-modal-grid';
        items.forEach((item) => {
            const tile = document.createElement('button');
            tile.type = 'button';
            tile.className = 'rte-modal-item';
            tile.innerHTML = '<img src="' + rteEscape(item.src) + '" alt="" loading="lazy">'
                + '<span>' + rteEscape(item.label || item.src.split('/').pop()) + '</span>';
            tile.addEventListener('click', () => {
                onPick(item);
                dismiss();
            });
            body.appendChild(tile);
        });
    }
    card.appendChild(body);
    modal.appendChild(card);
    document.body.appendChild(modal);

    let closed = false;
    const dismiss = () => {
        if (closed) {
            return;
        }
        closed = true;
        modal.classList.remove('is-open');
        document.removeEventListener('keydown', onKey);
        // Cho CSS transition kịp chạy (fade + hạ thẻ) rồi mới gỡ node.
        window.setTimeout(() => modal.remove(), 200);
    };
    // Class `is-open` gắn SAU khi node nằm trong DOM (rAF) để trình duyệt
    // tính transition từ trạng thái đóng → mở mượt thay vì nhảy tức thì.
    window.requestAnimationFrame(() => modal.classList.add('is-open'));
    const onKey = (ev) => {
        if (ev.key === 'Escape') {
            dismiss();
        }
    };
    close.addEventListener('click', dismiss);
    modal.addEventListener('click', (ev) => {
        if (ev.target === modal) {
            dismiss();
        }
    });
    document.addEventListener('keydown', onKey);
}

// ========== MEDIA PICKER: khung preview là trigger duy nhất ==========
// 14/09 (feedback user): bỏ dòng trạng thái "— Chưa chọn ảnh —" rồi bỏ nốt nút
// chữ "Chọn ảnh từ thư viện…" — click/Enter vào khung .media-pick-frame mở
// popup lưới ảnh; chọn xong ảnh tự hiện trong khung (ẩn span empty, bỏ hidden
// trên img). Bỏ chọn bằng dấu × overlay góc khung (handler .media-pick-clear).
// Cảnh báo id chết cũng ẩn sau thao tác đầu tiên. Ghi `src` qua setAttribute
// (img.src='' bị browser resolve thành URL tuyệt đối).
function mediaPickRender(group, item) {
    const img = group.querySelector('.media-pick-preview');
    const empty = group.querySelector('.media-pick-empty');
    const clear = group.querySelector('.media-pick-clear');
    const error = group.querySelector('.media-pick-error');
    if (img) {
        if (item) {
            img.setAttribute('src', item.src);
            img.hidden = false;
        } else {
            img.hidden = true;
        }
    }
    if (empty) {
        empty.hidden = !!item;
    }
    if (clear) {
        clear.hidden = !item;
    }
    if (error) {
        error.hidden = true;
    }
}

function mediaPickOpen(group) {
    const input = group.querySelector('input.media-id');
    if (!input) {
        return;
    }
    const scope = input.form || group.closest('form');
    rteOpenMediaModal(rteMediaChoices(scope), (item) => {
        input.value = item.value;
        mediaPickRender(group, item);
    }, 'Thư viện media chưa có ảnh — tải ảnh lên ở mục Media trước.');
}

document.querySelectorAll('.media-pick-frame').forEach((frame) => {
    const group = frame.closest('.form-group') || frame.parentElement;
    if (!group) {
        return;
    }
    // Click lên dấu × DO handler .media-pick-clear xử lý — bỏ qua ở đây.
    frame.addEventListener('click', (ev) => {
        if (ev.target.closest('.media-pick-clear')) {
            return;
        }
        mediaPickOpen(group);
    });
    frame.addEventListener('keydown', (ev) => {
        if (ev.target !== frame) {
            return;
        }
        if (ev.key === 'Enter' || ev.key === ' ') {
            ev.preventDefault();
            mediaPickOpen(group);
        }
    });
});

document.querySelectorAll('.media-pick-clear').forEach((btn) => {
    btn.addEventListener('click', () => {
        const group = btn.closest('.form-group') || btn.parentElement;
        const input = group ? group.querySelector('input.media-id') : null;
        if (!input) {
            return;
        }
        input.value = '';
        mediaPickRender(group, null);
    });
});

document.querySelectorAll('textarea[data-wysiwyg]').forEach(rteInit);

// ========== HOME SECTION CONFIG: form thân thiện -> JSON hidden ==========
// Tầng Service vẫn validate `config` JSON như cũ; JS chỉ đóng gói các ô dễ hiểu
// trên form thành object đúng shape theo type section.
document.querySelectorAll('.js-home-config-form').forEach((form) => {
    const typeField = form.querySelector('#f-type');
    const jsonField = form.querySelector('.js-home-config-json');
    const panels = Array.from(form.querySelectorAll('[data-home-config-panel]'));
    if (!typeField || !jsonField || panels.length === 0) {
        return;
    }

    const activePanel = () => {
        const type = String(typeField.value || '');
        return panels.find((panel) => panel.getAttribute('data-home-config-panel') === type) || null;
    };

    const coerceValue = (field) => {
        const value = String(field.value || '').trim();
        if (value === '') {
            return null;
        }
        if (field.dataset.homeConfigType === 'int') {
            const number = parseInt(value, 10);
            return Number.isFinite(number) ? number : null;
        }
        if (field.dataset.homeConfigType === 'bool') {
            return value === '1' || value === 'true';
        }
        return value;
    };

    const syncConfig = () => {
        const panel = activePanel();
        const config = {};
        if (!panel) {
            jsonField.value = '';
            return;
        }

        panel.querySelectorAll('[data-home-config-key]').forEach((field) => {
            const key = field.dataset.homeConfigKey;
            const value = coerceValue(field);
            if (key && value !== null) {
                config[key] = value;
            }
        });

        const stepRows = Array.from(panel.querySelectorAll('.home-step-row'));
        if (stepRows.length) {
            const steps = [];
            stepRows.forEach((row) => {
                const title = (row.querySelector('[data-home-config-step-title]')?.value || '').trim();
                const desc = (row.querySelector('[data-home-config-step-desc]')?.value || '').trim();
                if (title === '' && desc === '') {
                    return;
                }
                const step = { title };
                if (desc !== '') {
                    step.desc = desc;
                }
                steps.push(step);
            });
            if (steps.length) {
                config.steps = steps;
            }
        }

        jsonField.value = Object.keys(config).length ? JSON.stringify(config) : '';
    };

    const refreshPanels = () => {
        const current = String(typeField.value || '');
        panels.forEach((panel) => {
            const active = panel.getAttribute('data-home-config-panel') === current;
            panel.hidden = !active;
            panel.querySelectorAll('input, select, textarea').forEach((field) => {
                field.disabled = !active;
            });
        });
        syncConfig();
    };

    typeField.addEventListener('change', refreshPanels);
    form.addEventListener('input', (event) => {
        if (event.target && event.target.closest('[data-home-config-panel]')) {
            syncConfig();
        }
    });
    form.addEventListener('change', (event) => {
        if (event.target && event.target.closest('[data-home-config-panel]')) {
            syncConfig();
        }
    });
    form.addEventListener('submit', syncConfig);
    refreshPanels();
});

// ========== UPLOAD AREA: tên file + ảnh preview của file vừa chọn ==========
document.querySelectorAll('.upload-area').forEach((area) => {
    const input = area.querySelector('input[type=file]');
    const nameEl = area.querySelector('.upload-filename');
    if (!input) {
        return;
    }
    let grid = null;
    let objectUrls = [];
    input.addEventListener('change', () => {
        const files = Array.from(input.files || []);
        if (nameEl) {
            const names = files.map((f) => f.name);
            nameEl.textContent = files.length === 0
                ? ''
                : files.length + ' file: ' + names.slice(0, 3).join(', ') + (files.length > 3 ? '…' : '');
        }
        if (!grid) {
            grid = document.createElement('div');
            grid.className = 'upload-previews';
            area.appendChild(grid);
        }
        objectUrls.forEach((u) => URL.revokeObjectURL(u));
        objectUrls = [];
        grid.innerHTML = '';
        files.forEach((f) => {
            const tile = document.createElement('div');
            tile.className = 'upload-thumb';
            if (f.type && f.type.indexOf('image/') === 0) {
                const url = URL.createObjectURL(f);
                objectUrls.push(url);
                const img = document.createElement('img');
                img.src = url;
                img.alt = f.name;
                tile.appendChild(img);
            } else {
                const badge = document.createElement('span');
                badge.className = 'upload-thumb-ext';
                badge.textContent = (f.name.split('.').pop() || '?').toUpperCase();
                tile.appendChild(badge);
            }
            const cap = document.createElement('span');
            cap.className = 'upload-thumb-name';
            cap.textContent = f.name;
            tile.appendChild(cap);
            grid.appendChild(tile);
        });
    });
    // Upload-area là <label> bọc input[file] ẩn — bấm vào preview không được
    // mở lại hộp thoại chọn file.
    area.addEventListener('click', (ev) => {
        if (ev.target.closest('.upload-thumb')) {
            ev.preventDefault();
        }
    });
});

// ========== FR-21: AUTOSAVE 60s + beforeunload + SO SÁNH REVISION ==========
(function () {
    const bar = document.getElementById('autosave-bar');
    const form = document.querySelector('form[action*="/admin/posts"]');
    if (bar && form) {
        const url = bar.getAttribute('data-autosave-url');
        const titleEl = document.getElementById('f-title');
        const excerptEl = document.getElementById('f-excerpt');
        const contentEl = document.getElementById('f-content');
        const editorEl = document.querySelector('.rte-editor');
        let lastSnapshot = '';
        let saving = false;
        let dirty = false;

        const snapshot = () => {
            const content = editorEl ? editorEl.innerHTML : (contentEl ? contentEl.value : '');
            return (titleEl ? titleEl.value : '') + '\n' + (excerptEl ? excerptEl.value : '') + '\n' + content;
        };

        const markDirty = () => { dirty = true; };
        if (titleEl) titleEl.addEventListener('input', markDirty);
        if (excerptEl) excerptEl.addEventListener('input', markDirty);
        if (contentEl) contentEl.addEventListener('input', markDirty);
        if (editorEl) editorEl.addEventListener('input', markDirty);

        const tick = async () => {
            if (saving || !dirty) return;
            const cur = snapshot();
            if (cur === lastSnapshot) { dirty = false; return; }
            const content = editorEl ? editorEl.innerHTML : (contentEl ? contentEl.value : '');
            if (!url) return;
            saving = true;
            bar.textContent = 'Đang tự lưu…';
            try {
                const res = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        title: titleEl ? titleEl.value : '',
                        excerpt: excerptEl ? excerptEl.value : '',
                        content: content,
                    }),
                });
                if (res.ok) {
                    lastSnapshot = cur;
                    dirty = false;
                    bar.textContent = 'Đã tự lưu lúc ' + new Date().toLocaleTimeString('vi-VN');
                } else {
                    bar.textContent = 'Tự lưu lỗi — sẽ thử lại.';
                }
            } catch (_e) {
                bar.textContent = 'Tự lưu lỗi mạng — sẽ thử lại.';
            } finally {
                saving = false;
            }
        };

        lastSnapshot = snapshot();
        setInterval(tick, 60000);

        window.addEventListener('beforeunload', (e) => {
            if (!dirty || snapshot() === lastSnapshot) return;
            e.preventDefault();
            e.returnValue = '';
        });

        form.addEventListener('submit', () => {
            dirty = false;
            window.removeEventListener('beforeunload', () => {});
        });
    }

    // So sánh revision: modal hiện nội dung bản đã lưu vs hiện tại
    const modal = document.getElementById('rev-compare-modal');
    if (modal) {
        const closeBtn = modal.querySelector('.rte-modal-close');
        const aEl = modal.querySelector('.rev-compare-a');
        const bEl = modal.querySelector('.rev-compare-b');
        // Thêm class .is-closing để chạy keyframe thoát (fade + hạ thẻ) rồi mới ẩn.
        const dismiss = () => {
            if (modal.style.display === 'none' || modal.classList.contains('is-closing')) return;
            modal.classList.add('is-closing');
            window.setTimeout(() => {
                modal.style.display = 'none';
                modal.classList.remove('is-closing');
            }, 180);
        };
        if (closeBtn) closeBtn.addEventListener('click', dismiss);
        modal.addEventListener('click', (ev) => { if (ev.target === modal) dismiss(); });
        document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape') dismiss(); });

        document.addEventListener('click', (ev) => {
            const btn = ev.target.closest('.js-rev-compare');
            if (!btn || !aEl || !bEl) return;
            const title = btn.getAttribute('data-title') || '';
            const excerpt = btn.getAttribute('data-excerpt') || '';
            const content = btn.getAttribute('data-content') || '';
            const created = btn.getAttribute('data-created') || '';
            const curTitle = document.getElementById('f-title') ? document.getElementById('f-title').value : '';
            const curExcerpt = document.getElementById('f-excerpt') ? document.getElementById('f-excerpt').value : '';
            const curContentEl = document.getElementById('f-content');
            const curEditor = document.querySelector('.rte-editor');
            const curContent = curEditor ? curEditor.innerHTML : (curContentEl ? curContentEl.value : '');
            const textA = (created ? '[' + created + ']\n' : '') + 'Tiêu đề: ' + title + '\nMô tả: ' + excerpt + '\n---\n' + content;
            const textB = 'Tiêu đề: ' + curTitle + '\nMô tả: ' + curExcerpt + '\n---\n' + curContent;
            // Escape rồi giữ xuống dòng; content là HTML nên hiển thị text thuần để so sánh
            const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            aEl.innerHTML = esc(textA).replace(/\n/g, '<br>');
            bEl.innerHTML = esc(textB).replace(/\n/g, '<br>');
            modal.style.display = 'flex';
        });
    }
})();

// ========== SELECT ẢNH MEDIA: preview nhỏ dưới select khi đổi lựa chọn ==========
document.querySelectorAll('select.media-select').forEach((sel) => {
    const group = sel.closest('.form-group') || sel.parentElement;
    let img = group ? group.querySelector('.media-pick-preview') : null;
    sel.addEventListener('change', () => {
        const opt = sel.options[sel.selectedIndex];
        const src = opt ? opt.getAttribute('data-src') : '';
        if (!img) {
            if (!src || !group) {
                return;
            }
            img = document.createElement('img');
            img.className = 'media-pick-preview';
            img.alt = '';
            sel.insertAdjacentElement('afterend', img);
        }
        if (src) {
            img.src = src;
            img.style.display = '';
        } else {
            img.removeAttribute('src');
            img.style.display = 'none';
        }
    });
});

// ========== FR-22: BULK — chọn checkbox, chọn action, xác nhận xoá ==========
(() => {
    const form = document.getElementById('bulk-form');
    if (!form) {
        return;
    }
    const actionField = document.getElementById('bulk-action');
    const catSelect   = document.getElementById('bulk-category');
    const confirmEl   = document.getElementById('bulk-confirm-count');
    const checks      = () => Array.from(document.querySelectorAll('.js-bulk-check'));

    // Master checkbox chọn tất cả (nằm trong thead, gắn form qua thuộc tính form).
    document.querySelectorAll('.js-bulk-all').forEach((master) => {
        master.addEventListener('change', () => {
            checks().forEach((c) => {
                c.checked = master.checked;
            });
        });
    });

    // Nút action quyết định giá trị `action` + bật select danh mục khi cần.
    form.querySelectorAll('[data-bulk]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const action = btn.getAttribute('data-bulk') || '';
            actionField.value = action;
            catSelect.disabled = action !== 'category';
        });
    });

    form.addEventListener('submit', (ev) => {
        const action = actionField.value;
        const picked = checks().filter((c) => c.checked);
        if (!action) {
            ev.preventDefault();
            return;
        }
        if (picked.length === 0) {
            ev.preventDefault();
            window.alert('Chọn ít nhất một bài viết.');
            return;
        }
        if (action === 'category' && !catSelect.value) {
            ev.preventDefault();
            window.alert('Chọn danh mục đích cho bài viết.');
            return;
        }
        if (action === 'delete') {
            // Spec §3.3.1: xoá hàng loạt bắt buộc GÕ ĐÚNG số bài để xác nhận.
            const answer = window.prompt(
                'Xoá vĩnh viễn ' + picked.length
                + ' bài — không thể khôi phục. Gõ đúng số ' + picked.length + ' để xác nhận.'
            );
            if (answer === null || answer.trim() !== String(picked.length)) {
                ev.preventDefault();
                if (answer !== null) {
                    window.alert('Số bạn gõ không khớp số bài đã chọn.');
                }
                return;
            }
            confirmEl.name  = 'confirmCount';
            confirmEl.value = answer.trim();
        } else {
            confirmEl.removeAttribute('name');
        }
    });
})();

// ========== FR-26/29/30/32: KÉO-THẢ ĐỔI THỨ TỰ — tbody.js-sortable ==========
// Khuôn chung cho 4 bảng danh mục/dịch vụ/banner/section: tbody khai báo
// data-reorder-url (POST /admin/{module}/reorder) + data-csrf (hash của
// ReorderFilter); mỗi <tr data-id>. Kéo bằng cán ⠿ → DOM đổi vị trí tức thì;
// thả xong gửi `ids` CSV qua fetch (X-Requested-With → controller trả JSON).
// Lỗi mạng/CSRF → khôi phục thứ tự cũ; thành công → đánh lại số cột "Thứ tự".
// JS tắt thì bảng vẫn dùng bình thường, chỉ mất tính năng kéo.
(() => {
    document.querySelectorAll('tbody.js-sortable').forEach((tbody) => {
        const url  = tbody.getAttribute('data-reorder-url');
        const csrf = tbody.getAttribute('data-csrf');
        if (!url || !csrf) {
            return;
        }

        // Cây 2 cấp (danh mục): tbody[data-tree] + mỗi <tr data-parent> (rỗng ở
        // cấp 1, = id cha ở cấp 2). Ở chế độ cây, kéo cha mang theo cả khối con,
        // kéo con chỉ đổi vị trí trong nhóm anh em cùng cha — đúng ràng buộc mà
        // listOrdered()/applyReorder chỉ ghi sortOrder (không đổi cha) cần.
        const tree = tbody.getAttribute('data-tree') === '1';

        const rowList = () => Array.from(tbody.querySelectorAll('tr[data-id]'));
        let savedIds = rowList().map((tr) => tr.getAttribute('data-id'));
        let dragged  = null;
        let pending  = false;

        // Con của một dòng cha: id cha đang là dragged? → dòng nào có data-parent
        // trùng data-id của cha (chỉ ở chế độ cây).
        const isChild = (tr) => tree && (tr.getAttribute('data-parent') || '') !== '';

        // Khối di chuyển cùng nhau: dòng con chỉ là chính nó; dòng cha kéo theo
        // các dòng con đứng ngay sau nó (data-parent === id cha).
        const blockOf = (tr) => {
            if (!tree || isChild(tr)) {
                return [tr];
            }
            const block = [tr];
            let node = tr.nextElementSibling;
            while (node && node.getAttribute('data-parent') === tr.getAttribute('data-id')) {
                block.push(node);
                node = node.nextElementSibling;
            }
            return block;
        };

        // FLIP: đo vị trí layout (offsetTop, không dính transform) trước khi đổi
        // DOM, rồi trượt các dòng bị dịch từ chỗ cũ về chỗ mới cho mượt.
        const animate = (mutate) => {
            const before = new Map();
            rowList().forEach((r) => before.set(r, r.offsetTop));
            mutate();
            rowList().forEach((r) => {
                if (r === dragged || !before.has(r)) {
                    return;
                }
                const dy = before.get(r) - r.offsetTop;
                if (!dy) {
                    return;
                }
                r.style.transition = 'none';
                r.style.transform  = 'translateY(' + dy + 'px)';
                requestAnimationFrame(() => {
                    r.style.transition = 'transform 150ms ease';
                    r.style.transform  = '';
                });
            });
        };

        const clearAnim = () => {
            rowList().forEach((r) => {
                r.style.transition = '';
                r.style.transform  = '';
            });
        };

        // Chỉ cho kéo khi nắm cán ⠿ (mouse-down lên handle → draggable).
        tbody.addEventListener('mousedown', (ev) => {
            const handle = ev.target.closest('.drag-handle');
            const tr     = ev.target.closest('tr[data-id]');
            rowList().forEach((r) => {
                r.draggable = Boolean(handle && tr && r === tr);
            });
        });
        window.addEventListener('mouseup', () => {
            rowList().forEach((r) => {
                r.draggable = false;
            });
        });

        tbody.addEventListener('dragstart', (ev) => {
            dragged = ev.target.closest('tr[data-id]');
            if (!dragged) {
                return;
            }
            dragged.classList.add('dragging');
            ev.dataTransfer.effectAllowed = 'move';
            ev.dataTransfer.setData('text/plain', dragged.getAttribute('data-id') || '');
        });

        tbody.addEventListener('dragover', (ev) => {
            if (!dragged) {
                return;
            }
            ev.preventDefault();
            const targetRow = ev.target.closest('tr[data-id]');
            if (!targetRow || targetRow === dragged) {
                return;
            }

            if (isChild(dragged)) {
                // Con chỉ đổi chỗ trong cùng nhóm anh em (cùng data-parent).
                if (targetRow.getAttribute('data-parent') !== dragged.getAttribute('data-parent')) {
                    return;
                }
                const box    = targetRow.getBoundingClientRect();
                const before = (ev.clientY - box.top) < box.height / 2;
                const ref    = before ? targetRow : targetRow.nextElementSibling;
                if (ref === dragged) {
                    return;
                }
                animate(() => tbody.insertBefore(dragged, ref));
                return;
            }

            // Kéo cha (hoặc bảng phẳng): xác định dòng cha gốc của target rồi di
            // chuyển cả khối cha trước/sau khối đó.
            const parentId = targetRow.getAttribute('data-parent') || '';
            const anchor   = parentId !== ''
                ? tbody.querySelector('tr[data-id="' + parentId + '"]')
                : targetRow;
            if (!anchor || anchor === dragged) {
                return;
            }
            const box    = anchor.getBoundingClientRect();
            const before = (ev.clientY - box.top) < box.height / 2;
            const block  = blockOf(anchor);
            const ref    = before ? anchor : block[block.length - 1].nextElementSibling;
            if (ref === dragged) {
                return;
            }
            const moving = blockOf(dragged);
            if (moving.indexOf(ref) !== -1) {
                return;
            }
            animate(() => moving.forEach((node) => tbody.insertBefore(node, ref)));
        });

        tbody.addEventListener('drop', (ev) => {
            ev.preventDefault();
        });

        tbody.addEventListener('dragend', () => {
            if (!dragged) {
                return;
            }
            dragged.classList.remove('dragging');
            dragged.draggable = false;
            dragged = null;
            clearAnim();
            persist();
        });

        function renumber() {
            rowList().forEach((tr, index) => {
                const cell = tr.querySelector('td.sort-cell');
                if (cell) {
                    cell.textContent = String(index);
                }
            });
        }

        function restoreOldOrder() {
            savedIds.forEach((id) => {
                const tr = tbody.querySelector('tr[data-id="' + id + '"]');
                if (tr) {
                    tbody.appendChild(tr);
                }
            });
        }

        function notify(message) {
            const toast = document.createElement('div');
            toast.className = 'reorder-toast';
            toast.textContent = message;
            document.body.appendChild(toast);
            window.setTimeout(() => toast.remove(), 4000);
        }

        function persist() {
            const ids = rowList().map((tr) => tr.getAttribute('data-id'));
            if (pending || ids.join(',') === savedIds.join(',')) {
                return;
            }
            pending = true;

            const body = 'ids=' + encodeURIComponent(ids.join(','))
                + '&csrf=' + encodeURIComponent(csrf);
            fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body,
            })
                .then((response) => response.json())
                .then((data) => {
                    if (!data || data.flag !== 'reordered') {
                        throw new Error((data && data.flag) || 'reorder failed');
                    }
                    savedIds = ids;
                    renumber();
                })
                .catch(() => {
                    restoreOldOrder();
                    notify('Không lưu được thứ tự mới — đã khôi phục như cũ.');
                })
                .finally(() => {
                    pending = false;
                });
        }
    });
})();

// ========== FR-28: AUTOCOMPLETE TAG trong form bài viết (#f-tags) ==========
// Gõ tìm tag đã có qua `GET {data-tag-search}?q=` (= /api/admin/tags,
// envelope {data:[{name,postCount}]}) — URL do view truyền, không hardcode.
// Chọn gợi ý → điền tên + dấu phẩy vào chính input comma-separated cũ —
// giá trị submit không đổi nên Filter/Service (ensureByName) giữ nguyên luồng.
(() => {
    const input = document.getElementById('f-tags');
    if (!input || !input.dataset.tagSearch) {
        return;
    }

    let panel = null;
    let active = -1;
    let timer = null;
    let seq = 0;

    function parts() {
        return input.value.split(',');
    }

    function takenNames() {
        return parts()
            .slice(0, -1)
            .map((s) => s.trim().toLowerCase())
            .filter(Boolean);
    }

    function close() {
        if (panel) {
            panel.remove();
            panel = null;
        }
        active = -1;
    }

    function highlight() {
        if (!panel) {
            return;
        }
        Array.prototype.forEach.call(panel.children, (el, i) => {
            el.classList.toggle('active', i === active);
        });
    }

    function complete(name) {
        const head = parts().slice(0, -1).join(',');
        input.value = (head === '' ? '' : head + ',') + ' ' + name + ', ';
        close();
        input.focus();
    }

    function open(names, fragment) {
        close();
        panel = document.createElement('div');
        panel.className = 'tag-ac';
        const lower = fragment.toLowerCase();
        const taken = takenNames();
        names.forEach((tag) => {
            const name = String(tag.name || '');
            const key = name.toLowerCase();
            if (!name || key === lower || taken.indexOf(key) !== -1) {
                return;
            }
            const row = document.createElement('div');
            row.className = 'tag-ac-item';
            const label = document.createElement('span');
            label.textContent = name;
            row.appendChild(label);
            if (typeof tag.postCount === 'number') {
                const count = document.createElement('span');
                count.className = 'tag-ac-count';
                count.textContent = tag.postCount + ' bài';
                row.appendChild(count);
            }
            // mousedown + preventDefault: chọn trước khi blur đóng panel, giữ focus input.
            row.addEventListener('mousedown', (event) => {
                event.preventDefault();
                complete(name);
            });
            panel.appendChild(row);
        });
        if (!panel.children.length) {
            close();
            return;
        }
        const rect = input.getBoundingClientRect();
        panel.style.left = rect.left + 'px';
        panel.style.top = (rect.bottom + 4) + 'px';
        panel.style.width = rect.width + 'px';
        document.body.appendChild(panel);
    }

    function suggest() {
        const fragment = parts().pop().trim();
        if (fragment === '') {
            close();
            return;
        }
        const mine = ++seq;
        fetch(input.dataset.tagSearch + '?q=' + encodeURIComponent(fragment), {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then((response) => response.json())
            .then((envelope) => {
                if (mine !== seq) {
                    return;
                }
                const data = (envelope && envelope.data) || [];
                open(data.slice(0, 12), fragment);
            })
            .catch(() => {
                /* mất mạng/API lỗi → để người dùng gõ tự do */
            });
    }

    input.addEventListener('input', () => {
        window.clearTimeout(timer);
        timer = window.setTimeout(suggest, 200);
    });

    input.addEventListener('blur', close);

    input.addEventListener('keydown', (event) => {
        if (!panel) {
            return;
        }
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const max = panel.children.length - 1;
            active = event.key === 'ArrowDown'
                ? (active >= max ? 0 : active + 1)
                : (active <= 0 ? max : active - 1);
            highlight();
        } else if (event.key === 'Enter' && active >= 0) {
            event.preventDefault();
            const name = panel.children[active].firstChild.textContent;
            complete(name);
        } else if (event.key === 'Escape') {
            close();
        }
    });
})();

// ========== LIGHTBOX ANH (nut.thumb-zoom[data-lightbox] -> xem anh to) ==========
// Delegated: mo overlay duoc dung tay khi click o [data-lightbox];
// data-full = URL anh lon, data-caption = chu thich. Dong: X / Esc / click nen.
(function () {
    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-lightbox]');
        if (!trigger) {
            return;
        }
        const overlay = document.createElement('div');
        overlay.className = 'img-lightbox';

        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'img-lightbox-close';
        closeBtn.setAttribute('aria-label', 'Đóng');
        closeBtn.textContent = '×';

        const fig = document.createElement('figure');
        const img = document.createElement('img');
        img.src = trigger.getAttribute('data-full') || '';
        img.alt = trigger.getAttribute('data-caption') || '';
        fig.appendChild(img);
        const caption = trigger.getAttribute('data-caption') || '';
        if (caption) {
            const cap = document.createElement('figcaption');
            cap.textContent = caption;
            fig.appendChild(cap);
        }

        overlay.appendChild(closeBtn);
        overlay.appendChild(fig);
        document.body.appendChild(overlay);

        function onKey(ev) {
            if (ev.key === 'Escape') {
                dismiss();
            }
        }
        function dismiss() {
            overlay.remove();
            document.removeEventListener('keydown', onKey);
        }
        closeBtn.addEventListener('click', dismiss);
        overlay.addEventListener('click', (ev) => {
            if (ev.target === overlay || ev.target === img) {
                dismiss();
            }
        });
        document.addEventListener('keydown', onKey);
    });
})();
