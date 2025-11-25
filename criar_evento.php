<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../Database.php';

$pdo = getDbConnection();

function respondError(string $msg): void {
    http_response_code(400);
    echo "<p style='color:red;'>$msg</p>";
    echo "<p><a href='javascript:history.back()'>Voltar</a></p>";
    exit;
}

// Verifica se usuário está logado
if (!isset($_SESSION['id_usuario'])) {
    respondError('Usuário não autenticado.');
}

$idOrganizador = (int)$_SESSION['id_usuario'];

// Captura dados do formulário (names iguais ao HTML ajustado)
$titulo        = trim($_POST['titulo'] ?? '');
$descricao     = trim($_POST['descricao'] ?? '');
$data_inicio   = trim($_POST['data_inicio'] ?? '');
$data_fim      = trim($_POST['data_fim'] ?? '');
$local         = trim($_POST['local'] ?? '');
$carga_horaria = $_POST['carga_horaria'] ?? null;
$status        = trim($_POST['status'] ?? 'Aberto');

// Validação mínima
if ($titulo === '') respondError('Título é obrigatório.');
if ($descricao === '') respondError('Descrição é obrigatória.');
if ($data_inicio === '') respondError('Data de início é obrigatória.');
if ($local === '') respondError('Local é obrigatório.');
if ($carga_horaria === null || !is_numeric($carga_horaria)) {
    respondError('Carga horária deve ser um número inteiro.');
}

try {
    $pdo = getDbConnection();
    $pdo->beginTransaction();

    // PostgreSQL: usar RETURNING para obter id_evento
    $sql = "INSERT INTO evento (id_organizador, titulo, descricao, data_inicio, data_fim, local, carga_horaria, status)
            VALUES (:id_organizador, :titulo, :descricao, :data_inicio, :data_fim, :local, :carga_horaria, :status)
            RETURNING id_evento";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id_organizador' => $idOrganizador,
        ':titulo' => $titulo,
        ':descricao' => $descricao,
        ':data_inicio' => $data_inicio,
        ':data_fim' => $data_fim !== '' ? $data_fim : null,
        ':local' => $local,
        ':carga_horaria' => (int)$carga_horaria,
        ':status' => $status
    ]);

    $idEvento = $stmt->fetchColumn();
    $pdo->commit();

    // Redireciona para listagem com id criado
    header('Location: eventosADM.php?created=1&id=' . urlencode((string)$idEvento));
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Erro ao inserir evento: ' . $e->getMessage());
    respondError('Erro ao salvar evento. Tente novamente.');
}
