<?php
require_once '../Database.php';

// Inicia a sessão para verificar a autenticação
session_start();

header('Content-Type: application/json');

// 1. Valida se o usuário está autenticado via sessão
if (!isset($_SESSION['is_root_authenticated']) || $_SESSION['is_root_authenticated'] !== true) {
    http_response_code(403); // Forbidden
    echo json_encode(['success' => false, 'message' => 'Acesso negado. Sessão inválida.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['success' => false, 'message' => 'Método não permitido.']);
    exit;
}

$id_evento = $_POST['id_evento'] ?? null;

// 2. Valida o ID do evento e atualiza o banco
if ($id_evento && filter_var($id_evento, FILTER_VALIDATE_INT)) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("UPDATE evento SET status = 'Aberto' WHERE id_evento = :id");
        $stmt->execute([':id' => $id_evento]);
        echo json_encode(['success' => true, 'message' => 'Evento aprovado com sucesso!']);
    } catch (PDOException $e) {
        http_response_code(500); // Internal Server Error
        echo json_encode(['success' => false, 'message' => 'Erro ao aprovar o evento no banco de dados.']);
    }
} else {
    http_response_code(400); // Bad Request
    echo json_encode(['success' => false, 'message' => 'ID do evento inválido.']);
}
exit;