/* =================================================================
   app.js - Interactions globales (Vanilla JS) — Futuristic
   ================================================================= */

// Les conteneurs de page utilisent parfois transform/backdrop-filter, qui
// créent des contextes d'empilement. Les modales Bootstrap doivent rester
// directement sous body pour passer au-dessus du backdrop.
document.querySelectorAll('.app-shell .modal').forEach(function (modal) {
    document.body.appendChild(modal);
});

document.addEventListener('DOMContentLoaded', function () {
    // Particules discrètes sur le fond clair.
    var canvas = document.getElementById('particleCanvas');
    if (canvas && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        var ctx = canvas.getContext('2d');
        var particles = [];
        var count = Math.min(58, Math.floor(window.innerWidth / 25));
        function resizeCanvas() {
            canvas.width = window.innerWidth;
            canvas.height = window.innerHeight;
        }
        resizeCanvas();
        window.addEventListener('resize', resizeCanvas);
        for (var i = 0; i < count; i++) {
            particles.push({
                x: Math.random() * canvas.width,
                y: Math.random() * canvas.height,
                vx: (Math.random() - .5) * .25,
                vy: (Math.random() - .5) * .25,
                r: Math.random() * 1.6 + .9
            });
        }
        (function drawParticles() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            particles.forEach(function (p, index) {
                p.x += p.vx; p.y += p.vy;
                if (p.x < 0 || p.x > canvas.width) p.vx *= -1;
                if (p.y < 0 || p.y > canvas.height) p.vy *= -1;
                ctx.beginPath();
                ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
                ctx.fillStyle = 'rgba(21, 91, 208, .38)';
                ctx.fill();
                for (var j = index + 1; j < particles.length; j++) {
                    var q = particles[j], dx = p.x - q.x, dy = p.y - q.y;
                    var distance = Math.sqrt(dx * dx + dy * dy);
                    if (distance < 125) {
                        ctx.beginPath(); ctx.moveTo(p.x, p.y); ctx.lineTo(q.x, q.y);
                        ctx.strokeStyle = 'rgba(21, 91, 208, ' + ((1 - distance / 125) * .17) + ')';
                        ctx.stroke();
                    }
                }
            });
            requestAnimationFrame(drawParticles);
        })();
    }

    // ----- Navigation mobile : ouverture / fermeture -----
    var sidebar  = document.getElementById('sidebar');
    var backdrop = document.getElementById('sidebarBackdrop');
    var burger   = document.getElementById('burger');
    var closeNavTimer = null;

    function openSidebar() {
        window.clearTimeout(closeNavTimer);
        if (sidebar) {
            sidebar.classList.add('open');
            requestAnimationFrame(function () { sidebar.classList.add('is-visible'); });
        }
        if (burger) burger.setAttribute('aria-expanded', 'true');
        if (backdrop) {
            backdrop.style.display = 'block';
            requestAnimationFrame(function () {
                backdrop.classList.add('visible');
            });
        }
        document.body.style.overflow = 'hidden';
    }

    function closeSidebar() {
        window.clearTimeout(closeNavTimer);
        if (sidebar) {
            sidebar.classList.remove('is-visible');
            closeNavTimer = setTimeout(function () { sidebar.classList.remove('open'); }, 220);
        }
        if (burger) burger.setAttribute('aria-expanded', 'false');
        if (backdrop) {
            backdrop.classList.remove('visible');
            setTimeout(function () {
                backdrop.style.display = 'none';
            }, 300);
        }
        document.body.style.overflow = '';
    }

    if (burger) {
        burger.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (sidebar && sidebar.classList.contains('is-visible')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        });
    }

    if (backdrop) {
        backdrop.addEventListener('click', function () {
            closeSidebar();
        });
    }

    var prevWidth = window.innerWidth;
    window.addEventListener('resize', function () {
        var w = window.innerWidth;
        if (prevWidth <= 991 && w > 991) {
            closeSidebar();
        }
        prevWidth = w;
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            closeSidebar();
            navSections.forEach(function (section) {
                section.classList.remove('open');
                var head = section.querySelector('.nav-section-head');
                if (head) head.setAttribute('aria-expanded', 'false');
            });
        }
    });

    // ----- Menus de navigation : ouverture exclusive -----
    var navSections  = document.querySelectorAll('.nav-section');

    navSections.forEach(function (section) {
        var open = section.classList.contains('has-active');
        section.classList.toggle('open', open);

        var head = section.querySelector('.nav-section-head');
        if (!head) return;
        head.setAttribute('aria-expanded', open ? 'true' : 'false');

        head.addEventListener('click', function () {
            var wasOpen = section.classList.contains('open');
            navSections.forEach(function (otherSection) {
                otherSection.classList.remove('open');
                var otherHead = otherSection.querySelector('.nav-section-head');
                if (otherHead) otherHead.setAttribute('aria-expanded', 'false');
            });
            var now = !wasOpen;
            section.classList.toggle('open', now);
            head.setAttribute('aria-expanded', now ? 'true' : 'false');
        });
    });

    document.addEventListener('click', function (e) {
        if (sidebar && !sidebar.contains(e.target)) {
            navSections.forEach(function (section) {
                section.classList.remove('open');
                var head = section.querySelector('.nav-section-head');
                if (head) head.setAttribute('aria-expanded', 'false');
            });
        }
    });

    if (sidebar) {
        sidebar.querySelectorAll('.nav-link, .nav-home').forEach(function (link) {
            link.addEventListener('click', function () {
                if (window.innerWidth <= 991) closeSidebar();
            });
        });
    }

    // ----- Auto-focus -----
    var focusEl = document.querySelector('[data-autofocus]');
    if (focusEl) focusEl.focus();

    // ----- Tables filtrables (recherche instantanée) -----
    var filterInputs = document.querySelectorAll('input[data-filter-table]');
    for (var i = 0; i < filterInputs.length; i++) {
        (function (input) {
            input.addEventListener('keyup', function () {
                var target = document.getElementById(input.dataset.filterTable);
                if (!target) return;
                var term = input.value.toLowerCase();
                var rows = target.querySelectorAll('tbody tr');
                for (var j = 0; j < rows.length; j++) {
                    var txt = rows[j].textContent.toLowerCase();
                    rows[j].style.display = txt.indexOf(term) !== -1 ? '' : 'none';
                }
            });
        })(filterInputs[i]);
    }

    // ----- Confirmations (CSP-safe) -----
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (!confirm(form.getAttribute('data-confirm'))) e.preventDefault();
        });
    });
    document.querySelectorAll('[data-confirm-click]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            if (!confirm(el.getAttribute('data-confirm-click'))) {
                e.preventDefault();
                e.stopPropagation();
            }
        });
    });

    // Select : data-auto-submit
    document.querySelectorAll('select[data-auto-submit]').forEach(function (sel) {
        sel.addEventListener('change', function () {
            if (sel.form) sel.form.submit();
        });
    });

    // ----- Auto-dismiss des alertes -----
    var alerts = document.querySelectorAll('.alert-success:not([data-persistent]), .alert-info:not([data-persistent])');
    for (var k = 0; k < alerts.length; k++) {
        (function (el) {
            setTimeout(function () {
                el.style.transition = 'opacity .5s, transform .5s';
                el.style.opacity = '0';
                el.style.transform = 'translateY(-10px)';
                setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 520);
            }, 4000);
        })(alerts[k]);
    }

    // =================================================================
    // ANIMATIONS FUTURISTES — IntersectionObserver
    // =================================================================

    // Animate elements on scroll
    var animateElements = document.querySelectorAll('.card, .stat-card, .glass-card, .glass-badge, .table, .dashboard-hero');
    animateElements.forEach(function(el) {
        el.classList.add('animate-on-scroll');
    });

    if ('IntersectionObserver' in window) {
        var observer = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.1, rootMargin: '0px 0px -40px 0px' });

        document.querySelectorAll('.animate-on-scroll').forEach(function(el) {
            observer.observe(el);
        });
    } else {
        // Fallback: show all immediately
        document.querySelectorAll('.animate-on-scroll').forEach(function(el) {
            el.classList.add('is-visible');
        });
    }

    // =================================================================
    // STAT COUNTER ANIMATION
    // =================================================================
    function animateCounter(el) {
        var target = parseFloat(el.getAttribute('data-count'));
        if (isNaN(target)) return;
        var duration = 1200;
        var start = 0;
        var startTime = null;
        var isDecimal = target % 1 !== 0;

        function step(timestamp) {
            if (!startTime) startTime = timestamp;
            var progress = Math.min((timestamp - startTime) / duration, 1);
            var eased = 1 - Math.pow(1 - progress, 3); // easeOutCubic
            var current = start + (target - start) * eased;
            el.textContent = isDecimal ? current.toFixed(2) : Math.floor(current).toLocaleString('fr-FR');
            if (progress < 1) {
                requestAnimationFrame(step);
            } else {
                el.textContent = isDecimal ? target.toFixed(2) : Math.floor(target).toLocaleString('fr-FR');
            }
        }
        requestAnimationFrame(step);
    }

    // Observe stat values for counter animation
    if ('IntersectionObserver' in window) {
        var statObserver = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    animateCounter(entry.target);
                    statObserver.unobserve(entry.target);
                }
            });
        }, { threshold: 0.5 });

        document.querySelectorAll('.stat-value[data-count]').forEach(function(el) {
            statObserver.observe(el);
        });
    }

    // =================================================================
    // RIPPLE EFFECT ON BUTTONS
    // =================================================================
    document.querySelectorAll('.btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            var rect = btn.getBoundingClientRect();
            var ripple = document.createElement('span');
            var size = Math.max(rect.width, rect.height);
            ripple.style.cssText = 'position:absolute;border-radius:50%;pointer-events:none;width:' + size + 'px;height:' + size + 'px;left:' + (e.clientX - rect.left - size/2) + 'px;top:' + (e.clientY - rect.top - size/2) + 'px;background:rgba(255,255,255,0.3);transform:scale(0);animation:rippleEffect .6s ease-out forwards;';
            btn.style.position = 'relative';
            btn.style.overflow = 'hidden';
            btn.appendChild(ripple);
            setTimeout(function() { ripple.remove(); }, 700);
        });
    });

    // Add ripple keyframes dynamically
    var style = document.createElement('style');
    style.textContent = '@keyframes rippleEffect{to{transform:scale(4);opacity:0;}}';
    document.head.appendChild(style);

    // =================================================================
    // GLOW TRAIL ON SIDEBAR HOVER
    // =================================================================
    if (sidebar) {
        sidebar.addEventListener('mousemove', function(e) {
            var rect = sidebar.getBoundingClientRect();
            sidebar.style.setProperty('--mouse-x', (e.clientX - rect.left) + 'px');
            sidebar.style.setProperty('--mouse-y', (e.clientY - rect.top) + 'px');
        });
    }

    // =================================================================
    // SMOOTH PAGE TRANSITIONS
    // =================================================================
    var appMain = document.getElementById('appMain');
    if (appMain) {
        appMain.style.opacity = '0';
        appMain.style.transform = 'translateY(12px)';
        requestAnimationFrame(function() {
            appMain.style.transition = 'opacity .5s cubic-bezier(.22,1,.36,1), transform .5s cubic-bezier(.22,1,.36,1)';
            appMain.style.opacity = '1';
            appMain.style.transform = 'translateY(0)';
        });
    }
});

// Helper global
function confirmerEtAller(message, url) {
    if (confirm(message)) window.location.href = url;
}
