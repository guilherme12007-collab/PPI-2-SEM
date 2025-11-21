<?php
// Arquivo: EmailService.php

// Carrega as classes do PHPMailer via Composer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Inclui o autoloader do Composer
require 'vendor/autoload.php';

/**
 * Envia um e-mail com código de verificação.
 * @param string $toEmail O endereço de e-mail do destinatário.
 * @param string $toName O nome do destinatário.
 * @param string $code O código de 4 dígitos a ser enviado.
 * @return bool True se o envio foi bem-sucedido, False caso contrário.
 */
function sendVerificationEmail(string $toEmail, string $toName, string $code): bool {
    $mail = new PHPMailer(true);

    try {
        // Configurações do Servidor SMTP (Exemplo usando Gmail ou servidor IFFar)
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com'; // Ex: 'smtp.gmail.com'
        $mail->SMTPAuth   = true;
        $mail->Username   = 'meco.valandro@gmail.com'; // Seu e-mail de envio
        $mail->Password   = 'jbgc cxbj maiw kemq'; // Senha ou App Password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // Use PHPMailer::ENCRYPTION_STARTTLS para porta 587
        $mail->Port       = 465; // Ou 587 para TLS
        
        // Remetente
        $mail->setFrom('sistema@sigecca.com.br', 'SIGECA - IFFar');

        // Destinatário
        $mail->addAddress($toEmail, $toName); 

        // Conteúdo
        $mail->isHTML(true);
        $mail->Subject = 'Codigo de Verificacao de Cadastro SIGECA';
        $mail->Body    = "Olá $toName,<br><br>Seu código de verificação de 4 dígitos é: <h1>$code</h1><br>Use-o para finalizar seu cadastro no Sistema SIGECA.";
        $mail->AltBody = "Seu código de verificação é: $code";

        $mail->send();
        return true;

    } catch (Exception $e) {
        // CAPTURA o erro do PHPMailer e LANÇA uma exceção mais limpa 
        // para ser tratada no script principal.
        throw new \Exception("Falha no envio do email: " . $mail->ErrorInfo);
    }
}
?>