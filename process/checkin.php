<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

ob_start();
require_once __DIR__ . '/../Database.php';
$require_output = ob_get_clean();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido', 'debug' => $require_output]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$eventId = isset($input['event_id']) ? (int)$input['event_id'] : 0;
$action = isset($input['action']) ? strtolower($input['action']) : '';

if ($eventId <= 0 || !in_array($action, ['check-in','check-out'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Parâmetros inválidos']);
    exit;
}

if (empty($_SESSION['id_usuario'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Usuário não autenticado']);
    exit;
}

// função simples para registrar IP detectado (não usada para permitir/neg ar)
function getClientIpRaw() {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim(end($parts)); // último proxy mais próximo do servidor
    }
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) return $_SERVER['HTTP_CLIENT_IP'];
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

$clientIpRaw = getClientIpRaw();
$userId = (int) $_SESSION['id_usuario'];

try {
    $pdo = getDbConnection();

    // verifica inscrição do usuário no evento
    $q = $pdo->prepare("SELECT id_inscricao FROM inscricao WHERE id_usuario = :uid AND id_evento = :eid");
    $q->execute([':uid' => $userId, ':eid' => $eventId]);
    $ins = $q->fetch();
    if (!$ins) {
        http_response_code(403);
        echo json_encode(['error' => 'Usuário não inscrito neste evento']);
        exit;
    }
    $id_inscricao = (int)$ins['id_inscricao'];

    // busca datas do evento para validar se está em andamento
    $ev = $pdo->prepare("SELECT data_inicio, data_fim FROM evento WHERE id_evento = :eid");
    $ev->execute([':eid' => $eventId]);
    $evRow = $ev->fetch();
    if (!$evRow) {
        http_response_code(404);
        echo json_encode(['error' => 'Evento não encontrado']);
        exit;
    }

    $today = date('Y-m-d');
    $start = $evRow['data_inicio'] ? date('Y-m-d', strtotime($evRow['data_inicio'])) : null;
    $end   = $evRow['data_fim']    ? date('Y-m-d', strtotime($evRow['data_fim']))    : null;

    // determina se o evento está em andamento hoje
    if ($start && $end) {
        $inProgress = ($today >= $start && $today <= $end);
    } elseif ($start && !$end) {
        $inProgress = ($today >= $start);
    } elseif (!$start && $end) {
        $inProgress = ($today <= $end);
    } else {
        $inProgress = true; // sem datas, considera disponível
    }

    if (!$inProgress) {
        http_response_code(403);
        echo json_encode(['error' => 'Check-in/Check-out permitido somente enquanto o evento estiver em andamento', 'data_inicio' => $start, 'data_fim' => $end]);
        exit;
    }

    $now = date('H:i:s');

    if ($action === 'check-in') {
        // tenta inserir presença para o dia (constraint única evita duplicatas)
        try {
            $insStmt = $pdo->prepare("
                INSERT INTO presenca (id_inscricao, data_registro, hora_entrada, ip_entrada, created_at)
                VALUES (:iid, :dr, :he, :ipe, NOW())
            ");
            $insStmt->execute([
                ':iid' => $id_inscricao,
                ':dr'  => $today,
                ':he'  => $now,
                ':ipe' => $clientIpRaw
            ]);
            echo json_encode(['success' => true, 'message' => 'Check-in realizado', 'hora' => $now]);
            exit;
        } catch (\PDOException $e) {
            // unique violation PostgreSQL = 23505
            if ($e->getCode() === '23505' || stripos($e->getMessage(), 'unique') !== false) {
                http_response_code(409);
                echo json_encode(['error' => 'Já registrado check-in hoje']);
                exit;
            }
            throw $e;
        }
    }

    // check-out
    $chk = $pdo->prepare("SELECT id_presenca, hora_entrada, hora_saida FROM presenca WHERE id_inscricao = :iid AND data_registro = :dr");
    $chk->execute([':iid' => $id_inscricao, ':dr' => $today]);
    $row = $chk->fetch();

    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'Não há check-in registrado para hoje']);
        exit;
    }

    if (!empty($row['hora_saida'])) {
        http_response_code(409);
        echo json_encode(['error' => 'Check-out já realizado neste registro']);
        exit;
    }

    $upd = $pdo->prepare("
        UPDATE presenca
        SET hora_saida = :hs, ip_saida = :ips, updated_at = NOW()
        WHERE id_presenca = :pid
    ");
    $upd->execute([
        ':hs'  => $now,
        ':ips' => $clientIpRaw,
        ':pid' => (int)$row['id_presenca']
    ]);

    echo json_encode(['success' => true, 'message' => 'Check-out realizado', 'hora' => $now]);
    exit;

} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro de BD', 'exception' => $e->getMessage(), 'debug' => $require_output]);
    exit;
}
?>