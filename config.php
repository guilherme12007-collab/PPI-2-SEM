<?php
// Arquivo: config.php

// Constantes de conexão com o PostgreSQL do Supabase (MODO SESSION POOLER)
define('DB_HOST', 'aws-1-sa-east-1.pooler.supabase.com'); 
define('DB_PORT', '5432');
define('DB_NAME', 'postgres'); 
define('DB_USER', 'postgres.cqdbsxjafcssjbfzjngb'); // Usuário no formato pooler
define('DB_PASS', 'Ppi@2025');
define('DB_CHARSET', 'utf8');

// IPs públicos permitidos (separados por vírgula).
// Em produção coloque os IPs válidos; para dev adicione 127.0.0.1 e ::1
define('ALLOWED_PUBLIC_IPS', '127.0.0.1, 45.236.133.158, 192.168.1.11');
?>