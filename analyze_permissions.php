<?php
$pdo = new PDO(
    'mysql:host=69.62.90.56;port=3306;dbname=crm;charset=utf8mb4',
    'crmuser',
    'CRM@19user',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

echo "=== ESTRUTURA DA TABELA USERS ===" . PHP_EOL . PHP_EOL;
$stmt = $pdo->query("DESCRIBE users");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo sprintf(
        "%-20s | %-15s | %-5s | %-4s | %s" . PHP_EOL,
        $row['Field'],
        $row['Type'],
        $row['Null'],
        $row['Key'],
        $row['Default'] ?? 'NULL'
    );
}

echo PHP_EOL . "=== ROLES DISTINTOS NA TABELA ===" . PHP_EOL . PHP_EOL;
$stmt = $pdo->query("SELECT DISTINCT role FROM users WHERE role IS NOT NULL");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "- " . $row['role'] . PHP_EOL;
}

echo PHP_EOL . "=== VERIFICANDO TABELAS DE PERMISSÕES ===" . PHP_EOL . PHP_EOL;
$stmt = $pdo->query("SHOW TABLES LIKE '%permission%'");
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
if (empty($tables)) {
    echo "Nenhuma tabela de permissões encontrada." . PHP_EOL;
} else {
    foreach ($tables as $table) {
        echo "Tabela encontrada: $table" . PHP_EOL;
    }
}
