        </main><!-- /.app-content -->
    </div><!-- /.app-main -->
</div><!-- /.app-shell -->

<!-- Toast container pour les messages flash -->
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index:1090;" id="flashToastContainer"></div>

<?php $flashMessages = $flash_captured ?? []; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script nonce="<?= h(csp_nonce()) ?>" src="<?= BASE_URL ?>assets/js/app.js"></script>
<script nonce="<?= h(csp_nonce()) ?>">
(function(){
    var msgs = <?= json_encode($flashMessages, JSON_UNESCAPED_UNICODE) ?>;
    if (!msgs || !msgs.length) return;
    var icons = {success:'check-circle-fill', danger:'exclamation-triangle-fill', warning:'exclamation-triangle-fill', info:'info-circle-fill'};
    var container = document.getElementById('flashToastContainer');
    msgs.forEach(function(f){
        var toastEl = document.createElement('div');
        toastEl.className = 'toast align-items-center text-bg-' + (f.type === 'danger' ? 'danger' : f.type) + ' border-0';
        toastEl.setAttribute('role','alert');
        toastEl.setAttribute('aria-live','assertive');
        toastEl.setAttribute('aria-atomic','true');
        toastEl.innerHTML =
            '<div class="d-flex">' +
              '<div class="toast-body"><i class="bi bi-' + (icons[f.type]||'bell') + ' me-2"></i>' + f.message + '</div>' +
              '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>' +
            '</div>';
        container.appendChild(toastEl);
        var toast = new bootstrap.Toast(toastEl, {delay: 5000});
        toast.show();
        toastEl.addEventListener('hidden.bs.toast', function(){ toastEl.remove(); });
    });
})();
</script>
<script nonce="<?= h(csp_nonce()) ?>">
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('<?= BASE_URL ?>sw.js').then(function(reg) {
        reg.addEventListener('updatefound', function() {
            var newSW = reg.installing;
            if (newSW) {
                newSW.addEventListener('statechange', function() {
                    if (newSW.state === 'activated' && navigator.serviceWorker.controller) {
                        window.location.reload();
                    }
                });
            }
        });
    }).catch(function(err){
        console.warn('Service Worker registration failed:', err);
    });
}
window.addEventListener('unhandledrejection', function(e) {
    console.error('Unhandled promise rejection:', e.reason);
});
</script>
</body>
</html>
