(function () {
    'use strict';

    var button = document.querySelector('.hamburger');
    var menu = document.getElementById('mobile-menu');
    if (!button || !menu) {
        return;
    }

    var closeMenu = function () {
        button.setAttribute('aria-expanded', 'false');
        button.setAttribute('aria-label', 'Mở menu');
        button.classList.remove('is-open');
        menu.classList.remove('is-open');
        menu.hidden = true;
    };

    var openMenu = function () {
        button.setAttribute('aria-expanded', 'true');
        button.setAttribute('aria-label', 'Đóng menu');
        button.classList.add('is-open');
        menu.hidden = false;
        menu.classList.add('is-open');
    };

    button.addEventListener('click', function () {
        if (button.getAttribute('aria-expanded') === 'true') {
            closeMenu();
            return;
        }

        openMenu();
    });

    menu.addEventListener('click', function (event) {
        if (event.target.closest('a')) {
            closeMenu();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeMenu();
        }
    });

    window.addEventListener('resize', function () {
        if (window.innerWidth > 1100) {
            closeMenu();
        }
    });
}());
