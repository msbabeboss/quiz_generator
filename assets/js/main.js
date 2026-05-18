/**
 * assets/js/main.js
 * Landing page interactivity — navbar scroll, mobile menu,
 * scroll-reveal animations, animated counters, quiz mockup demo.
 */

(function () {
    'use strict';

    /* =========================================================
       1. Navbar — shrink on scroll
       ========================================================= */
    const navbar = document.getElementById('navbar');

    function handleNavbarScroll() {
        if (!navbar) return;
        if (window.scrollY > 40) {
            navbar.classList.add('scrolled');
        } else {
            navbar.classList.remove('scrolled');
        }
    }

    window.addEventListener('scroll', handleNavbarScroll, { passive: true });
    handleNavbarScroll(); // run once on load

    /* =========================================================
       2. Mobile menu
       ========================================================= */
    const navToggle  = document.getElementById('nav-toggle');
    const navMobile  = document.getElementById('nav-mobile');
    const navMobileClose = document.getElementById('nav-mobile-close');

    function openMobileMenu() {
        if (!navMobile) return;
        navMobile.classList.add('open');
        navToggle && navToggle.classList.add('open');
        document.body.style.overflow = 'hidden';
    }

    function closeMobileMenu() {
        if (!navMobile) return;
        navMobile.classList.remove('open');
        navToggle && navToggle.classList.remove('open');
        document.body.style.overflow = '';
    }

    navToggle     && navToggle.addEventListener('click', openMobileMenu);
    navMobileClose && navMobileClose.addEventListener('click', closeMobileMenu);

    // Close on any link click inside mobile menu
    navMobile && navMobile.querySelectorAll('a').forEach(function (link) {
        link.addEventListener('click', closeMobileMenu);
    });

    // Close on Escape key
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeMobileMenu();
    });

    /* =========================================================
       3. Scroll-reveal (IntersectionObserver)
       ========================================================= */
    const revealEls = document.querySelectorAll('.reveal');

    if ('IntersectionObserver' in window && revealEls.length) {
        const revealObserver = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');
                    revealObserver.unobserve(entry.target);
                }
            });
        }, { threshold: 0.12 });

        revealEls.forEach(function (el) { revealObserver.observe(el); });
    } else {
        // Fallback: show all immediately
        revealEls.forEach(function (el) { el.classList.add('visible'); });
    }

    /* =========================================================
       4. Animated counters
       ========================================================= */
    function animateCounter(el) {
        const target   = parseInt(el.dataset.target, 10);
        const suffix   = el.dataset.suffix || '';
        const duration = 1800; // ms
        const start    = performance.now();

        function step(now) {
            const elapsed  = now - start;
            const progress = Math.min(elapsed / duration, 1);
            // Ease-out cubic
            const eased    = 1 - Math.pow(1 - progress, 3);
            const current  = Math.round(eased * target);
            el.textContent = current.toLocaleString() + suffix;
            if (progress < 1) requestAnimationFrame(step);
        }

        requestAnimationFrame(step);
    }

    const counterEls = document.querySelectorAll('[data-target]');

    if ('IntersectionObserver' in window && counterEls.length) {
        const counterObserver = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    animateCounter(entry.target);
                    counterObserver.unobserve(entry.target);
                }
            });
        }, { threshold: 0.5 });

        counterEls.forEach(function (el) { counterObserver.observe(el); });
    }

    /* =========================================================
       5. Quiz mockup — cycle through options on click
       ========================================================= */
    const mockupOptions = document.querySelectorAll('.mockup-option');

    mockupOptions.forEach(function (opt) {
        opt.addEventListener('click', function () {
            // Deselect all
            mockupOptions.forEach(function (o) {
                o.classList.remove('selected');
                o.querySelector('.option-dot') &&
                    o.querySelector('.option-dot').classList.remove('filled');
            });
            // Select clicked
            opt.classList.add('selected');
            opt.querySelector('.option-dot') &&
                opt.querySelector('.option-dot').classList.add('filled');
        });
    });

    /* =========================================================
       6. Smooth scroll for anchor links
       ========================================================= */
    document.querySelectorAll('a[href^="#"]').forEach(function (anchor) {
        anchor.addEventListener('click', function (e) {
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                e.preventDefault();
                const offset = navbar ? navbar.offsetHeight + 16 : 80;
                const top    = target.getBoundingClientRect().top + window.scrollY - offset;
                window.scrollTo({ top: top, behavior: 'smooth' });
            }
        });
    });

    /* =========================================================
       7. Active nav link highlight on scroll
       ========================================================= */
    const sections  = document.querySelectorAll('section[id]');
    const navAnchors = document.querySelectorAll('.nav-links a[href^="#"]');

    function updateActiveLink() {
        let current = '';
        sections.forEach(function (section) {
            const sectionTop = section.offsetTop - 120;
            if (window.scrollY >= sectionTop) {
                current = '#' + section.id;
            }
        });

        navAnchors.forEach(function (a) {
            a.style.color = a.getAttribute('href') === current ? '#fff' : '';
            a.style.background = a.getAttribute('href') === current
                ? 'rgba(255,255,255,0.07)' : '';
        });
    }

    window.addEventListener('scroll', updateActiveLink, { passive: true });

}());
