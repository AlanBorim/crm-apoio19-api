<?php

namespace Apoio19\Crm\Controllers;

class BaseController
{
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
}
