<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../Database.php';

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

$logFile = __DIR__ . '/../../logs/validate_error.log';
@mkdir(dirname($logFile), 0775, true);

if (empty($_SESSION['id_usuario'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Autenticação requerida']);
    exit;
}

$uid = (int) $_SESSION['id_usuario'];
$hash = trim((string)($_GET['hash'] ?? ''));

if ($hash === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Hash inválido']);
    exit;
}

function columnExists(PDO $pdo, string $table, string $column): bool {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    try {
        if ($driver === 'mysql') {
            $st = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c");
            $st->execute([':t' => $table, ':c' => $column]);
            return (bool)$st->fetchColumn();
        } elseif ($driver === 'pgsql') {
            $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_name = :t AND column_name = :c");
            $st->execute([':t' => $table, ':c' => $column]);
            return (bool)$st->fetchColumn();
        } else { // sqlite or others
            $st = $pdo->prepare("PRAGMA table_info($table)");
            $st->execute();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) if (isset($r['name']) && $r['name'] === $column) return true;
            return false;
        }
    } catch (Exception $e) {
        return false;
    }
}

try {
    $pdo = getDbConnection();

    // fetch certificate and owner
    $stmt = $pdo->prepare("
        SELECT c.id_certificado, c.status_validacao, i.id_usuario
        FROM certificado c
        JOIN inscricao i ON c.id_inscricao = i.id_inscricao
        WHERE c.codigo_hash = :h
        LIMIT 1
    ");
    $stmt->execute([':h' => $hash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Certificado não encontrado']);
        exit;
    }

    if ((int)$row['id_usuario'] !== $uid) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Sem permissão para validar este certificado']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Already validated?
        if (strtoupper((string)$row['status_validacao']) === 'VALIDADO') {
            echo json_encode(['success' => true, 'message' => 'Certificado já validado']);
            exit;
        }

        // choose which date column to update, if any
        $dateCols = ['data_validacao', 'data_validado'];
        $foundDateCol = null;
        foreach ($dateCols as $col) {
            if (columnExists($pdo, 'certificado', $col)) { $foundDateCol = $col; break; }
        }

        // build update
        $fields = "status_validacao = 'VALIDADO'";
        if ($foundDateCol) {
            $fields .= ", {$foundDateCol} = " . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? "NOW()" : "NOW()");
        }

        $pdo->beginTransaction();
        $update = $pdo->prepare("UPDATE certificado SET {$fields} WHERE id_certificado = :id");
        $ok = $update->execute([':id' => (int)$row['id_certificado']]);
        $rowsAffected = $update->rowCount();
        if ($ok && $rowsAffected > 0) {
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Certificado validado com sucesso']);
            exit;
        } else {
            $pdo->rollBack();
            // log details for debugging
            $msg = date('c') . " - validate.php update failed; hash={$hash}; id={$row['id_certificado']}; driver=" . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "; foundDateCol=" . ($foundDateCol ?? 'null') . "; rowsAffected={$rowsAffected}\n";
            file_put_contents($logFile, $msg, FILE_APPEND);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Falha ao atualizar status do certificado (nenhuma linha modificada)']);
            exit;
        }
    }

    // GET -> return current status
    echo json_encode(['success' => true, 'status' => $row['status_validacao']]);
    exit;

} catch (PDOException $e) {
    $msg = date('c') . " - PDOException: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    file_put_contents($logFile, $msg, FILE_APPEND);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro no servidor (DB)']);
    exit;
} catch (Exception $e) {
    $msg = date('c') . " - Exception: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    file_put_contents($logFile, $msg, FILE_APPEND);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro no servidor']);
    exit;
}
?>