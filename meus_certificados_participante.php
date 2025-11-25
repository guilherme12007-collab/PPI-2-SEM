<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/Database.php';

// exige autenticação
if (empty($_SESSION['id_usuario'])) {
    header('Location: login.php');
    exit;
}

$uid = (int) $_SESSION['id_usuario'];
$threshold = 70.0; // % mínimo para emitir

try {
    $pdo = getDbConnection();

    $sql = "
    SELECT
      i.id_inscricao,
      e.id_evento,
      e.titulo,
      e.data_inicio,
      e.data_fim,
      COALESCE(p.presencas,0) AS presencas,
      GREATEST(1, ((EXTRACT(epoch FROM (COALESCE(e.data_fim,e.data_inicio)::timestamp - e.data_inicio::timestamp)) / 86400)::int + 1)) AS total_dias,
      ROUND((COALESCE(p.presencas,0)::numeric / GREATEST(1, ((EXTRACT(epoch FROM (COALESCE(e.data_fim,e.data_inicio)::timestamp - e.data_inicio::timestamp)) / 86400)::int + 1)))*100, 2) AS pct,
      c.id_certificado,
      c.caminho_arquivo,
      c.status_validacao,
      c.codigo_hash
    FROM inscricao i
    JOIN evento e ON e.id_evento = i.id_evento
    LEFT JOIN (
      SELECT id_inscricao, COUNT(DISTINCT data_registro) AS presencas
      FROM presenca
      GROUP BY id_inscricao
    ) p ON p.id_inscricao = i.id_inscricao
    LEFT JOIN certificado c ON c.id_inscricao = i.id_inscricao
    WHERE i.id_usuario = :uid
    ORDER BY e.data_inicio DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':uid' => $uid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $rows = [];
    $error = $e->getMessage();
}

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Meus Certificados</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
  <style>
    /* layout similar ao perfil.php para manter o visual antigo */
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;background:#e8e8e8;min-height:100vh;display:flex}
    .sidebar{width:250px;background:#2d2d2d;color:#fff;padding:2rem 0;position:fixed;height:100vh;overflow:auto}
    .sidebar-header{padding:0 1.5rem;margin-bottom:2rem}
    .sidebar-header h2{font-size:1.1rem;color:#fff}
    .sidebar-menu{list-style:none;padding:0;margin:0}
    .sidebar-menu li{margin-bottom:.5rem}
    .sidebar-menu a{display:flex;align-items:center;gap:1rem;padding:.9rem 1.5rem;color:#b0b0b0;text-decoration:none;font-size:.95rem}
    .sidebar-menu a:hover,.sidebar-menu a.active{background:#3d3d3d;color:#fff;border-left:4px solid #28a745}
    .main-wrapper{margin-left:250px;flex:1;display:flex;flex-direction:column}
    header{background:#fff;padding:1rem 1.5rem;box-shadow:0 2px 4px rgba(0,0,0,.08);display:flex;justify-content:space-between;align-items:center}
    .logo-placeholder{width:50px;height:50px;background:#f0f0f0;border-radius:4px;display:flex;align-items:center;justify-content:center;color:#999}
    .container{max-width:1100px;margin:1.5rem auto;padding:1rem;width:100%}
    .page-header h1{font-size:1.6rem;color:#333;margin-bottom:.25rem}
    .page-header p{color:#666;font-size:.95rem;margin-bottom:1rem}
    .cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px}
    .card{background:#fff;padding:16px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.06);display:flex;flex-direction:column;justify-content:space-between}
    .card h3{font-size:1.05rem;margin-bottom:.5rem;color:#111}
    .meta{color:#6b7280;font-size:.9rem;margin-bottom:.5rem}
    .badge{display:inline-block;padding:6px 10px;border-radius:999px;font-weight:700;font-size:.85rem}
    .badge-available{background:#10b981;color:#fff}
    .badge-eligible{background:#34d399;color:#065f46}
    .badge-pending{background:#f59e0b;color:#fff}
    .badge-unavailable{background:#6b7280;color:#fff}
    .btn{padding:8px 12px;border-radius:6px;border:0;cursor:pointer}
    .btn-primary{background:#28a745;color:#fff}
    .btn-ghost{background:#f3f4f6}
    .small{font-size:.85rem;color:#6b7280}
    @media (max-width:900px){ .sidebar{display:none} .main-wrapper{margin-left:0} }
  </style>
</head>
<body>
  <aside class="sidebar">
    <div class="sidebar-header"><h2>Menu</h2></div>
    <ul class="sidebar-menu">
      <li><a href="index_participante.php"><i class="fa-solid fa-table-cells"></i><span>Mural de Eventos</span></a></li>
      <li><a href="meus_eventos_participante.php"><i class="fa-solid fa-calendar-check"></i><span>Meus Eventos</span></a></li>
      <li><a href="meu_certificados_participante.php" class="active"><i class="fa-solid fa-certificate"></i><span>Emitir Certificados</span></a></li>
      <li><a href="perfil.php"><i class="fa-solid fa-user"></i><span>Meu Perfil</span></a></li>
    </ul>
  </aside>

  <div class="main-wrapper">
    <header>
      <div style="display:flex;align-items:center;gap:1rem">
        <div class="logo-placeholder"><img src="https://www.iffarroupilha.edu.br/component/k2/attachments/download/2367/0e66a1e8d61f98d1b8f84efa8700e499" alt="logo" style="height:40px"></div>
        <div>
          <div style="font-weight:700;color:#333">Sistema de Eventos</div>
          <div style="font-size:.85rem;color:#6b7280">Meus Certificados</div>
        </div>
      </div>
      <div>
        <a href="process/logout.php" class="btn btn-ghost" style="text-decoration:none;color:#111"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
      </div>
    </header>

    <main class="container">
      <div class="page-header">
        <h1>Meus Certificados</h1>
        <p>Aqui você vê os certificados dos eventos em que participou — solicite emissão quando elegível e faça download quando disponível.</p>
      </div>

      <?php if (!empty($error)): ?>
        <div style="color:#b91c1c;background:#fee2e2;padding:10px;border-radius:6px;margin-bottom:12px">
          Erro ao carregar dados: <?php echo e($error); ?>
        </div>
      <?php endif; ?>

      <?php if (empty($rows)): ?>
        <p class="small">Nenhum evento encontrado.</p>
      <?php else: ?>
        <div class="cards">
          <?php foreach ($rows as $r):
            $pct = (float)($r['pct'] ?? 0.0);
            $eligible = $pct >= $threshold;
            $status = $r['status_validacao'] ?? null;
            $hasFile = !empty($r['caminho_arquivo']);
            $start = $r['data_inicio'] ? date('d/m/Y', strtotime($r['data_inicio'])) : '';
            $end = !empty($r['data_fim']) ? date('d/m/Y', strtotime($r['data_fim'])) : '';
          ?>
            <div class="card" data-id="<?php echo (int)$r['id_inscricao']; ?>">
              <div>
                <h3><?php echo e($r['titulo']); ?></h3>
                <div class="meta"><?php echo e($start); ?><?php if ($end): ?> — <?php echo e($end); ?><?php endif; ?></div>
                <div class="meta">Presenças: <?php echo e($r['presencas'] ?? 0); ?> / Dias: <?php echo e($r['total_dias'] ?? 1); ?> — <strong><?php echo e($r['pct']); ?>%</strong></div>
              </div>

              <div style="margin-top:12px;display:flex;gap:8px;align-items:center;justify-content:space-between">
                <div>
                  <?php if ($hasFile): 
                    $path = '/' . trim('PPI/' . $r['caminho_arquivo'], '/');
                  ?>
                    <span class="badge badge-available">Disponível</span>
                  <?php elseif ($eligible): ?>
                    <span class="badge badge-eligible">Elegível</span>
                  <?php elseif ($status === 'PENDENTE'): ?>
                    <span class="badge badge-pending">Pendente</span>
                  <?php else: ?>
                    <span class="badge badge-unavailable">Não elegível</span>
                  <?php endif; ?>
                </div>

                <div style="display:flex;gap:8px;align-items:center">
                  <?php if ($hasFile): ?>
                    <?php if (($r['status_validacao'] ?? '') !== 'VALIDADO'): ?>
                      <!-- mesmo botão: abre visualização e dispara validação -->
                      <button class="btn btn-primary" onclick="viewAndValidate('<?php echo e($r['codigo_hash'] ?? ''); ?>', this)">Visualizar / Validar</button>
                    <?php else: ?>
                      <!-- já validado: apenas visualizar + indicação -->
                      <button class="btn" onclick="openCertificateView('<?php echo e($r['codigo_hash'] ?? ''); ?>')">Visualizar</button>
                      <button class="btn btn-ghost" disabled>Validado</button>
                    <?php endif; ?>
                  <?php elseif ($eligible): ?>
                    <button class="btn btn-primary" onclick="requestIssue(<?php echo (int)$r['id_inscricao']; ?>, this)">Solicitar Emissão</button>
                    <?php if (!empty($status)): ?><div class="small" style="margin-left:8px">Status: <?php echo e($status); ?></div><?php endif; ?>
                  <?php else: ?>
                    <button class="btn btn-ghost" disabled>Não elegível</button>
                    <div class="small" style="margin-left:8px">Requer <?php echo e($threshold); ?>% de presença</div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </main>
  </div>

<script>
async function requestIssue(id_inscricao, btn) {
  if (!confirm('Solicitar emissão de certificado?')) return;
  btn.disabled = true;
  const form = new FormData();
  form.append('id_inscricao', id_inscricao);
  try {
    const res = await fetch('process/certificados/request_issue.php', { method: 'POST', body: form });
    const json = await res.json();
    if (json && json.success) {
      alert('Solicitação enviada: ' + (json.message || 'Aguarde a emissão.'));
      location.reload();
    } else {
      alert('Erro: ' + (json.message || JSON.stringify(json)));
      btn.disabled = false;
    }
  } catch (err) {
    alert('Erro de comunicação: ' + (err.message || err));
    btn.disabled = false;
  }
}

function verificar(hash) {
  if (!hash) { alert('Código de validação não disponível.'); return; }
  window.open('process/certificados/validate.php?hash=' + encodeURIComponent(hash), '_blank');
}

function openCertificateView(hash) {
  if (!hash) { alert('Código de validação não disponível.'); return; }
  window.open('process/certificados/render_pdf.php?hash=' + encodeURIComponent(hash), '_blank');
}

async function viewAndValidate(hash, btn) {
  if (!hash) { alert('Hash inválido'); return; }
  // abre visualização em nova aba
  window.open('process/certificados/render_pdf.php?hash=' + encodeURIComponent(hash), '_blank');

  // dispara validação (sem confirmação extra) e atualiza UI
  btn.disabled = true;
  const originalText = btn.textContent;
  btn.textContent = 'Validando...';
  try {
    const res = await fetch('process/certificados/validate.php?hash=' + encodeURIComponent(hash), {
      method: 'POST',
      credentials: 'same-origin'
    });
    const json = await res.json();
    if (json && json.success) {
      // marcar botão como validado
      btn.textContent = 'Validado';
      btn.classList.remove('btn-primary');
      btn.classList.add('btn-ghost');
      btn.disabled = true;
      // opcional - acrescentar etiqueta small "Validado" no card
      const card = btn.closest('.card');
      if (card) {
        let tag = card.querySelector('.validated-label');
        if (!tag) {
          tag = document.createElement('span');
          tag.className = 'small validated-label';
          tag.style.marginLeft = '8px';
          tag.textContent = 'Validado';
          btn.parentElement.appendChild(tag);
        } else {
          tag.textContent = 'Validado';
        }
      }
      // feedback
      alert(json.message || 'Certificado validado com sucesso');
    } else {
      btn.disabled = false;
      btn.textContent = originalText || 'Visualizar / Validar';
      alert('Erro: ' + (json && json.message ? json.message : 'Falha ao validar'));
    }
  } catch (err) {
    btn.disabled = false;
    btn.textContent = originalText || 'Visualizar / Validar';
    alert('Erro de comunicação: ' + (err.message || err));
  }
}
</script>
</body>
</html>