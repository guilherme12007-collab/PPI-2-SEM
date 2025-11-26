<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/Database.php';

function isOrganizador(): bool {
    $userId = $_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? null;
    $tipo = $_SESSION['tipo_perfil'] ?? $_SESSION['perfil'] ?? null;
    if (empty($userId) || empty($tipo)) return false;
    return mb_strtolower(trim((string)$tipo), 'UTF-8') === 'organizador';
}

function gerarCodigoRastreio(int $id_inscricao = 0, int $id_evento = 0): string {
    $salt = getenv('CERT_SALT') ?: 'troque_este_salt_em_producao';
    $payload = $id_inscricao . '|' . $id_evento . '|' . microtime(true) . '|' . bin2hex(random_bytes(8));
    return strtoupper(hash_hmac('sha256', $payload, $salt));
}

function gerarCodigoHash(int $id_inscricao = 0, int $id_evento = 0): string {
    $salt = getenv('CERT_SALT') ?: 'troque_este_salt_em_producao';
    $payload = 'hash|' . $id_inscricao . '|' . $id_evento . '|' . bin2hex(random_bytes(12)) . '|' . microtime(true);
    return strtoupper(hash_hmac('sha256', $payload, $salt));
}

// try to load Composer autoload (for TCPDF)
$composerAutoload = __DIR__ . '/vendor/autoload.php';
$tcpdfAvailable = false;
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
    $tcpdfAvailable = class_exists('TCPDF');
}

