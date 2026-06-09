<?php

namespace Apoio19\Crm\Services;

use Exception;
use Apoio19\Crm\Models\SystemConfig;

class NfseService
{
    private string $baseUrl = 'https://api.brasilnfe.com.br/services/Fiscal';
    private ?string $apiToken;
    private int $tipoAmbiente;

    public function __construct(bool $useTestKey = false)
    {
        $envConfig = SystemConfig::get('nfe_environment');
        $environment = $envConfig['config_value'] ?? 'homologacao';
        $isTest = $useTestKey || $environment === 'homologacao';

        $this->apiToken = $isTest 
            ? ($_ENV['NFE_TEST_API_KEY'] ?? getenv('NFE_TEST_API_KEY') ?: null)
            : ($_ENV['NFE_API_KEY'] ?? getenv('NFE_API_KEY') ?: null);

        // Fallback for DB if not in .env (though preference is .env per user request)
        if (!$this->apiToken) {
            $config = SystemConfig::get('nfe_api_token');
            $this->apiToken = $config['config_value'] ?? null;
        }

        $this->tipoAmbiente = $isTest ? 2 : 1;
    }

    public function setApiToken(string $token): void
    {
        $this->apiToken = $token;
    }

    private function request(string $method, string $endpoint, array $data = []): array
    {
        if (!$this->apiToken) {
            throw new Exception("Token da API de NFe não configurado.");
        }

        $url = $this->baseUrl . $endpoint;
        $ch = curl_init();

        $headers = [
            'Content-Type: application/json',
            'Token: ' . $this->apiToken
        ];

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if (!empty($data)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if (!empty($data)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("Erro de conexão com provedor NFe: $error");
        }

        $decodedResponse = json_decode($response, true) ?? [];

        // Return the full response for 20x
        if ($httpCode >= 400) {
            $msg = $decodedResponse['message'] ?? 'Erro desconhecido na API NFe';
            throw new Exception("NFe API Error ($httpCode): " . $msg . " - " . json_encode($decodedResponse));
        }

        return $decodedResponse;
    }

    /**
     * Emitir a NFS-e
     * Dados esperados devem ser mapeados para o formato que a BrasilNFE espera.
     * Estamos montando um objeto genérico baseado na estrutura comum de NFS-e (abrasf/nacional).
     */
    public function buildPayload(array $nfeData): array
    {
        return [
            'TipoAmbiente' => $this->tipoAmbiente,
            'data_emissao' => date('c'),
            'prestador' => [
                'cnpj' => $nfeData['prestador']['cnpj'] ?? '',
                'inscricao_municipal' => $nfeData['prestador']['inscricao_municipal'] ?? '',
                'codigo_municipio' => $nfeData['prestador']['codigo_municipio'] ?? ''
            ],
            'tomador' => [
                'cpf_cnpj' => preg_replace('/[^0-9]/', '', $nfeData['tomador']['documento'] ?? ''),
                'razao_social' => $nfeData['tomador']['nome'] ?? '',
                'email' => $nfeData['tomador']['email'] ?? '',
                'endereco' => [
                    'cep' => preg_replace('/[^0-9]/', '', $nfeData['tomador']['endereco']['cep'] ?? ''),
                    'logradouro' => $nfeData['tomador']['endereco']['logradouro'] ?? '',
                    'numero' => $nfeData['tomador']['endereco']['numero'] ?? 'S/N',
                    'bairro' => $nfeData['tomador']['endereco']['bairro'] ?? '',
                    'codigo_municipio' => $nfeData['tomador']['endereco']['codigo_municipio'] ?? '', // IBGE code
                    'uf' => $nfeData['tomador']['endereco']['uf'] ?? ''
                ]
            ],
            'servico' => [
                'valor_servicos' => $nfeData['servico']['valor'] ?? 0,
                'codigo_tributacao_municipio' => $nfeData['servico']['codigo_tributacao_municipio'] ?? '',
                'item_lista_servico' => $nfeData['servico']['item_lc116'] ?? '',
                'codigo_cnae' => $nfeData['servico']['cnae'] ?? '',
                'discriminacao' => $nfeData['servico']['descricao'] ?? 'Serviços prestados',
                'aliquota' => $nfeData['servico']['aliquota_iss'] ?? 0,
                'valor_iss' => ($nfeData['servico']['valor'] ?? 0) * (($nfeData['servico']['aliquota_iss'] ?? 0) / 100),
                'iss_retido' => false,
                'valor_pis' => ($nfeData['servico']['valor'] ?? 0) * (($nfeData['servico']['aliquota_pis'] ?? 0) / 100),
                'valor_cofins' => ($nfeData['servico']['valor'] ?? 0) * (($nfeData['servico']['aliquota_cofins'] ?? 0) / 100),
                'valor_inss' => ($nfeData['servico']['valor'] ?? 0) * (($nfeData['servico']['aliquota_inss'] ?? 0) / 100),
                'valor_ir' => ($nfeData['servico']['valor'] ?? 0) * (($nfeData['servico']['aliquota_ir'] ?? 0) / 100),
                'valor_csll' => ($nfeData['servico']['valor'] ?? 0) * (($nfeData['servico']['aliquota_csll'] ?? 0) / 100),
            ]
        ];
    }

    public function emitirNfse(array $nfeData): array
    {
        $emitirViaPortal = SystemConfig::get('nfe_emitir_via_portal');
        $isSimulacaoPortal = $emitirViaPortal ? filter_var($emitirViaPortal['config_value'], FILTER_VALIDATE_BOOLEAN) : false;

        if ($isSimulacaoPortal) {
            $simuladorService = new NfseEmissorNacionalService();
            return $simuladorService->emitirNfse($nfeData);
        }

        $payload = $this->buildPayload($nfeData);

        return $this->request('POST', '/EnviarNotaFiscalServico', $payload);
    }

    public function consultarStatus(string $nfeId): array
    {
        return $this->request('GET', "/{$nfeId}");
    }

    public function cancelarNfse(string $nfeId, string $motivo): array
    {
        $payload = [
            'motivo' => $motivo
        ];
        return $this->request('POST', "/{$nfeId}/cancelar", $payload);
    }
}
