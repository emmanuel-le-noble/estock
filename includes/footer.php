        </main><!-- /.app-content -->
    </div><!-- /.app-main -->
</div><!-- /.app-shell -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script nonce="<?= h(csp_nonce()) ?>" src="<?= BASE_URL ?>assets/js/app.js"></script>
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
    }).catch(function(){});
}
</script>
</body>
</html>
