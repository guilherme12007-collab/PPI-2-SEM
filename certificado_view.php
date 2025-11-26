<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/Database.php';

if (empty($_SESSION['id_usuario'])) {
    header('Location: login.php');
    exit;
}

$hash = trim((string)($_GET['hash'] ?? ''));
if ($hash === '') { http_response_code(400); echo 'Hash inválido'; exit; }

function isOrganizador(): bool {
    $tipo = $_SESSION['tipo_perfil'] ?? $_SESSION['perfil'] ?? null;
    return !empty($tipo) && mb_strtolower(trim((string)$tipo),'UTF-8') === 'organizador';
}

try {
    $pdo = getDbConnection();
    $is_org = isOrganizador();
    
    $sql = "
        SELECT c.id_certificado, c.caminho_arquivo, c.data_emissao,
               u.nome AS participante, e.titulo AS evento, COALESCE(e.carga_horaria::text, '') AS carga_horaria,
               c.codigo_hash
        FROM certificado c
        JOIN inscricao i ON c.id_inscricao = i.id_inscricao
        JOIN usuario u ON i.id_usuario = u.id_usuario
        JOIN evento e ON i.id_evento = e.id_evento
        WHERE c.codigo_hash = :h ";

    $params = [':h' => $hash];
    if (!$is_org) {
        $sql .= " AND i.id_usuario = :uid ";
        $params[':uid'] = (int)$_SESSION['id_usuario'];
    }
    $sql .= " LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(404); echo 'Certificado não encontrado ou sem permissão.'; exit; }
} catch (Exception $e) {
    http_response_code(500);
    // Para depuração: exibe o erro real. Em produção, isso deve ser registrado em um arquivo de log.
    error_log('Erro na consulta do certificado: ' . $e->getMessage()); // Loga o erro no servidor
    die('Erro ao consultar certificado: ' . htmlspecialchars($e->getMessage()));
}

$fileUrl = 'process/certificados/render_pdf.php?hash=' . urlencode($hash) . '&force=1';
$participant = htmlspecialchars($row['participante'] ?? '', ENT_QUOTES, 'UTF-8');
$eventTitle = htmlspecialchars($row['evento'] ?? '', ENT_QUOTES, 'UTF-8');
$carga = htmlspecialchars($row['carga_horaria'] ?? '', ENT_QUOTES, 'UTF-8');
$date = $row['data_emissao'] ? date('d/m/Y', strtotime($row['data_emissao'])) : date('d/m/Y');
$codigo_hash = htmlspecialchars($row['codigo_hash'] ?? '', ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Visualizar Certificado</title>
<style>
  html,body{height:100%;margin:0;font-family:Arial,Helvetica,sans-serif;background:#f3f4f6}
  .topbar{background:#fff;padding:12px 16px;box-shadow:0 1px 4px rgba(0,0,0,.06);display:flex;justify-content:space-between;align-items:center}
  .controls{display:flex;gap:8px}
  .btn{padding:8px 12px;border-radius:6px;border:0;cursor:pointer}
  .btn-primary{background:#2563eb;color:#fff}
  .btn-ghost{background:#f3f4f6}
  .note{font-size:.9rem;color:#374151}
  .content{height:calc(100vh - 68px);padding:12px}
  .pdf-frame{width:100%;height:100%;border:1px solid #ddd;border-radius:6px;overflow:hidden}
  .center-msg{display:flex;align-items:center;justify-content:center;height:100%;color:#374151}
</style>
</head>
<body>
  <div class="topbar">
    <div>
      <strong>Visualizar Certificado</strong>
      <div class="note">Evento: <?php echo $eventTitle; ?></div>
    </div>
    <div class="controls">
      <a class="btn btn-primary" href="<?php echo $fileUrl; ?>&download=1" target="_blank">Baixar PDF</a>
      <button class="btn btn-ghost" onclick="window.close()">Fechar</button>
    </div>
  </div>

  <div class="content">
    <div class="pdf-frame">
      <!-- always load the PDF rendering endpoint in iframe (it will stream existing PDF or generate one dynamically) -->
      <iframe src="<?php echo $fileUrl; ?>" style="width:100%;height:100%;border:0"></iframe>
    </div>

    <div style="margin-top:8px;color:#6b7280;font-size:.9rem">Código de rastreio: <?php echo $codigo_hash; ?></div>
  </div>
</body>
</html>