/*
 * Hero slider trang chủ (FR-32) — vanilla JS tối giản, tự động theo
 * data-autoplay/data-interval của .main-slider (HomeService đổ config
 * hero_banner từ CMS). Không kéo thả, không thư viện ngoài.
 */
(function () {
    'use strict';

    var sliders = document.querySelectorAll('.main-slider');

    Array.prototype.forEach.call(sliders, function (root) {
        var track = root.querySelector('.slider-track');
        if (!track) {
            return;
        }
        var slides = track.children;
        if (slides.length < 2) {
            return;
        }
        var dots = root.querySelectorAll('.slider-dots .dot');
        var index = 0;
        var timer = null;
        var interval = Math.max(1000, parseInt(root.getAttribute('data-interval'), 10) || 5000);

        var go = function (next) {
            index = (next + slides.length) % slides.length;
            track.style.transform = 'translateX(-' + index * 100 + '%)';
            Array.prototype.forEach.call(dots, function (dot, i) {
                dot.classList.toggle('active', i === index);
            });
        };

        var prev = root.querySelector('.slider-arrow.prev');
        var next = root.querySelector('.slider-arrow.next');
        if (prev) {
            prev.addEventListener('click', function () { go(index - 1); restart(); });
        }
        if (next) {
            next.addEventListener('click', function () { go(index + 1); restart(); });
        }
        Array.prototype.forEach.call(dots, function (dot, i) {
            dot.addEventListener('click', function () { go(i); restart(); });
        });

        var restart = function () {
            if (timer) {
                clearInterval(timer);
                timer = null;
            }
            if (root.getAttribute('data-autoplay') === '1') {
                timer = setInterval(function () { go(index + 1); }, interval);
            }
        };

        restart();
    });
})();
