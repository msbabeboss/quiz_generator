<?php
require_once __DIR__ . '/config/app.php';
// Always redirect to the landing page
header('Location: ' . APP_BASE . '/index.html');
exit;
