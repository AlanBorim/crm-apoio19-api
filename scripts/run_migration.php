<?php
require __DIR__ . '/../vendor/autoload.php';

$host = '69.62.90.56';
$port = '3306';
$db   = 'crm';
$user = 'crmuser';
$pass = 'CRM@19user';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    
    $sql = file_get_contents(__DIR__ . '/../migrations/modulo_financeiro.sql');
    
    // Split by semicolons for basic execution, or just execute it if PDO supports multiple queries
    // Usually PDO supports executing the entire script at once if emulate prepares is off
    $pdo->exec($sql);
    
    echo "Migration executada com sucesso!\n";
} catch (\PDOException $e) {
    echo "Erro na conexao ou execucao: " . $e->getMessage() . "\n";
}
