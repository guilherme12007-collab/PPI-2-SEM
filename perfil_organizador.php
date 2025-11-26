<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/Database.php';

// Exige autenticação de um organizador
if (empty($_SESSION['id_usuario'])) {
    header('Location: login.php');
    exit;
}

$user = [];
$stats = [
    'events_created' => 0,
    'total_subscribers' => 0,
    'certificates_issued' => 0
];
$error = null;

try {
    $pdo = getDbConnection();
    $id_organizador = (int)$_SESSION['id_usuario'];

    // Busca dados do usuário
    $stmt = $pdo->prepare("SELECT * FROM usuario WHERE id_usuario = :uid LIMIT 1");
    $stmt->execute([':uid' => $id_organizador]);
    $user = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

    // Estatísticas do Organizador
    // Eventos criados
    $q = $pdo->prepare("SELECT COUNT(*) FROM evento WHERE id_organizador = :id_org");
    $q->execute([':id_org' => $id_organizador]);
    $stats['events_created'] = (int) ($q->fetchColumn() ?: 0);

    // Total de inscritos nos seus eventos
    $q = $pdo->prepare("
        SELECT COUNT(i.id_inscricao) 
        FROM inscricao i
        JOIN evento e ON i.id_evento = e.id_evento
        WHERE e.id_organizador = :id_org
    ");
    $q->execute([':id_org' => $id_organizador]);
    $stats['total_subscribers'] = (int) ($q->fetchColumn() ?: 0);

    // Certificados emitidos (considerando todos os inscritos nos seus eventos)
    $q = $pdo->prepare("
        SELECT COUNT(c.id_certificado) FROM certificado c
        JOIN inscricao i ON i.id_inscricao = c.id_inscricao
        JOIN evento e ON e.id_evento = i.id_evento
        WHERE e.id_organizador = :id_org
    ");
    $q->execute([':id_org' => $id_organizador]);
    $stats['certificates_issued'] = (int) ($q->fetchColumn() ?: 0);

} catch (\PDOException $e) {
    $error = "Erro ao carregar dados do perfil: " . htmlspecialchars($e->getMessage());
}

// Helper para saída segura
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Preenche campos para a interface
$nome = $user['nome'] ?? 'Organizador';
$email = $user['email'] ?? '';
$tipo = $user['tipo_perfil'] ?? 'Organizador';

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meu Perfil - Organizador</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css" integrity="sha512-2SwdPD6INVrV/lHTZbO2nodKhrnDdJK9/kg2XD1r9uGqPo1cUbujc+IYdlYdEErWNu69gVcYgdxlmVmzTWnetw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/css/eventosADM.CSS">
    <style>
        /* Estilos adaptados de perfil.php para o layout do organizador */
        .profile-container { background-color: white; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); padding: 2rem; }
        .profile-photo-section { display: flex; flex-direction: column; align-items: center; padding-bottom: 2rem; border-bottom: 1px solid #e5e7eb; margin-bottom: 2rem; }
        .photo-wrapper { position: relative; margin-bottom: 1.5rem; }
        .profile-photo { width: 150px; height: 150px; border-radius: 50%; object-fit: cover; border: 4px solid #10b981; /* green-500 */ background-color: #f3f4f6; display: flex; align-items: center; justify-content: center; font-size: 4rem; color: #9ca3af; }
        .photo-upload-btn { position: absolute; bottom: 5px; right: 5px; background-color: #10b981; color: white; border: none; border-radius: 50%; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; cursor: pointer; box-shadow: 0 2px 8px rgba(0,0,0,0.2); transition: background-color 0.2s; }
        .photo-upload-btn:hover { background-color: #059669; }
        .photo-input { display: none; }
        .user-name { font-size: 1.5rem; color: #1f2937; font-weight: 600; margin-bottom: 0.3rem; }
        .user-type { color: #4b5563; font-size: 0.95rem; display: flex; align-items: center; gap: 0.5rem; }
        .user-type i { color: #10b981; }
        .profile-info { display: grid; grid-template-columns: 1fr; gap: 1.5rem; }
        .info-group { margin-bottom: 1rem; }
        .info-label { display: block; color: #6b7280; font-size: 0.8rem; font-weight: 600; margin-bottom: 0.5rem; text-transform: uppercase; letter-spacing: 0.5px; }
        .info-value { display: flex; align-items: center; gap: 0.7rem; padding: 0.75rem; background-color: #f9fafb; border-radius: 6px; color: #374151; font-size: 0.95rem; border: 1px solid #e5e7eb; }
        .info-value i { color: #10b981; font-size: 1.1rem; }
        .btn-edit-profile { width: 100%; background-color: #10b981; color: white; border: none; padding: 0.85rem; border-radius: 6px; font-weight: 600; cursor: pointer; transition: background-color 0.2s; font-size: 1rem; display: flex; align-items: center; justify-content: center; gap: 0.5rem; margin-top: 2rem; }
        .btn-edit-profile:hover { background-color: #059669; }
        .stats-section { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1.5rem; margin-top: 2rem; padding-top: 2rem; border-top: 1px solid #e5e7eb; }
        .stat-card { background: linear-gradient(135deg, #10b981 0%, #059669 100%); padding: 1.5rem; border-radius: 8px; color: white; text-align: center; }
        .stat-number { font-size: 2rem; font-weight: 700; margin-bottom: 0.3rem; }
        .stat-label { font-size: 0.9rem; opacity: 0.95; }
    </style>
</head>

<body>
    <div class="container">
        <aside class="sidebar">
            <img src="img/logo_iffar-removebg-preview.png" alt="iff" class="logo" />
            <nav>
                <ul>
                    <li><a href="index_organizador.php"><i class="fa-solid fa-chart-line"></i>Dashboard</a></li>
                    <li><a href="eventosADM.php"><i class="fa-solid fa-calendar-days"></i>Eventos</a></li>
                    <li><a href="inscritos.php"><i class="fa-solid fa-users"></i> Inscritos</a></li>
                    <li><a href="certificados.php"><i class="fa-solid fa-certificate"></i>&nbsp;Certificados</a></li>
                    <li><a href="#"><i class="fa-solid fa-gear"></i>Configurações</a></li>
                </ul>
            </nav>
        </aside>
        <main class="main-content">
            <header class="top-bar">
                <!-- Pode-se remover a busca ou adaptar para outra funcionalidade -->
                <div class="flex-grow"></div>
                <div class="flex items-center gap-4 ml-auto">
                    <a href="cadastrar-evento.html" class="novo-evento-btn">+ Novo Evento</a>
                    <div class="flex items-center gap-3">
                        <a href="perfil_organizador.php" title="Meu Perfil" class="text-green-600"><i class="fa-solid fa-user fa-lg"></i></a>
                        <a href="login.php" title="Sair" class="text-gray-600 hover:text-red-600"><i class="fa-solid fa-sign-out-alt fa-lg"></i></a>
                    </div>
                </div>
            </header>

            <section class="eventos-section">
                <div class="eventos-header">
                    <div>
                        <h1>Meu Perfil</h1>
                        <p>Visualize e gerencie suas informações de organizador.</p>
                    </div>
                </div>

                <?php if ($error): ?>
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                        <strong class="font-bold">Erro!</strong>
                        <span class="block sm:inline"><?php echo $error; ?></span>
                    </div>
                <?php endif; ?>

                <div class="profile-container max-w-4xl mx-auto">
                    <div class="profile-photo-section">
                        <div class="photo-wrapper">
                            <div class="profile-photo" id="profilePhoto">
                                <i class="fa-solid fa-user"></i>
                            </div>
                            <button class="photo-upload-btn" onclick="document.getElementById('photoInput').click()">
                                <i class="fa-solid fa-camera"></i>
                            </button>
                            <input type="file" id="photoInput" class="photo-input" accept="image/*" onchange="handlePhotoUpload(event)">
                        </div>

                        <h2 class="user-name"><?php echo e($nome); ?></h2>
                        <div class="user-type"><i class="fa-solid fa-star"></i><span><?php echo e($tipo); ?></span></div>
                    </div>

                    <div class="profile-info">
                        <div class="info-group">
                            <label class="info-label">Nome Completo</label>
                            <div class="info-value"><i class="fa-solid fa-user"></i><span><?php echo e($nome); ?></span></div>
                        </div>

                        <div class="info-group">
                            <label class="info-label">Email</label>
                            <div class="info-value"><i class="fa-solid fa-envelope"></i><span><?php echo e($email); ?></span></div>
                        </div>
                    </div>

                    <div class="stats-section">
                        <div class="stat-card">
                            <div class="stat-number"><?php echo e($stats['events_created']); ?></div>
                            <div class="stat-label">Eventos Criados</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number"><?php echo e($stats['total_subscribers']); ?></div>
                            <div class="stat-label">Inscrições Totais</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number"><?php echo e($stats['certificates_issued']); ?></div>
                            <div class="stat-label">Certificados Emitidos</div>
                        </div>
                    </div>

                    <button class="btn-edit-profile" onclick="window.location.href='editar_perfil.php'"><i class="fa-solid fa-pen"></i> Editar Perfil</button>
                </div>

            </section>
        </main>
    </div>

    <script>
        // Função para upload de foto (mesma do perfil.php)
        function handlePhotoUpload(event) {
            const file = event.target.files[0];
            if (!file) return;
            if (!file.type.startsWith('image/')) {
                alert('Por favor, selecione uma imagem.');
                return;
            }
            if (file.size > 5 * 1024 * 1024) { // Limite de 5MB
                alert('O arquivo é muito grande. O máximo permitido é 5MB.');
                return;
            }
            const reader = new FileReader();
            reader.onload = function(e) {
                const photo = document.getElementById('profilePhoto');
                photo.style.backgroundImage = `url(${e.target.result})`;
                photo.style.backgroundSize = 'cover';
                photo.style.backgroundPosition = 'center';
                photo.innerHTML = ''; // Limpa o ícone
            };
            reader.readAsDataURL(file);
        }
    </script>
</body>
</html>