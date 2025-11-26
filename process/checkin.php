<?php
session_start();
// Define o fuso horário para o de São Paulo para garantir que as horas sejam registradas corretamente.
date_default_timezone_set('America/Sao_Paulo');

header('Content-Type: application/json; charset=utf-8');

// Lista de endereços IP públicos permitidos para realizar check-in e check-out.
$allowed_ips = [
    '127.0.0.1', // IP para testes locais (desenvolvimento)
    '::1',       // IP para testes locais (desenvolvimento, IPv6)
    // --- Adicione aqui os IPs públicos da sua empresa ---
    // Ex: '203.0.113.1', '198.51.100.54'
];

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

// Função para obter o endereço IP do cliente, priorizando o REMOTE_ADDR.
function getClientIp() {
    // Utiliza o IP de conexão direta do cliente.
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return $ip;
}

$clientIp = getClientIp();
$userId = (int) $_SESSION['id_usuario'];

// Verifica se o IP do cliente está na lista de IPs permitidos.
if (!in_array($clientIp, $allowed_ips, true)) {
    http_response_code(403); // Forbidden
    // Retorna uma mensagem de erro clara para o usuário.
    echo json_encode(['error' => 'Acesso negado. Você só pode realizar esta operação a partir de uma rede autorizada.']);
    exit;
}

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
    
    // Simplificar a atribuição, assumindo que data_inicio/data_fim já estão em 'YYYY-MM-DD'
    // Se eles puderem ser NULL ou string vazia, usamos o operador de coalescência nula (PHP 7+) ou o ternário
    $start = !empty($evRow['data_inicio']) ? date('Y-m-d', strtotime($evRow['data_inicio'])) : null;
    $end   = !empty($evRow['data_fim']) ? date('Y-m-d', strtotime($evRow['data_fim'])) : null;

    // determina se o evento está em andamento hoje
    if ($start && $end) {
        $inProgress = ($today >= $start && $today <= $end);
    } elseif ($start && !$end) {
        $inProgress = ($today === $start); // Permite check-in apenas no dia do evento
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
                ':ipe' => $clientIp
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
        ':ips' => $clientIp,
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