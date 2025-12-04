<?php

namespace Apoio19\Crm\Controllers;

use Apoio19\Crm\Models\User;
use Apoio19\Crm\Services\AuthService;
use Apoio19\Crm\Services\AuditLogService;
use Apoio19\Crm\Services\RateLimitingService;
use Apoio19\Crm\Services\EmailService;
use Apoio19\Crm\Views\EmailView;

class AuthController extends BaseController
{
    private AuthService $authService;
    private RateLimitingService $rateLimiter;
    private EmailService $emailService;

    public function __construct()
    {
        parent::__construct();
        $this->authService = new AuthService();
        $this->rateLimiter = new RateLimitingService();
        $this->emailService = new EmailService();
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
                    ['message' => 'Login bem-sucedido', 'user_id' => $user->id, 'email' => $user->email],
                    $ipAddress,
                    $_SERVER['HTTP_USER_AGENT'] ?? null
                );

                // Atualizar último login
                User::updateLastLogin($user->id);

                // Get user permissions
                $permissions = $this->permissionService->getUserPermissions($user);

                return [
                    "token" => $token,
                    "user" => [
                        "id" => $user->id,
                        "nome" => $user->nome,
                        "email" => $user->email,
                        "role" => $user->funcao,
                        "permissions" => $permissions
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

        if (!preg_match('/Bearer\s(\S+)/', $refreshToken, $matches)) {
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
                null,
                ['error' => "Refresh token não fornecido."],
                $ipAddress,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            );
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
                null,
                ['error' => "Refresh token inválido ou expirado."],
                $ipAddress,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            );
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
            ['message' => 'Token renovado com sucesso', 'user_id' => $userData['id'], 'email' => $userData['email']],
            $ipAddress,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        );

        // Get fresh user data with permissions
        $user = User::findById($userData['id']);
        $permissions = $user ? $this->permissionService->getUserPermissions($user) : [];

        return [
            "access_token" => $accessToken,
            "user" => [
                "id" => $userData['id'],
                "nome" => $userData['nome'],
                "email" => $userData['email'],
                "role" => $userData['role'],
                "permissions" => $permissions
            ]
        ];
    }

    /**
     * Inicia o processo de recuperação de senha.
     *
     * @param array $requestData Deve conter 'email'.
     * @return array Resposta da API.
     */
    public function requestPasswordReset(array $requestData): array
    {
        $email = $requestData['email'] ?? null;
        if (!$email) {
            http_response_code(400);
            return ['status' => 'error', 'message' => 'O e-mail é obrigatório.'];
        }

        // valida o formato do email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            return ['status' => 'error', 'message' => 'Formato de e-mail inválido.'];
        }

        // Verifica se o usuário existe
        $user = User::findByEmail($email); // Supondo que você tenha este método
        if (!$user) {
            http_response_code(400);
            return ['status' => 'error', 'message' => 'Usuário não localizado.'];
        }

        // 1. Gerar Token Seguro
        $token = bin2hex(random_bytes(32)); // Gera um token de 64 caracteres
        $expiresAt = (new \DateTime())->modify('+1 hour')->format('Y-m-d H:i:s');

        // 2. Salvar Token no Banco
        if (!User::setPasswordResetToken($email, $token, $expiresAt)) {
            http_response_code(500);
            return ['status' => 'error', 'message' => 'Não foi possível iniciar o processo de recuperação.'];
        }

        // 3. Montar o Link e o Corpo do E-mail
        $resetLink = 'https://crm.apoio19.com.br/reset-password?token=' . $token;
        $emailBody = EmailView::render('recuperacao_senha.html', [
            'nome_usuario' => $user->nome,
            'link_recuperacao' => $resetLink
        ]);

        if (!$emailBody) {
            http_response_code(500);
            return ['status' => 'error', 'message' => 'Template de e-mail não encontrado.'];
        }

        // 4. Enviar o E-mail
        $subject = 'Recuperação de Senha - Apoio19';
        $success = $this->emailService->send($user->email, $user->nome, $subject, $emailBody);

        if ($success) {
            return ['status' => 'success', 'message' => 'Se um usuário com este e-mail existir, um link de recuperação será enviado.'];
        } else {
            http_response_code(500);
            return ['status' => 'error', 'message' => 'Falha ao enviar o e-mail de recuperação.'];
        }
    }

    /**
     * Valida um token de redefinição de senha.
     * Usado para verificar se o token é válido antes de mostrar o formulário de nova senha.
     *
     * @param array $requestData Deve conter 'token'.
     * @return array Resposta da API.
     */
    public function validateResetToken(array $requestData): array
    {
        $token = $requestData['token'] ?? null;

        if (!$token) {
            http_response_code(400); // Bad Request
            return ['status' => 'error', 'message' => 'Token não fornecido.'];
        }

        // Usa o método que criamos no Model
        $user = User::findByValidResetToken($token);

        if ($user) {
            return ['status' => 'success', 'message' => 'Token válido.'];
        } else {
            http_response_code(404); // Not Found
            return ['status' => 'error', 'message' => 'Token inválido ou expirado.'];
        }
    }

    /**
     * Redefine a senha do usuário usando um token válido.
     *
     * @param array $requestData Deve conter 'token' e 'nova_senha'.
     * @return array Resposta da API.
     */
    public function resetPassword(array $requestData): array
    {
        $token = $requestData['token'] ?? null;
        $newPassword = $requestData['newPassword'] ?? null;

        if (!$token || !$newPassword) {
            http_response_code(400);
            return ['status' => 'error', 'message' => 'Token e nova senha são obrigatórios.'];
        }

        // 1. Valida o token novamente (verificação final de segurança)
        $user = User::findByValidResetToken($token);

        if (!$user) {
            http_response_code(404);
            return ['status' => 'error', 'message' => 'Token inválido ou expirado. Tente solicitar a recuperação novamente.'];
        }

        // 2. Atualiza a senha no banco de dados
        // É CRUCIAL que a senha seja armazenada com hash!
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

        // 3. Invalida o token para que não possa ser reutilizado
        $success = User::updatePasswordAndInvalidateToken($user['id'], $hashedPassword);

        if ($success) {

            return ['status' => 'success', 'message' => 'Senha alterada com sucesso!'];
        } else {
            http_response_code(500);
            return ['status' => 'error', 'message' => 'Ocorreu um erro ao atualizar sua senha.'];
        }
    }

    /**
     * Logout do usuário, invalidando o refresh token.
     *
     * @param array $userData
     * @return array
     */
    public function logout(array $userData): array
    {

        $ipAddress = $_SERVER["REMOTE_ADDR"] ?? "unknown";
        setcookie('refresh_token', '', [
            'expires' => time() - 3600,
            'httponly' => true,
            'secure' => true,
            'samesite' => 'Strict',
            'path' => '/refresh'
        ]);

        http_response_code(200);
        AuditLogService::log(
            $userData['id'] ?? null,
            "logout_sucesso",
            null,
            null,
            $userData ?? null,
            ['message' => 'Logout realizado com sucesso.'],
            $ipAddress,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        );

        return ["message" => "Logout realizado com sucesso."];
    }
}
