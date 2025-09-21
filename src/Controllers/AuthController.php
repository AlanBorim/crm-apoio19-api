<?php
namespace Apoio19\Crm\Controllers;

use Apoio19\Crm\Models\User;
use Apoio19\Crm\Services\AuthService;
use Apoio19\Crm\Services\AuditLogService;
use Apoio19\Crm\Services\RateLimitingService;

class AuthController
{
    private AuthService $authService;
    private RateLimitingService $rateLimiter;

    public function __construct()
    {
        $this->authService = new AuthService();
        $this->rateLimiter = new RateLimitingService();
    }

    public function login(array $requestData): array
    {
        $email = $requestData["email"] ?? null;
        $senha = $requestData["senha"] ?? null;
        $ipAddress = $_SERVER["REMOTE_ADDR"] ?? "unknown";

        $loginAction = "login_attempt";
        $loginLimit = 5;
        $loginPeriod = 300;

        if (!$this->rateLimiter->isAllowed($ipAddress, $loginAction, $loginLimit, $loginPeriod)) {
            http_response_code(429);
            AuditLogService::log(
                null,
                "login_bloqueado",
                null,
                null,
                null,
                ['error' => 'Muitas tentativas de login. Tente novamente mais tarde.'],
                $ipAddress,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            );
            
            return ["error" => "Muitas tentativas de login. Tente novamente mais tarde."];
        }

        if (!$email || !$senha) {
            http_response_code(400);
            $this->rateLimiter->recordAttempt($ipAddress, $loginAction);
            AuditLogService::log(
                null,
                "login_falha",
                null,
                null,
                null,
                ['error' => 'Email e senha são obrigatórios.'],
                $ipAddress,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            );
            return ["error" => "Email e senha são obrigatórios."];
        }

        $user = User::findByEmail($email);

        if (!$user || !$user->ativo) {
            http_response_code(401);
            $this->rateLimiter->recordAttempt($ipAddress, $loginAction);
            AuditLogService::log(
                null,
                "login_falha",
                "users",
                null,
                null,
                ['error' => "Usuário não encontrado ou inativo: " . $email],
                $ipAddress,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            );
            return ["error" => "Credenciais inválidas ou usuário inativo."];
        }

        if (password_verify($senha, $user->senha_hash)) {
            $token = $this->authService->generateToken($user->id, $user->email, $user->funcao, $user->nome);
            $refreshToken = $this->authService->generateRefreshToken($user->id, $user->email);

            if ($token) {
                setcookie('refresh_token', $refreshToken, [
                    'expires' => time() + (60 * 60 * 24 * 7),
                    'httponly' => true,
                    'secure' => true,
                    'samesite' => 'Strict',
                    'path' => '/refresh'
                ]);

                http_response_code(200);
                AuditLogService::log(
                    $user->id,
                    "login_sucesso",
                    "users",
                    $user->id, 
                    null,
                    ['message' => 'Login bem-sucedido','user_id' => $user->id,'email' => $user->email],
                    $ipAddress,
                    $_SERVER['HTTP_USER_AGENT'] ?? null
                );
                return [
                    "token" => $token,
                    "user" => [
                        "id" => $user->id,
                        "nome" => $user->nome,
                        "email" => $user->email,
                        "role" => $user->funcao
                    ]
                ];
            } else {
                http_response_code(500);
                AuditLogService::log(
                    $user->id,
                    "login_erro",
                    "users",
                    $user->id,
                    null,
                    ['error' => 'Erro ao gerar token de autenticação.'],
                    $ipAddress,
                    $_SERVER['HTTP_USER_AGENT'] ?? null
                );
                return ["error" => "Erro ao gerar token de autenticação."];
            }
        } else {
            http_response_code(401);
            $this->rateLimiter->recordAttempt($ipAddress, $loginAction);
            AuditLogService::log(
                $user->id,
                "login_falha",
                "users",
                $user->id,
                null,
                ['error' => 'Senha incorreta.'],
                $ipAddress,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            );
            return ["error" => "Credenciais inválidas."];
        }
    }

