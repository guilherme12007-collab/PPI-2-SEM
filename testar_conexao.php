<?php
// Carrega a função de conexão
require_once 'Database.php';

echo "<pre>";

try {
    $pdo = getDbConnection();

    // Testa um simples SELECT
    $stmt = $pdo->query("SELECT version();");
    $versao = $stmt->fetch();

    echo "✅ Conexão estabelecida com sucesso!\n";
    echo "Versão do PostgreSQL:\n";
    print_r($versao);

} catch (PDOException $e) {
    echo "❌ Erro ao conectar: " . $e->getMessage();
}

echo "</pre>";
?>
