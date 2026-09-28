<?php
// Local CI router: PHP API plus the freshly compiled H5 history routes.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (is_file($_SERVER['DOCUMENT_ROOT'] . $path)) return false;
if (strpos($path, '/api/') === 0) {
    require __DIR__ . '/../../crmeb/public/index.php';
} else {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/../../crmeb/public/index.html');
}
