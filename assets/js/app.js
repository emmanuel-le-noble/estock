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

    // ----- Sous-menus déroulants sidebar (accordion) -----
    var dropdownToggles = document.querySelectorAll('.sidebar-dropdown-toggle');
    for (var i = 0; i < dropdownToggles.length; i++) {
        dropdownToggles[i].addEventListener('click', function (e) {
            e.preventDefault();
            var parent = this.closest('.nav-group');
            var submenu = parent ? parent.querySelector('.sidebar-submenu') : null;
            var isOpen = this.getAttribute('aria-expanded') === 'true';

            // Fermer les autres groupes ouverts (accordion)
            var allToggles = document.querySelectorAll('.sidebar-dropdown-toggle');
            for (var j = 0; j < allToggles.length; j++) {
                if (allToggles[j] !== this) {
                    var otherParent = allToggles[j].closest('.nav-group');
                    var otherSubmenu = otherParent ? otherParent.querySelector('.sidebar-submenu') : null;
                    allToggles[j].setAttribute('aria-expanded', 'false');
                    if (otherSubmenu) otherSubmenu.style.maxHeight = null;
                    if (otherParent) otherParent.classList.remove('active');
                }
            }

            if (isOpen) {
                // FERMETURE
                this.setAttribute('aria-expanded', 'false');
                if (submenu) submenu.style.maxHeight = null;
                if (parent) parent.classList.remove('active');
            } else {
                // OUVERTURE
                this.setAttribute('aria-expanded', 'true');
                if (submenu) submenu.style.maxHeight = submenu.scrollHeight + 'px';
                if (parent) parent.classList.add('active');
            }
        });
    }
    // Auto-ouvrir le groupe contenant la page active
    var activeInGroup = document.querySelector('.sidebar-submenu .nav-link.active');
    if (activeInGroup) {
        var parentGroup = activeInGroup.closest('.nav-group');
        if (parentGroup) {
            parentGroup.classList.add('active');
            var submenuEl = parentGroup.querySelector('.sidebar-submenu');
            var toggleBtn = parentGroup.querySelector('.sidebar-dropdown-toggle');
            if (submenuEl) submenuEl.style.maxHeight = submenuEl.scrollHeight + 'px';
            if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'true');
        }
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
