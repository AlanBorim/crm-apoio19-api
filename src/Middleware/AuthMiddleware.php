<?php

namespace Apoio19\Crm\Middleware;

use Apoio19\Crm\Services\AuthService;
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

        // Check role permissions if required
        if (!empty($allowedRoles)) {
            $userRole = $decodedPayload->data->role ?? null;
            if (!$userRole || !in_array($userRole, $allowedRoles)) {
                // User does not have the required role
                error_log("Middleware: Acesso negado. Role necessária: " . implode(", ", $allowedRoles) . ", Role do usuário: " . ($userRole ?? 'Nenhuma'));
                // In a real app, send a 403 Forbidden response here
                return null;
            }
        }

        // Authentication and Authorization successful
        // Return the decoded user data (or just the data part)
        return $decodedPayload->data; 
    }
}

