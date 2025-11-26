<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../Database.php';
header('Content-Type: application/json; charset=utf-8');

function isOrganizador(): bool {
    $tipo = $_SESSION['tipo_perfil'] ?? $_SESSION['perfil'] ?? null;
    return !empty($tipo) && mb_strtolower(trim((string)$tipo),'UTF-8') === 'organizador';
}
function gerarCodigoRastreio(int $id_inscricao, int $id_evento): string {
    $salt = getenv('CERT_SALT') ?: 'troque_este_salt_em_producao';
    $payload = $id_inscricao.'|'.$id_evento.'|'.microtime(true).'|'.bin2hex(random_bytes(8));
    return strtoupper(substr(hash_hmac('sha256',$payload,$salt),0,64));
}
function gerarCodigoHash(int $id_inscricao,int $id_evento): string {
    $salt = getenv('CERT_SALT') ?: 'troque_este_salt_em_producao';
    $payload = 'hash|'.$id_inscricao.'|'.$id_evento.'|'.bin2hex(random_bytes(12)).'|'.microtime(true);
    return strtoupper(substr(hash_hmac('sha256',$payload,$salt),0,64));
}

// try to load Composer autoload (for TCPDF)
$composerAutoload = __DIR__ . '/../../../vendor/autoload.php';
$tcpdfAvailable = false;
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
    $tcpdfAvailable = class_exists('TCPDF');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error'=>'method_not_allowed']); exit; }
if (!isOrganizador()) { http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }

$ids = $_POST['ids'] ?? [];
if (!is_array($ids)) $ids = array_filter(array_map('trim', explode(',', (string)$ids)));
if (empty($ids)) { http_response_code(400); echo json_encode(['error'=>'ids_obrigatorios']); exit; }

// add server-side validation early in the POST handling
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // validate uploaded background if present
    if (!empty($_FILES['background']) && $_FILES['background']['error'] !== UPLOAD_ERR_NO_FILE) {
        $f = $_FILES['background'];
        if ($f['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['error'=>'upload_failed','message'=>'Erro no upload do arquivo. Código: ' . $f['error']]);
            exit;
        }
        if ($f['size'] > 5 * 1024 * 1024) {
            http_response_code(400);
            echo json_encode(['error'=>'file_too_large','message'=>'Tamanho máximo 5MB']);
            exit;
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $f['tmp_name']);
        finfo_close($finfo);
        $allowed = ['image/png' => '.png', 'image/jpeg' => '.jpg'];
        if (!isset($allowed[$mime])) {
            http_response_code(400);
            echo json_encode(['error'=>'invalid_type','message'=>'Apenas PNG ou JPEG são permitidos']);
            exit;
        }
        // store file
        $uploadDir = __DIR__ . '/../../uploads/certificados';
        if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);
        $safeName = 'bg_' . time() . '_' . bin2hex(random_bytes(6)) . $allowed[$mime];
        $dst = $uploadDir . '/' . $safeName;
        if (!move_uploaded_file($f['tmp_name'], $dst)) {
            http_response_code(500);
            echo json_encode(['error'=>'cannot_move','message'=>'Falha ao salvar a imagem']);
            exit;
        }
        // you can return the relative path for later use
        $_POST['background_saved_path'] = 'uploads/certificados/' . $safeName;
    }
}

try {
    $pdo = getDbConnection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    http_response_code(500); echo json_encode(['error'=>'db_connection_failed','message'=>$e->getMessage()]); exit;
}

$results = [];
$sqlIns = "SELECT i.id_evento, u.nome, u.email, e.titulo, COALESCE(e.carga_horaria::text,'') AS carga_horaria
  FROM inscricao i
  JOIN usuario u ON u.id_usuario = i.id_usuario
  JOIN evento e ON e.id_evento = i.id_evento
  WHERE i.id_inscricao = :id_inscricao
  LIMIT 1";

