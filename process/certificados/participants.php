<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../Database.php';
header('Content-Type: application/json; charset=utf-8');

function isOrganizador(): bool {
    $tipo = $_SESSION['tipo_perfil'] ?? $_SESSION['perfil'] ?? null;
    return !empty($tipo) && mb_strtolower(trim((string)$tipo),'UTF-8') === 'organizador';
}

if (!isOrganizador()) {
    http_response_code(403); echo json_encode(['error'=>'forbidden']); exit;
}

$id_evento = (int)($_GET['id_evento'] ?? 0);
if ($id_evento <= 0) { http_response_code(400); echo json_encode(['error'=>'bad_request','reason'=>'id_evento_obrigatorio']); exit; }

try {
    $pdo = getDbConnection();
    $sql = "
      SELECT u.id_usuario, u.nome, u.email, i.id_inscricao,
        COALESCE(p.presencas,0) AS presencas,
        GREATEST(1, ((EXTRACT(epoch FROM (COALESCE(e.data_fim,e.data_inicio)::timestamp - e.data_inicio::timestamp)) / 86400)::int + 1)) AS total_dias,
        ROUND((COALESCE(p.presencas,0)::numeric / GREATEST(1, ((EXTRACT(epoch FROM (COALESCE(e.data_fim,e.data_inicio)::timestamp - e.data_inicio::timestamp)) / 86400)::int + 1)))*100, 2) AS pct
      FROM inscricao i
      JOIN usuario u ON u.id_usuario = i.id_usuario
      JOIN evento e ON e.id_evento = i.id_evento
      LEFT JOIN (
        SELECT id_inscricao, COUNT(DISTINCT data_registro) AS presencas FROM presenca GROUP BY id_inscricao
      ) p ON p.id_inscricao = i.id_inscricao
      WHERE i.id_evento = :id_evento
      ORDER BY u.nome
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id_evento' => $id_evento]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['data' => $rows]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error'=>'query_failed','message'=>$e->getMessage()]);
}
?>