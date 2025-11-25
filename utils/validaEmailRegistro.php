<?php
function determineProfileAndValidateDomain(string $email): ?string {
    $parts = explode('@', $email);
    if (count($parts) !== 2) {
        return null; // Formato de e-mail inválido
    }
    $domain = $parts[1];

    if ($domain === 'iffarroupilha.edu.br') {
        return 'Organizador'; // Professor/Organizador
    } elseif ($domain === 'aluno.iffar.edu.br') {
        return 'Participante'; // Aluno/Participante
    } else {
        return null; // Domínio não permitido
    }
}

// Função simples para gerar um código numérico de 4 dígitos
function generateVerificationCode(): string {
    return str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
}

?>