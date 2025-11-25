<?php
session_start();
require_once __DIR__ . '/Database.php';

// exige autenticação
if (empty($_SESSION['id_usuario'])) {
    header('Location: login.php');
    exit;
}

$events = [];
$error = null;

try {
    $pdo = getDbConnection();

    // buscar todas as inscrições do usuário (a página decide se o evento está em andamento)
    $sql = "
        SELECT
            e.*,
            org.nome AS organizador,
            i.data_inscricao,
            (SELECT COUNT(*) FROM inscricao ins WHERE ins.id_evento = e.id_evento) AS inscritos
        FROM inscricao i
        JOIN evento e ON e.id_evento = i.id_evento
        JOIN usuario org ON org.id_usuario = e.id_organizador
        WHERE i.id_usuario = :uid
        ORDER BY e.data_inicio DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':uid' => (int) $_SESSION['id_usuario']]);
    $events = $stmt->fetchAll();
} catch (\PDOException $e) {
    $error = $e->getMessage();
}
date_default_timezone_set('America/Sao_Paulo');

// colocar antes de exibir a string "Inscrito em:"
function formatDbDatetimeLocal($s, $tz='America/Sao_Paulo') {
  if (empty($s)) return '';
  $s = trim($s);
  try {
    // se o valor contém timezone (Z ou +HH:MM), DateTime tratará corretamente
    if (preg_match('/Z$|[+\-]\d{2}:\d{2}$/', $s)) {
      $dt = new DateTimeImmutable($s);
    } else {
      // assume UTC quando sem tz (muitos DB usam UTC)
      $dt = new DateTimeImmutable($s, new DateTimeZone('UTC'));
    }
    return $dt->setTimezone(new DateTimeZone($tz))->format('d/m/Y H:i');
  } catch (Exception $e) {
    return $s;
  }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meus Eventos</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="assets/css/meus_eventos_participante.css">
</head>
<body>
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header"><h2>Menu</h2></div>
        <ul class="sidebar-menu">
            <li><a href="index_participante.php"><i class="fa-solid fa-table-cells"></i><span>Mural de Eventos</span></a></li>
            <li><a href="#" class="active"><i class="fa-solid fa-calendar-check"></i><span>Meus Eventos</span></a></li>
            <li><a href="meus_certificados_participante.php"><i class="fa-solid fa-certificate"></i><span>Meus Certificados</span></a></li>
            <li><a href="perfil.php"><i class="fa-solid fa-user"></i><span>Meu Perfil</span></a></li>
        </ul>
    </aside>

    <div class="main-wrapper">
        <header>
            <div class="header-left">
                <button class="mobile-menu-btn" onclick="toggleSidebar()"><i class="fa-solid fa-bars"></i></button>
                <div class="logo-placeholder">
                    <img src="https://www.iffarroupilha.edu.br/component/k2/attachments/download/2367/0e66a1e8d61f98d1b8f84efa8700e499" alt="logo" style="height:80px;">
                </div>
                <span class="system-title">Sistema de Eventos</span>
            </div>
            <div class="header-actions">
                <a class="logout" href="process/logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
            </div>
        </header>

        <div class="container">
            <div class="page-header">
                <h1>Meus Eventos</h1>
                <p>Gerencie suas inscrições e controle de presença</p>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <div class="events-grid">
                <?php if (empty($events)): ?>
                    <div class="event-card">
                        <div class="event-header">
                            <h2 class="event-title">Nenhuma inscrição encontrada</h2>
                            <span class="event-badge badge-finalizado">—</span>
                        </div>
                        <p class="event-description">Você ainda não se inscreveu em nenhum evento.</p>
                    </div>
                <?php else: ?>
                    <?php 
                    $today = new DateTimeImmutable('today');
                    foreach ($events as $ev):
                        $titulo = htmlspecialchars($ev['titulo'], ENT_QUOTES, 'UTF-8');
                        $descricao = htmlspecialchars($ev['descricao'], ENT_QUOTES, 'UTF-8');
                        $local = htmlspecialchars($ev['local'], ENT_QUOTES, 'UTF-8');
                        $organizador = htmlspecialchars($ev['organizador'], ENT_QUOTES, 'UTF-8');
                        $inscritos = (int) $ev['inscritos'];
                        $carga = (int) $ev['carga_horaria'];
                        $statusDb = isset($ev['status']) ? $ev['status'] : 'Aberto';
                        $start = !empty($ev['data_inicio']) ? new DateTimeImmutable($ev['data_inicio']) : null;
                        $end = !empty($ev['data_fim']) ? new DateTimeImmutable($ev['data_fim']) : null;
                        $dateStartText = $start ? $start->format('d/m/Y') : '';
                        $dateEndText = $end ? $end->format('d/m/Y') : '';
                        $dateText = $dateEndText ? "{$dateStartText} a {$dateEndText}" : $dateStartText;

                        // compare using dates only (Y-m-d) to avoid time-of-day issues
                        $startDate = $start ? new DateTimeImmutable($start->format('Y-m-d')) : null;
                        $endDate = $end ? new DateTimeImmutable($end->format('Y-m-d')) : null;

                        $notStarted = false;
                        $inProgress = false;
                        $finished = false;

                        if ($startDate && $endDate) {
                            if ($today < $startDate) {
                                $notStarted = true;
                            } elseif ($today > $endDate) {
                                $finished = true;
                            } else {
                                $inProgress = true;
                            }
                        } elseif ($startDate && !$endDate) {
                            if ($today < $startDate) $notStarted = true;
                            else $inProgress = true;
                        } elseif (!$startDate && $endDate) {
                            if ($today > $endDate) $finished = true;
                            else $inProgress = true;
                        } else {
                            // no dates → consider available
                            $inProgress = true;
                        }

                        // decide badge and label
                        if ($notStarted) {
                            $badgeClass = 'badge-breve';
                            $statusLabel = 'Em breve';
                        } elseif ($inProgress) {
                            $badgeClass = 'badge-andamento';
                            $statusLabel = 'Em andamento';
                        } elseif ($finished) {
                            $badgeClass = 'badge-finalizado';
                            $statusLabel = 'Encerrado';
                        } else {
                            // fallback to database status
                            if ($statusDb === 'Encerrado') {
                                $badgeClass = 'badge-finalizado';
                            } elseif ($statusDb === 'Cancelado') {
                                $badgeClass = 'badge-breve';
                            } else {
                                $badgeClass = 'badge-andamento';
                            }
                            $statusLabel = $statusDb;
                        }
                    ?>
                    <div class="event-card" aria-data-event-id="<?php echo (int)$ev['id_evento']; ?>">
                        <div class="event-header">
                            <h2 class="event-title"><?php echo $titulo; ?></h2>
                            <span class="event-badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <p class="event-description"><?php echo $descricao; ?></p>
                        <div class="event-info">
                            <div class="info-item"><i class="fa-solid fa-calendar"></i><span><?php echo $dateText; ?></span></div>
                            <div class="info-item"><i class="fa-solid fa-location-dot"></i><span><?php echo $local; ?></span></div>
                            <div class="info-item"><i class="fa-solid fa-user"></i><span>Organizador: <?php echo $organizador; ?></span></div>
                            <div class="info-item"><i class="fa-solid fa-users"></i><span><?php echo $inscritos; ?> inscritos</span></div>
                            <div class="info-item"><i class="fa-solid fa-clock"></i><span><?php echo $carga; ?> h</span></div>
                            <div class="info-item"><i class="fa-solid fa-calendar-plus"></i><span>Inscrito em: <?php echo formatDbDatetimeLocal($ev['data_inscricao']); ?></span></div>
                        </div>
                        <div class="event-actions">
                            <?php if ($inProgress): ?>
                                <!-- evento em andamento: permite check-in/out -->
                                <button class="btn btn-primary"
                                    onclick='openCheckinModal(<?php echo json_encode($titulo); ?>, <?php echo json_encode('check-in'); ?>, <?php echo (int)$ev['id_evento']; ?>)'>
                                    <i class="fa-solid fa-arrow-right"></i> Fazer Check-in
                                </button>
                                <button class="btn btn-secondary"
                                    onclick='openCheckinModal(<?php echo json_encode($titulo); ?>, <?php echo json_encode('check-out'); ?>, <?php echo (int)$ev['id_evento']; ?>)'>
                                    <i class="fa-solid fa-arrow-left"></i> Fazer Check-out
                                </button>
                            <?php else: ?>
                                <!-- evento não disponível para check-in -->
                                <?php if ($notStarted): ?>
                                  <button class="btn btn-primary" disabled><i class="fa-solid fa-clock"></i> Check-in disponível em <?php echo $start ? $start->format('d/m/Y') : '—'; ?></button>
                                <?php elseif ($finished): ?>
                                  <button class="btn btn-primary" disabled><i class="fa-solid fa-ban"></i> Evento encerrado</button>
                                <?php else: ?>
                                  <button class="btn btn-primary" disabled><i class="fa-solid fa-clock"></i> Check-in indisponível</button>
                                <?php endif; ?>
                                <button class="btn btn-secondary" disabled><i class="fa-solid fa-arrow-left"></i> Fazer Check-out</button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Modal -->
    <div id="modal" class="modal" onclick="closeModalOutside(event)">
        <div class="modal-content">
            <h2 id="modal-title">Confirmar Ação</h2>
            <p id="modal-message">Deseja continuar?</p>
            <div class="modal-buttons">
                <button class="modal-btn modal-btn-cancel" onclick="closeModal()">Cancelar</button>
                <button class="modal-btn modal-btn-confirm" onclick="confirmAction()">Confirmar</button>
            </div>
        </div>
    </div>
    <script>
        let currentAction = null;
        let currentEvent = null;
        let currentEventId = null;

        function toggleSidebar(){ document.getElementById('sidebar').classList.toggle('active'); }

        function openCheckinModal(eventName, action, eventId){
            currentEvent = eventName;
            currentAction = action;
            currentEventId = eventId;
            const modal = document.getElementById('modal');
            const title = document.getElementById('modal-title');
            const message = document.getElementById('modal-message');
            title.textContent = action === 'check-in' ? 'Confirmar Check-in' : 'Confirmar Check-out';
            message.textContent = `Deseja ${action === 'check-in' ? 'fazer check-in' : 'fazer check-out'} no evento "${eventName}"?`;
            modal.classList.add('show');
        }

        function closeModal(){ document.getElementById('modal').classList.remove('show'); currentAction = null; currentEvent = null; currentEventId = null; }

        function closeModalOutside(e){ if (e.target.id === 'modal') closeModal(); }

        async function confirmAction(){
            if (!currentEventId || !currentAction) { closeModal(); return; }
            // chama endpoint server-side que valida IP e faz registro
            try {
                const res = await fetch('process/checkin.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ event_id: currentEventId, action: currentAction })
                });
                const text = await res.text();
                let data;
                try { data = JSON.parse(text); } catch(e) {
                    alert('Resposta inválida do servidor: ' + text);
                    closeModal();
                    return;
                }

                if (res.ok && data.success) {
                    alert(data.message || 'Operação concluída');
                } else {
                    alert(data.error || 'Erro na operação' + (data.message ? '\n' + data.message : ''));
                }
            } catch (err) {
                alert('Erro de comunicação: ' + (err.message || err));
            } finally {
                closeModal();
                // opcional: recarregar para atualizar status/visualização
                // location.reload();
            }
        }

        document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });
    </script>
    
</body>
</html>