<?php
require_once 'Database.php';

try {
    $pdo = getDbConnection();

    // --- Buscar eventos para o filtro ---
    $stmtEventos = $pdo->query("SELECT id_evento, titulo FROM evento ORDER BY titulo");
    $eventos = $stmtEventos->fetchAll();

    // --- Construir a consulta para os inscritos ---
    $sql = "SELECT 
                u.nome AS participante_nome, 
                u.email, 
                e.titulo AS evento_nome, 
                i.data_inscricao
            FROM 
                inscricao i
            JOIN 
                usuario u ON i.id_usuario = u.id_usuario
            JOIN 
                evento e ON i.id_evento = e.id_evento";

    $params = [];
    $whereClauses = [];

    // Filtro por evento
    if (!empty($_GET['evento_id']) && $_GET['evento_id'] !== 'todos') {
        $whereClauses[] = "e.id_evento = :evento_id";
        $params[':evento_id'] = $_GET['evento_id'];
    }

    // Filtro por busca (nome ou email)
    if (!empty($_GET['search'])) {
        $whereClauses[] = "(u.nome ILIKE :search OR u.email ILIKE :search)";
        $params[':search'] = '%' . $_GET['search'] . '%';
    }

    if (!empty($whereClauses)) {
        $sql .= " WHERE " . implode(" AND ", $whereClauses);
    }

    $sql .= " ORDER BY i.data_inscricao DESC";

    $stmtInscritos = $pdo->prepare($sql);
    $stmtInscritos->execute($params);
    $inscritos = $stmtInscritos->fetchAll();
    $totalInscritos = count($inscritos);

} catch (PDOException $e) {
    // Em um ambiente de produção, você poderia logar o erro e mostrar uma mensagem amigável.
    die("Erro ao conectar ou consultar o banco de dados: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Inscritos</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
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
        .top-bar {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            padding: 24px 0 0 0;
            gap: 16px;
        }
        .settings .icon {
            font-size: 20px;
            color: #6b7280;
            margin-right: 8px;
        }
        .logout-btn {
            background: #22c55e;
            color: #fff;
            border: none;
            padding: 8px 24px;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s;
        }
        .logout-btn:hover {
            background: #16a34a;
        }
        .eventos-section {
            margin-top: 24px;
        }
        .eventos-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            gap: 12px;
        }
        .eventos-header h1 {
            font-size: 1.6rem;
            font-weight: 700;
            margin-bottom: 4px;
        }
        .eventos-header p {
            color: #6b7280;
            font-size: 0.95rem;
            margin-bottom: 0;
        }
        .search-input {
            flex: 1;
            max-width: 420px;
            padding: 10px 16px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            margin-left: 0;
            margin-right: 8px;
            background: #fff;
            font-size: 1rem;
        }
        .filter-select {
            padding: 10px 14px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            background: #fff;
            font-size: 1rem;
        }
        .inscritos-table {
            width: 100%;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
            overflow: auto;
        }
        .inscritos-table table {
            width: 100%;
            border-collapse: collapse;
        }
        .inscritos-table thead th {
            text-align: left;
            padding: 12px 16px;
            color: #6b7280;
            font-weight: 600;
            font-size: 0.95rem;
            border-bottom: 1px solid #e5e7eb;
        }
        .inscritos-table tbody td {
            padding: 14px 16px;
            border-bottom: 1px solid #f3f4f6;
            color: #374151;
            vertical-align: middle;
        }
        .inscritos-table tbody tr:last-child td {
            border-bottom: none;
        }
        .badge-confirmado {
            background: #22c55e;
            color: #fff;
            padding: 6px 12px;
            border-radius: 999px;
            font-weight: 600;
            font-size: 0.9rem;
        }
        .badge-pendente {
            background: #f3f4f6;
            color: #374151;
            padding: 6px 12px;
            border-radius: 999px;
            font-weight: 600;
            font-size: 0.9rem;
        }
        .check-presente { color: #16a34a; font-weight:600 }
        .check-ausente { color: #9ca3af; font-weight:600 }

        @media (max-width: 900px) {
            .container {
                flex-direction: column;
            }
            .sidebar {
                width: 100%;
                flex-direction: row;
                justify-content: space-between;
                padding: 16px;
            }
            .main-content {
                padding: 0 12px;
            }
            .eventos-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }
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
                    <li><a href="eventosADM.php"><i class="fa-solid fa-calendar-days"></i>Eventos</a></li>
                    <li class="active"><a href="inscritos.php"><i class="fa-solid fa-users"></i> Inscritos</a></li>
                    <li><a href="certificados.php" aria-current="page"><i class="fa-solid fa-certificate"></i>&nbsp;Certificados</a></li>
                    <li><a href="inscrever_organizador.php"><i class="fa-solid fa-pen-to-square"></i>Inscrever-se</a></li>
                </ul>
            </nav>
        </aside>
        <main class="main-content">
            <header class="top-bar">
                <div class="flex items-center gap-3 ml-auto">
                    <a href="perfil_organizador.php" title="Meu Perfil" class="text-gray-600 hover:text-green-600"><i class="fa-solid fa-user fa-lg"></i></a>
                    <a href="login.php" title="Sair" class="text-gray-600 hover:text-red-600"><i class="fa-solid fa-sign-out-alt fa-lg"></i></a>
                </div>
            </header>
            <section class="eventos-section">
                <div class="eventos-header">
                    <div>
                        <h1>Gerenciar Inscritos</h1>
                        <p>Visualize e gerencie todos os participantes inscritos nos eventos</p>
                    </div>
                    <form method="GET" action="inscritos.php" class="flex items-center">
                        <input type="text" name="search" class="search-input" placeholder="Buscar por nome ou email..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
                        <select name="evento_id" class="filter-select" onchange="this.form.submit()">
                            <option value="todos">Todos os Eventos</option>
                            <?php foreach ($eventos as $evento): ?>
                                <option value="<?= $evento['id_evento'] ?>" <?= (($_GET['evento_id'] ?? '') == $evento['id_evento']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($evento['titulo']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>

                <div class="inscritos-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th>Email</th>
                                <th>Evento</th>
                                <th>Data de Inscrição</th>
                                <th>Status</th>
                                <th>Check-in</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($inscritos)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-10 text-gray-500">Nenhum inscrito encontrado.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($inscritos as $inscrito): ?>
                                <tr>
                                    <td><?= htmlspecialchars($inscrito['participante_nome']) ?></td>
                                    <td><?= htmlspecialchars($inscrito['email']) ?></td>
                                    <td><?= htmlspecialchars($inscrito['evento_nome']) ?></td>
                                    <td><?= (new DateTime($inscrito['data_inscricao']))->format('d/m/Y') ?></td>
                                    <td>
                                        <span class="badge-confirmado">Confirmado</span>
                                    </td>
                                    <td>
                                        <span class="check-ausente">
                                            <i class="fa-solid fa-user-xmark"></i> Ausente
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <p style="margin-top:12px;color:#6b7200">Mostrando <?= $totalInscritos ?> de <?= $totalInscritos ?> inscritos</p>
            </section>
        </main>
    </div>
</body>
</html>