if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $pdo = getDbConnection(); // usar apenas isto
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'db_connection_failed']);
        exit;
    }

    $action = $_GET['action'];

    // listar eventos
    if ($action === 'events') {
        try {
            $rows = $pdo->query("SELECT id_evento, titulo, to_char(data_inicio,'YYYY-MM-DD') AS data_inicio FROM evento ORDER BY data_inicio DESC")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['data' => $rows]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'query_failed']);
        }
        exit;
    }

    // listar inscritos (sem validação)
    if ($action === 'participants') {
        if (!isOrganizador()) {
            http_response_code(403);
            echo json_encode(['error' => 'forbidden']);
            exit;
        }

        $id_evento = (int)($_GET['id_evento'] ?? 0);
        if ($id_evento <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'bad_request', 'reason' => 'id_evento_obrigatorio']);
            exit;
        }

        try {
            // cálculo de total_dias e pct compatível com DATE/TIMESTAMP
            $sql = "
              SELECT u.id_usuario, u.nome, u.email, i.id_inscricao,
                COALESCE(p.presencas,0) AS presencas,
                GREATEST(1, ((EXTRACT(epoch FROM (COALESCE(e.data_fim,e.data_inicio)::timestamp - e.data_inicio::timestamp)) / 86400)::int + 1)) AS total_dias,
                ROUND((COALESCE(p.presencas,0)::numeric / GREATEST(1, ((EXTRACT(epoch FROM (COALESCE(e.data_fim,e.data_inicio)::timestamp - e.data_inicio::timestamp)) / 86400)::int + 1)))*100, 2) AS pct
              FROM inscricao i
              JOIN usuario u ON u.id_usuario = i.id_usuario
              JOIN evento e ON e.id_evento = i.id_evento
              LEFT JOIN (
                SELECT id_inscricao, COUNT(DISTINCT data_registro) AS presencas
                FROM presenca
                GROUP BY id_inscricao
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
            echo json_encode(['error' => 'query_failed']);
        }
        exit;
    }

    // listar certificados emitidos
    if ($action === 'list_issued') {
        if (!isOrganizador()) {
            http_response_code(403);
            echo json_encode(['error' => 'forbidden']);
            exit;
        }

        $id_evento = (int)($_GET['id_evento'] ?? 0);

        try {
            $sql = "
                SELECT c.id_certificado, c.nome_participante, c.email_participante,
                       c.data_emissao, c.codigo_hash, e.titulo AS evento_titulo
                FROM certificado c
                JOIN evento e ON c.id_evento = e.id_evento
            ";

            $params = [];
            if ($id_evento > 0) {
                $sql .= " WHERE c.id_evento = :id_evento";
                $params[':id_evento'] = $id_evento;
            }
            $sql .= " ORDER BY c.data_emissao DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            echo json_encode(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Exception $e) {
            http_response_code(500); echo json_encode(['error' => 'query_failed', 'message' => $e->getMessage()]);
        }
        exit;
    }

    // emitir lote (validação 70-75% ocorre aqui)
    if ($action === 'emit_lote' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isOrganizador()) {
            http_response_code(403);
            echo json_encode(['error' => 'forbidden']);
            exit;
        }

        // Alterado para suportar multipart/form-data (upload de arquivo)
        $ids = is_array($_POST['ids'] ?? null) ? $_POST['ids'] : [];
        if (empty($ids)) {
            http_response_code(400);
            echo json_encode(['error' => 'bad_request', 'reason' => 'ids_obrigatorios']);
            exit;
        }

        $id_imagem_fundo = null;
        $imagem_fundo_conteudo = null; // Para usar na geração do PDF
        if (isset($_FILES['background']) && $_FILES['background']['error'] === UPLOAD_ERR_OK) {
            // Validação de segurança básica
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($_FILES['background']['tmp_name']);
            if (in_array($mime, ['image/jpeg', 'image/png'])) {
                $imagem_fundo_conteudo = file_get_contents($_FILES['background']['tmp_name']);
                $nome_arquivo_unico = 'bg_' . time() . '_' . bin2hex(random_bytes(12)) . ($mime === 'image/jpeg' ? '.jpg' : '.png');

                try {
                    $stmtImg = $pdo->prepare(
                        "INSERT INTO certificado_imagem_fundo (nome_arquivo_unico, conteudo_imagem) VALUES (:nome, :conteudo) RETURNING id_imagem_fundo"
                    );
                    $stmtImg->bindParam(':nome', $nome_arquivo_unico, PDO::PARAM_STR);
                    $stmtImg->bindParam(':conteudo', $imagem_fundo_conteudo, PDO::PARAM_LOB);
                    $stmtImg->execute();
                    $resultImg = $stmtImg->fetch(PDO::FETCH_ASSOC);
                    if ($resultImg && isset($resultImg['id_imagem_fundo'])) {
                        $id_imagem_fundo = (int)$resultImg['id_imagem_fundo'];
                    }
                } catch (Exception $imgEx) {
                    // Falha ao salvar a imagem, mas pode continuar sem ela
                }
            }
        }

        $results = [];
        foreach ($ids as $id_inscricao) {
            $id_inscricao = (int)$id_inscricao;
            if ($id_inscricao <= 0) {
                $results[] = ['id_inscricao' => $id_inscricao, 'error' => 'invalid_id'];
                continue;
            }

            try {
                $stmt = $pdo->prepare("
                  SELECT i.id_evento, u.nome, u.email, e.titulo, COALESCE(e.carga_horaria::text,'') AS carga_horaria,
                    COALESCE(p.presencas,0) AS presencas,
                    GREATEST(1, ((EXTRACT(epoch FROM (COALESCE(e.data_fim,e.data_inicio)::timestamp - e.data_inicio::timestamp)) / 86400)::int + 1)) AS total_dias,
                    ROUND((COALESCE(p.presencas,0)::numeric / GREATEST(1, ((EXTRACT(epoch FROM (COALESCE(e.data_fim,e.data_inicio)::timestamp - e.data_inicio::timestamp)) / 86400)::int + 1)))*100, 2) AS pct
                  FROM inscricao i
                  JOIN usuario u ON u.id_usuario = i.id_usuario
                  JOIN evento e ON e.id_evento = i.id_evento
                  LEFT JOIN (
                    SELECT id_inscricao, COUNT(DISTINCT data_registro) AS presencas
                    FROM presenca
                    GROUP BY id_inscricao
                  ) p ON p.id_inscricao = i.id_inscricao
                  WHERE i.id_inscricao = :id_inscricao
                  LIMIT 1
                ");
                $stmt->execute([':id_inscricao' => $id_inscricao]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    $results[] = ['id_inscricao' => $id_inscricao, 'error' => 'not_found'];
                    continue;
                }

                $pct = (float)($row['pct'] ?? 0.0);
                if ($pct < 70.0) {
                    $results[] = [
                        'id_inscricao' => $id_inscricao,
                        'nome' => $row['nome'],
                        'email' => $row['email'],
                        'pct' => $pct,
                        'status' => 'skipped',
                        'reason' => 'percentual_inferior_a_70'
                    ];
                    continue;
                }

                $id_evento = (int)$row['id_evento'];
                $nome = $row['nome'];
                $email = $row['email'];
                $titulo_evento = $row['titulo'];
                $carga_horaria = $row['carga_horaria'];

                $codigo_rastreio = gerarCodigoRastreio($id_inscricao, $id_evento);
                $codigo_hash = gerarCodigoHash($id_inscricao, $id_evento);

                $sqlUpsert = "
                  INSERT INTO certificado (
                    id_inscricao, id_evento, nome_participante, email_participante, id_imagem_fundo,
                    data_emissao, codigo_rastreio, codigo_hash, caminho_arquivo, status_validacao
                  ) VALUES (
                    :id_inscricao, :id_evento, :nome_participante, :email_participante, :id_imagem_fundo,
                    NOW(), :codigo_rastreio, :codigo_hash, :caminho_arquivo, 'PENDENTE'
                  )
                  ON CONFLICT (id_inscricao) DO UPDATE
                    SET id_evento = EXCLUDED.id_evento,
                        nome_participante = EXCLUDED.nome_participante,
                        email_participante = EXCLUDED.email_participante,
                        data_emissao = EXCLUDED.data_emissao,
                        codigo_rastreio = EXCLUDED.codigo_rastreio,
                        codigo_hash = EXCLUDED.codigo_hash,
                        id_imagem_fundo = EXCLUDED.id_imagem_fundo,
                        caminho_arquivo = '', -- Define um valor temporário para evitar erro de NOT NULL
                        status_validacao = 'PENDENTE'
                  RETURNING id_certificado
                ";
                $stmt2 = $pdo->prepare($sqlUpsert);
                $params = [
                    ':id_inscricao' => $id_inscricao,
                    ':id_evento' => $id_evento,
                    ':nome_participante' => $nome,
                    ':email_participante' => $email,
                    ':codigo_rastreio' => $codigo_rastreio,
                    ':codigo_hash' => $codigo_hash,
                    ':caminho_arquivo' => null,
                    ':id_imagem_fundo' => $id_imagem_fundo
                ];
                $stmt2->execute($params);

                // ler o RETURNING de forma robusta
                $returned = $stmt2->fetch(PDO::FETCH_ASSOC);
                if ($returned && isset($returned['id_certificado'])) {
                    $id_cert = (int)$returned['id_certificado'];
                } else {
                    // tentar obter lastInsertId (fallback) ou marcar erro
                    try {
                        $id_cert = (int)$pdo->lastInsertId('certificado_id_certificado_seq');
                    } catch (Exception $e2) {
                        $id_cert = 0;
                    }
                }

                if ($id_cert <= 0) {
                    $results[] = ['id_inscricao' => $id_inscricao, 'error' => 'no_id_returned', 'debug' => $returned ?? null];
                    continue;
                }

                // O caminho do arquivo não é mais gerado aqui,
                // apenas o caminho da imagem de fundo (se houver) é salvo no banco.
                // A geração do PDF ocorrerá em render_pdf.php.
                $pdfRelative = ''; // Não há mais caminho de PDF para salvar.

                $results[] = [
                    'id_inscricao' => $id_inscricao,
                    'id_certificado' => $id_cert,
                    'codigo_hash' => $codigo_hash,
                    'caminho_arquivo' => $pdfRelative,
                    'nome' => $nome,
                    'email' => $email,
                    'pct' => $pct,
                    'status' => 'saved'
                ];
            } catch (Exception $ex) {
                // retorne mensagem de erro para debug (remova em produção)
                $results[] = ['id_inscricao' => $id_inscricao, 'error' => 'db_error', 'message' => $ex->getMessage()];
            }
        }

        echo json_encode(['success' => true, 'results' => $results]);
        exit;
    }

    // validação pública por codigo_hash
    if ($action === 'validate' && isset($_GET['hash'])) {
        $hash = trim((string)$_GET['hash']);
        if ($hash === '') { http_response_code(400); echo json_encode(['error' => 'bad_request']); exit; }
        try {
            $stmt = $pdo->prepare("
              SELECT id_certificado, id_inscricao, id_evento, nome_participante, email_participante, data_emissao, codigo_rastreio, codigo_hash, status_validacao
              FROM certificado
              WHERE codigo_hash = :h
              LIMIT 1
            ");
            $stmt->execute([':h' => $hash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) { http_response_code(404); echo json_encode(['error' => 'not_found']); exit; }
            echo json_encode(['data' => $row]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'query_failed']);
        }
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'bad_request']);
    exit;
}

