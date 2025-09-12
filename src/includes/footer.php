<?php /* filepath: c:\xampp\htdocs\saku_santri\src\includes\footer.php */ ?>
<footer class="site-footer" id="appFooter">
  <p>© 2025 HIMATIF. All Rights Reserved. <span style="font-size:11px;opacity:.7"></span></p>
</footer>
<?php // Close main opened in header if not already closed by page templates ?>
<?php if(!defined('MAIN_CLOSED')): ?>
</main>
<?php endif; ?>
<script src="<?php echo url('assets/js/footer.js'); ?>?v=20250830a" defer></script>
<?php // bumped version to force reload after tab fix ?>
<script src="<?php echo url('assets/js/ui.js'); ?>?v=20250906b" defer></script>
</body>
</html>
