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
        WHERE e.status = 'Aberto'
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
  <title>Inscrever-se em Eventos</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css" integrity="sha512-2SwdPD6INVrV/lHTZbO2nodKhrnDdJK9/kg2XD1r9uGqPo1cUbujc+IYdlYdEErWNu69gVcYgdxlmVmzTWnetw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
  <script src="https://cdn.tailwindcss.com"></script>
  <!-- Usando o CSS do index_participante para a grade de eventos -->
  <link rel="stylesheet" href="assets/css/index_participante.css">
  <!-- Estilos específicos para a página do organizador -->
  <style>
    body {
        background-color: #f8f9fa;
    }
    .sidebar {
        width: 250px;
        background: #fff;
        padding: 20px;
        height: 100vh;
        position: fixed;
        border-right: 1px solid #e5e7eb;
    }
    .sidebar .logo {
        margin-bottom: 30px;
        text-align: center;
    }
    .sidebar nav ul {
        list-style: none;
        padding: 0;
    }
    .sidebar nav ul li a {
        display: flex;
        align-items: center;
        padding: 12px 15px;
        color: #333;
        text-decoration: none;
        border-radius: 8px;
        margin-bottom: 5px;
        transition: background 0.2s, color 0.2s;
    }
    .sidebar nav ul li a:hover, .sidebar nav ul li.active a {
        background: #e0f2fe;
        color: #0c4a6e;
    }
    .sidebar nav ul li a i {
        margin-right: 10px;
    }
    .main-content {
        margin-left: 250px;
        padding: 20px;
    }
  </style>
</head>
<body>
  <div class="sidebar">
    <img src="img/logo_iffar-removebg-preview.png" alt="iff" class="logo">
    <nav>
      <ul>
        <li><a href="index_organizador.php"><i class="fa-solid fa-chart-line"></i>Dashboard</a></li>
        <li><a href="eventosADM.php"><i class="fa-solid fa-calendar-days"></i>Eventos</a></li>
        <li><a href="inscritos.php"><i class="fa-solid fa-users"></i> Inscritos</a></li>
        <li><a href="certificados.php"><i class="fa-solid fa-certificate"></i>&nbsp;Certificados</a></li>
        <li class="active"><a href="inscrever_organizador.php"><i class="fa-solid fa-pen-to-square"></i>Inscrever-se</a></li>
      </ul>
    </nav>
  </div>

  <div class="main-content">
    <main class="main-content" style="padding:0;">
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
            <p class="event-description">Ainda não há eventos abertos para inscrição.</p>
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
              <div class="event-detail"><i class="fa-solid fa-calendar"></i><span><?php echo $dateText; ?></span></div>
              <div class="event-detail"><i class="fa-solid fa-location-dot"></i><span><?php echo $local; ?></span></div>
              <div class="event-detail"><i class="fa-solid fa-users"></i><span><span class="inscritos-count" data-event-id="<?php echo $eventId; ?>"><?php echo $inscritos; ?></span> inscritos</span></div>
              <div class="event-detail"><i class="fa-solid fa-clock"></i><span><?php echo $carga; ?> h</span></div>
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