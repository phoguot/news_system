// ========== NAVIGATION ==========
function showPage(pageId) {
  // Hide all pages
  document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
  // Show target
  const target = document.getElementById('page-' + pageId);
  if (target) target.classList.add('active');

  // Update nav
  document.querySelectorAll('.nav-item').forEach(item => {
    item.classList.toggle('active', item.dataset.page === pageId);
  });

  // Close sidebar on mobile
  closeSidebar();

  // Scroll to top
  document.querySelector('.content').scrollTop = 0;
}

function showCreatePost() {
  showPage('create-post');
  document.querySelectorAll('.nav-item').forEach(item => {
    item.classList.toggle('active', item.dataset.page === 'posts');
  });
}

// ========== NAV LINKS ==========
document.querySelectorAll('.nav-item').forEach(item => {
  item.addEventListener('click', e => {
    e.preventDefault();
    const page = item.dataset.page;
    if (page) showPage(page);
  });
});

// "Xem tất cả" link
document.querySelectorAll('.view-all[data-page]').forEach(link => {
  link.addEventListener('click', e => {
    e.preventDefault();
    showPage(link.dataset.page);
  });
});

// ========== SIDEBAR TOGGLE ==========
const sidebar = document.getElementById('sidebar');
const menuToggle = document.getElementById('menuToggle');

// Create overlay
const overlay = document.createElement('div');
overlay.className = 'sidebar-overlay';
document.body.appendChild(overlay);

function openSidebar() {
  sidebar.classList.add('open');
  overlay.classList.add('active');
}

function closeSidebar() {
  sidebar.classList.remove('open');
  overlay.classList.remove('active');
}

menuToggle.addEventListener('click', () => {
  if (sidebar.classList.contains('open')) closeSidebar();
  else openSidebar();
});

overlay.addEventListener('click', closeSidebar);

// ========== MEDIA TABS ==========
document.querySelectorAll('.media-tab').forEach(tab => {
  tab.addEventListener('click', () => {
    document.querySelectorAll('.media-tab').forEach(t => t.classList.remove('active'));
    tab.classList.add('active');
  });
});

// ========== SETTINGS TABS ==========
document.querySelectorAll('.settings-tab').forEach(tab => {
  tab.addEventListener('click', () => {
    document.querySelectorAll('.settings-tab').forEach(t => t.classList.remove('active'));
    tab.classList.add('active');
  });
});

// ========== BAR CHART ANIMATION ==========
window.addEventListener('load', () => {
  const bars = document.querySelectorAll('.bar');
  bars.forEach((bar, i) => {
    const h = bar.style.height;
    bar.style.height = '0';
    setTimeout(() => {
      bar.style.transition = 'height .5s ease';
      bar.style.height = h;
    }, 100 + i * 50);
  });
});

// ========== CHAR COUNTER ==========
const textarea = document.querySelector('.form-group .form-textarea');
const charCount = document.querySelector('.char-count');
if (textarea && charCount) {
  textarea.addEventListener('input', () => {
    charCount.textContent = `${textarea.value.length}/200`;
  });
}

// ========== TABLE CHECKBOXES ==========
document.querySelectorAll('th input[type="checkbox"]').forEach(th => {
  th.addEventListener('change', () => {
    const table = th.closest('table');
    table.querySelectorAll('td input[type="checkbox"]').forEach(td => {
      td.checked = th.checked;
    });
  });
});
