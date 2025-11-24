<?php
require_once 'Database.php';

try {
    $pdo = getDbConnection();

    // Helper para retornar contagens com segurança
    function fetchCount(PDO $pdo, string $sql, array $params = []): int {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return isset($row['total']) ? (int)$row['total'] : 0;
    }

    // 1) Eventos ativos (status armazenado como varchar na tabela evento)
    $eventosAtivos = fetchCount($pdo, "SELECT COUNT(*) AS total FROM evento WHERE status = '1' OR status = 'Aberto'");

    // 2) Novos eventos na semana (data_inicio é timestamp)
    $novosEventos = fetchCount($pdo, "SELECT COUNT(*) AS total FROM evento WHERE data_inicio >= NOW() - INTERVAL '7 days'");

    // 3) Total de inscritos (tabela inscricao)
    $totalInscritos = fetchCount($pdo, "SELECT COUNT(*) AS total FROM inscricao");

    // 4) Check-ins hoje (presenca.data_registro timestamp; comparo a data)
    $checkinsHoje = fetchCount($pdo, "SELECT COUNT(*) AS total FROM presenca WHERE (data_registro::date) = CURRENT_DATE");

    // 5) Eventos com check-in hoje (conta distinct id_evento via inscricao)
    $eventosHoje = fetchCount($pdo, "
        SELECT COUNT(DISTINCT i.id_evento) AS total
        FROM inscricao i
        JOIN presenca p ON p.id_inscricao = i.id_inscricao
        WHERE (p.data_registro::date) = CURRENT_DATE
    ");

    // 6) Taxa de presença (presencas / inscricoes)
    $totalPresencas = fetchCount($pdo, "SELECT COUNT(*) AS total FROM presenca");
    $totalInscricoes = fetchCount($pdo, "SELECT COUNT(*) AS total FROM inscricao");
    $taxaPresenca = $totalInscricoes > 0 ? round(($totalPresencas / $totalInscricoes) * 100) : 0;

    // 7) Eventos recentes (traz titulo, data_inicio, status e quantidade de inscritos)
    $stmt = $pdo->query("
        SELECT e.id_evento,
               e.titulo,
               e.data_inicio,
               e.status,
               COALESCE(ci.inscritos, 0) AS inscritos
        FROM evento e
        LEFT JOIN (
            SELECT id_evento, COUNT(*) AS inscritos
            FROM inscricao
            GROUP BY id_evento
        ) ci ON ci.id_evento = e.id_evento
        ORDER BY e.data_inicio DESC
        LIMIT 3
    ");
    $eventosRecentes = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    // Em ambiente de produção, registre o erro em log e mostre mensagem genérica.
    die("Erro ao obter dados do banco: " . htmlspecialchars($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard do Administrador</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css" integrity="sha512-2SwdPD6INVrV/lHTZbO2nodKhrnDdJK9/kg2XD1r9uGqPo1cUbujc+IYdlYdEErWNu69gVcYgdxlmVmzTWnetw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    /* Mantida a mesma organização do CSS */
    body { font-family: 'Segoe UI', Arial, sans-serif;
       background: #f6f7f9; 
       color: #222; 
       margin: 0; }

    .sidebar { 
      position: fixed; 
      left: 0; 
      top: 0; 
      bottom: 0; 
      width: 220px; 
      background: #fff; 
      border-right: 1px solid #e5e7eb; 
      padding: 2rem 1rem 1rem 1rem; 
      display: flex; 
      flex-direction: column; 
      gap: 2rem; 
      min-height: 100vh; z-index: 10; }

    .sidebar .logo { 
      width: 120px; 
      margin-bottom: 2rem; 
      display: block; 
      margin-left: auto; 
      margin-right: auto; }

    .sidebar nav { 
      display: flex; 
      flex-direction: column; 
      gap: 1rem; }

    .sidebar nav a { 
      color: #222; 
      text-decoration: none; 
      font-weight: 600; 
      padding: 0.7rem 1rem; 
      border-radius: 8px; 
      display: flex; 
      align-items: center;
      gap: 0.7rem; 
      transition: background 0.2s; }

        .sidebar nav ul li.active a,
        .sidebar nav ul li a:hover {
            background: #22c55e22;
            color: #22c55e;
        }
        .sidebar nav a.active, .sidebar nav a:hover { background:#22c55e22; color:#22c55e }
        .main-content {
            flex: 1;
            padding: 0 40px;
        }

    .main-content { margin-left: 240px; 
      padding: 2rem; 
      min-height: 100vh; }

    .dashboard-header { 
      font-size: 2rem; 
      font-weight: 700; 
      margin-bottom: 0.5rem; 
      color: #125c2b; }

    .dashboard-sub { 
      color: #666; 
      margin-bottom: 2rem; 
      font-size: 1.1rem; }

    .cards-row { 
      display: flex; 
      gap: 2rem; 
      margin-bottom: 2rem; 
      flex-wrap: wrap; }

    .card { 
      background: #fff; 
      border-radius: 12px; 
      box-shadow: 0 4px 24px rgba(0,0,0,0.07); 
      padding: 1.5rem 2rem; 
      min-width: 220px; 
      flex: 1; color: #222; 
      border: 1px solid #e5e7eb; }

    .card h3 { 
      font-size: 1.1rem; 
      color: #125c2b; 
      margin-bottom: 0.7rem; 
      font-weight: 700; }

    .card .big { 
      font-size: 2.2rem; 
      font-weight: 700; 
      color: #22c55e; 
      margin-bottom: 0.3rem; }

    .card .muted {
      color: #888; 
      font-size: 0.95rem; }

    .recent-events { 
      background: #fff; 
      border-radius: 12px; 
      box-shadow: 0 4px 24px rgba(0,0,0,0.07); 
      padding: 1.5rem 2rem; 
      border: 1px solid #e5e7eb; }

    .recent-events h3 { 
      color: #125c2b; 
      font-size: 1.1rem; 
      margin-bottom: 1rem; 
      font-weight: 700; }

    .event-list { 
      list-style: none; 
      padding: 0; 
      margin: 0; }

    .event-list li { 
      display: flex; 
      justify-content: space-between; 
      align-items: center; 
      padding: 1rem 0; 
      border-bottom: 1px solid #eee; 
      font-size: 1rem; 
      color: #222; }

    .event-list li:last-child { 
      border-bottom: none; }

    .event-status { 
      color: #22c55e; 
      font-weight: 600; 
      font-size: 0.95rem; }

    @media (max-width: 900px) { 
      .main-content { margin-left: 0; padding: 1rem; } .sidebar { position: static; width: 100%; flex-direction: row; gap: 1rem; padding: 1rem; } 
      .cards-row { flex-direction: column; gap: 1rem; } }
  </style>
</head>
<body>
  <div class="sidebar">
    <img src="img/logo_iffar-removebg-preview.png" alt="iff" class="logo">
    <nav>
      <ul>
        <li class="active"><a href="index.php"><i class="fa-solid fa-chart-line"></i>Dashboard</a></li>
        <li><a href="eventosADM.php"><i class="fa-solid fa-calendar-days"></i>Eventos</a></li>
        <li><a href="inscritos.html"><i class="fa-solid fa-users"></i>Inscritos</a></li>
        <li><a href="presenca.html"><i class="fa-solid fa-check"></i>Presença</a></li>
        <li><a href="certificados.php" aria-current="page"><i class="fa-solid fa-certificate"></i>&nbsp;Certificados</a></li>
        <li><a href="#"><i class="fa-solid fa-gear"></i>Configurações</a></li>
        </ul>
      </nav>
  </div>
  <div class="main-content">
    <div class="dashboard-header">Dashboard</div>
    <div class="dashboard-sub">Visão geral dos eventos e estatísticas</div>
    <div class="cards-row">
      <div class="card">
        <h3>Eventos Ativos</h3>
        <div class="big"><?= htmlspecialchars($eventosAtivos) ?></div>
        <div class="muted"><?= htmlspecialchars($novosEventos) ?> novos esta semana</div>
      </div>
      <div class="card">
        <h3>Total de Inscritos</h3>
        <div class="big"><?= number_format($totalInscritos, 0, ',', '.') ?></div>
        <div class="muted">+18% vs mês anterior</div>
      </div>
      <div class="card">
        <h3>Check-ins Hoje</h3>
        <div class="big"><?= htmlspecialchars($checkinsHoje) ?></div>
        <div class="muted">Em <?= htmlspecialchars($eventosHoje) ?> eventos</div>
      </div>
      <div class="card">
        <h3>Taxa de Presença</h3>
        <div class="big"><?= htmlspecialchars($taxaPresenca) ?>%</div>
        <div class="muted">Média geral</div>
      </div>
    </div>
    <div class="recent-events">
      <h3>Eventos Recentes</h3>
      <ul class="event-list">
        <?php if (!empty($eventosRecentes)): ?>
          <?php foreach ($eventosRecentes as $evento): ?>
            <li>
              <span>
                <?= htmlspecialchars($evento['titulo']) ?><br>
                <span class="muted"><?= htmlspecialchars((int)$evento['inscritos']) ?> inscritos</span>
              </span>
              <?php
                $statusVal = trim((string)$evento['status']);
                $isAtivo = ($statusVal === '1' || strcasecmp($statusVal, 'ativo') === 0);
              ?>
              <?php if ($isAtivo): ?>
                <span class="event-status">Em andamento</span>
              <?php else: ?>
                <span class="event-status" style="color:#888;">
                  Programado <?= htmlspecialchars(date('d M', strtotime($evento['data_inicio'] ?? 'now'))) ?>
                </span>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        <?php else: ?>
          <li>
            <span>Nenhum evento encontrado</span>
            <span class="event-status" style="color:#888;">-</span>
          </li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</body>
</html>