/* ---------------- UI (mantém seu estilo) ---------------- */
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Certificados</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css" />
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="./assets/css/certificados_organizador.css">
</head>
<body>
  <div class="sidebar">
    <img src="img/logo_iffar-removebg-preview.png" alt="iff" class="logo">
    <nav>
      <ul>
        <li><a href="index_organizador.php"><i class="fa-solid fa-chart-line"></i> Dashboard</a></li>
        <li><a href="eventosADM.php"><i class="fa-solid fa-calendar-days"></i> Eventos</a></li>
        <a href="inscritos.php"><i class="fa-solid fa-users"></i> Inscritos</a>
        <li class="active"><a href="certificados.php"><i class="fa-solid fa-certificate"></i> Certificados</a></li>
        <li><a href="inscrever_organizador.php"><i class="fa-solid fa-pen-to-square"></i>Inscrever-se</a></li>
      </ul>
    </nav>
  </div>

  <div class="main-content">
    <header class="top-bar" style="display:flex; justify-content:flex-end; padding: 24px 0; gap: 1rem;">
        <div class="flex items-center gap-3 ml-auto">
            <a href="perfil_organizador.php" title="Meu Perfil" class="text-gray-600 hover:text-green-600"><i class="fa-solid fa-user fa-lg"></i></a>
            <a href="login.php" title="Sair" class="text-gray-600 hover:text-red-600"><i class="fa-solid fa-sign-out-alt fa-lg"></i></a>
        </div>
    </header>

    <h1 style="color:#125c2b;font-size:1.8rem;margin-bottom:0.25rem;font-weight:700">Certificados</h1>
    <p style="color:#666;margin-bottom:1rem">Grave os dados necessários para que o inscrito gere o certificado em seu navegador posteriormente.</p>

    <!-- A área de output foi movida para cá -->
    <div id="output" style="display:none;margin-bottom:1rem;padding:1rem;border-radius:0.5rem;background-color: #f3f4f6;border: 1px solid #e5e7eb;">
    </div>

    <div class="panel">
      <div class="controls">
        <select id="eventSelect" style="padding:8px;border:1px solid #e5e7eb;border-radius:6px">
          <option value="">-- selecione evento --</option>
        </select>

        <div style="margin-left:auto;display:flex;gap:8px">
          <button id="emitAllBtn" class="btn out">Emitir todos</button>
          <button id="emitBtn" class="btn">Emitir selecionados</button>
        </div>
      </div>

      <div id="lista">
        <p style="color:#6b7280">Selecione um evento para ver os inscritos. A validação 70%–75% será feita somente ao emitir.</p>
      </div>
    </div>

    <!-- Seção de consulta de certificados emitidos -->
    <div class="panel" style="margin-top: 2rem;">
      <h2 style="color:#125c2b;font-size:1.5rem;margin-bottom:1rem;font-weight:700">Certificados Emitidos</h2>
      <div class="controls">
        <select id="issuedEventSelect" style="padding:8px;border:1px solid #e5e7eb;border-radius:6px">
          <option value="todos">-- Todos os Eventos --</option>
        </select>
      </div>

      <div id="issuedList" style="margin-top: 1rem;">
        <p style="color:#6b7280">Selecione um evento para filtrar ou veja todos os certificados emitidos.</p>
      </div>
      <div id="issuedTotal" style="margin-top: 1rem; color: #666; font-size: 0.9rem;">
        <!-- Contagem total será inserida aqui -->
      </div>
    </div>
  </div>

  <!-- Modal de upload do fundo do certificado -->
  <div id="uploadModal" class="modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; z-index:50;">
    <div class="modal-box" style="background:#fff;padding:20px;border-radius:8px;width:90%;max-width:480px;">
      <h3 style="margin-top:0">Upload do fundo do certificado</h3>
      <p>Envie a imagem que será usada como plano de fundo (opcional). JPG/PNG, máx 5MB.</p>
      <input type="file" id="bgFile" accept="image/png, image/jpeg"><br>
      <img id="bgPreview" class="file-preview" style="display:none; max-width:100%; height:auto; margin-top:8px;" alt="preview">
      <div style="margin-top:12px;display:flex;gap:8px;justify-content:flex-end">
        <button class="btn out" type="button" id="cancelUploadBtn">Cancelar</button>
        <button id="confirmEmitBtn" class="btn" type="button">Emitir e enviar</button>
      </div>
    </div>
  </div>

  <div id="output" style="display:none;margin-top:12px;padding:10px;border-radius:6px;background:#fff;max-width:980px;"></div>
  
  <!-- no head/footer mantenha HTML existente; apenas substitua o bloco de script por este: -->
  <script> // ATENÇÃO: O endpoint de emissão foi alterado para o próprio certificados.php
