<?php

/**
 * Importação Final V3 - APENAS Campanha Missão Herbarium
 * 
 * Regras Absolutas:
 * 1. NÃO mexer em whatsapp_chat_messages
 * 2. Importar APENAS para whatsapp_campaign_messages
 * 3. Campaign ID fixo: 1 (Missão Herbarium)
 * 4. NÃO usar CSV
 * 5. Processar apenas webhooks JSON (statuses)
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Models/Database.php';

use Apoio19\Crm\Models\Database;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

echo "=== IMPORTACAO V3 - APENAS CAMPANHA ===\n";

try {
    $db = Database::getInstance();
    $userId = 1;
    $campaignId = 1; // Missão Herbarium

    // Verificar se campanha existe
    $stmt = $db->prepare("SELECT name, status FROM whatsapp_campaigns WHERE id = ?");
    $stmt->execute([$campaignId]);
    $campaign = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$campaign) {
        die("ERRO: Campanha ID 1 (Missão Herbarium) não encontrada no banco!\n");
    }

    echo "Campanha: {$campaign['name']} | Status: {$campaign['status']}\n";

    // Setup Templates
    $tpl1 = ensureTemplate($db, 'herbarium_msg_1');
    $tpl2 = ensureTemplate($db, 'herbarium_msg_2');

    $path1 = realpath(__DIR__ . '/../api/hook/envio-2101-13h45');
    $path2 = realpath(__DIR__ . '/../api/hook');

    $stats = [
        'files_processed' => 0,
        'msg1_created' => 0,
        'msg2_created' => 0,
        'skipped' => 0
    ];

    // Processar Pasta 1 (herbarium_msg_1)
    if ($path1) {
        $files1 = glob($path1 . '/*.json');
        echo "\nProcessando " . count($files1) . " arquivos (Template 1)...\n";
        foreach ($files1 as $f) {
            processFile($db, $f, $campaignId, $tpl1, $userId, $stats, 'msg1_created');
        }
    }

    // Processar Pasta 2 (herbarium_msg_2)
    if ($path2) {
        $files2 = glob($path2 . '/webhook_data_*.json');
        echo "\nProcessando " . count($files2) . " arquivos (Template 2)...\n";
        foreach ($files2 as $f) {
            processFile($db, $f, $campaignId, $tpl2, $userId, $stats, 'msg2_created');
        }
    }

    echo "\n=== RESUMO ===\n";
    echo "Arquivos processados: {$stats['files_processed']}\n";
    echo "Template 1 criados: {$stats['msg1_created']}\n";
    echo "Template 2 criados: {$stats['msg2_created']}\n";
    echo "Duplicados ignorados: {$stats['skipped']}\n";
} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
}

// ============= FUNCTIONS =============

function processFile($db, $filepath, $campaignId, $templateId, $userId, &$stats, $statKey)
{
    $content = file_get_contents($filepath);
    $json = json_decode($content, true);

    // Tentar encontrar entry no payload
    $payload = isset($json['body']['entry']) ? $json['body'] : (isset($json['entry']) ? $json : null);

    if (!$payload || !isset($payload['entry'][0]['changes'][0]['value'])) {
        return; // Skip arquivo inválido
    }

    $value = $payload['entry'][0]['changes'][0]['value'];
    $stats['files_processed']++;

    // Processar apenas STATUSES (outbound)
    if (!isset($value['statuses'])) {
        return; // Ignorar mensagens inbound
    }

    foreach ($value['statuses'] as $st) {
        $waId = $st['id'];
        $status = $st['status'];
        $timestamp = $st['timestamp'];
        $sentAt = date('Y-m-d H:i:s', $timestamp);
        $recipientId = $st['recipient_id'];

        // Verificar duplicata
        if (existsInCampaign($db, $waId)) {
            $stats['skipped']++;
            continue;
        }

        // Obter/criar contato
        $contactId = getContactId($db, $recipientId, $userId);

        // Inserir em whatsapp_campaign_messages
        $stmt = $db->prepare("
            INSERT INTO whatsapp_campaign_messages
            (campaign_id, contact_id, template_id, status, message_id, sent_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");

        $stmt->execute([
            $campaignId,
            $contactId,
            $templateId,
            $status,
            $waId,
            $sentAt
        ]);

        $stats[$statKey]++;
    }
}

function ensureTemplate($db, $name)
{
    $stmt = $db->prepare("SELECT id FROM whatsapp_templates WHERE name = ?");
    $stmt->execute([$name]);
    if ($row = $stmt->fetch()) {
        return $row['id'];
    }

    // Criar template se não existe
    try {
        $stmt = $db->prepare("
            INSERT INTO whatsapp_templates 
            (name, template_id, category, language, status, created_at) 
            VALUES (?, ?, 'UTILITY', 'pt_BR', 'APPROVED', NOW())
        ");
        $stmt->execute([$name, $name . '_id']);
        return $db->lastInsertId();
    } catch (Exception $e) {
        echo "AVISO: Erro ao criar template $name: " . $e->getMessage() . "\n";
        return 0;
    }
}

function getContactId($db, $phone, $userId)
{
    if (!$phone) return null;

    // Buscar contato existente
    $stmt = $db->prepare("SELECT id FROM whatsapp_contacts WHERE phone_number = ?");
    $stmt->execute([$phone]);
    if ($id = $stmt->fetchColumn()) {
        return $id;
    }

    // Criar novo contato
    $contactName = 'Contato ' . substr($phone, -4);

    // Tentar buscar nome na tabela contacts antiga
    try {
        $stmt2 = $db->prepare("SELECT name FROM contacts WHERE phone LIKE ? LIMIT 1");
        $stmt2->execute(["%$phone%"]);
        if ($n = $stmt2->fetchColumn()) {
            $contactName = $n;
        }
    } catch (Exception $e) {
    }

    // Inserir contato
    try {
        $stmt = $db->prepare("
            INSERT INTO whatsapp_contacts (phone_number, name, user_id, created_at) 
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$phone, $contactName, $userId]);
    } catch (Exception $e) {
        // Tentar sem user_id se falhar
        try {
            $stmt = $db->prepare("
                INSERT INTO whatsapp_contacts (phone_number, name, created_at) 
                VALUES (?, ?, NOW())
            ");
            $stmt->execute([$phone, $contactName]);
        } catch (Exception $e2) {
            echo "ERRO ao criar contato $phone: " . $e2->getMessage() . "\n";
            return null;
        }
    }

    return $db->lastInsertId();
}

function existsInCampaign($db, $messageId)
{
    $stmt = $db->prepare("SELECT id FROM whatsapp_campaign_messages WHERE message_id = ?");
    $stmt->execute([$messageId]);
    return (bool) $stmt->fetchColumn();
}
