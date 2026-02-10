<?php

/**
 * Unified Import Script - Fixed V4
 * Processa todos os arquivos em api/hook para reconstruir o histórico.
 * Correções: Removido contact_name/phone de campaign_messages.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Models/Database.php';

use Apoio19\Crm\Models\Database;
use Dotenv\Dotenv;

// Carregar variáveis de ambiente
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

echo "=== INICIANDO IMPORTACAO UNIFICADA (FIX V4) ===\n";

try {
    $db = Database::getInstance();
    $userId = 1; // ID do sistema/admin padrão

    // 1. Configuração da Campanha Padrão
    $campaignName = 'Histórico Importado';
    $stmt = $db->prepare("SELECT id FROM whatsapp_campaigns WHERE name = ?");
    $stmt->execute([$campaignName]);
    $campaign = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$campaign) {
        $sql = "INSERT INTO whatsapp_campaigns (name, status, user_id, created_at) VALUES (?, 'completed', ?, NOW())";
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute([$campaignName, $userId]);
        } catch (Exception $e) {
            $stmt = $db->prepare("INSERT INTO whatsapp_campaigns (name, status, created_at) VALUES (?, 'completed', NOW())");
            $stmt->execute([$campaignName]);
        }
        $campaignId = $db->lastInsertId();
        echo "Campanha criada/encontrada: $campaignName (ID: $campaignId)\n";
    } else {
        $campaignId = $campaign['id'];
        echo "Usando campanha existente: $campaignName (ID: $campaignId)\n";
    }

    // Configuração do Template Padrão
    $templateName = 'Template Histórico';
    $metaTemplateId = 'historical_import_1';

    $stmt = $db->prepare("SELECT id FROM whatsapp_templates WHERE name = ?");
    $stmt->execute([$templateName]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$template) {
        $sql = "INSERT INTO whatsapp_templates (name, template_id, category, language, status, created_at) 
                VALUES (?, ?, 'UTILITY', 'pt_BR', 'APPROVED', NOW())";
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute([$templateName, $metaTemplateId]);
            $templateId = $db->lastInsertId();
            echo "Template criado: $templateName (ID: $templateId)\n";
        } catch (Exception $e) {
            echo "Erro insert template V4: " . $e->getMessage() . "\n";
            $stmt = $db->prepare("INSERT INTO whatsapp_templates (name, language, status, created_at) VALUES (?, 'pt_BR', 'APPROVED', NOW())");
            $stmt->execute([$templateName]);
            $templateId = $db->lastInsertId();
            echo "Template criado (Fallback): $templateName (ID: $templateId)\n";
        }
    } else {
        $templateId = $template['id'];
        echo "Template existente: $templateName (ID: $templateId)\n";
    }

    $logDir = __DIR__ . '/../api/hook';
    $files = glob($logDir . '/*.{txt,json}', GLOB_BRACE);

    echo "Encontrados " . count($files) . " arquivos de log.\n";

    $stats = [
        'files_processed' => 0,
        'outbound_new' => 0,
        'outbound_skipped' => 0,
        'inbound_new' => 0,
        'inbound_skipped' => 0,
        'status_updated' => 0
    ];

    foreach ($files as $file) {
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        if ($extension === 'txt') {
            processTxtLog($db, $file, $campaignId, $templateId, $userId, $stats);
        } elseif ($extension === 'json') {
            processJsonWebhook($db, $file, $userId, $stats);
        }

        $stats['files_processed']++;
        if ($stats['files_processed'] % 10 === 0) echo ".";
    }

    echo "\n\n=== RESUMO DA IMPORTACAO ===\n";
    echo "Arquivos processados: {$stats['files_processed']}\n";
    echo "Envios (Novos): {$stats['outbound_new']}\n";
    echo "Envios (Ignorados/Duplicados): {$stats['outbound_skipped']}\n";
    echo "Recebidos (Novos): {$stats['inbound_new']}\n";
    echo "Recebidos (Ignorados): {$stats['inbound_skipped']}\n";
    echo "Status Atualizados: {$stats['status_updated']}\n";
    echo "============================\n";
} catch (Exception $e) {
    echo "\nERRO FATAL: " . $e->getMessage() . "\n";
}

// ---------------- Functions ----------------

function processTxtLog($db, $filepath, $campaignId, $templateId, $userId, &$stats)
{
    $content = file_get_contents($filepath);

    $fileDate = null;
    if (preg_match('/Data\/Hora:\s*(\d{2}\/\d{2}\/\d{4})/', $content, $m)) {
        $dt = DateTime::createFromFormat('d/m/Y', $m[1]);
        if ($dt) $fileDate = $dt->format('Y-m-d');
    }
    if (!$fileDate && preg_match('/_(\d{4}-\d{2}-\d{2})_/', $filepath, $m)) {
        $fileDate = $m[1];
    }
    if (!$fileDate) $fileDate = date('Y-m-d');

    $blocks = explode('----------------------------------------', $content);

    foreach ($blocks as $block) {
        if (!trim($block)) continue;

        $phone = null;
        $time = null;
        $waId = null;
        $status = 'failed';

        if (preg_match('/Número:\s*(\d+)/', $block, $m)) $phone = $m[1];
        if (!$phone) continue;

        if (preg_match('/Horário:\s*([\d:]+)/', $block, $m)) $time = $m[1];
        if (preg_match('/Message ID:\s*(wamid\.[^\s]+)/', $block, $m)) $waId = $m[1];
        if (strpos($block, 'Status: SUCESSO') !== false) $status = 'sent';

        if (!$waId) continue;

        $sentAt = "$fileDate " . ($time ? $time : '00:00:00');

        $contactId = getContactId($db, $phone, $userId);

        if (existsMessage($db, $waId)) {
            $stats['outbound_skipped']++;
            continue;
        }

        $msgContent = "Template Enviado (Campanha)";

        $stmt = $db->prepare("
            INSERT INTO whatsapp_chat_messages 
            (contact_id, user_id, direction, message_type, message_content, whatsapp_message_id, status, sent_at, created_at)
            VALUES (?, ?, 'outgoing', 'template', ?, ?, ?, ?, ?)
         ");
        $stmt->execute([$contactId, $userId, $msgContent, $waId, $status, $sentAt, $sentAt]);

        // V4 Fix: Remover contact_name e contact_phone
        $stmt = $db->prepare("
            INSERT INTO whatsapp_campaign_messages
            (campaign_id, contact_id, template_id, status, message_id, sent_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
         ");
        $stmt->execute([$campaignId, $contactId, $templateId, $status, $waId, $sentAt, $sentAt]);

        $stats['outbound_new']++;
    }
}

function processJsonWebhook($db, $filepath, $userId, &$stats)
{
    $content = file_get_contents($filepath);
    $data = json_decode($content, true);

    if (!$data || !isset($data['entry'][0]['changes'][0]['value'])) return;
    $value = $data['entry'][0]['changes'][0]['value'];

    if (isset($value['messages'])) {
        foreach ($value['messages'] as $msg) {
            $waId = $msg['id'];
            if (existsMessage($db, $waId)) {
                $stats['inbound_skipped']++;
                continue;
            }

            $from = $msg['from'];
            $timestamp = $msg['timestamp'];
            $sentAt = date('Y-m-d H:i:s', $timestamp);
            $type = $msg['type'];
            $body = $type === 'text' ? ($msg['text']['body'] ?? '') : "[$type message]";

            $contactId = getContactId($db, $from, $userId);

            $stmt = $db->prepare("
                INSERT INTO whatsapp_chat_messages 
                (contact_id, user_id, direction, message_type, message_content, whatsapp_message_id, status, sent_at, created_at)
                VALUES (?, ?, 'incoming', ?, ?, ?, 'delivered', ?, ?)
            ");
            $stmt->execute([$contactId, $userId, $type, $body, $waId, $sentAt, $sentAt]);

            $stats['inbound_new']++;
        }
    }

    if (isset($value['statuses'])) {
        foreach ($value['statuses'] as $st) {
            updateStatus($db, $st['id'], $st['status'], date('Y-m-d H:i:s', $st['timestamp']));
            $stats['status_updated']++;
        }
    }
}

function getContactId($db, $phone, $userId)
{
    $stmt = $db->prepare("SELECT id FROM whatsapp_contacts WHERE phone_number = ?");
    $stmt->execute([$phone]);
    if ($id = $stmt->fetchColumn()) return $id;

    $name = 'Contato ' . substr($phone, -4);
    $stmt2 = $db->prepare("SELECT name FROM contacts WHERE phone LIKE ? LIMIT 1");
    $stmt2->execute(["%$phone%"]);
    if ($n = $stmt2->fetchColumn()) $name = $n;

    try {
        $stmt = $db->prepare("INSERT INTO whatsapp_contacts (phone_number, name, user_id, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$phone, $name, $userId]);
    } catch (Exception $e) {
        try {
            $stmt = $db->prepare("INSERT INTO whatsapp_contacts (phone_number, name, created_at) VALUES (?, ?, NOW())");
            $stmt->execute([$phone, $name]);
        } catch (Exception $e2) {
        }
    }
    return $db->lastInsertId();
}

function existsMessage($db, $waId)
{
    $stmt = $db->prepare("SELECT id FROM whatsapp_chat_messages WHERE whatsapp_message_id = ?");
    $stmt->execute([$waId]);
    return (bool) $stmt->fetchColumn();
}

function updateStatus($db, $waId, $status, $timestamp)
{
    $col = ($status === 'delivered') ? 'delivered_at' : (($status === 'read') ? 'read_at' : (($status === 'failed') ? 'failed_at' : null));

    $sql = "UPDATE whatsapp_chat_messages SET status = ?";
    if ($col) $sql .= ", $col = ?";
    $sql .= " WHERE whatsapp_message_id = ?";
    $db->prepare($sql)->execute($col ? [$status, $timestamp, $waId] : [$status, $waId]);

    $sql = "UPDATE whatsapp_campaign_messages SET status = ?";
    if ($col) $sql .= ", $col = ?";
    $sql .= " WHERE message_id = ?";
    $db->prepare($sql)->execute($col ? [$status, $timestamp, $waId] : [$status, $waId]);
}
