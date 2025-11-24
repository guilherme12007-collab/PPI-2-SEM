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
  <link rel="stylesheet" href="assets/css/index_organizador.CSS">
</head>
<body>
  <div class="sidebar">
    <img src="img/logo_iffar-removebg-preview.png" alt="iff" class="logo">
    <nav>
      <ul>
        <li class="active"><a href="index_organizador.php"><i class="fa-solid fa-chart-line"></i>Dashboard</a></li>
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
