<?php
session_start();
require_once '../Database.php'; 

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $codigo_digitado = $_POST['codigo'] ?? '';
    $temp_data = $_SESSION['temp_cadastro'] ?? null;

    if ($temp_data === null) {
        $_SESSION['final_error'] = "Sessão expirada ou dados ausentes. Recomece o cadastro.";
        header('Location: cadastro.html'); 
        exit();
    }
    
    // 1. Compara o código digitado com o código da sessão
    if ($codigo_digitado === $temp_data['codigo']) {
        
        // CÓDIGO VÁLIDO: INSERIR NO BANCO
        $pdo = getDbConnection();
        $sql = "INSERT INTO USUARIO (nome, email, senha, tipo_perfil) VALUES (?, ?, ?, ?)";
        
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $temp_data['nome'], 
                $temp_data['email'], 
                $temp_data['senha'],
                $temp_data['perfil']
            ]);

            // Limpa os dados temporários da sessão
            unset($_SESSION['temp_cadastro']);
            $_SESSION['success_message'] = "Cadastro realizado com sucesso! Faça login.";
            header('Location: ../login.php'); // Vai para a tela de login
            exit();

        } catch (\PDOException $e) {
            $_SESSION['final_error'] = "Erro ao finalizar o cadastro no banco de dados.";
            header('Location: ../registro.html');
            exit();
        }

    } else {
        // CÓDIGO INVÁLIDO
        $_SESSION['verificacao_error'] = "Código de verificação incorreto. Tente novamente.";
        // Retorna para a tela_verificacao.html
        header('Location: tela_verificacao.html'); 
        exit();
    }
}
?>