document.addEventListener('DOMContentLoaded', function () {
  const eventSelect = document.getElementById('eventSelect');
  const lista = document.getElementById('lista');
  const emitBtn = document.getElementById('emitBtn');
  const emitAllBtn = document.getElementById('emitAllBtn');
  const uploadModal = document.getElementById('uploadModal');
  const bgFile = document.getElementById('bgFile');
  const bgPreview = document.getElementById('bgPreview');
  const confirmEmitBtn = document.getElementById('confirmEmitBtn');
  const cancelUploadBtn = document.getElementById('cancelUploadBtn');
  const output = document.getElementById('output');
  
  const issuedEventSelect = document.getElementById('issuedEventSelect');
  const issuedList = document.getElementById('issuedList');
  const issuedTotal = document.getElementById('issuedTotal');

  function showModal() {
    uploadModal.style.display = 'flex';
  }
  function hideModal() {
    uploadModal.style.display = 'none';
  }

  async function fetchEvents(){
    try {
      const res = await fetch('?action=events');
      const json = await res.json();
      if (json.error) {
        console.error('events error', json);
        return;
      }
      (json.data||[]).forEach(ev => {
        const o = document.createElement('option');
        o.value = ev.id_evento; o.textContent = (ev.titulo || '') + ' — ' + (ev.data_inicio || '');
        const o2 = o.cloneNode(true);
        
        eventSelect.appendChild(o);
        issuedEventSelect.appendChild(o2);
      });
    } catch (err) {
      console.error('fetchEvents error', err);
    }
  }

  async function loadParticipants(id_evento){
    if(!id_evento){ lista.innerHTML = '<p style="color:#6b7280">Selecione um evento para ver os inscritos.</p>'; return; }
    try {
      const res = await fetch(`?action=participants&id_evento=${encodeURIComponent(id_evento)}`);
      const json = await res.json();
      if(json.error){ lista.innerHTML = '<p>Erro: '+json.error+'</p>'; return; }
      const rows = json.data || [];
      if(!rows.length){ lista.innerHTML = '<p style="color:#6b7280">Nenhum inscrito encontrado para este evento.</p>'; return; }
      
      let html = `<div class="overflow-x-auto border border-gray-200 rounded-lg">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th scope="col" class="px-6 py-3 text-center w-12"><input id="chkAll" type="checkbox"></th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Participante</th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Presenças</th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">% Frequência</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">`;
      rows.forEach(r=>{
        html += `<tr class="hover:bg-gray-50">
          <td class="px-6 py-4 whitespace-nowrap text-center"><input type="checkbox" name="ids[]" value="${r.id_inscricao}" class="h-4 w-4 text-indigo-600 border-gray-300 rounded"></td>
          <td class="px-6 py-4 whitespace-nowrap"><div class="text-sm font-medium text-gray-900">${escapeHtml(r.nome)}</div><div class="text-sm text-gray-500">${escapeHtml(r.email)}</div></td>
          <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">${r.presencas}</td>
          <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">${r.pct}%</td>
        </tr>`;
      });
      html += '</tbody></table></div>';
      lista.innerHTML = html;

      const chkAll = document.getElementById('chkAll');
      if (chkAll) {
        chkAll.addEventListener('change', e=>{
          document.querySelectorAll('input[name="ids[]"]').forEach(cb => cb.checked = e.target.checked);
        });
      }
    } catch (err) {
      console.error('loadParticipants error', err);
      lista.innerHTML = '<p>Erro ao carregar inscritos.</p>';
    }
  }

  async function loadIssuedCertificates(id_evento = 'todos') {
    const url = id_evento === 'todos' 
        ? `?action=list_issued` 
        : `?action=list_issued&id_evento=${encodeURIComponent(id_evento)}`;
    
    issuedList.innerHTML = '<p>Carregando certificados...</p>';
    issuedTotal.textContent = '';

    try {
        const res = await fetch(url);
        const json = await res.json();
        if (json.error) {
            issuedList.innerHTML = `<p>Erro: ${json.error}</p>`;
            return;
        }
        const rows = json.data || [];
        if (!rows.length) {
            issuedList.innerHTML = '<p>Nenhum certificado emitido encontrado para este filtro.</p>';
            return;
        }
        let html = `<div class="overflow-x-auto border border-gray-200 rounded-lg">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Participante</th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Evento</th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Data Emissão</th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ações</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">`;
        rows.forEach(c => {
            const dataEmissao = new Date(c.data_emissao).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric' });
            html += `<tr class="hover:bg-gray-50">
                <td class="px-6 py-4 whitespace-nowrap"><div class="text-sm font-medium text-gray-900">${escapeHtml(c.nome_participante)}</div><div class="text-sm text-gray-500">${escapeHtml(c.email_participante)}</div></td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">${escapeHtml(c.evento_titulo)}</td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">${dataEmissao}</td>
                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium"><a href="certificado_view.php?hash=${c.codigo_hash}" target="_blank" class="text-indigo-600 hover:text-indigo-900">Visualizar</a></td>
            </tr>`;
        });
        html += '</tbody></table></div>';
        issuedList.innerHTML = html;
        issuedTotal.textContent = `Total: ${rows.length} certificado(s) emitido(s).`;
    } catch (err) {
        issuedList.innerHTML = '<p>Erro ao carregar certificados emitidos.</p>';
    }
  }

  function escapeHtml(s){ return String(s).replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]); }

  eventSelect.addEventListener('change', ()=> loadParticipants(eventSelect.value));

  emitBtn.addEventListener('click', ()=>{
    const selected = Array.from(document.querySelectorAll('input[name="ids[]"]:checked')).map(i=>i.value);
    if(!selected.length){ alert('Selecione ao menos um inscrito.'); return; }
    bgFile.value = '';
    bgPreview.style.display = 'none';
    showModal();
  });

  emitAllBtn.addEventListener('click', async ()=>{
    const checkboxes = Array.from(document.querySelectorAll('input[name="ids[]"]'));
    if(!checkboxes.length){ alert('Carregue participantes antes.'); return; }
    checkboxes.forEach(cb => cb.checked = true);
    emitBtn.click();
  });

  bgFile.addEventListener('change', ()=>{
    const f = bgFile.files[0];
    if(!f) { bgPreview.style.display='none'; return; }
    // allow only PNG or JPEG
    const allowed = ['image/png','image/jpeg'];
    if (!allowed.includes(f.type)) {
      alert('Formato inválido — envie apenas PNG ou JPEG.');
      bgFile.value = '';
      bgPreview.style.display = 'none';
      return;
    }
    if(f.size > 5*1024*1024){ alert('Máx 5MB'); bgFile.value=''; return; }
    const reader = new FileReader();
    reader.onload = e => { bgPreview.src = e.target.result; bgPreview.style.display='block'; };
    reader.readAsDataURL(f);
  });

  // fechar modal ao clicar no botão cancelar
  cancelUploadBtn.addEventListener('click', ()=> hideModal());

  // fechar modal ao clicar fora da caixa
  uploadModal.addEventListener('click', function(e){
    if (e.target === uploadModal) hideModal();
  });

  confirmEmitBtn.addEventListener('click', async ()=>{
    const selected = Array.from(document.querySelectorAll('input[name="ids[]"]:checked')).map(i=>i.value);
    if(!selected.length){ alert('Selecione ao menos um inscrito.'); return; }

    const form = new FormData();
    // Adiciona os IDs selecionados ao FormData.
    // O PHP irá recebê-los como um array $_POST['ids']
    selected.forEach(id => {
      form.append('ids[]', id);
    });

    // Adiciona o arquivo de imagem, se um foi selecionado
    if (bgFile.files[0]) {
      form.append('background', bgFile.files[0]);
    }

    output.style.display='block';
    output.textContent = 'Processando...';
    confirmEmitBtn.disabled = true;

    try {
      const res = await fetch('?action=emit_lote', { method: 'POST', body: form });
      const json = await res.json();
      console.log('Resposta detalhada do servidor:', json); // Log detalhado no console

      // não imprimir o JSON cru do banco — mostrar mensagem amigável
      if (json && json.success) {
        output.innerHTML = '<div style="color:green">Certificados emitidos com sucesso.</div>';
        // Opcional: mostrar detalhes dos resultados
        if (json.results && json.results.length) {
            const savedCount = json.results.filter(r => r.status === 'saved').length;
            output.innerHTML += `<br>${savedCount} de ${json.results.length} certificados foram processados.`;
        }
      } else {
        console.error('Falha na emissão:', json);
        output.innerHTML = '<div style="color:orange">Processamento concluído com avisos. Verifique logs do servidor.</div>';
      }
    } catch (err) {
      output.textContent = 'Erro de comunicação: ' + (err.message || err);
    } finally {
      confirmEmitBtn.disabled = false;
      hideModal();
      // Recarrega a lista de emitidos para refletir as novas emissões
      loadIssuedCertificates(issuedEventSelect.value);
    }
  });

  // inicializa
  fetchEvents();
  loadIssuedCertificates(); // Carrega todos ao iniciar
  issuedEventSelect.addEventListener('change', () => loadIssuedCertificates(issuedEventSelect.value));
});
</script>
</body>
</html>
