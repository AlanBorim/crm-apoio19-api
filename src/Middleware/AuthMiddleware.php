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

    /**
     * Handles incoming requests, validates JWT token.
     *
     * @param array $headers Request headers (e.g., ["Authorization" => "Bearer xyz..."])
     * @param array $allowedRoles Roles allowed for the requested resource.
     * @return object|null Decoded user data if authentication and authorization succeed, null otherwise.
     */
    public function handle(array $headers, array $allowedRoles = []): ?object
    {

        $token = $headers["Authorization"] ?? $headers["authorization"] ?? null;

        if (!$token) {
            // No Authorization header provided
            http_response_code(401);
            echo json_encode(["erro" => "Cabeçalho de autorização ausente"]);
            exit;
        }

        if (!$headers) {
            http_response_code(401);
            echo json_encode(["erro" => "Cabeçalho de autorização ausente"]);
            exit;
        }

        if (!preg_match('/Bearer\s(\S+)/', $token, $matches)) {
            http_response_code(401);
            echo json_encode(["erro" => "Formato do token inválido"]);
            exit;
        }

        $token = $matches[1]; // apenas o token JWT sem o "Bearer"

        if (!$token) {
            // No token provided
            error_log("Middleware: Token não fornecido.");
            // In a real app, send a 401 Unauthorized response here
            return null;
        }

        $decodedPayload = $this->authService->validateToken($token);

        if (!$decodedPayload) {
            // Invalid or expired token
            error_log("Middleware: Token inválido ou expirado.");
            // In a real app, send a 401 Unauthorized response here
            return null;
        }

        // Check if user exists and is active in DB (Single Source of Truth)
        $user = User::findById($decodedPayload->userId);

        if (!$user) {
            error_log("Middleware: Usuário não encontrado no banco de dados. ID: " . $decodedPayload->userId);
            return null;
        }

        if (!$user->active) {
            error_log("Middleware: Usuário inativo. ID: " . $decodedPayload->userId);
            return null;
        }

        // Return the full User object (including permissions from DB)
        // We ignore $allowedRoles here because we want Controllers to use requirePermission()
        // based on the user's specific permissions, not just their role.
        return $user;
    }
}
