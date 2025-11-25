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

    // emitir lote (validação 70-75% ocorre aqui)
    if ($action === 'emit_lote' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isOrganizador()) {
            http_response_code(403);
            echo json_encode(['error' => 'forbidden']);
            exit;
        }

        $body = json_decode(file_get_contents('php://input'), true);
        $ids = is_array($body['ids'] ?? null) ? $body['ids'] : [];
        if (empty($ids)) {
            http_response_code(400);
            echo json_encode(['error' => 'bad_request', 'reason' => 'ids_obrigatorios']);
            exit;
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
                  SELECT i.id_evento, u.nome, u.email, e.titulo,
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
                if (!($pct >= 70.0 && $pct <= 75.0)) {
                    $results[] = [
                        'id_inscricao' => $id_inscricao,
                        'nome' => $row['nome'],
                        'email' => $row['email'],
                        'pct' => $pct,
                        'status' => 'skipped',
                        'reason' => 'percentual_fora_intervalo_70_75'
                    ];
                    continue;
                }

                $id_evento = (int)$row['id_evento'];
                $nome = $row['nome'];
                $email = $row['email'];

                $codigo_rastreio = gerarCodigoRastreio($id_inscricao, $id_evento);
                $codigo_hash = gerarCodigoHash($id_inscricao, $id_evento);

                $sqlUpsert = "
                  INSERT INTO certificado (
                    id_inscricao, id_evento, nome_participante, email_participante,
                    data_emissao, codigo_rastreio, codigo_hash, caminho_arquivo, status_validacao
                  ) VALUES (
                    :id_inscricao, :id_evento, :nome_participante, :email_participante,
                    NOW(), :codigo_rastreio, :codigo_hash, NULL, :status_validacao
                  )
                  ON CONFLICT (id_inscricao) DO UPDATE
                    SET id_evento = EXCLUDED.id_evento,
                        nome_participante = EXCLUDED.nome_participante,
                        email_participante = EXCLUDED.email_participante,
                        data_emissao = EXCLUDED.data_emissao,
                        codigo_rastreio = EXCLUDED.codigo_rastreio,
                        codigo_hash = EXCLUDED.codigo_hash,
                        caminho_arquivo = EXCLUDED.caminho_arquivo,
                        status_validacao = EXCLUDED.status_validacao
                  RETURNING id_certificado
                ";
                $stmt2 = $pdo->prepare($sqlUpsert);
                $stmt2->execute([
                    ':id_inscricao' => $id_inscricao,
                    ':id_evento' => $id_evento,
                    ':nome_participante' => $nome,
                    ':email_participante' => $email,
                    ':codigo_rastreio' => $codigo_rastreio,
                    ':codigo_hash' => $codigo_hash,
                    ':status_validacao' => 'PENDENTE'
                ]);

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

                $results[] = [
                    'id_inscricao' => $id_inscricao,
                    'id_certificado' => $id_cert,
                    'codigo_rastreio' => $codigo_rastreio,
                    'codigo_hash' => $codigo_hash,
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
        <li class="active"><a href="certificados.php" aria-current="page"><i class="fa-solid fa-certificate"></i> Certificados</a></li>
        <li><a href="#"><i class="fa-solid fa-gear"></i> Configurações</a></li>
      </ul>
    </nav>
  </div>

  <div class="main-content">
    <h1 style="color:#125c2b;font-size:1.8rem;margin-bottom:0.25rem;font-weight:700">Certificados</h1>
    <p style="color:#666;margin-bottom:1rem">Grave os dados necessários para que o inscrito gere o certificado em seu navegador posteriormente.</p>

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
  <script>
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

  function showModal() {
    uploadModal.style.display = 'flex';
  }
  function hideModal() {
    uploadModal.style.display = 'none';
  }

  async function fetchEvents(){
    try {
      const res = await fetch('process/certificados/events.php');
      const json = await res.json();
      if (json.error) {
        console.error('events error', json);
        return;
      }
      (json.data||[]).forEach(ev => {
        const o = document.createElement('option');
        o.value = ev.id_evento; o.textContent = (ev.titulo || '') + ' — ' + (ev.data_inicio || '');
        eventSelect.appendChild(o);
      });
    } catch (err) {
      console.error('fetchEvents error', err);
    }
  }

  async function loadParticipants(id_evento){
    if(!id_evento){ lista.innerHTML = '<p style="color:#6b7280">Selecione um evento para ver os inscritos.</p>'; return; }
    try {
      const res = await fetch(`process/certificados/participants.php?id_evento=${encodeURIComponent(id_evento)}`);
      const json = await res.json();
      if(json.error){ lista.innerHTML = '<p>Erro: '+json.error+'</p>'; return; }
      const rows = json.data || [];
      if(!rows.length){ lista.innerHTML = '<p>Nenhum inscrito.</p>'; return; }
      let html = '<table><thead><tr><th><input id="chkAll" type="checkbox"></th><th>Participante</th><th>Email</th><th>Presenças</th><th>%</th></tr></thead><tbody>';
      rows.forEach(r=>{
        html += `<tr>
          <td><input type="checkbox" name="ids[]" value="${r.id_inscricao}"></td>
          <td>${escapeHtml(r.nome)}</td>
          <td>${escapeHtml(r.email)}</td>
          <td>${r.presencas}</td>
          <td>${r.pct}%</td>
        </tr>`;
      });
      html += '</tbody></table>';
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
    form.append('action','emit_lote');
    selected.forEach(id => form.append('ids[]', id));
    if(bgFile.files[0]) form.append('background', bgFile.files[0]);

    output.style.display='block';
    output.textContent = 'Processando...';

    try {
      const res = await fetch('process/certificados/emit_lote.php', { method: 'POST', body: form });
      const json = await res.json();
      // não imprimir o JSON cru do banco — mostrar mensagem amigável
      if (json && json.success) {
        output.innerHTML = '<div style="color:green">Certificados emitidos com sucesso.</div>';
      } else {
        output.innerHTML = '<div style="color:orange">Processamento concluído com avisos. Verifique logs do servidor.</div>';
      }
    } catch (err) {
      output.textContent = 'Erro de comunicação: ' + (err.message || err);
    } finally {
      hideModal();
    }
  });

  // inicializa
  fetchEvents();
});
</script>
</body>
</html>
