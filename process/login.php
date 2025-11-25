<?php
session_start();
// O caminho pode variar dependendo da sua estrutura, mas assumimos 'Database.php' no raiz
require_once '../Database.php'; 

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // 1. Recebe e filtra os dados
    $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
    $senha = $_POST['senha'] ?? '';
    
    if (!$email || empty($senha)) {
        $_SESSION['login_error'] = "Credenciais inválidas. Preencha e-mail e senha.";
        echo $_SESSION['login_error']; // <-- echo adicionado para visualizar o erro
        header('Location: ../login.php'); // Redireciona para o formulário de login
        exit();
    }

    // 2. Tenta autenticar
    attemptLogin($email, $senha);
}


function attemptLogin($email, $senha) {
    try {
        $pdo = getDbConnection();

        // Query SQL para buscar o usuário. USAR Prepared Statement é CRUCIAL!
        $sql = "SELECT id_usuario, nome, senha, tipo_perfil FROM usuario WHERE email = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$email]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        // 3. Verifica se o usuário foi encontrado
        if ($usuario) {
            
            // 4. Verifica a senha hashada
            if ($senha === $usuario['senha']) {
                
                // Senha correta: Inicia a sessão e redireciona
                finalizeLogin($usuario);
                
            } else {
                // Senha incorreta
                handleLoginError("Credenciais inválidas (senha). Tente novamente.");
            }
        } else {
            // Usuário não encontrado
            handleLoginError("Credenciais inválidas (e-mail). Tente novamente.");
        }

    } catch (\PDOException $e) {
        // Trata erros de banco de dados
        $msg = "Erro de BD no login: " . $e->getMessage();
        error_log($msg); // Loga o erro
        handleLoginError($msg); // <-- agora passa a mensagem real para visualização
    }
}

/**
 * Registra dados na sessão e redireciona.
 */
function finalizeLogin($usuario) {
    // Registra dados essenciais na sessão
    $_SESSION['logado'] = true;
    $_SESSION['id_usuario'] = $usuario['id_usuario'];
    $_SESSION['nome'] = $usuario['nome'];
    $_SESSION['perfil'] = $usuario['tipo_perfil'];

    // Define o redirecionamento com base no perfil
    if ($usuario['tipo_perfil'] === 'Organizador') {
        header('Location: ../index_organizador.php');
    } else {
        header('Location: ../index_participante.php'); 
    }
    exit();
}

/**
 * Armazena a mensagem de erro na sessão e redireciona.
 */
function handleLoginError(string $message) {
    $_SESSION['login_error'] = $message;
    echo $message; // <-- echo adicionado para visualizar a mensagem de erro
    header('Location: ../login.php');
    exit();
}
?>