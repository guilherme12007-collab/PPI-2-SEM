<?php
session_start();
require_once '../Database.php'; // Para a conexão (em caso de checagem de e-mail já existe)
require_once '../utils/validaEmailRegistro.php'; // Onde estão as funções acima
require_once '../EmailService.php'; // Serviço de envio de e-mails (você precisará implementar isso)

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nome = filter_input(INPUT_POST, 'nome', FILTER_SANITIZE_STRING);
    $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
    $senha = $_POST['senha'] ?? '';
    
    // 1. Determina o perfil com base no domínio
    $perfil = determineProfileAndValidateDomain($email);

    if (!$perfil) {
        $_SESSION['register_error'] = "Domínio de e-mail inválido. Use @iffarroupilha.edu.br ou @aluno.iffar.edu.br.";
        header('Location: cadastro.html'); 
        exit();
    }
    
    // 2. Verifica se o e-mail já está cadastrado no BD (boa prática)
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT id_usuario FROM USUARIO WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        $_SESSION['register_error'] = "Este e-mail já está cadastrado.";
        echo "Este e-mail já está cadastrado.";
        header('Location: ../registro.html'); 
        exit();
    }

    // 3. Gera código e armazena dados na sessão
    $codigo_verificacao = generateVerificationCode();
    
    // Armazena dados para uso após a verificação
    $_SESSION['temp_cadastro'] = [
        'nome' => $nome,
        'email' => $email,
        'senha' => $senha,
        'perfil' => $perfil,
        'codigo' => $codigo_verificacao,
        'timestamp' => time() // Opcional: para expirar o código
    ];
    
    // --- 4. TENTATIVA DE ENVIO COM TRATAMENTO DE ERRO DETALHADO ---
try {
    // A função agora lança uma exceção se falhar
    if (sendVerificationEmail($email, $nome, $codigo_verificacao)) {
        // E-mail enviado com sucesso
        header('Location: ./verifica_codigo.html'); // Redirecionamento corrigido
        exit();
    }
} catch (\Exception $e) {
    // FALHA NO ENVIO (captura a exceção lançada no EmailService)
    
    // Armazena a mensagem detalhada do erro (ex: problema de senha SMTP) na sessão
    $_SESSION['register_error'] = "Erro ao enviar o código de verificação. Detalhes: " . $e->getMessage();

    echo "Erro ao enviar o código de verificação: " . $e->getMessage();
    
    // Redireciona para a tela de cadastro (caminho corrigido)
    header('Location: ../registro.html'); 
    exit();
}

// Se o script chegou aqui, algo inesperado ocorreu (bom ter um fallback)
$_SESSION['register_error'] = "Erro desconhecido no fluxo de registro.";
header('Location: ../registro.html');
exit();
}
?>