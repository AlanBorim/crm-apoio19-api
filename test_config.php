<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/vendor/autoload.php';

try {
    if (file_exists(__DIR__ . '/.env')) {
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
        $dotenv->load();
    }
} catch (\Exception $e) {
    echo "Env error: " . $e->getMessage() . "\n";
}

use Apoio19\Crm\Controllers\FinanceiroController;

$controller = new FinanceiroController();
$result = $controller->getConfig([]);
echo json_encode($result);
