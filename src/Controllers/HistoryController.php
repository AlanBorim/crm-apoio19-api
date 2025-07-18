<?php

namespace Apoio19\Crm\Controllers;

use Apoio19\Crm\Models\HistoricoInteracoes;
use Apoio19\Crm\Middleware\AuthMiddleware;

class HistoryController
{

    private AuthMiddleware $authMiddleware;
    

    public function __construct()
    {
        $this->authMiddleware = new AuthMiddleware();
        
    }
    public function logAction(array $headers, array $data): array
    {
        
        $userData = $this->authMiddleware->handle($headers);

        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária.");
        }
        
        // Validar os dados de entrada
        if (empty($data['lead_id']) || empty($data['usuario_id']) || empty($data['acao'])) {
            return $this->errorResponse(401, "Dados insuficientes para registrar a ação."); 
        }

        // Criar uma nova instância do modelo HistoricoInteracoes
        $historico = new HistoricoInteracoes();

        // Registrar a ação no histórico
        $historico->logAction(
            $data['lead_id'],
            $data['contato_id'] ?? null,
            $data['usuario_id'],
            $data['acao'],
            $data['observacao']
        );
        
        return $this->successResponse([], "Histórico de interações registrado com sucesso.");
    }

    public function getHistory(array $headers, int $leadId): array
    {
        $userData = $this->authMiddleware->handle($headers);

        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária.");
        }

        // Validar o ID do lead
        if (empty($leadId)) {
            return $this->errorResponse(400, "ID do lead inválido.");
        }

        // Buscar histórico de interações
        $historico = new HistoricoInteracoes();
        
        $historicoResponse = $historico->getHistoryByLeadId($leadId);

        if ($historicoResponse === false) {
            return $this->errorResponse(500, "Erro ao buscar histórico de interações.");
        }

        return $this->successResponse($historicoResponse, "Histórico de interações recuperado com sucesso.");
    }

    /**
     * Resposta de sucesso padronizada
     */
    private function successResponse($data = null, string $message = "Operação realizada com sucesso.", int $code = 200): array
    {
        http_response_code($code);
        $response = ["success" => true, "message" => $message];

        if ($data !== null) {
            $response["data"] = $data;
        }

        return $response;
    }
    
    /**
     * Resposta de erro padronizada
     */
    private function errorResponse(int $code, string $message, string $details = null): array
    {
        http_response_code($code);
        $response = ["success" => false, "error" => $message];

        if ($details !== null) {
            $response["details"] = $details;
        }

        return $response;
    }
}
