<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../Database.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['error'=>'method_not_allowed']); exit;
}
if (empty($_SESSION['id_usuario'])) { http_response_code(401); echo json_encode(['error'=>'auth_required']); exit; }

$id_inscricao = (int)($_POST['id_inscricao'] ?? 0);
if ($id_inscricao <= 0) { http_response_code(400); echo json_encode(['error'=>'bad_request']); exit; }

$uid = (int)$_SESSION['id_usuario'];
$threshold = 70.0;

try {
    $pdo = getDbConnection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // valida inscrição pertence ao usuário e calcula pct
    $sql = "
      SELECT i.id_inscricao, i.id_evento,
        COALESCE(p.presencas,0) AS presencas,
        GREATEST(1, ((EXTRACT(epoch FROM (COALESCE(e.data_fim,e.data_inicio)::timestamp - e.data_inicio::timestamp)) / 86400)::int + 1)) AS total_dias,
        ROUND((COALESCE(p.presencas,0)::numeric / GREATEST(1, ((EXTRACT(epoch FROM (COALESCE(e.data_fim,e.data_inicio)::timestamp - e.data_inicio::timestamp)) / 86400)::int + 1)))*100, 2) AS pct,
        c.id_certificado, c.status_validacao
      FROM inscricao i
      JOIN evento e ON e.id_evento = i.id_evento
      LEFT JOIN (SELECT id_inscricao, COUNT(DISTINCT data_registro) AS presencas FROM presenca GROUP BY id_inscricao) p ON p.id_inscricao = i.id_inscricao
      LEFT JOIN certificado c ON c.id_inscricao = i.id_inscricao
      WHERE i.id_inscricao = :id_inscricao AND i.id_usuario = :uid
      LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id_inscricao'=>$id_inscricao, ':uid'=>$uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(404); echo json_encode(['error'=>'not_found']); exit; }

    $pct = (float)($row['pct'] ?? 0.0);
    if ($pct < $threshold) {
        http_response_code(403);
        echo json_encode(['error'=>'not_eligible','message'=>"Presença insuficiente ({$pct}%). Requer {$threshold}%."]);
        exit;
    }

    // gera códigos e insere/atualiza certificado com status PENDENTE
    $salt = getenv('CERT_SALT') ?: 'troque_este_salt_em_producao';
    $payload = $id_inscricao . '|' . $row['id_evento'] . '|' . microtime(true) . '|' . bin2hex(random_bytes(8));
    $codigo_rastreio = strtoupper(substr(hash_hmac('sha256', $payload, $salt), 0, 32));
    $codigo_hash = strtoupper(substr(hash_hmac('sha256', 'hash|'.$payload, $salt), 0, 48));

    $upsert = "
      INSERT INTO certificado (id_inscricao, id_evento, nome_participante, email_participante, data_emissao, codigo_rastreio, codigo_hash, caminho_arquivo, status_validacao)
      SELECT i.id_inscricao, e.id_evento, u.nome, u.email, NOW(), :codigo_rastreio, :codigo_hash, '', :status_validacao
      FROM inscricao i
      JOIN usuario u ON u.id_usuario = i.id_usuario
      JOIN evento e ON e.id_evento = i.id_evento
      WHERE i.id_inscricao = :id_inscricao
      ON CONFLICT (id_inscricao) DO UPDATE SET
        codigo_rastreio = EXCLUDED.codigo_rastreio,
        codigo_hash = EXCLUDED.codigo_hash,
        status_validacao = EXCLUDED.status_validacao,
        data_emissao = NOW()
      RETURNING id_certificado, codigo_hash
    ";
    $stmt2 = $pdo->prepare($upsert);
    $stmt2->execute([
        ':codigo_rastreio' => $codigo_rastreio,
        ':codigo_hash' => $codigo_hash,
        ':status_validacao' => 'PENDENTE',
        ':id_inscricao' => $id_inscricao
    ]);
    $ret = $stmt2->fetch(PDO::FETCH_ASSOC);
    if ($ret && !empty($ret['id_certificado'])) {
        echo json_encode(['success'=>true,'message'=>'Solicitação registrada','id_certificado'=>$ret['id_certificado'],'codigo_hash'=>$ret['codigo_hash']]);
        exit;
    } else {
        echo json_encode(['error'=>'db_no_return','message'=>'Não foi possível registrar solicitação']);
        exit;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error'=>'db_error','message'=>$e->getMessage()]);
    exit;
}
?>