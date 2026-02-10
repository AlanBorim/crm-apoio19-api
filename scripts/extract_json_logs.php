<?php
// Extrator de JSON dos logs .txt

$mappings = [
    'iniciais-respostas_whatsapp_2026-01-21_16-45-36.txt' => 'iniciais.json',
    'reenvios-respostas_whatsapp_2026-01-21_19-06-26.txt' => 'reenvios.json',
    'respostas_whatsapp_2026-01-21_21-59-20.txt' => 'respostas.json'
];

$dir = __DIR__ . '/../api/hook/';

foreach ($mappings as $txtFile => $jsonFile) {
    echo "Processando $txtFile -> $jsonFile ...\n";
    $txtPath = $dir . $txtFile;
    if (!file_exists($txtPath)) {
        echo " [ERRO] Arquivo não encontrado: $txtFile\n";
        continue;
    }

    $content = file_get_contents($txtPath);
    $blocks = explode('----------------------------------------', $content);
    $extracted = [];

    foreach ($blocks as $block) {
        if (preg_match('/Resposta completa:\s*({.*})/s', $block, $matches)) {
            $jsonStr = trim($matches[1]);
            $json = json_decode($jsonStr, true);
            if ($json) {
                // Adicionar timestamp se disponivel no bloco para contexto futuro
                if (preg_match('/Horário:\s*([\d:]+)/', $block, $tm)) {
                    $json['_extracted_time'] = $tm[1];
                }
                $extracted[] = $json;
            }
        }
    }

    file_put_contents($dir . $jsonFile, json_encode($extracted, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo " [OK] " . count($extracted) . " itens extraídos.\n";
}

echo "Extração concluída.\n";
