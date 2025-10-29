</main>
<footer class="site-footer">
    <p>&copy; <?php echo date('Y'); ?> <?php echo sanitize(SITE_TITLE); ?>. All rights reserved.</p>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" defer></script>
<?php
$themeJsFiles = [];

if (isset($GLOBALS['nx_theme_loaded_assets']['js']) && is_array($GLOBALS['nx_theme_loaded_assets']['js'])) {
    $themeJsFiles = $GLOBALS['nx_theme_loaded_assets']['js'];
}

foreach ($themeJsFiles as $scriptUrl):
?>
    <script src="<?php echo sanitize($scriptUrl); ?>" defer></script>
<?php endforeach; ?>
<script src="<?php echo sanitize(base_url('assets/js/app.js')); ?>" defer></script>
<?php if (function_exists('theme_footer')): ?>
    <?php theme_footer(); ?>
<?php endif; ?>
<button class="btn btn-sm btn-warning position-fixed bottom-0 end-0 m-3" id="contrastToggle" style="z-index:9999">Contrast Safe</button>
<script>
document.getElementById('contrastToggle').addEventListener('click',()=>{
  document.body.classList.toggle('contrast-safe');
});
</script>
</body>
</html>
