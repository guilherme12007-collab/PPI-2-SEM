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
                $id_cert = (int)$stmt2->fetchColumn();

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
                $results[] = ['id_inscricao' => $id_inscricao, 'error' => 'db_error'];
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
  <style>
    body { font-family: 'Segoe UI', Arial, sans-serif; background: #f6f7f9; color: #222; margin: 0; }
    .sidebar { position: fixed; left: 0; top: 0; bottom: 0; width: 220px; background: #fff; border-right: 1px solid #e5e7eb; padding: 2rem 1rem 1rem 1rem; display:flex; flex-direction:column; gap:2rem; min-height:100vh; z-index:10; }
    .sidebar .logo { width:120px; margin-bottom:2rem; display:block; margin-left:auto; margin-right:auto; }
    .sidebar nav { display:flex; flex-direction:column; gap:1rem; }
    .sidebar nav a { color:#222; text-decoration:none; font-weight:600; padding:0.7rem 1rem; border-radius:8px; display:flex; align-items:center; gap:0.7rem; transition:background 0.2s; }
    .sidebar nav ul li.active a, .sidebar nav ul li a:hover { background:#22c55e22; color:#22c55e; }
    .main-content { margin-left:240px; padding:2rem; min-height:100vh; }
    .panel { background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px; box-shadow: 0 4px 24px rgba(0,0,0,0.04); max-width:980px; }
    .controls{display:flex;gap:8px;align-items:center;margin-bottom:12px}
    .list-item{display:flex;justify-content:space-between;padding:10px;border:1px solid #f3f4f6;margin-bottom:8px;border-radius:6px}
    .btn{background:#22c55e;color:#fff;padding:8px 12px;border-radius:6px;border:none;cursor:pointer}
    .btn.out{background:#fff;color:#111;border:1px solid #e5e7eb}
    @media (max-width:900px){ .main-content{margin-left:0;padding:1rem} .sidebar{position:static;width:100%;flex-direction:row;gap:1rem;padding:1rem} }
  </style>
</head>
<body>
  <div class="sidebar">
    <img src="img/logo_iffar-removebg-preview.png" alt="iff" class="logo">
    <nav>
      <ul>
        <li><a href="index_organizador.php"><i class="fa-solid fa-chart-line"></i> Dashboard</a></li>
        <li><a href="eventosADM.php"><i class="fa-solid fa-calendar-days"></i> Eventos</a></li>
        <li><a href="inscritos.html"><i class="fa-solid fa-users"></i> Inscritos</a></li>
        <li><a href="presenca.html"><i class="fa-solid fa-check"></i> Presença</a></li>
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

  <script src="assets/JS/certificados.js"></script>
</body>
</html>
