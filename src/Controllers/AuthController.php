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
            AuditLogService::log("login_bloqueado_rate_limit", null, null, null, "Tentativa de login bloqueada por rate limit.", $ipAddress);
            return ["error" => "Muitas tentativas de login. Tente novamente mais tarde."];
        }

        if (!$email || !$senha) {
            http_response_code(400);
            $this->rateLimiter->recordAttempt($ipAddress, $loginAction);
            AuditLogService::log("login_falha", null, null, null, "Email ou senha não fornecidos.", $ipAddress);
            return ["error" => "Email e senha são obrigatórios."];
        }

        $user = User::findByEmail($email);

        if (!$user || !$user->ativo) {
            http_response_code(401);
            $this->rateLimiter->recordAttempt($ipAddress, $loginAction);
            AuditLogService::log("login_falha", null, "usuario", null, "Usuário não encontrado ou inativo: " . $email, $ipAddress);
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
                AuditLogService::log("login_sucesso", $user->id, "usuario", $user->id, "Login bem-sucedido.", $ipAddress);
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
                AuditLogService::log("login_erro_jwt", $user->id, "usuario", $user->id, "Falha ao gerar token JWT.", $ipAddress);
                return ["error" => "Erro ao gerar token de autenticação."];
            }
        } else {
            http_response_code(401);
            $this->rateLimiter->recordAttempt($ipAddress, $loginAction);
            AuditLogService::log("login_falha", $user->id, "usuario", $user->id, "Senha incorreta.", $ipAddress);
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
            AuditLogService::log("registro_falha", null, null, null, "Dados de registro incompletos.", $ipAddress);
            return ["error" => "Nome, email e senha são obrigatórios."];
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            AuditLogService::log("registro_falha", null, null, null, "Formato de email inválido: " . $email, $ipAddress);
            return ["error" => "Formato de email inválido."];
        }

        if (User::findByEmail($email)) {
            http_response_code(409);
            AuditLogService::log("registro_falha", null, null, null, "Email já cadastrado: " . $email, $ipAddress);
            return ["error" => "Este email já está cadastrado."];
        }

        $senha_hash = password_hash($senha, PASSWORD_DEFAULT);
        $userId = User::create($nome, $email, $senha_hash, $funcao);

        if ($userId) {
            http_response_code(201);
            AuditLogService::log("usuario_criado", $userId, "usuario", $userId, "Novo usuário registrado: " . $email, $ipAddress);
            return ["message" => "Usuário registrado com sucesso.", "user_id" => $userId];
        } else {
            http_response_code(500);
            AuditLogService::log("registro_erro", null, null, null, "Falha ao inserir usuário no banco de dados: " . $email, $ipAddress);
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
            AuditLogService::log("refresh_token_ausente", null, null, null, "Refresh token não fornecido.", $ipAddress);
            return ["error" => "Refresh token não fornecido."];
        }

        $userData = $this->authService->validateRefreshToken($refreshToken);
        
        if (!$userData) {
            http_response_code(401);
            AuditLogService::log("refresh_token_invalido", null, null, null, "Refresh token inválido ou expirado.", $ipAddress);
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
        AuditLogService::log("refresh_token_sucesso", $userData['id'], "usuario", $userData['id'], "Refresh de token bem-sucedido.", $ipAddress);

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
