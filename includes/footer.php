        </main><!-- /.app-content -->
    </div><!-- /.app-main -->
</div><!-- /.app-shell -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script nonce="<?= h(csp_nonce()) ?>" src="<?= BASE_URL ?>assets/js/app.js"></script>
<script nonce="<?= h(csp_nonce()) ?>">
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('<?= BASE_URL ?>sw.js').catch(function(){});
}
</script>
</body>
</html>
