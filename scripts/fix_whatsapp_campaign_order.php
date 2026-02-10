<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Models/Database.php';

use Apoio19\Crm\Models\Database;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

echo "=== CORRECAO DE ORDEM CAMPANHA WHATSAPP ===\n";

try {
    $db = Database::getInstance();

    // Buscar todas as mensagens ordenadas por contato e data de envio
    $stmt = $db->query("
        SELECT id, contact_id, template_id, sent_at 
        FROM whatsapp_campaign_messages 
        ORDER BY contact_id, sent_at ASC
    ");

    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($messages)) {
        die("Nenhuma mensagem encontrada.\n");
    }

    $grouped = [];
    foreach ($messages as $msg) {
        $grouped[$msg['contact_id']][] = $msg;
    }

    echo "Verificando " . count($grouped) . " contatos...\n";

    $updates = 0;

    // Preparar statement de update
    $updateStmt = $db->prepare("UPDATE whatsapp_campaign_messages SET template_id = ? WHERE id = ?");

    $db->beginTransaction();

    foreach ($grouped as $contactId => $msgs) {
        $count = count($msgs);

        // Ignorar se tiver menos de 2 mensagens (não dá para definir ordem 1->2 claramente se só tem 1)
        if ($count < 2) {
            continue;
        }

        $first = $msgs[0];
        $last = $msgs[$count - 1];

        // Corrigir primeira mensagem para template 1
        if ($first['template_id'] != 1) {
            $updateStmt->execute([1, $first['id']]);
            echo "FIX: Contact $contactId - Msg ID {$first['id']} (First) -> Template 1\n";
            $updates++;
        }

        // Corrigir ultima mensagem para template 2
        if ($last['template_id'] != 2) {
            $updateStmt->execute([2, $last['id']]);
            echo "FIX: Contact $contactId - Msg ID {$last['id']} (Last) -> Template 2\n";
            $updates++;
        }
    }

    $db->commit();

    echo "\n=== CONCLUIDO ===\n";
    echo "Total de correcoes aplicadas: $updates\n";
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo "ERRO CRITICO: " . $e->getMessage() . "\n";
}