    public function register(array $requestData): array
    {
        $ipAddress = $_SERVER["REMOTE_ADDR"] ?? "unknown";
        $registerAction = "register_attempt";
        $registerLimit = 10;
        $registerPeriod = 3600;

        if (!$this->rateLimiter->checkAndRecord($ipAddress, $registerAction, $registerLimit, $registerPeriod)) {
            http_response_code(429);
            return ["error" => "Limite de taxa de registro excedido. Tente novamente mais tarde."];
        }

        $nome = $requestData["nome"] ?? null;
        $email = $requestData["email"] ?? null;
        $senha = $requestData["senha"] ?? null;
        $funcao = $requestData["funcao"] ?? "Comercial";

        if (!$nome || !$email || !$senha) {
            http_response_code(400);
            AuditLogService::log(
                null,
                "registro_falha", 
                "users", 
                null, 
                null, 
                ['error' => "Dados de registro incompletos."], 
                $ipAddress,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            );
            return ["error" => "Nome, email e senha são obrigatórios."];
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            AuditLogService::log(
                null,
                "registro_falha",
                "users",
                null,
                null,
                ['error' => "Formato de email inválido: " . $email],
                $ipAddress,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            );
            return ["error" => "Formato de email inválido."];
        }

        if (User::findByEmail($email)) {
            http_response_code(409);
            AuditLogService::log(
                null,
                "registro_falha",
                "users",
                null,
                null,
                ['error' => "Email já cadastrado: " . $email],
                $ipAddress,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            );
            return ["error" => "Este email já está cadastrado."];
        }

        $senha_hash = password_hash($senha, PASSWORD_DEFAULT);
        $userId = User::create($nome, $email, $senha_hash, $funcao);

        if ($userId) {
            http_response_code(201);
            AuditLogService::log(
                null,
                "usuario_criado",
                "users",
                $userId,
                null,
                ['message' => "Novo usuário registrado: " . $email],
                $ipAddress,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            );
            return ["message" => "Usuário registrado com sucesso.", "user_id" => $userId];
        } else {
            http_response_code(500);
            AuditLogService::log(
                null,
                "registro_erro",
                "users",
                null,
                null,
                ['error' => "Falha ao inserir usuário no banco de dados: " . $email],
                $ipAddress,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            );
            return ["error" => "Falha ao registrar usuário."];
        }
    }

    public function refresh(): array
    {
        $headers = getallheaders();
        $ipAddress = $_SERVER["REMOTE_ADDR"] ?? "unknown";
        $refreshToken = $headers["Authorization"] ?? $headers["authorization"] ?? null;

        if (!$headers) {
            http_response_code(401);
            echo json_encode(["erro" => "Cabeçalho de autorização ausente"]);
            exit;
        }

        if (!preg_match('/Bearer\s(\S+)/', $refreshToken , $matches)) {
            http_response_code(401);
            echo json_encode(["erro" => "Formato do token inválido"]);
            exit;
        }

        $refreshToken = $matches[1]; // apenas o token JWT sem o "Bearer"

        if (!$refreshToken) {
            http_response_code(401);
            AuditLogService::log(
                null,
                "refresh_token_ausente", 
                null, 
                null, 
                null, ['error' => "Refresh token não fornecido."], 
                $ipAddress,
                $_SERVER['HTTP_USER_AGENT'] ?? null);
            return ["error" => "Refresh token não fornecido."];
        }

        $userData = $this->authService->validateRefreshToken($refreshToken);
        
        if (!$userData) {
            http_response_code(401);
            AuditLogService::log(
                null,
                "refresh_token_invalido", 
                null, 
                null, 
                null, ['error' => "Refresh token inválido ou expirado."], 
                $ipAddress,
                $_SERVER['HTTP_USER_AGENT'] ?? null);
            return ["error" => "Refresh token inválido ou expirado."];
        }

        $accessToken = $this->authService->generateToken(
            $userData['id'],
            $userData['email'],
            $userData['role'],
            $userData['nome']
        );

        $newRefreshToken = $this->authService->generateRefreshToken($userData['id'], $userData['email']);
        setcookie('refresh_token', $newRefreshToken, [
            'expires' => time() + (60 * 60 * 24 * 7),
            'httponly' => true,
            'secure' => true,
            'samesite' => 'Strict',
            'path' => '/refresh'
        ]);

        http_response_code(200);
        AuditLogService::log(
            $userData['id'],
            'refresh_token_sucesso',
            'users',
            $userData['id'],
            null,
            ['message' => 'Token renovado com sucesso','user_id' => $userData['id'],'email' => $userData['email']],
            $ipAddress,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        );

        return [
            "access_token" => $accessToken,
            "user" => [
                "id" => $userData['id'],
                "nome" => $userData['nome'],
                "email" => $userData['email'],
                "role" => $userData['role']
            ]
        ];
    }
}
