<?php

namespace Apoio19\Crm\Controllers;

use Apoio19\Crm\Models\User;
use Apoio19\Crm\Services\AuthService;
use Apoio19\Crm\Services\AuditLogService;
use Apoio19\Crm\Services\RateLimitingService; // Import RateLimitingService

// Placeholder for Request/Response handling (replace with actual framework/library)
class AuthController
{
    private AuthService $authService;
    private RateLimitingService $rateLimiter; // Add RateLimiter instance

    public function __construct()
    {
        $this->authService = new AuthService();
        $this->rateLimiter = new RateLimitingService(); // Instantiate RateLimiter
    }

    /**
     * Handle user login.
     *
     * @param array $requestData Expected keys: email, senha.
     * @return array JSON response with JWT token or error.
     */
    public function login(array $requestData): array
    {
        $email = $requestData["email"] ?? null;
        $senha = $requestData["senha"] ?? null;
        $ipAddress = $_SERVER["REMOTE_ADDR"] ?? "unknown"; // Get user IP

        // --- Rate Limiting Check ---
        $loginAction = "login_attempt";
        $loginLimit = 5; // Max 5 attempts
        $loginPeriod = 300; // Within 5 minutes (300 seconds)

        if (!$this->rateLimiter->isAllowed($ipAddress, $loginAction, $loginLimit, $loginPeriod)) {
            http_response_code(429); // Too Many Requests
            AuditLogService::log("login_bloqueado_rate_limit", null, null, null, "Tentativa de login bloqueada por rate limit.", $ipAddress);
            return ["error" => "Muitas tentativas de login. Tente novamente mais tarde."];
        }
        // --- End Rate Limiting Check ---

        if (!$email || !$senha) {
            http_response_code(400);
            $this->rateLimiter->recordAttempt($ipAddress, $loginAction); // Record failed attempt
            AuditLogService::log("login_falha", null, null, null, "Email ou senha não fornecidos.", $ipAddress);
            return ["error" => "Email e senha são obrigatórios."];
        }

        $user = User::findByEmail($email);

        if (!$user || !$user->ativo) {
            http_response_code(401);
            $this->rateLimiter->recordAttempt($ipAddress, $loginAction); // Record failed attempt
            AuditLogService::log("login_falha", null, "usuario", null, "Usuário não encontrado ou inativo: " . $email, $ipAddress);
            return ["error" => "Credenciais inválidas ou usuário inativo."];
        }

        if (password_verify($senha, $user->senha_hash)) {
            // Password is correct, generate JWT
            // Successful login - Rate limit attempt count for this action could be reset here if desired, but typically not needed.
            $token = $this->authService->generateToken($user->id, $user->email, $user->funcao, $user->nome);
            
            if ($token) {
                http_response_code(200);
                AuditLogService::log("login_sucesso", $user->id, "usuario", $user->id, "Login bem-sucedido.", $ipAddress);
                return ["token" => $token, "user" => ["id" => $user->id, "nome" => $user->nome, "email" => $user->email, "funcao" => $user->funcao]];
            } else {
                http_response_code(500);
                 AuditLogService::log("login_erro_jwt", $user->id, "usuario", $user->id, "Falha ao gerar token JWT.", $ipAddress);
                return ["error" => "Erro ao gerar token de autenticação."];
            }
        } else {
            http_response_code(401);
            $this->rateLimiter->recordAttempt($ipAddress, $loginAction); // Record failed attempt
            AuditLogService::log("login_falha", $user->id, "usuario", $user->id, "Senha incorreta.", $ipAddress);
            return ["error" => "Credenciais inválidas."];
        }
    }

    /**
     * Handle user registration (example - adjust permissions as needed).
     *
     * @param array $requestData Expected keys: nome, email, senha, funcao.
     * @return array JSON response.
     */
    public function register(array $requestData): array
    {
        // !! IMPORTANT: Restrict who can register users (e.g., only Admins)
        // This endpoint should likely require authentication and authorization
        
        // --- Rate Limiting Check (Optional for registration) ---
        $ipAddress = $_SERVER["REMOTE_ADDR"] ?? "unknown";
        $registerAction = "register_attempt";
        $registerLimit = 10; // Example: Max 10 registrations per hour from one IP
        $registerPeriod = 3600;
        if (!$this->rateLimiter->checkAndRecord($ipAddress, $registerAction, $registerLimit, $registerPeriod)) {
             http_response_code(429);
             return ["error" => "Limite de taxa de registro excedido. Tente novamente mais tarde."];
        }
        // --- End Rate Limiting Check ---
        
        $nome = $requestData["nome"] ?? null;
        $email = $requestData["email"] ?? null;
        $senha = $requestData["senha"] ?? null;
        $funcao = $requestData["funcao"] ?? "Comercial"; // Default role

        if (!$nome || !$email || !$senha) {
            http_response_code(400);
            AuditLogService::log("registro_falha", null, null, null, "Dados de registro incompletos.", $ipAddress);
            return ["error" => "Nome, email e senha são obrigatórios."];
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
             http_response_code(400);
             AuditLogService::log("registro_falha", null, null, null, "Formato de email inválido: " . $email, $ipAddress);
            return ["error" => "Formato de email inválido."];
        }

        // Check if email already exists
        if (User::findByEmail($email)) {
            http_response_code(409); // Conflict
            AuditLogService::log("registro_falha", null, null, null, "Email já cadastrado: " . $email, $ipAddress);
            return ["error" => "Este email já está cadastrado."];
        }

        // Hash the password
        $senha_hash = password_hash($senha, PASSWORD_DEFAULT);

        $userId = User::create($nome, $email, $senha_hash, $funcao);

        if ($userId) {
            http_response_code(201);
            AuditLogService::log("usuario_criado", $userId, "usuario", $userId, "Novo usuário registrado: " . $email, $ipAddress);
            // Optionally log the user in immediately by generating a token
            return ["message" => "Usuário registrado com sucesso.", "user_id" => $userId];
        } else {
            http_response_code(500);
            AuditLogService::log("registro_erro", null, null, null, "Falha ao inserir usuário no banco de dados: " . $email, $ipAddress);
            return ["error" => "Falha ao registrar usuário."];
        }
    }

    // Add methods for password reset, user profile management, etc., 
    // ensuring AuditLogService::log() and rate limiting are applied where appropriate.
}

