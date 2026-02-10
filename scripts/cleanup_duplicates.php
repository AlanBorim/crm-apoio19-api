<?php
// Script para remover arquivos duplicados em api/hook

$baseDir = __DIR__ . '/../api/hook/';
$subDirName = 'envio-2101-13h45';
$subDirPath = $baseDir . $subDirName;

if (!is_dir($subDirPath)) {
    die("Diretório $subDirName não encontrado em $baseDir\n");
}

$filesInSubDir = glob($subDirPath . '/*');
echo "Encontrados " . count($filesInSubDir) . " arquivos no subdiretório para verificar.\n";

$deletedCount = 0;
$notFoundCount = 0;

foreach ($filesInSubDir as $filePath) {
    if (is_dir($filePath)) continue;

    $filename = basename($filePath);
    $parentFilePath = $baseDir . $filename;

    if (file_exists($parentFilePath)) {
        if (unlink($parentFilePath)) {
            $deletedCount++;
        } else {
            echo " [ERRO] Falha ao deletar: $filename\n";
        }
    } else {
        $notFoundCount++;
    }
}

echo "Limpeza concluída.\n";
echo "Arquivos deletados de api/hook: $deletedCount\n";
echo "Arquivos não encontrados no pai (já limpos ou únicos): $notFoundCount\n";
