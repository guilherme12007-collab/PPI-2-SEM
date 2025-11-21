<?php
session_start();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css" integrity="sha512-2SwdPD6INVrV/lHTZbO2nodKhrnDdJK9/kg2XD1r9uGqPo1cUbujc+IYdlYdEErWNu69gVcYgdxlmVmzTWnetw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body { min-height: 100vh; display: flex; align-items: center; justify-content: center; background: linear-gradient(to bottom, #133824 0%, #125c2b 100%); padding: 0 1rem; font-family: 'Segoe UI', Arial, sans-serif; }
    .login-container { width: 100%; max-width: 420px; background: rgba(255,255,255,0.95); border-radius: 12px; box-shadow: 0 8px 32px rgba(0,0,0,0.15); padding: 3rem 2rem; }
    .login-header { text-align: center; margin-bottom: 1rem; }
    .login-header h1 { font-size: 2.2rem; color: #125c2b; margin: 0 0 0.5rem 0; font-weight: 700; }
    .login-header p { color: #666; font-size: 0.95rem; margin: 0; }
    .alert { padding: .75rem 1rem; border-radius: 8px; margin-bottom: 1rem; font-weight:600; }
    .alert-error { background:#ffe6e6; color:#8b0000; border:1px solid #f5c2c2; }
    .form-group { margin-bottom: 1.5rem; }
    .form-group label { display:block; font-weight:600; color:#333; margin-bottom:.5rem; font-size:.95rem; }
    .form-group input[type="email"], .form-group input[type="password"] { width:100%; padding:.75rem; border:2px solid #e5e7eb; border-radius:6px; font-size:1rem; box-sizing:border-box; }
    .button-group { display:flex; gap:1rem; margin-bottom:1.5rem; }
    .btn-login { flex:1; padding:.85rem; background:linear-gradient(135deg,#125c2b 0%,#0d4620 100%); color:white; border:none; border-radius:6px; font-size:1.05rem; font-weight:700; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:.5rem; }
    .signup-section { text-align:center; padding:1.5rem; background:#f8fafb; border-radius:8px; border:1px solid #e5e7eb; }
  </style>
</head>
<body>
  <main>
    <div class="login-container">
      <div class="login-header">
        <h1>Login</h1>
        <p>Acesse sua conta para continuar</p>
      </div>

      <?php
      // Exibe todos os erros registrados na sessão (string ou array) e limpa após exibir
      if (!empty($_SESSION['login_error'])) {
          $errors = $_SESSION['login_error'];
          echo '<div class="alert alert-error" id="login-errors">';
          if (is_array($errors)) {
              foreach ($errors as $err) {
                  echo '<div>' . htmlspecialchars($err, ENT_QUOTES, 'UTF-8') . '</div>';
              }
          } else {
              echo htmlspecialchars($errors, ENT_QUOTES, 'UTF-8');
          }
          echo '</div>';
          unset($_SESSION['login_error']);
      }
      ?>

      <form method="POST" action="process/login.php">
        <div class="form-group">
          <label for="email">E-mail</label>
          <input type="email" id="email" name="email" placeholder="seu@email.com" required>
        </div>

        <div class="form-group">
          <label for="senha">Senha</label>
          <input type="password" id="senha" name="senha" placeholder="Sua senha" required>
        </div>

        <div class="button-group">
          <button type="submit" class="btn-login">
            <i class="fa-solid fa-door-open"></i>
            <span>Entrar</span>
          </button>
        </div>
      </form>

      <div class="signup-section">
        <p>Não tem conta?</p>
        <a href="registro.html" class="btn-signup" style="display:inline-flex;align-items:center;gap:.5rem;background:linear-gradient(135deg,#125c2b 0%,#0d4620 100%);color:#fff;padding:.65rem 1.5rem;border-radius:6px;font-weight:700;text-decoration:none;">
          <i class="fa-solid fa-user-plus"></i>
          <span>Registrar Agora</span>
        </a>
      </div>
    </div>
  </main>

  <script>
    // exemplo: não previne submit real — mantém redirecionamento opcional se desejar
  </script>
</body>
</html>