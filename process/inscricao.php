<?php
session_start();
require_once __DIR__ . '/../Database.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

// lê JSON ou form-data
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$eventId = isset($input['event_id']) ? (int)$input['event_id'] : 0;

if ($eventId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'ID de evento inválido']);
    exit;
}

if (empty($_SESSION['id_usuario'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Usuário não autenticado']);
    exit;
}

$userId = (int) $_SESSION['id_usuario'];

try {
    $pdo = getDbConnection();

    // tenta inserir; constraint UNIQUE (id_usuario, id_evento) previne duplicatas
    $sql = "INSERT INTO inscricao (id_usuario, id_evento, data_inscricao) VALUES (:uid, :eid, NOW())";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':uid' => $userId, ':eid' => $eventId]);

    // conta inscritos atualizados
    $cnt = $pdo->prepare("SELECT COUNT(*) AS total FROM inscricao WHERE id_evento = :eid");
    $cnt->execute([':eid' => $eventId]);
    $row = $cnt->fetch();
    $inscritos = (int) ($row['total'] ?? 0);

    echo json_encode(['success' => true, 'inscritos' => $inscritos]);
    exit;
} catch (\PDOException $e) {
    // unique violation no Postgres = 23505
    $code = $e->getCode();
    if ($code === '23505' || strpos($e->getMessage(), 'unique') !== false) {
        http_response_code(409);
        echo json_encode(['error' => 'Já inscrito neste evento']);
        exit;
    }

    http_response_code(500);
    // em dev pode retornar mensagem; em produção retornar genérico
    echo json_encode(['error' => 'Erro ao processar inscrição: ' . $e->getMessage()]);
    exit;
}
?>