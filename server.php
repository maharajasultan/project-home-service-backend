<?php

$publicPath = __DIR__ . '/public';

$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? ''
);

// Add CORS headers to all requests (including static files like images)
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: *");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Serve static files directly with CORS headers
if ($uri !== '/' && file_exists($publicPath . $uri)) {
    if (is_file($publicPath . $uri)) {
        $mime = mime_content_type($publicPath . $uri);
        if ($mime) {
            header("Content-Type: $mime");
        }
        header("Cache-Control: public, max-age=86400");
        readfile($publicPath . $uri);
        exit;
    }
    return false;
}

require_once $publicPath . '/index.php';
