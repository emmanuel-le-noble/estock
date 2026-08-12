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

    document.addEventListener('DOMContentLoaded', function () {
    // ----- Sous-menus déroulants sidebar (accordion avec persistance localStorage) -----
    const STORAGE_KEY = 'estock_sidebar_open';

    function getSavedGroup() {
        try { 
            return localStorage.getItem(STORAGE_KEY) || ''; 
        } catch (e) { 
            return ''; 
        }
    }

    function saveGroup(group) {
        try { 
            localStorage.setItem(STORAGE_KEY, group || ''); 
        } catch (e) {}
    }

    function openGroup(toggle, submenu, parent) {
        toggle.setAttribute('aria-expanded', 'true');
        if (submenu) {
            // Si vous utilisez inline max-height en CSS :
            submenu.style.maxHeight = submenu.scrollHeight + 'px';
        }
        if (parent) {
            parent.classList.add('active');
        }
    }

    function closeGroup(toggle, submenu, parent) {
        toggle.setAttribute('aria-expanded', 'false');
        if (submenu) {
            submenu.style.maxHeight = null;
        }
        if (parent) {
            parent.classList.remove('active');
        }
    }

    const dropdownToggles = document.querySelectorAll('.sidebar-dropdown-toggle');

    // Identifie le groupe de la page active si présent
    const activeInGroup = document.querySelector('.sidebar-submenu .nav-link.active');
    let activeGroupName = '';

    if (activeInGroup) {
        const activeParentGroup = activeInGroup.closest('.nav-group');
        if (activeParentGroup) {
            const activeToggle = activeParentGroup.querySelector('.sidebar-dropdown-toggle');
            activeGroupName = activeToggle ? (activeToggle.getAttribute('data-group') || '') : '';
        }
    }

    const savedGroup = getSavedGroup();

    // Initialisation et événements des menus
    dropdownToggles.forEach(function (toggle) {
        const parent = toggle.closest('.nav-group');
        const submenu = parent ? parent.querySelector('.sidebar-submenu') : null;
        const groupName = toggle.getAttribute('data-group') || '';

        // Déterminer si ce groupe doit être ouvert au chargement
        let shouldOpen = false;
        if (activeGroupName && groupName === activeGroupName) {
            shouldOpen = true; // Page active prioritaire
        } else if (!activeGroupName && savedGroup && groupName === savedGroup) {
            shouldOpen = true; // Restaurer la persistance si pas de page active
        }

        if (shouldOpen) {
            openGroup(toggle, submenu, parent);
        }

        toggle.addEventListener('click', function (e) {
            e.preventDefault();
            const isOpen = toggle.getAttribute('aria-expanded') === 'true';

            // Accordion : fermer tous les autres groupes
            dropdownToggles.forEach(function (otherToggle) {
                if (otherToggle !== toggle) {
                    const otherParent = otherToggle.closest('.nav-group');
                    const otherSubmenu = otherParent ? otherParent.querySelector('.sidebar-submenu') : null;
                    closeGroup(otherToggle, otherSubmenu, otherParent);
                }
            });

            // Basculer l'état du groupe sélectionné
            if (isOpen) {
                closeGroup(toggle, submenu, parent);
                saveGroup('');
            } else {
                openGroup(toggle, submenu, parent);
                saveGroup(groupName);
            }
        });
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
