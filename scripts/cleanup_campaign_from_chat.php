<?php

/**
 * Script de Limpeza COMPLETA - Campanha para Chat
 * 
 * Remove TODAS as mensagens de whatsapp_chat_messages
 * que existem em whatsapp_campaign_messages (campanha ID 1)
 */

require_once __DIR__ . '/../api/index.php';
// Usar conexão direta ao invés de autoload
$db = Apoio19\Crm\Models\Database::getInstance();

echo "=== LIMPEZA COMPLETA: Campanha \u003e Chat ===\n\n";

try {
    $db = Database::getInstance();

    // 1. Contar mensagens na campanha
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM whatsapp_campaign_messages WHERE campaign_id = 1");
    $stmt->execute();
    $campaignCount = $stmt->fetchColumn();

    echo "Mensagens na campanha (ID 1): $campaignCount\n";

    // 2. Contar mensagens duplicadas no chat
    $stmt = $db->prepare("
        SELECT COUNT(*) as total
        FROM whatsapp_chat_messages wcm
        WHERE EXISTS (
            SELECT 1 
            FROM whatsapp_campaign_messages wccm 
            WHERE wccm.message_id = wcm.whatsapp_message_id
            AND wccm.campaign_id = 1
        )
    ");
    $stmt->execute();
    $duplicatesCount = $stmt->fetchColumn();

    echo "Mensagens duplicadas no chat: $duplicatesCount\n\n";

    if ($duplicatesCount == 0) {
        echo "✓ Nenhuma mensagem duplicada encontrada!\n";
        echo "A separação já está correta.\n";
        exit(0);
    }

    echo "Removendo $duplicatesCount mensagens do chat...\n";

    // 3. Deletar mensagens duplicadas
    $stmt = $db->prepare("
        DELETE wcm FROM whatsapp_chat_messages wcm
        WHERE EXISTS (
            SELECT 1 
            FROM whatsapp_campaign_messages wccm 
            WHERE wccm.message_id = wcm.whatsapp_message_id
            AND wccm.campaign_id = 1
        )
    ");

    $stmt->execute();
    $deleted = $stmt->rowCount();

    echo "\n=== RESULTADO ===\n";
    echo "✓ Removidas: $deleted mensagens\n";
    echo "✓ Chat agora contém APENAS conversas reais\n";
    echo "✓ Campanha mantém TODAS as $campaignCount mensagens\n\n";
    echo "Mensagens agora aparecem SOMENTE na aba de campanha!\n";
} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
    echo "Stack: " . $e->getTraceAsString() . "\n";
}
