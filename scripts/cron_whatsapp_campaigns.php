<?php

/**
 * Script CRON para processamento de Campanhas de WhatsApp Agendadas.
 * Deve ser executado a cada minuto pelo servidor Debian.
 * 
 * Configuração crontab -e:
 * * * * * * cd /var/www/html/crm/scripts && /usr/bin/php cron_whatsapp_campaigns.php >> /var/log/whatsapp_cron.log 2>&1
 */

// Define as constantes básicas
define('BASE_PATH', dirname(__DIR__));

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Access denied. Este script deve ser executado via CLI (linha de comando).";
    exit;
}

require BASE_PATH . '/vendor/autoload.php';

// Carrega as variáveis de ambiente, se necessário.
if (file_exists(BASE_PATH . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(BASE_PATH);
    $dotenv->load();
}

try {
    error_log("CRON START: process_scheduled_campaigns (" . date('Y-m-d H:i:s') . ")");
    
    // Instanciar a controller e executar
    $controller = new \Apoio19\Crm\Controllers\WhatsappCampaignController();
    $result = $controller->processScheduled();
    
    error_log("CRON END: process_scheduled_campaigns result: " . json_encode($result, JSON_UNESCAPED_UNICODE));
    echo "Execution completed.\n";

} catch (\Exception $e) {
    error_log("CRON ERROR: process_scheduled_campaigns exception: " . $e->getMessage());
    echo "Execution failed: " . $e->getMessage() . "\n";
}
