<?php
/**
 * teacher/sections.php — Legacy redirect to access-codes.php
 *
 * Kept for backward compatibility. All links pointing here are forwarded
 * transparently to the new unified access-codes.php page.
 */
require_once __DIR__ . '/../config/app.php';

$qs     = $_SERVER['QUERY_STRING'] ?? '';
$target = APP_BASE . '/teacher/access-codes.php' . ($qs !== '' ? '?' . $qs : '');
header('Location: ' . $target, true, 301);
exit;
