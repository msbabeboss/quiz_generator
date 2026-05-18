<?php
/**
 * includes/footer.php — Shared page footer include.
 *
 * Requirements: 8.1, 8.3, 9.1
 *
 * Outputs:
 *   - Bootstrap 5 JS bundle (CDN)
 *   - darkmode.js script tag (handles toggle button wiring on DOMContentLoaded)
 *   - Closing </body> and </html> tags
 *
 * Usage:
 *   require __DIR__ . '/../includes/footer.php';
 */

// Ensure APP_BASE is available.
if (!defined('APP_BASE')) {
    require_once __DIR__ . '/../config/app.php';
}

if (!function_exists('e')) {
    function e(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
?>

<!-- Bootstrap 5 JS bundle -->
<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
    integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmFXFMrWCU3FA0e3dbKJx/A45Bqp"
    crossorigin="anonymous"
></script>

<!-- Dark mode toggle wiring (DOMContentLoaded listener inside darkmode.js) -->
<script src="<?= e(APP_BASE) ?>/assets/js/darkmode.js"></script>

</body>
</html>
