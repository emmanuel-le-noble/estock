/* =================================================================
   app.js - Interactions globales (Vanilla JS)
   ================================================================= */

document.addEventListener('DOMContentLoaded', function () {

    // ----- Sidebar mobile : ouverture / fermeture -----
    var sidebar  = document.getElementById('sidebar');
    var backdrop = document.getElementById('sidebarBackdrop');
    var burger   = document.getElementById('burger');

    function openSidebar() {
        if (sidebar) sidebar.classList.add('open');
        if (backdrop) {
            backdrop.style.display = 'block';
            // Petit délai pour que le navigateur prenne en compte display:block
            // avant d'appliquer l'opacité (sinon pas de transition)
            requestAnimationFrame(function () {
                backdrop.classList.add('visible');
            });
        }
        // Empêcher le scroll du body quand la sidebar est ouverte
        document.body.style.overflow = 'hidden';
    }

    function closeSidebar() {
        if (sidebar) sidebar.classList.remove('open');
        if (backdrop) {
            backdrop.classList.remove('visible');
            // Attendre la fin de la transition opacity avant de cacher
            setTimeout(function () {
                backdrop.style.display = 'none';
            }, 260);
        }
        document.body.style.overflow = '';
    }

    if (burger) {
        burger.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (sidebar && sidebar.classList.contains('open')) {
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

    // Fermer la sidebar si on passe en desktop
    var prevWidth = window.innerWidth;
    window.addEventListener('resize', function () {
        var w = window.innerWidth;
        // Détecter un changement de breakpoint (mobile → desktop)
        if (prevWidth <= 991 && w > 991) {
            closeSidebar();
        }
        prevWidth = w;
    });

    // ESC pour fermer
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeSidebar();
    });

    // ----- Sidebar : sections pliables / dépliables -----
    // L'état est mémorisé dans localStorage ; une section contenant la page
    // active est toujours ouverte.
    var navSections  = document.querySelectorAll('.nav-section');
    var collapsedMap = {};
    try { collapsedMap = JSON.parse(localStorage.getItem('eStock_side_collapsed') || '{}') || {}; }
    catch (e) { collapsedMap = {}; }

    navSections.forEach(function (section) {
        var key = section.getAttribute('data-section') || '';
        var hasActive = !!section.querySelector('.nav-link.active');
        // Prioriser l'état PHP (classe open déjà dans le HTML) :
        // si la section a déjà la classe open côté serveur, la conserver
        // sauf si l'utilisateur l'a explicitement pliée (stocké en localStorage).
        var serverOpen = section.classList.contains('open');
        var open;
        if (hasActive) {
            // La page active est dans cette section → toujours ouverte
            open = true;
        } else if (collapsedMap.hasOwnProperty(key)) {
            // L'utilisateur a explicitement plié/déplié cette section → respecter
            open = collapsedMap[key] !== true;
        } else {
            // Pas d'historique localStorage → respecter l'état PHP
            open = serverOpen;
        }
        section.classList.toggle('open', open);

        var head = section.querySelector('.nav-section-head');
        if (!head) return;
        head.setAttribute('aria-expanded', open ? 'true' : 'false');

        head.addEventListener('click', function () {
            var now = section.classList.toggle('open');
            head.setAttribute('aria-expanded', now ? 'true' : 'false');
            collapsedMap[key] = !now;
            try { localStorage.setItem('eStock_side_collapsed', JSON.stringify(collapsedMap)); }
            catch (e) { /* stockage indisponible : on ignore */ }
        });
    });

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

    // ----- Confirmations (CSP-safe : pas de handlers inline) -----
    // Formulaire : data-confirm="Message ?"
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (!confirm(form.getAttribute('data-confirm'))) e.preventDefault();
        });
    });
    // Élément cliquable : data-confirm-click="Message ?"
    document.querySelectorAll('[data-confirm-click]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            if (!confirm(el.getAttribute('data-confirm-click'))) {
                e.preventDefault();
                e.stopPropagation();
            }
        });
    });

    // Select : data-auto-submit (soumission auto au changement)
    document.querySelectorAll('select[data-auto-submit]').forEach(function (sel) {
        sel.addEventListener('change', function () {
            if (sel.form) sel.form.submit();
        });
    });

    // ----- Auto-dismiss des alertes "success" / "info" -----
    var alerts = document.querySelectorAll('.alert-success, .alert-info');
    for (var k = 0; k < alerts.length; k++) {
        (function (el) {
            setTimeout(function () {
                el.style.transition = 'opacity .4s';
                el.style.opacity = '0';
                setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 420);
            }, 4000);
        })(alerts[k]);
    }
});

// Helper global
function confirmerEtAller(message, url) {
    if (confirm(message)) window.location.href = url;
}
