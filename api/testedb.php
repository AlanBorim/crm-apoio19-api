<?php
/**
 * Script de Diagnóstico - Conexão com Banco de Dados
 * 
 * Este script testa a conexão com o banco e verifica a existência das tabelas
 * necessárias para o funcionamento da API.
 */

echo "=== DIAGNÓSTICO DE CONEXÃO COM BANCO DE DADOS ===<br><br>";

// Carregar autoload
$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    echo "❌ ERRO: Autoload não encontrado em {$autoloadPath}<br>";
    exit(1);
}
require $autoloadPath;

// Carregar variáveis de ambiente
try {
    if (file_exists(__DIR__ . '/../.env')) {
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__.'/../');
        $dotenv->load();
        echo "✅ Arquivo .env carregado com sucesso<br>";
    } else {
        echo "❌ ERRO: Arquivo .env não encontrado<br>";
        exit;
    }
} catch (Exception $e) {
    echo "❌ ERRO ao carregar .env: " . $e->getMessage() . "<br>";
    exit(1);
}

// Mostrar configurações do banco
echo "<br>=== CONFIGURAÇÕES DO BANCO ===<br>";
echo "Host: " . ($_ENV['DB_HOST'] ?? 'NÃO DEFINIDO') . "<br>";
echo "Port: " . ($_ENV['DB_PORT'] ?? 'NÃO DEFINIDO') . "<br>";
echo "Database: " . ($_ENV['DB_DATABASE'] ?? 'NÃO DEFINIDO') . "<br>";
echo "Username: " . ($_ENV['DB_USERNAME'] ?? 'NÃO DEFINIDO') . "<br>";
echo "Password: " . (isset($_ENV['DB_PASSWORD']) ? '[DEFINIDA]' : 'NÃO DEFINIDA') . "<br>";

// Testar conexão usando as configurações do .env
echo "<br>=== TESTE DE CONEXÃO ===<br>";

$host = $_ENV['DB_HOST'] ?? 'localhost';
$port = $_ENV['DB_PORT'] ?? 3306;
$database = $_ENV['DB_DATABASE'] ?? 'crm_apoio19';
$username = $_ENV['DB_USERNAME'] ?? 'root';
$password = $_ENV['DB_PASSWORD'] ?? '';

$dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    echo "✅ Conexão estabelecida com sucesso!<br>";
    echo "   Banco conectado: {$database}<br>";
    
    // Verificar qual banco está sendo usado
    $stmt = $pdo->query("SELECT DATABASE() as current_db");
    $currentDb = $stmt->fetch()['current_db'];
    echo "   Banco atual: {$currentDb}<br>";
    
} catch (PDOException $e) {
    echo "❌ ERRO de conexão: " . $e->getMessage() . "<br>";
    
    // Tentar com banco padrão crm_apoio19
    if ($database !== 'crm_apoio19') {
        echo "<br>--- Tentando com banco padrão 'crm_apoio19' ---<br>";
        $dsn2 = "mysql:host={$host};port={$port};dbname=crm_apoio19;charset=utf8mb4";
        try {
            $pdo = new PDO($dsn2, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            echo "✅ Conexão com 'crm_apoio19' estabelecida!<br>";
            $database = 'crm_apoio19';
        } catch (PDOException $e2) {
            echo "❌ ERRO também com 'crm_apoio19': " . $e2->getMessage() . "<br>";
            exit(1);
        }
    } else {
        exit(1);
    }
}

// Listar todas as tabelas
echo "<br>=== TABELAS DISPONÍVEIS ===<br>";
try {
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($tables)) {
        echo "❌ Nenhuma tabela encontrada no banco '{$database}'<br>";
    } else {
        echo "Tabelas encontradas no banco '{$database}':<br>";
        foreach ($tables as $table) {
            echo "  - {$table}<br>";
        }
    }
} catch (PDOException $e) {
    echo "❌ ERRO ao listar tabelas: " . $e->getMessage() . "<br>";
}

// Testar tabelas específicas necessárias para a API
echo "<br>=== TESTE DAS TABELAS DA API ===<br>";

$requiredTables = [
    'users' => 'AuthController (Login)',
    'leads' => 'LeadController (Leads)',
    'notifications' => 'NotificationController (Notificações)'
];

