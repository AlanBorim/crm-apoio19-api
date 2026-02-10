<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Models/Database.php';

use Apoio19\Crm\Models\Database;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

echo "=== VALIDACAO DE CAMPANHA WHATSAPP ===\n";

try {
    $db = Database::getInstance();

    // Buscar todas as mensagens ordenadas por contato e data de envio
    $stmt = $db->query("
        SELECT contact_id, template_id, sent_at, message_id, status 
        FROM whatsapp_campaign_messages 
        ORDER BY contact_id, sent_at ASC
    ");

    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Total de mensagens encontradas: " . count($messages) . "\n";

    if (empty($messages)) {
        die("Nenhuma mensagem encontrada para validar.\n");
    }

    $grouped = [];
    foreach ($messages as $msg) {
        $grouped[$msg['contact_id']][] = $msg;
    }

    echo "Total de contatos impactados: " . count($grouped) . "\n\n";

    $errors = 0;
    $validGroup = 0;
    $incomplete = 0;

    foreach ($grouped as $contactId => $msgs) {
        $count = count($msgs);

        // Caso 1: Apenas 1 mensagem (Incompleto ou erro?)
        if ($count == 1) {
            // Se só tem 1, deveria ser a msg 1? O user disse "validar a busca e a comparação é o contact_id"
            // Se tiver só 1, vamos apenas logar como 'Incompleto' mas verificar se o template faz sentido (esperado ser o 1 se for o inicio)
            // Mas o foco é a ORDEM.
            echo "[ALERTA] Contact ID $contactId possui apenas 1 mensagem. Template ID: {$msgs[0]['template_id']}\n";
            $incomplete++;
            continue;
        }

        // Caso 2: Mais de 2 mensagens (Pode acontecer? Vamos supor que apenas os primeiros 2 importam ou todos devem seguir logica)
        // O user disse: "a mensagem enviada mais cedo é a template_id 1 a mensagem enviada mais tarde é o template_id 2"

        $first = $msgs[0];
        $last = $msgs[$count - 1];

        $hasError = false;

        // Validar primeira mensagem (Mais antiga)
        if ($first['template_id'] != 1) {
            echo "[ERRO] Contact ID $contactId: Primeira mensagem (sent_at: {$first['sent_at']}) tem Template ID {$first['template_id']} (Esperado: 1)\n";
            $hasError = true;
        }

        // Validar ultima mensagem (Mais recente)
        // Se houver apenas 2 mensagens, a ultima deve ser template 2
        // Se houver mais de 2, a logica pode ser mais complexa, mas vamos focar no requisito: "mais tarde é o template 2"
        if ($last['template_id'] != 2) {
            echo "[ERRO] Contact ID $contactId: Ultima mensagem (sent_at: {$last['sent_at']}) tem Template ID {$last['template_id']} (Esperado: 2)\n";
            $hasError = true;
        }

        if ($hasError) {
            $errors++;
        } else {
            $validGroup++;
        }
    }

    echo "\n=== RESULTADO ===\n";
    echo "Contatos Validos (Ordem Correta 1 -> 2): $validGroup\n";
    echo "Contatos com Erros de Ordem: $errors\n";
    echo "Contatos com apenas 1 mensagem: $incomplete\n";
} catch (Exception $e) {
    echo "ERRO CRITICO: " . $e->getMessage() . "\n";
}
