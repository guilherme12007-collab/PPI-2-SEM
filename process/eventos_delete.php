<?php
session_start();
require_once __DIR__ . '/../Database.php';

// Verifica se o usuário está logado
if (!isset($_SESSION['id_usuario'])) {
    header('Location: ../login.php');
    exit;
}

// Verifica se o usuário tem perfil de organizador
if ($_SESSION['perfil'] !== 'Organizador') {
    http_response_code(403);
    echo "Você não tem permissão para excluir eventos.";
    exit;
}

// Verifica se o método é POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "Método não permitido.";
    exit;
}

// Captura o ID do evento
$eventoId = $_POST['id'] ?? null;
if (!$eventoId || !is_numeric($eventoId)) {
    http_response_code(400);
    echo "ID do evento inválido.";
    exit;
}

$idOrganizador = (int)$_SESSION['id_usuario'];

try {
    $pdo = getDbConnection();

    // Verifica se o evento pertence ao organizador logado
    $check = $pdo->prepare("SELECT id_evento FROM evento WHERE id_evento = :id AND id_organizador = :org");
    $check->execute([
        ':id' => $eventoId,
        ':org' => $idOrganizador
    ]);

    if (!$check->fetch()) {
        http_response_code(403);
        echo "Você não pode excluir este evento.";
        exit;
    }

    // Executa a exclusão
    $delete = $pdo->prepare("DELETE FROM evento WHERE id_evento = :id");
    $delete->execute([':id' => $eventoId]);

    // Redireciona após exclusão
    header('Location: ../eventosADM.php?deleted=1');
    exit;

} catch (Throwable $e) {
    error_log('Erro ao excluir evento: ' . $e->getMessage());
    http_response_code(500);
    echo "Erro interno ao excluir evento.";
    exit;
}
