<?php
// Test database connection
try {
    $pdo = new PDO(
        'mysql:host=69.62.90.56;port=3306;dbname=crm;charset=utf8mb4',
        'crmuser',
        'CRM@19user',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

    echo "✓ Conexão estabelecida com sucesso!" . PHP_EOL;
    echo "Servidor MySQL: " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . PHP_EOL;

    // Test query
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM users");
    $result = $stmt->fetch();
    echo "Total de usuários no banco: " . $result['total'] . PHP_EOL;

    echo PHP_EOL . "✓ Teste de conexão concluído com sucesso!" . PHP_EOL;
} catch (PDOException $e) {
    echo "✗ Erro ao conectar ao banco de dados:" . PHP_EOL;
    echo "Mensagem: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