$sqlUpsert = "INSERT INTO certificado (
    id_inscricao, id_evento, nome_participante, email_participante,
    data_emissao, codigo_rastreio, codigo_hash, caminho_arquivo, status_validacao
) VALUES (
    :id_inscricao, :id_evento, :nome_participante, :email_participante,
    NOW(), :codigo_rastreio, :codigo_hash, :caminho_arquivo, :status_validacao
)
ON CONFLICT (id_inscricao) DO UPDATE SET
    id_evento = EXCLUDED.id_evento,
    nome_participante = EXCLUDED.nome_participante,
    email_participante = EXCLUDED.email_participante,
    data_emissao = EXCLUDED.data_emissao,
    codigo_rastreio = EXCLUDED.codigo_rastreio,
    codigo_hash = EXCLUDED.codigo_hash,
    caminho_arquivo = EXCLUDED.caminho_arquivo,
    status_validacao = EXCLUDED.status_validacao
RETURNING id_certificado, codigo_hash, caminho_arquivo";

$uploadedBgPath = $_POST['background_saved_path'] ?? '';

try {
    $stmtIns = $pdo->prepare($sqlIns);
    $stmtUpsert = $pdo->prepare($sqlUpsert);

    foreach ($ids as $rawId) {
        $id_inscricao = (int)$rawId;
        if ($id_inscricao <= 0) { $results[] = ['id_inscricao'=>$rawId,'error'=>'id_invalido']; continue; }

        try {
            $stmtIns->execute([':id_inscricao'=>$id_inscricao]);
            $row = $stmtIns->fetch(PDO::FETCH_ASSOC);
            if (!$row) { $results[] = ['id_inscricao'=>$id_inscricao,'error'=>'inscricao_nao_encontrada']; continue; }

            $id_evento = (int)$row['id_evento'];
            $nome = substr((string)($row['nome'] ?? ''), 0, 255);
            $email = substr((string)($row['email'] ?? ''), 0, 255);
            $titulo = substr((string)($row['titulo'] ?? ''), 0, 255);
            $carga = substr((string)($row['carga_horaria'] ?? ''), 0, 64);

            $codigo_rastreio = substr(gerarCodigoRastreio($id_inscricao,$id_evento),0,64);
            $codigo_hash = substr(gerarCodigoHash($id_inscricao,$id_evento),0,64);

            // initial caminho (will be replaced by PDF path after generation)
            $initialPath = $uploadedBgPath ?: '';

            $params = [
                ':id_inscricao'=>$id_inscricao,
                ':id_evento'=>$id_evento,
                ':nome_participante'=>$nome,
                ':email_participante'=>$email,
                ':codigo_rastreio'=>$codigo_rastreio,
                ':codigo_hash'=>$codigo_hash,
                ':caminho_arquivo'=>$initialPath,
                ':status_validacao'=>'PENDENTE'
            ];

            $stmtUpsert->execute($params);
            $ret = $stmtUpsert->fetch(PDO::FETCH_ASSOC);

            if ($ret && isset($ret['id_certificado'])) {
                $id_cert = (int)$ret['id_certificado'];

                $results[] = [
                    'id_inscricao'=>$id_inscricao,
                    'id_certificado'=>$id_cert,
                    'status'=>'ok',
                    'caminho_arquivo'=>$initialPath, // O caminho do background é mantido
                    'codigo_hash'=>$codigo_hash
                ];
            } else {
                $results[] = ['id_inscricao'=>$id_inscricao,'error'=>'no_returning','debug'=>$ret];
            }
        } catch (Exception $e) {
            $results[] = ['id_inscricao'=>$id_inscricao,'error'=>'db_exception','message'=>$e->getMessage()];
        }
    }

    echo json_encode(['success'=>true,'results'=>$results]);
} catch (Exception $e) {
    http_response_code(500); echo json_encode(['error'=>'erro_geral','message'=>$e->getMessage()]);
}
?>