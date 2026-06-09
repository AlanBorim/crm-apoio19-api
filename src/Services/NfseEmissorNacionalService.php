<?php

namespace Apoio19\Crm\Services;

use Exception;

class NfseEmissorNacionalService
{
    private string $cnpj;
    private string $senha;

    public function __construct(string $cnpj = '35008980000153', string $senha = '050714Af@2025')
    {
        $this->cnpj = preg_replace('/[^0-9]/', '', $cnpj); // Remove formatação
        $this->senha = $senha;
    }

    public function emitirNfse(array $nfeData): array
    {
        $payload = [
            'cnpj_prestador' => $this->cnpj,
            'senha_prestador' => $this->senha,
            'data_competencia' => date('d/m/Y', strtotime($nfeData['data_emissao'] ?? 'now')),
            'cnpj_tomador' => preg_replace('/[^0-9]/', '', $nfeData['tomador']['documento'] ?? ''),
            'codigo_municipio_prestacao' => '3539301', // Pirassununga fixo conforme regra do cliente
            'nome_municipio_prestacao' => 'Pirassununga',
            'codigo_ctn' => '01.01.01', // CTN fixo conforme regra do cliente
            'descricao_servico' => $nfeData['servico']['descricao'] ?? 'Serviços prestados',
            'valor_servico' => floatval($nfeData['servico']['valor'] ?? 0),
            'simples_nacional' => true // Pode ser obtido de config no futuro
        ];

        error_log("Executando script Python via CLI para emissão no Emissor Nacional Direto...");

        $pythonScriptPath = __DIR__ . '/../../emissor_nacional_py/emitir.py';
        $pythonExecutable = 'python'; // Ou caminho absoluto se necessário
        
        $jsonPayload = json_encode($payload);
        $escapedPayload = escapeshellarg($jsonPayload);
        
        $command = escapeshellcmd($pythonExecutable) . " " . escapeshellarg($pythonScriptPath) . " " . $escapedPayload . " 2>&1";
        
        $output = shell_exec($command);
        
        // Procurar o JSON na resposta
        $jsonStart = strpos($output, '{');
        $jsonEnd = strrpos($output, '}');
        
        if ($jsonStart !== false && $jsonEnd !== false) {
            $jsonString = substr($output, $jsonStart, $jsonEnd - $jsonStart + 1);
            $decodedResponse = json_decode($jsonString, true) ?? [];
        } else {
            throw new Exception("Erro ao executar automação Python. Saída: " . $output);
        }

        if (isset($decodedResponse['status']) && $decodedResponse['status'] === 'erro') {
            $msg = $decodedResponse['detail'] ?? 'Erro desconhecido no script de automação';
            throw new Exception("Emissor Nacional Direto Error: " . $msg);
        }

        return [
            'id' => 'RASCUNHO_' . time(),
            'status' => 'processing',
            'message' => $decodedResponse['mensagem'] ?? 'NFS-e gerada como rascunho com sucesso via automação.',
            'url' => null,
            'xml' => null,
            'pdf' => null
        ];
    }
}
