<?php
require_once __DIR__ . '/Database.php';

$events = [];
$error = null;

try {
    $pdo = getDbConnection();

    $sql = "
        SELECT
            e.*,
            u.nome AS organizador,
            (SELECT COUNT(*) FROM inscricao i WHERE i.id_evento = e.id_evento) AS inscritos
        FROM evento e
        JOIN usuario u ON u.id_usuario = e.id_organizador
        ORDER BY e.data_inicio DESC
    ";
    $stmt = $pdo->query($sql);
    $events = $stmt->fetchAll();
} catch (\PDOException $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Mural de Eventos</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css" integrity="sha512-2SwdPD6INVrV/lHTZbO2nodKhrnDdJK9/kg2XD1r9uGqPo1cUbujc+IYdlYdEErWNu69gVcYgdxlmVmzTWnetw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
  <link rel="stylesheet" href="assets/css/index_participante.css">
</head>
<body>
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
      <h2>Menu</h2>
    </div>
    <ul class="sidebar-menu">
      <li>
        <a href="#" class="active">
          <i class="fa-solid fa-table-cells"></i>
          <span>Mural de Eventos</span>
        </a>
      </li>
      <li>
        <a href="meus_eventos_participante.php">
          <i class="fa-solid fa-calendar-check"></i>
          <span>Meus Eventos</span>
        </a>
      </li>
      <li>
        <a href="meus_certificados_participante.html">
          <i class="fa-solid fa-certificate"></i>
          <span>Meus Certificados</span>
        </a>
      </li>
      <li>
        <a href="perfil.php">
          <i class="fa-solid fa-user"></i>
          <span>Meu Perfil</span>
        </a>
      </li>
    </ul>
  </aside>

  <div class="main-wrapper">
    <header class="header">
      <div class="header-left">
        <button class="mobile-menu-btn" onclick="toggleSidebar()">
          <i class="fa-solid fa-bars"></i>
        </button>
        <div class="logo-placeholder">
          <img src="https://www.iffarroupilha.edu.br/component/k2/attachments/download/2367/0e66a1e8d61f98d1b8f84efa8700e499" alt="ifflogo" style="height: 80px; width: auto;">
        </div>
        <span class="system-title">Sistema de Eventos</span>
      </div>
      <div class="header-right">
        <a href="login.php" class="btn-logout">
          <i class="fa-solid fa-right-from-bracket"></i>
          <span>Logout</span>
        </a>
      </div>
    </header>

    <main class="main-content">
      <div class="page-header">
        <h1>Mural de Eventos</h1>
        <p>Explore e se inscreva nos eventos disponíveis</p>
      </div>

      <?php if (!empty($error)): ?>
        <div class="alert-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
      <?php endif; ?>

      <div class="search-box">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="text" placeholder="Buscar eventos..." id="searchInput">
      </div>

      <div class="events-grid" id="eventsGrid">
        <?php if (empty($events)): ?>
          <div class="event-card">
            <div class="event-card-header">
              <div>
                <h3 class="event-title">Nenhum evento encontrado</h3>
              </div>
              <span class="event-badge">—</span>
            </div>
            <p class="event-description">Ainda não há eventos cadastrados.</p>
          </div>
        <?php else: ?>
          <?php foreach ($events as $ev): 
              $titulo = htmlspecialchars($ev['titulo'], ENT_QUOTES, 'UTF-8');
              $descricao = htmlspecialchars($ev['descricao'], ENT_QUOTES, 'UTF-8');
              $local = htmlspecialchars($ev['local'], ENT_QUOTES, 'UTF-8');
              $organizador = htmlspecialchars($ev['organizador'], ENT_QUOTES, 'UTF-8');
              $inscritos = (int)$ev['inscritos'];
              $carga = (int)$ev['carga_horaria'];
              $status = htmlspecialchars($ev['status'], ENT_QUOTES, 'UTF-8');
              $start = $ev['data_inicio'] ? date('d/m/Y', strtotime($ev['data_inicio'])) : '';
              $end = $ev['data_fim'] ? date('d/m/Y', strtotime($ev['data_fim'])) : '';
              $dateText = $end ? "{$start} a {$end}" : $start;
              $eventId = (int)$ev['id_evento'];
          ?>
          <div class="event-card" data-event-id="<?php echo $eventId; ?>" data-title="<?php echo strtolower($titulo); ?>" data-desc="<?php echo strtolower($descricao); ?>">
            <div class="event-card-header">
              <div>
                <h3 class="event-title"><?php echo $titulo; ?></h3>
                <div style="font-size:0.85rem;color:#666;margin-top:4px;">Organizador: <?php echo $organizador; ?></div>
              </div>
              <span class="event-badge"><?php echo $status; ?></span>
            </div>
            <p class="event-description"><?php echo $descricao; ?></p>
            <div class="event-details">
              <div class="event-detail">
                <i class="fa-solid fa-calendar"></i>
                <span><?php echo $dateText; ?></span>
              </div>
              <div class="event-detail">
                <i class="fa-solid fa-location-dot"></i>
                <span><?php echo $local; ?></span>
              </div>
              <div class="event-detail">
                <i class="fa-solid fa-users"></i>
                <span><span class="inscritos-count" data-event-id="<?php echo $eventId; ?>"><?php echo $inscritos; ?></span> inscritos</span>
              </div>
              <div class="event-detail">
                <i class="fa-solid fa-clock"></i>
                <span><?php echo $carga; ?> h</span>
              </div>
            </div>
            <button id="btn-inscrever-<?php echo $eventId; ?>" class="btn-inscrever" onclick="inscrever(<?php echo $eventId; ?>)">Inscrever-se</button>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </main>
  </div>

  <script src="assets/js/index_participante.js" defer></script>
</body>
</html>