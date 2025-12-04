<?php

namespace Apoio19\Crm\Controllers;

use Apoio19\Crm\Services\PermissionService;

class BaseController
{
    protected PermissionService $permissionService;

    public function __construct()
    {
        $this->permissionService = new PermissionService();
    }
    /**
     * Mapeia SQLSTATE/erros de PDO para uma resposta mais amigável.
     */
    protected function mapPdoError(\PDOException $e): array
    {
        $sqlState = $e->getCode() ?: '';
        // Alguns padrões comuns:
        // 23000 -> Violação de integridade (chave duplicada, FK, NOT NULL, etc.)
        // 08xxx -> Erros de conexão
        // 40001 -> Deadlock/serialization failure (depende do SGBD)
        if ($sqlState === '23000') {
            return [
                'status' => 409,
                'code' => 'DB_CONSTRAINT_VIOLATION',
                'message' => 'Falha de integridade no banco de dados (possível chave duplicada ou referência inválida).'
            ];
        }

        if (str_starts_with($sqlState, '08')) {
            return [
                'status' => 503,
                'code' => 'DB_CONNECTION_ERROR',
                'message' => 'Falha de conexão com o banco de dados. Tente novamente mais tarde.'
            ];
        }

        if ($sqlState === '40001') {
            return [
                'status' => 409,
                'code' => 'DB_DEADLOCK',
                'message' => 'Conflito de concorrência no banco de dados. Tente novamente.'
            ];
        }

        return [
            'status' => 500,
            'code' => 'DB_ERROR',
            'message' => 'Erro de banco de dados.'
        ];
    }

    /**
     * Exibe detalhes técnicos apenas se APP_DEBUG estiver habilitado.
     */
    protected function debugDetails(\Throwable $e): ?array
    {
        if (!$this->isDebugEnabled()) {
            return null;
        }

        return [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'sqlstate' => ($e instanceof \PDOException) ? $e->getCode() : null,
            'errorInfo' => ($e instanceof \PDOException && isset($e->errorInfo)) ? $e->errorInfo : null,
            'trace' => $e->getTrace(),
        ];
    }

    protected function isDebugEnabled(): bool
    {
        // Ajuste conforme seu ambiente (ex.: variável de ambiente, config, etc.)
        $debug = getenv('APP_DEBUG');
        return filter_var($debug, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Retorna uma resposta de sucesso padronizada.
     */
    protected function successResponse($data = null, ?string $message = null, int $statusCode = 200, ?string $traceId = null): array
    {
        http_response_code($statusCode);
        return [
            "success" => true,
            "message" => $message,
            "data" => $data,
            "trace_id" => $traceId ?? bin2hex(random_bytes(8))
        ];
    }

    /**
     * Retorna uma resposta de erro padronizada.
     */
    protected function errorResponse(int $statusCode, string $message, ?string $code = null, ?string $traceId = null, $details = null): array
    {
        http_response_code($statusCode);
        return [
            "success" => false,
            "error" => $message,
            "code" => $code ?? 'ERROR',
            "trace_id" => $traceId ?? bin2hex(random_bytes(8)),
            "details" => $details
        ];
    }

    /**
     * Check if user has permission for an action on a resource
     */
    protected function can(object $user, string $resource, string $action, ?int $resourceOwnerId = null): bool
    {
        return $this->permissionService->can($user, $resource, $action, $resourceOwnerId);
    }

    /**
     * Check if user is admin
     */
    protected function isAdmin(object $user): bool
    {
        return $this->permissionService->isAdmin($user);
    }

    /**
     * Return forbidden response (403)
     */
    protected function forbidden(string $message = 'Você não tem permissão para executar esta ação.', ?string $traceId = null): array
    {
        return $this->errorResponse(403, $message, 'FORBIDDEN', $traceId);
    }

    /**
     * Require permission or send forbidden response and exit
     * 
     * @param object $user User object with permissions
     * @param string $resource Resource name (e.g., 'usuarios', 'leads')
     * @param string $action Action name (e.g., 'view', 'create', 'edit', 'delete')
     * @param int|null $resourceOwnerId Optional resource owner ID for 'own' permissions
     * @return void Exits with 403 if permission denied
     */
    protected function requirePermission(object $user, string $resource, string $action, ?int $resourceOwnerId = null): void
    {
        if (!$this->can($user, $resource, $action, $resourceOwnerId)) {
            http_response_code(403);
            echo json_encode($this->forbidden('Usuário sem permissão para executar essa ação'));
            exit;
        }
    }

    /**
     * Return unauthorized response (401)
     */
    protected function unauthorized(string $message = 'Não autorizado.', ?string $traceId = null): array
    {
        return $this->errorResponse(401, $message, 'UNAUTHORIZED', $traceId);
    }
}
