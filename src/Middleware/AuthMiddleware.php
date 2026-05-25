<?php

namespace Apoio19\Crm\Middleware;

use Apoio19\Crm\Services\AuthService;
use Apoio19\Crm\Models\User;
// Assuming a simple request/response handling mechanism for now.
// In a real framework, this would integrate with the framework's middleware system.

class AuthMiddleware
{
    private AuthService $authService;

    public function __construct()
    {
        $this->authService = new AuthService();
    }

    private ?string $lastError = null;

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Handles incoming requests, validates JWT token.
     *
     * @param array $headers Request headers (e.g., ["Authorization" => "Bearer xyz..."])
     * @param array $allowedRoles Roles allowed for the requested resource.
     * @return object|null Decoded user data if authentication and authorization succeed, null otherwise.
     */
    public function handle(array $headers, array $allowedRoles = []): ?object
    {
        $this->lastError = null;
        $token = $headers["Authorization"] ?? $headers["authorization"] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? null;

        if (!$token) {
            // No Authorization header provided
            $this->lastError = "Cabeçalho de autorização ausente";
            error_log("Middleware: Authorization header missing.");
            return null;
        }

        if (!preg_match('/Bearer\s(\S+)/', $token, $matches)) {
            $this->lastError = "Formato do token inválido";
            error_log("Middleware: Invalid token format.");
            return null;
        }

        $token = $matches[1]; // apenas o token JWT sem o "Bearer"

        if (!$token) {
            $this->lastError = "Token vazio.";
            error_log("Middleware: Token não fornecido.");
            return null;
        }

        // Verificação se é um token de sistema de longa duração (ex: n8n)
        if (str_starts_with($token, 'crm_sys_')) {
            $systemToken = \Apoio19\Crm\Models\SystemToken::findByToken($token);
            if (!$systemToken) {
                $this->lastError = "Token de sistema inválido ou inativo.";
                error_log("Middleware: Token de sistema inválido ou inativo.");
                return null;
            }

            // Buscar usuário associado ao token
            $user = User::findById((int)$systemToken['user_id']);
            if (!$user) {
                $this->lastError = "Usuário associado ao token de sistema não encontrado.";
                error_log("Middleware: Usuário associado ao token de sistema não encontrado.");
                return null;
            }

            if (!$user->active) {
                $this->lastError = "Usuário associado ao token de sistema está inativo.";
                error_log("Middleware: Usuário associado ao token de sistema está inativo.");
                return null;
            }

            // Sobrescrever as permissões do usuário com as permissões restritas do token de sistema
            $user->permissions = $systemToken['permissions'];

            // Atualizar carimbo de data/hora do último uso do token
            \Apoio19\Crm\Models\SystemToken::updateLastUsed((int)$systemToken['id']);

            return $user;
        }

        $decodedPayload = $this->authService->validateToken($token);

        if (!$decodedPayload) {
            $this->lastError = "Token inválido ou expirado.";
            error_log("Middleware: Token inválido ou expirado.");
            return null;
        }

        // Check if user exists and is active in DB (Single Source of Truth)
        $user = User::findById($decodedPayload->userId);

        if (!$user) {
            $this->lastError = "Usuário não encontrado (ID: " . $decodedPayload->userId . ")";
            error_log("Middleware: Usuário não encontrado no banco de dados. ID: " . $decodedPayload->userId);
            return null;
        }

        if (!$user->active) {
            $this->lastError = "Usuário inativo (ID: " . $decodedPayload->userId . ")";
            error_log("Middleware: Usuário inativo. ID: " . $decodedPayload->userId);
            return null;
        }

        return $user;
    }
}
