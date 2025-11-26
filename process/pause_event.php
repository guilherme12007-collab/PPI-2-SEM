<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../Database.php';

function respondErrorAndExit(string $msg, string $redirect = '../eventosADM.php'): void {
    $_SESSION['error_message'] = $msg;
    header("Location: $redirect");
    exit;
}

// 1. Verificar se o usuário está logado e se é um organizador
if (empty($_SESSION['id_usuario'])) {
    respondErrorAndExit('Acesso negado. Você precisa estar logado.', '../login.php');
}

$id_organizador = (int)$_SESSION['id_usuario'];
$id_evento = isset($_POST['id_evento']) ? (int)$_POST['id_evento'] : 0;

if ($id_evento <= 0) {
    respondErrorAndExit('ID do evento inválido.');
}

try {
    $pdo = getDbConnection();
    
    // 2. Verificar se o evento pertence ao organizador logado antes de alterar
    $stmt = $pdo->prepare("SELECT id_organizador FROM evento WHERE id_evento = :id_evento");
    $stmt->execute([':id_evento' => $id_evento]);
    $evento = $stmt->fetch();

    if (!$evento || (int)$evento['id_organizador'] !== $id_organizador) {
        respondErrorAndExit('Você não tem permissão para modificar este evento.');
    }

    // 3. Atualizar o status do evento para 'Encerrado'
    $updateStmt = $pdo->prepare("UPDATE evento SET status = 'Encerrado' WHERE id_evento = :id_evento");
    $updateStmt->execute([':id_evento' => $id_evento]);

    header('Location: ../eventosADM.php?paused=1');
    exit;

} catch (PDOException $e) {
    error_log("Erro ao pausar evento: " . $e->getMessage());
    respondErrorAndExit('Ocorreu um erro no banco de dados ao tentar pausar o evento.');
}