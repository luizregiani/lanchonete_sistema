<?php
require_once __DIR__ . '/config.php';
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NOME . ";charset=utf8mb4", DB_USER, DB_SENHA,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES => false]
    );
} catch (PDOException $e) {
    http_response_code(500);
    die("<p style='color:#842029;padding:15px;background:#f8d7da'>❌ Não foi possível conectar ao MySQL. Verifique se o MySQL está ligado no XAMPP e se importou o banco.sql.</p>");
}
