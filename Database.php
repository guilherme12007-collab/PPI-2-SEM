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
    if ($pdo) return $pdo;
    $dsn = "pgsql:host=".DB_HOST.";port=".DB_PORT.";dbname=".DB_NAME;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}?>