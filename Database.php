<?php
// Arquivo: Database.php

// Inclui as configurações
require_once 'config.php'; 

/**
 * Cria e retorna a conexão PDO com o banco de dados PostgreSQL.
 * @return PDO O objeto de conexão PDO.
 */
function getDbConnection() {
    static $pdo = null; 

    if ($pdo === null) {
        // A DSN usa 'pgsql', o novo HOST do pooler e a porta 5432
        $dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";options='--client_encoding=" . DB_CHARSET . "'";
        
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, 
        ];

        try {
            // Conexão usando o novo usuário do pooler (DB_USER = 'postgres.cqdbs...')
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            
            // Garante que o PDO use o esquema 'public' (padrão do Supabase)
            $pdo->exec("SET search_path TO public;");
            
        } catch (\PDOException $e) {
            die("Erro de Conexão com o Banco de Dados (Session Pooler): " . $e->getMessage());
        }
    }
    return $pdo;
}?>