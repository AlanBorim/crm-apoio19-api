<?php

namespace Apoio19\Crm\Controllers;

use Apoio19\Crm\Models\SystemToken;
use Apoio19\Crm\Middleware\AuthMiddleware;

class SystemTokenController extends BaseController
{
    private AuthMiddleware $authMiddleware;

    public function __construct()
    {
        parent::__construct();
        $this->authMiddleware = new AuthMiddleware();
    }

    /**
     * GET /settings/system-tokens
     * Listar todos os tokens de sistema
     *
     * @param array $headers Cabeçalhos da requisição
     * @return array
     */
    public function index(array $headers): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);

        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHENTICATED", $traceId);
        }

        // Apenas Admin e Gerente podem gerenciar configurações de tokens
        $this->requirePermission($userData, 'configuracoes', 'view');

        try {
            $tokens = SystemToken::findAll();

            // Decodificar permissões JSON para facilitar uso no frontend
            foreach ($tokens as &$token) {
                if (isset($token['permissions'])) {
                    $token['permissions'] = json_decode($token['permissions'], true);
                }
            }

            return $this->successResponse($tokens, "Tokens de sistema recuperados com sucesso.", 200, $traceId);
        } catch (\Exception $e) {
            return $this->errorResponse(500, "Erro ao buscar tokens de sistema.", "INTERNAL_ERROR", $traceId, $this->debugDetails($e));
        }
    }

    /**
     * POST /settings/system-tokens
     * Criar um novo token de sistema
     *
     * @param array $headers Cabeçalhos da requisição
     * @param array $requestData Dados de criação
     * @return array
     */
    public function store(array $headers, array $requestData): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);

        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHENTICATED", $traceId);
        }

        $this->requirePermission($userData, 'configuracoes', 'create');

        // Validação básica
        if (empty($requestData['name'])) {
            return $this->errorResponse(400, "O nome da credencial é obrigatório.", "VALIDATION_ERROR", $traceId);
        }
        if (empty($requestData['user_id'])) {
            return $this->errorResponse(400, "O usuário de atribuição é obrigatório.", "VALIDATION_ERROR", $traceId);
        }
        if (empty($requestData['permissions'])) {
            return $this->errorResponse(400, "Os escopos de acesso (permissões) são obrigatórios.", "VALIDATION_ERROR", $traceId);
        }

        try {
            $token = SystemToken::create([
                'name' => $requestData['name'],
                'user_id' => (int)$requestData['user_id'],
                'permissions' => $requestData['permissions'],
                'active' => isset($requestData['active']) ? (int)$requestData['active'] : 1
            ]);

            if ($token) {
                if (isset($token['permissions'])) {
                    $token['permissions'] = json_decode($token['permissions'], true);
                }

                // Registrar logs de auditoria
                $this->logAudit($userData->id, 'create', 'system_tokens', $token['id'], null, $token);

                return $this->successResponse($token, "Token de sistema criado com sucesso.", 201, $traceId);
            } else {
                return $this->errorResponse(500, "Falha ao criar o token de sistema.", "CREATE_FAILED", $traceId);
            }
        } catch (\Exception $e) {
            return $this->errorResponse(500, "Erro interno ao criar token de sistema.", "INTERNAL_ERROR", $traceId, $this->debugDetails($e));
        }
    }

    /**
     * PUT /settings/system-tokens/{id}
     * Atualizar status ou permissões do token
     *
     * @param array $headers Cabeçalhos da requisição
     * @param int $id
     * @param array $requestData Dados de atualização
     * @return array
     */
    public function update(array $headers, int $id, array $requestData): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);

        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHENTICATED", $traceId);
        }

        $this->requirePermission($userData, 'configuracoes', 'edit');

        try {
            $existing = SystemToken::findById($id);
            if (!$existing) {
                return $this->errorResponse(404, "Credencial não encontrada.", "NOT_FOUND", $traceId);
            }

            $success = SystemToken::update($id, $requestData);

            if ($success) {
                $updated = SystemToken::findById($id);
                if (isset($updated['permissions'])) {
                    $updated['permissions'] = json_decode($updated['permissions'], true);
                }
                if (isset($existing['permissions'])) {
                    $existing['permissions'] = json_decode($existing['permissions'], true);
                }

                // Log audit
                $this->logAudit($userData->id, 'update', 'system_tokens', $id, $existing, $updated);

                return $this->successResponse($updated, "Configurações da credencial atualizadas com sucesso.", 200, $traceId);
            } else {
                return $this->errorResponse(500, "Falha ao atualizar a credencial.", "UPDATE_FAILED", $traceId);
            }
        } catch (\Exception $e) {
            return $this->errorResponse(500, "Erro interno ao atualizar credencial.", "INTERNAL_ERROR", $traceId, $this->debugDetails($e));
        }
    }

    /**
     * DELETE /settings/system-tokens/{id}
     * Revogar/excluir um token de sistema
     *
     * @param array $headers Cabeçalhos da requisição
     * @param int $id
     * @return array
     */
    public function destroy(array $headers, int $id): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);

        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHENTICATED", $traceId);
        }

        $this->requirePermission($userData, 'configuracoes', 'delete');

        try {
            $existing = SystemToken::findById($id);
            if (!$existing) {
                return $this->errorResponse(404, "Credencial não encontrada.", "NOT_FOUND", $traceId);
            }

            $success = SystemToken::delete($id);

            if ($success) {
                if (isset($existing['permissions'])) {
                    $existing['permissions'] = json_decode($existing['permissions'], true);
                }

                // Log audit
                $this->logAudit($userData->id, 'delete', 'system_tokens', $id, $existing, null);

                return $this->successResponse(null, "Credencial de sistema revogada com sucesso.", 200, $traceId);
            } else {
                return $this->errorResponse(500, "Falha ao revogar a credencial.", "DELETE_FAILED", $traceId);
            }
        } catch (\Exception $e) {
            return $this->errorResponse(500, "Erro interno ao revogar credencial.", "INTERNAL_ERROR", $traceId, $this->debugDetails($e));
        }
    }
}
