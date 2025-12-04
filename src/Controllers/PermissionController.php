<?php

namespace Apoio19\Crm\Controllers;

use Apoio19\Crm\Models\User;
use Apoio19\Crm\Middleware\AuthMiddleware;

/**
 * Controller for managing user permissions
 */
class PermissionController extends BaseController
{
    private AuthMiddleware $authMiddleware;

    public function __construct()
    {
        parent::__construct();
        $this->authMiddleware = new AuthMiddleware();
    }

    /**
     * Get permissions for a specific user
     * 
     * @param array $headers Request headers
     * @param int $userId User ID
     * @return array JSON response
     */
    public function getUserPermissions(array $headers, int $userId): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);

        if (!$userData) {
            return $this->unauthorized("Autenticação necessária.", $traceId);
        }

        // Only admin or the user themselves can view permissions
        if (!$this->isAdmin($userData) && $userData->userId !== $userId) {
            return $this->forbidden("Você não tem permissão para visualizar permissões de outros usuários.", $traceId);
        }

        try {
            $user = User::findById($userId);

            if (!$user) {
                return $this->errorResponse(404, "Usuário não encontrado.", "NOT_FOUND", $traceId);
            }

            $permissions = $this->permissionService->getUserPermissions($user);

            return $this->successResponse([
                'user_id' => $userId,
                'role' => $user->funcao,
                'permissions' => $permissions
            ], null, 200, $traceId);
        } catch (\Exception $e) {
            return $this->errorResponse(500, "Erro ao buscar permissões.", "SERVER_ERROR", $traceId, $this->debugDetails($e));
        }
    }

    /**
     * Update permissions for a specific user (Admin only)
     * 
     * @param array $headers Request headers
     * @param int $userId User ID
     * @param array $requestData New permissions data
     * @return array JSON response
     */
    public function updateUserPermissions(array $headers, int $userId, array $requestData): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers, ["admin"]);

        if (!$userData) {
            return $this->unauthorized("Autenticação necessária.", $traceId);
        }

        if (!$this->isAdmin($userData)) {
            return $this->forbidden("Apenas administradores podem modificar permissões.", $traceId);
        }

        try {
            $user = User::findById($userId);

            if (!$user) {
                return $this->errorResponse(404, "Usuário não encontrado.", "NOT_FOUND", $traceId);
            }

            // Validate permissions structure
            if (!isset($requestData['permissions']) || !is_array($requestData['permissions'])) {
                return $this->errorResponse(400, "Estrutura de permissões inválida.", "INVALID_PERMISSIONS", $traceId);
            }

            $permissionsJson = json_encode($requestData['permissions']);

            $updated = User::update($userId, ['permissions' => $permissionsJson]);

            if ($updated) {
                return $this->successResponse([
                    'user_id' => $userId,
                    'permissions' => $requestData['permissions']
                ], "Permissões atualizadas com sucesso.", 200, $traceId);
            } else {
                return $this->errorResponse(500, "Falha ao atualizar permissões.", "UPDATE_FAILED", $traceId);
            }
        } catch (\Exception $e) {
            return $this->errorResponse(500, "Erro ao atualizar permissões.", "SERVER_ERROR", $traceId, $this->debugDetails($e));
        }
    }

    /**
     * Reset permissions to role defaults (Admin only)
     * 
     * @param array $headers Request headers
     * @param int $userId User ID
     * @return array JSON response
     */
    public function resetToDefaults(array $headers, int $userId): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers, ["admin"]);

        if (!$userData) {
            return $this->unauthorized("Autenticação necessária.", $traceId);
        }

        if (!$this->isAdmin($userData)) {
            return $this->forbidden("Apenas administradores podem redefinir permissões.", $traceId);
        }

        try {
            $user = User::findById($userId);

            if (!$user) {
                return $this->errorResponse(404, "Usuário não encontrado.", "NOT_FOUND", $traceId);
            }

            $defaultPermissions = $this->permissionService->setDefaultPermissions($user->funcao);

            $updated = User::update($userId, ['permissions' => $defaultPermissions]);

            if ($updated) {
                return $this->successResponse([
                    'user_id' => $userId,
                    'role' => $user->funcao,
                    'permissions' => json_decode($defaultPermissions, true)
                ], "Permissões redefinidas para padrão da função.", 200, $traceId);
            } else {
                return $this->errorResponse(500, "Falha ao redefinir permissões.", "RESET_FAILED", $traceId);
            }
        } catch (\Exception $e) {
            return $this->errorResponse(500, "Erro ao redefinir permissões.", "SERVER_ERROR", $traceId, $this->debugDetails($e));
        }
    }

    /**
     * Get available permission templates for each role
     * 
     * @param array $headers Request headers
     * @return array JSON response
     */
    public function getPermissionTemplates(array $headers): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);

        if (!$userData) {
            return $this->unauthorized("Autenticação necessária.", $traceId);
        }

        // Only admin can view templates
        if (!$this->isAdmin($userData)) {
            return $this->forbidden("Apenas administradores podem visualizar templates de permissões.", $traceId);
        }

        try {
            $roles = ['admin', 'gerente', 'vendedor', 'comercial', 'suporte', 'financeiro'];
            $templates = [];

            foreach ($roles as $role) {
                $templates[$role] = $this->permissionService->getDefaultPermissions($role);
            }

            return $this->successResponse($templates, null, 200, $traceId);
        } catch (\Exception $e) {
            return $this->errorResponse(500, "Erro ao buscar templates.", "SERVER_ERROR", $traceId, $this->debugDetails($e));
        }
    }
}
