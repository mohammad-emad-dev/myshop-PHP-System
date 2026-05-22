    </div> <!-- /#page-content-wrapper -->
</div> <!-- /#wrapper -->

<!-- Bootstrap 5 Bundle JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<?php if (isset($extra_js)): ?>
    <?php foreach ($extra_js as $js_url): ?>
        <script src="<?php echo htmlspecialchars($js_url); ?>"></script>
    <?php endforeach; ?>
<?php endif; ?>
<!-- Custom JS -->
<script src="assets/script.js"></script>
<script>
    var el = document.getElementById("wrapper");
    var toggleButton = document.getElementById("menu-toggle");

    if (toggleButton && el) {
        toggleButton.onclick = function () {
            el.classList.toggle("toggled");
        };
    }
</script>
</body>
</html>
