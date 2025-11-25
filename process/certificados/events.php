<?php
// debug rápido
if (session_status() === PHP_SESSION_NONE) session_start();
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../../Database.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = getDbConnection();
    $rows = $pdo->query("SELECT id_evento, titulo, to_char(data_inicio,'YYYY-MM-DD') AS data_inicio FROM evento ORDER BY data_inicio DESC")
                ->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['data' => $rows], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'query_failed',
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], JSON_UNESCAPED_UNICODE);
}
?>