foreach ($requiredTables as $table => $controller) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM {$table} LIMIT 1");
        $stmt->execute();
        $result = $stmt->fetch();
        $count = $result['count'];
        
        echo "✅ Tabela '{$table}': {$count} registros ({$controller})<br>";
        
        // Mostrar estrutura da tabela
        $stmt = $pdo->query("DESCRIBE {$table}");
        $columns = $stmt->fetchAll();
        echo "   Colunas: ";
        $columnNames = array_map(function($col) { return $col['Field']; }, $columns);
        echo implode(', ', $columnNames) . "<br>";
        
    } catch (PDOException $e) {
        echo "❌ Tabela '{$table}': ERRO - {$e->getMessage()} ({$controller})<br>";
        
        // Verificar se existe em outro banco
        if ($database === 'crm') {
            echo "   Verificando em 'crm_apoio19'...<br>";
            try {
                $stmt = $pdo->query("SELECT COUNT(*) FROM crm_apoio19.{$table}");
                $count = $stmt->fetchColumn();
                echo "   ✅ Encontrada em 'crm_apoio19': {$count} registros<br>";
            } catch (PDOException $e2) {
                echo "   ❌ Também não existe em 'crm_apoio19'<br>";
            }
        }
    }
}

// Testar usando a classe Database da aplicação
echo "<br>=== TESTE COM CLASSE DATABASE DA APLICAÇÃO ===<br>";

try {
    // Incluir a classe Database
    require_once __DIR__ . '/../src/Models/Database.php';
    
    $appPdo = \Apoio19\Crm\Models\Database::getInstance();
    echo "✅ Classe Database::getInstance() funcionou!<br>";
    
    // Verificar qual banco a classe está usando
    $stmt = $appPdo->query("SELECT DATABASE() as current_db");
    $appCurrentDb = $stmt->fetch()['current_db'];
    echo "   Banco usado pela classe: {$appCurrentDb}<br>";
    
    // Testar query do AuthController (que funciona)
    try {
        $stmt = $appPdo->prepare("SELECT COUNT(*) FROM users");
        $stmt->execute();
        $userCount = $stmt->fetchColumn();
        echo "✅ Query AuthController: {$userCount} usuários encontrados<br>";
    } catch (PDOException $e) {
        echo "❌ Query AuthController falhou: " . $e->getMessage() . "<br>";
    }
    
    // Testar query do LeadController (que falha)
    try {
        $stmt = $appPdo->prepare("SELECT COUNT(*) FROM leads");
        $stmt->execute();
        $leadCount = $stmt->fetchColumn();
        echo "✅ Query LeadController: {$leadCount} leads encontrados<br>";
    } catch (PDOException $e) {
        echo "❌ Query LeadController falhou: " . $e->getMessage() . "<br>";
    }
    
    // Testar query do NotificationController (que falha)
    try {
        $stmt = $appPdo->prepare("SELECT COUNT(*) FROM notifications");
        $stmt->execute();
        $notifCount = $stmt->fetchColumn();
        echo "✅ Query NotificationController: {$notifCount} notificações encontradas<br>";
    } catch (PDOException $e) {
        echo "❌ Query NotificationController falhou: " . $e->getMessage() . "<br>";
    }
    
} catch (Exception $e) {
    echo "❌ ERRO com classe Database: " . $e->getMessage() . "<br>";
}

// Verificar permissões do usuário
echo "<br>=== PERMISSÕES DO USUÁRIO ===<br>";
try {
    $stmt = $pdo->query("SHOW GRANTS FOR CURRENT_USER()");
    $grants = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "Permissões do usuário '{$username}':<br>";
    foreach ($grants as $grant) {
        echo "  {$grant}<br>";
    }
} catch (PDOException $e) {
    echo "❌ ERRO ao verificar permissões: " . $e->getMessage() . "<br>";
}

// Sugestões de correção
echo "<br>=== SUGESTÕES DE CORREÇÃO ===<br>";

if ($database === 'crm' && isset($appCurrentDb) && $appCurrentDb === 'crm') {
    echo "1. ✅ Configuração .env e classe Database estão alinhadas (banco: crm)<br>";
} elseif ($database !== ($appCurrentDb ?? '')) {
    echo "1. ❌ INCONSISTÊNCIA: .env usa '{$database}' mas classe Database usa '{$appCurrentDb}'<br>";
    echo "   SOLUÇÃO: Ajustar DB_DATABASE no .env para '{$appCurrentDb}'<br>";
}

$missingTables = [];
foreach ($requiredTables as $table => $controller) {
    try {
        $pdo->query("SELECT 1 FROM {$table} LIMIT 1");
    } catch (PDOException $e) {
        $missingTables[] = $table;
    }
}

if (!empty($missingTables)) {
    echo "2. ❌ TABELAS FALTANDO: " . implode(', ', $missingTables) . "<br>";
    echo "   SOLUÇÃO: Criar as tabelas ou ajustar o banco no .env<br>";
} else {
    echo "2. ✅ Todas as tabelas necessárias existem<br>";
}

echo "<br>=== FIM DO DIAGNÓSTICO ===<br>";
echo "Execute este script para identificar o problema específico do seu ambiente.<br>";
?>

