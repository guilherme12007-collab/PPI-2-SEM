<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'Database.php';

// Garante que apenas usuários logados (e que são organizadores) acessem.
if (empty($_SESSION['id_usuario'])) {
    header('Location: login.php'); // Redireciona para o login se não estiver logado
    exit;
}

$pdo = getDbConnection();

// Parâmetros de busca/paginação simples via GET
$search = isset($_GET['q']) ? trim($_GET['q']) : '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 12;
$offset = ($page - 1) * $perPage;
$id_organizador = (int)$_SESSION['id_usuario'];

try {
    // Consulta para total (com filtro de busca)
    if ($search !== '') {
        $countStmt = $pdo->prepare("SELECT COUNT(*) AS total FROM evento WHERE id_organizador = :id_org AND (titulo ILIKE :q OR descricao ILIKE :q)");
        $countStmt->execute([':id_org' => $id_organizador, ':q' => "%$search%"]);
    } else {
        $countStmt = $pdo->prepare("SELECT COUNT(*) AS total FROM evento WHERE id_organizador = :id_org");
        $countStmt->execute([':id_org' => $id_organizador]);
    }
    $total = (int) ($countStmt->fetch()['total'] ?? 0);

    // Busca dos eventos (com número de inscritos agregado)
    if ($search !== '') {
        $stmt = $pdo->prepare("
            SELECT e.id_evento, e.titulo, e.descricao, e.data_inicio, e.data_fim, e.local, e.carga_horaria, e.status,
                   COALESCE(i.inscritos,0) AS inscritos
            FROM evento e
            LEFT JOIN (
                SELECT id_evento, COUNT(*) AS inscritos
                FROM inscricao
                GROUP BY id_evento
            ) i ON i.id_evento = e.id_evento
            WHERE e.id_organizador = :id_org AND (e.titulo ILIKE :q OR e.descricao ILIKE :q)
            ORDER BY e.data_inicio DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':id_org', $id_organizador, PDO::PARAM_INT);
        $stmt->bindValue(':q', "%$search%", PDO::PARAM_STR);
    } else {
        $stmt = $pdo->prepare("
            SELECT e.id_evento, e.titulo, e.descricao, e.data_inicio, e.data_fim, e.local, e.carga_horaria, e.status,
                   COALESCE(i.inscritos,0) AS inscritos
            FROM evento e
            LEFT JOIN (
                SELECT id_evento, COUNT(*) AS inscritos
                FROM inscricao
                GROUP BY id_evento
            ) i ON i.id_evento = e.id_evento
            WHERE e.id_organizador = :id_org
            ORDER BY e.data_inicio DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':id_org', $id_organizador, PDO::PARAM_INT);
    }

    $stmt->bindValue(':limit', (int)$perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
    $stmt->execute();
    $eventos = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Erro ao carregar eventos: " . htmlspecialchars($e->getMessage()));
}

// Helper para formatar datas; retorna string compacta se data_fim existir
function formatPeriodo($inicio, $fim) {
    if (!$inicio) return '-';
    $dtInicio = date_create($inicio);
    if ($fim) {
        $dtFim = date_create($fim);
        if ($dtInicio && $dtFim && $dtInicio->format('Y') === $dtFim->format('Y')) {
            return $dtInicio->format('d M') . ($dtInicio->format('d M') === $dtFim->format('d M') ? '' : ' - ' . $dtFim->format('d M'));
        }
        return $dtInicio->format('d M Y') . ($dtFim ? ' - ' . $dtFim->format('d M Y') : '');
    }
    return $dtInicio->format('d M Y');
}

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Eventos</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css" integrity="sha512-2SwdPD6INVrV/lHTZbO2nodKhrnDdJK9/kg2XD1r9uGqPo1cUbujc+IYdlYdEErWNu69gVcYgdxlmVmzTWnetw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
  <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/css/eventosADM.CSS">
    <style>
        .pausar-btn {
            background-color: #f97316; /* orange-500 */
            color: white;
            padding: 4px 10px; border-radius: 4px; font-size: 0.8rem;
        }
    </style>
</head>

<body>
    <div class="container">
        <aside class="sidebar">
            <img src="img/logo_iffar-removebg-preview.png" alt="iff" id="logo" class="logo" style=" max-width: 120px;
  margin-bottom: 2rem;
  display: block;
  margin-left: auto;
  margin-right: auto;">
            <nav>
                <ul>
                    <li><a href="index_organizador.php"><i class="fa-solid fa-chart-line"></i>Dashboard</a></li>
                    <li class="active"><a href="eventosADM.php"><i class="fa-solid fa-calendar-days"></i>Eventos</a></li>
                    <li><a href="inscritos.php"><i class="fa-solid fa-users"></i> Inscritos</a></li>
                    <li><a href="certificados.php"><i class="fa-solid fa-certificate"></i>&nbsp;Certificados</a></li>
                    <li><a href="inscrever_organizador.php"><i class="fa-solid fa-pen-to-square"></i>Inscrever-se</a></li>
                </ul>
            </nav>
        </aside>
        <main class="main-content">
            <header class="top-bar">
                <form method="get" style="display:flex; gap:0.5rem; align-items:center;">
                    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" class="search-input" placeholder="Buscar eventos...">
                    <button type="submit" class="novo-evento-btn">Buscar</button>
                </form>
                <div class="flex items-center gap-4 ml-auto">
                    <a href="cadastrar-evento.html" class="novo-evento-btn">+ Novo Evento</a>
                    <div class="flex items-center gap-3">
                        <a href="perfil_organizador.php" title="Meu Perfil" class="text-gray-600 hover:text-green-600"><i class="fa-solid fa-user fa-lg"></i></a>
                        <a href="login.php" title="Sair" class="text-gray-600 hover:text-red-600"><i class="fa-solid fa-sign-out-alt fa-lg"></i></a>
                    </div>
                </div>
            </header>
            <section class="eventos-section">
                <div class="eventos-header">
                    <div>
                        <h1>Gerenciar Eventos</h1>
                        <p>Crie, edite e organize seus eventos</p>
                        <small><?= $total ?> evento(s) encontrado(s)</small>
                    </div>
                </div>

                <div class="eventos-list">
                    <?php if (empty($eventos)): ?>
                        <div class="evento-card">
                            <div class="evento-info">
                                <h2>Nenhum evento</h2>
                                <p>Nenhum evento corresponde à sua busca.</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($eventos as $ev): ?>
                            <?php
                                $status = trim((string)$ev['status']);
                                $statusClasse = 'programado'; // cinza (padrão)
                                $statusTexto = ucfirst(strtolower($status));

                                if (strcasecmp($status, 'Aberto') === 0) {
                                    $statusClasse = 'ativo'; // verde
                                    $statusTexto = 'Aprovado';
                                } elseif (strcasecmp($status, 'Pendente') === 0) {
                                    $statusClasse = 'pendente'; // amarelo
                                }

                                $periodo = formatPeriodo($ev['data_inicio'], $ev['data_fim']);
                            ?>
                            <div class="evento-card">
                                <div class="evento-info">
                                    <h2><?= htmlspecialchars($ev['titulo']) ?></h2>
                                    <p><?= htmlspecialchars(mb_strimwidth($ev['descricao'], 0, 120, '...')) ?></p>
                                    <span>Data: <?= htmlspecialchars($periodo) ?></span><br>
                                    <span>Inscritos: <?= (int)$ev['inscritos'] ?></span>
                                </div>

                                <div class="evento-status <?= $statusClasse ?>">
                                    <?= htmlspecialchars($statusTexto) ?>
                                </div>

                                <div class="evento-actions" aria-hidden="true">
                                    <?php if (strcasecmp($status, 'Aberto') === 0): ?>
                                        <form method="post" action="process/pause_event.php" style="display:inline-block" onsubmit="return confirm('Tem certeza que deseja pausar este evento? Isso o marcará como \'Encerrado\' e impedirá novas inscrições.');">
                                            <input type="hidden" name="id_evento" value="<?= (int)$ev['id_evento'] ?>">
                                            <button type="submit" class="pausar-btn">Pausar</button>
                                        </form>
                                    <?php endif; ?>


                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Paginação simples -->
                <?php
                $totalPages = (int) ceil($total / $perPage);
                if ($totalPages > 1):
                ?>
                <nav>
                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                        <?php if ($p === $page): ?>
                            <strong style="margin-right:0.5rem;"><?= $p ?></strong>
                        <?php else: ?>
                            <a href="?q=<?= urlencode($search) ?>&page=<?= $p ?>" style="margin-right:0.5rem;"><?= $p ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                </nav>
                <?php endif; ?>

            </section>
        </main>
    </div>
</body>
</html>