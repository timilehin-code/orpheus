<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: http://localhost:5173');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';

use App\Controllers\PlaylistController;
use App\Repositories\PlaylistRepository;

$pdo = getDatabaseConnection();
$controller = new PlaylistController(new PlaylistRepository($pdo));

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($method === 'POST' && str_ends_with($path, '/api/playlists')) {
    $controller->store();
} elseif ($method === 'GET' && preg_match('#/api/playlists/([a-f0-9]{8})$#', $path, $matches)) {
    $controller->show($matches[1]);
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Endpoint not found.']);
}
