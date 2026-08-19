    </main>
    </div> <!-- /#page-content-wrapper -->
</div> <!-- /#wrapper -->

<!-- Apply the response nonce to controlled runtime style elements before third-party UI libraries load. -->
<script src="assets/js/sweetalert-csp.js"></script>
<!-- Bootstrap 5 Bundle JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="<?php echo htmlspecialchars(get_asset_integrity('https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js'), ENT_QUOTES, 'UTF-8'); ?>" crossorigin="anonymous"></script>
<?php if (isset($extra_js)): ?>
    <?php foreach ($extra_js as $js_url): ?>
        <?php $js_integrity = get_asset_integrity($js_url); ?>
        <script src="<?php echo htmlspecialchars($js_url, ENT_QUOTES, 'UTF-8'); ?>"<?php if ($js_integrity !== null): ?> integrity="<?php echo htmlspecialchars($js_integrity, ENT_QUOTES, 'UTF-8'); ?>" crossorigin="anonymous"<?php endif; ?>></script>
    <?php endforeach; ?>
<?php endif; ?>
<!-- Custom JS -->
<script src="assets/js/script.js"></script>
</body>
</html>
