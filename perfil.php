<?php
session_start();
require_once __DIR__ . '/Database.php';

// exige autenticação
if (empty($_SESSION['id_usuario'])) {
    header('Location: login.php');
    exit;
}

$user = [];
$stats = [
    'events_participated' => 0,
    'hours_certified' => 0,
    'certificates' => 0
];
$error = null;

try {
    $pdo = getDbConnection();

    // busca dados do usuário (seleciona tudo e usaremos keys existentes)
    $stmt = $pdo->prepare("SELECT * FROM usuario WHERE id_usuario = :uid LIMIT 1");
    $stmt->execute([':uid' => (int) $_SESSION['id_usuario']]);
    $user = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

    // estatísticas
    $q = $pdo->prepare("SELECT COUNT(*) AS cnt FROM inscricao WHERE id_usuario = :uid");
    $q->execute([':uid' => (int) $_SESSION['id_usuario']]);
    $stats['events_participated'] = (int) ($q->fetchColumn() ?: 0);

    $q = $pdo->prepare("
        SELECT COALESCE(SUM(e.carga_horaria),0) AS total_hours
        FROM inscricao i
        JOIN evento e ON e.id_evento = i.id_evento
        WHERE i.id_usuario = :uid
    ");
    $q->execute([':uid' => (int) $_SESSION['id_usuario']]);
    $stats['hours_certified'] = (int) ($q->fetchColumn() ?: 0);

    $q = $pdo->prepare("
        SELECT COUNT(*) FROM certificado c
        JOIN inscricao i ON i.id_inscricao = c.id_inscricao
        WHERE i.id_usuario = :uid
    ");
    $q->execute([':uid' => (int) $_SESSION['id_usuario']]);
    $stats['certificates'] = (int) ($q->fetchColumn() ?: 0);

} catch (\PDOException $e) {
    $error = $e->getMessage();
}

// helper para saída segura
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// tenta preencher campos usados na interface mesmo se não existirem na tabela
$nome = $user['nome'] ?? ($user['full_name'] ?? 'Usuário');
$email = $user['email'] ?? '';
$matricula = $user['matricula'] ?? ($user['ra'] ?? '');
$telefone = $user['telefone'] ?? '';
$campus = $user['campus'] ?? '';
$curso = $user['curso'] ?? '';
$tipo = $user['tipo_perfil'] ?? '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meu Perfil</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        /* Preserve the same styles as the previous perfil.html */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #e8e8e8; display: flex; min-height: 100vh; }
        .sidebar { width: 250px; background-color: #2d2d2d; color: white; padding: 2rem 0; position: fixed; height: 100vh; overflow-y: auto; }
        .sidebar-header { padding: 0 1.5rem; margin-bottom: 2rem; }
        .sidebar-header h2 { font-size: 1.2rem; font-weight: 600; color: #fff; }
        .sidebar-menu { list-style: none; }
        .sidebar-menu li { margin-bottom: 0.5rem; }
        .sidebar-menu a { display: flex; align-items: center; gap: 1rem; padding: 0.9rem 1.5rem; color: #b0b0b0; text-decoration: none; transition: all 0.3s; font-size: 0.95rem; }
        .sidebar-menu a:hover, .sidebar-menu a.active { background-color: #3d3d3d; color: #fff; border-left: 4px solid #28a745; }
        .sidebar-menu a i { font-size: 1.1rem; width: 20px; text-align: center; }
        .main-wrapper { margin-left: 250px; flex: 1; display: flex; flex-direction: column; }
        header { background-color: white; padding: 1rem 2rem; box-shadow: 0 2px 4px rgba(0,0,0,0.1); display: flex; justify-content: space-between; align-items: center; }
        .header-left { display: flex; align-items: center; gap: 1rem; }
        .logo-placeholder { width: 50px; height: 50px; background-color: #f0f0f0; border: 2px dashed #ccc; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 0.7rem; color: #999; text-align: center; padding: 0.25rem; }
        .system-title { font-size: 1rem; color: #333; font-weight: 600; }
        .header-actions { display: flex; gap: 1rem; align-items: center; }
        .logout { background-color: #28a745; color: white; border: none; padding: 0.5rem 1.5rem; border-radius: 4px; cursor: pointer; font-weight: 600; display: flex; align-items: center; gap: 0.5rem; transition: background-color 0.2s; font-size: 0.9rem; }
        .logout:hover { background-color: #218838; }
        .container { max-width: 1000px; margin: 0 auto; padding: 2rem; width: 100%; }
        .page-header { margin-bottom: 2rem; }
        .page-header h1 { font-size: 1.8rem; color: #333; margin-bottom: 0.5rem; }
        .page-header p { color: #666; font-size: 0.95rem; }
        .profile-container { background-color: white; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); padding: 2rem; }
        .profile-photo-section { display: flex; flex-direction: column; align-items: center; padding: 2rem; border-bottom: 1px solid #e0e0e0; margin-bottom: 2rem; }
        .photo-wrapper { position: relative; margin-bottom: 1.5rem; }
        .profile-photo { width: 150px; height: 150px; border-radius: 50%; object-fit: cover; border: 4px solid #28a745; background-color: #f0f0f0; display: flex; align-items: center; justify-content: center; font-size: 4rem; color: #999; }
        .photo-upload-btn { position: absolute; bottom: 5px; right: 5px; background-color: #28a745; color: white; border: none; border-radius: 50%; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; cursor: pointer; box-shadow: 0 2px 8px rgba(0,0,0,0.2); transition: background-color 0.2s; }
        .photo-upload-btn:hover { background-color: #218838; }
        .photo-input { display: none; }
        .user-name { font-size: 1.5rem; color: #333; font-weight: 600; margin-bottom: 0.3rem; }
        .user-type { color: #666; font-size: 0.95rem; display: flex; align-items: center; gap: 0.5rem; }
        .user-type i { color: #28a745; }
        .profile-info { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 2rem; }
        .info-group { margin-bottom: 1.5rem; }
        .info-label { display: block; color: #666; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.5rem; text-transform: uppercase; letter-spacing: 0.5px; }
        .info-value { display: flex; align-items: center; gap: 0.7rem; padding: 0.75rem; background-color: #f8f9fa; border-radius: 6px; color: #333; font-size: 0.95rem; border: 1px solid #e0e0e0; }
        .info-value i { color: #28a745; font-size: 1.1rem; }
        .btn-edit-profile { width: 100%; background-color: #28a745; color: white; border: none; padding: 0.85rem; border-radius: 6px; font-weight: 600; cursor: pointer; transition: background-color 0.2s; font-size: 1rem; display: flex; align-items: center; justify-content: center; gap: 0.5rem; margin-top: 2rem; }
        .btn-edit-profile:hover { background-color: #218838; }
        .stats-section { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; margin-top: 2rem; padding-top: 2rem; border-top: 1px solid #e0e0e0; }
        .stat-card { background: linear-gradient(135deg, #28a745 0%, #20873a 100%); padding: 1.5rem; border-radius: 8px; color: white; text-align: center; }
        .stat-number { font-size: 2rem; font-weight: 700; margin-bottom: 0.3rem; }
        .stat-label { font-size: 0.9rem; opacity: 0.95; }
        .mobile-menu-btn { display: none; }
        @media (max-width: 1024px) { .sidebar { width: 200px; } .main-wrapper { margin-left: 200px; } }
        @media (max-width: 768px) { .sidebar { transform: translateX(-100%); transition: transform 0.3s; z-index: 1000; } .sidebar.active { transform: translateX(0); } .main-wrapper { margin-left: 0; } .mobile-menu-btn { display: block; background: none; border: none; font-size: 1.5rem; color: #333; cursor: pointer; margin-right: 1rem; } .profile-info { grid-template-columns: 1fr; } .stats-section { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header"><h2>Menu</h2></div>
        <ul class="sidebar-menu">
            <li><a href="index_participante.php"><i class="fa-solid fa-table-cells"></i><span>Mural de Eventos</span></a></li>
            <li><a href="meus_eventos_participante.php"><i class="fa-solid fa-calendar-check"></i><span>Meus Eventos</span></a></li>
            <li><a href="certificados.html"><i class="fa-solid fa-certificate"></i><span>Meus Certificados</span></a></li>
            <li><a href="perfil.php" class="active"><i class="fa-solid fa-user"></i><span>Meu Perfil</span></a></li>
            <li><a href="configuracoes.html"><i class="fa-solid fa-gear"></i><span>Configurações</span></a></li>
        </ul>
    </aside>

    <div class="main-wrapper">
        <header>
            <div class="header-left">
                <button class="mobile-menu-btn" onclick="toggleSidebar()"><i class="fa-solid fa-bars"></i></button>
                <div class="logo-placeholder">
                   <img src="https://www.iffarroupilha.edu.br/component/k2/attachments/download/2367/0e66a1e8d61f98d1b8f84efa8700e499" alt="ifflogo" style="height: 80px; width: auto;">
                </div>
                <span class="system-title">Sistema de Eventos</span>
            </div>
            <div class="header-actions">
                <a class="logout" href="process/logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
            </div>
        </header>

        <div class="container">
            <div class="page-header">
                <h1>Meu Perfil</h1>
                <p>Visualize e edite suas informações pessoais</p>
            </div>

            <?php if ($error): ?>
                <div class="alert-error" style="color:#a94442; background:#f2dede; padding:10px; border-radius:6px;"><?php echo e($error); ?></div>
            <?php endif; ?>

            <div class="profile-container">
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
                    <div class="user-type"><i class="fa-solid fa-graduation-cap"></i><span><?php echo e($tipo ?: 'Participante'); ?></span></div>
                </div>

                <div class="profile-info">
                    <div>
                        <div class="info-group">
                            <label class="info-label">Nome Completo</label>
                            <div class="info-value"><i class="fa-solid fa-user"></i><span><?php echo e($nome); ?></span></div>
                        </div>

                        <div class="info-group">
                            <label class="info-label">Email Institucional</label>
                            <div class="info-value"><i class="fa-solid fa-envelope"></i><span><?php echo e($email); ?></span></div>
                        </div>

                        <div class="info-group">
                            <label class="info-label">Matrícula</label>
                            <div class="info-value"><i class="fa-solid fa-id-card"></i><span><?php echo e($matricula ?: '—'); ?></span></div>
                        </div>
                    </div>

                    <div>
                        <div class="info-group">
                            <label class="info-label">Telefone</label>
                            <div class="info-value"><i class="fa-solid fa-phone"></i><span><?php echo e($telefone ?: '—'); ?></span></div>
                        </div>

                        <div class="info-group">
                            <label class="info-label">Campus</label>
                            <div class="info-value"><i class="fa-solid fa-building"></i><span><?php echo e($campus ?: '—'); ?></span></div>
                        </div>

                        <div class="info-group">
                            <label class="info-label">Curso</label>
                            <div class="info-value"><i class="fa-solid fa-book"></i><span><?php echo e($curso ?: '—'); ?></span></div>
                        </div>
                    </div>
                </div>

                <div class="stats-section">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo e($stats['events_participated']); ?></div>
                        <div class="stat-label">Eventos Participados</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo e($stats['hours_certified']); ?></div>
                        <div class="stat-label">Horas de Certificação</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo e($stats['certificates']); ?></div>
                        <div class="stat-label">Certificados</div>
                    </div>
                </div>

                <button class="btn-edit-profile" onclick="window.location.href='editar_perfil.php'"><i class="fa-solid fa-pen"></i> Editar Perfil</button>
            </div>
        </div>
    </div>

    <script>
        function toggleSidebar() { document.getElementById('sidebar').classList.toggle('active'); }
        function handlePhotoUpload(event) {
            const file = event.target.files[0];
            if (!file) return;
            if (!file.type.startsWith('image/')) { alert('Por favor, selecione uma imagem.'); return; }
            if (file.size > 5*1024*1024) { alert('Máx 5MB'); return; }
            const reader = new FileReader();
            reader.onload = function(e) {
                const photo = document.getElementById('profilePhoto');
                photo.style.backgroundImage = `url(${e.target.result})`;
                photo.style.backgroundSize = 'cover';
                photo.style.backgroundPosition = 'center';
                photo.innerHTML = '';
            };
            reader.readAsDataURL(file);
        }
    </script>
</body>
</html>