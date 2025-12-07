<?php

namespace Apoio19\Crm\Controllers;

use Apoio19\Crm\Models\User;
use Apoio19\Crm\Middleware\AuthMiddleware;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;


/**
 * Controlador para gerenciamento de usuários
 */
class UserController extends BaseController
{
    private AuthMiddleware $authMiddleware;

    private string $secretKey;
    private int $expirationTime;
    private string $algo;
    private string $issuer;
    private string $audience;
    private string $configPath;

    public function __construct(?array $config = null)
    {
        parent::__construct(); // Initialize PermissionService and NotificationService from BaseController

        $this->authMiddleware = new AuthMiddleware();

        if ($config === null) {
            $configPath = __DIR__ .

                '/../../config/jwt.php

';
            if (file_exists($configPath)) {
                $config = require $configPath;
            } else {
                // Fallback configuration (should not happen in a configured environment)
                $config = [
                    'secret' => $_ENV["JWT_SECRET"] ?? 'valor_padrao_inseguro_trocar_em_producao',
                    'expiration' => (int)($_ENV["JWT_EXPIRATION"] ?? 3600),
                    'algo' => 'HS256',
                    'issuer' => 'Apoio19 CRM',
                    'audience' => 'Apoio19 CRM Users'
                ];
                if ($config['secret'] === 'valor_padrao_inseguro_trocar_em_producao' && ($_ENV["APP_ENV"] ?? "production") !== "testing") {
                    error_log("CRITICAL: JWT Secret not configured properly! Using insecure default.", 0);
                }
            }
        }

        $this->secretKey = $config['secret'];
        $this->expirationTime = $config['expiration'];
        $this->algo = $config['algo'] ?? 'HS256';
        $this->issuer = $config['issuer'] ?? 'Apoio19 CRM';
        $this->audience = $config['audience'] ?? 'Apoio19 CRM Users';
    }

    /**
     * Listar usuários com filtros
     *
     * @param array $headers Cabeçalhos da requisição
     * @param array $queryParams Filtros (search, funcao, ativo, etc.)
     * @return array Resposta JSON
     */
    public function index(array $headers, array $queryParams = []): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária ou permissão insuficiente.");
        }

        // Check permission
        $this->requirePermission($userData, 'usuarios', 'view');

        try {
            // Montar condições dinâmicas
            $conditions = [];
            $params = [];

            // Filtro por função
            if (!empty($queryParams['funcao'])) {
                $conditions[] = "role = :funcao";
                $params[':funcao'] = $queryParams['funcao'];
            }

            // Filtro por status ativo
            if (isset($queryParams['ativo']) && $queryParams['ativo'] !== '') {
                $conditions[] = "active = :ativo";
                $params[':ativo'] = $queryParams['ativo'] ? '1' : '0';
            }

            // Filtro de busca textual (nome, email)
            if (!empty($queryParams['search'])) {
                $search = '%' . $queryParams['search'] . '%';
                $conditions[] = "(name LIKE :search1 OR email LIKE :search2)";
                $params[':search1'] = $search;
                $params[':search2'] = $search;
            }

            $where = $conditions ? "WHERE " . implode(" AND ", $conditions) : "";

            // Ordenação
            $orderBy = "ORDER BY name ASC";

            $users = User::findAllWithWhere($where . " " . $orderBy, $params);

            // Formatar resposta (remover senhas, etc)
            $formattedUsers = array_map(function ($user) {
                return $this->formatUserForResponse($user);
            }, $users);

            // Paginação
            $page = isset($queryParams['page']) ? (int)$queryParams['page'] : 1;
            $limit = isset($queryParams['limit']) ? (int)$queryParams['limit'] : 10;
            $total = count($formattedUsers);
            $totalPages = $limit > 0 ? (int)ceil($total / $limit) : 1;

            // Estrutura de resposta esperada pelo frontend
            $responseData = [
                'users' => $formattedUsers,
                'total' => $total,
                'page' => $page,
                'totalPages' => $totalPages
            ];

            return $this->successResponse($responseData, null, 200, $traceId);
        } catch (\PDOException $e) {
            $mapped = $this->mapPdoError($e);
            return $this->errorResponse($mapped['status'], $mapped['message'], $mapped['code'], $traceId, $this->debugDetails($e));
        } catch (\Throwable $e) {
            return $this->errorResponse(500, "Erro ao listar usuários.", "UNEXPECTED_ERROR", $traceId, $this->debugDetails($e));
        }
    }

    /**
     * Criar novo usuário
     * 
     * @param array $headers Cabeçalhos da requisição
     * @param array $requestData Dados do usuário
     * @return array Resposta JSON
     */
    public function store(array $headers, array $requestData): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);

        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHORIZED", $traceId);
        }

        // Check permission
        $this->requirePermission($userData, 'usuarios', 'create');

        // Normalizar dados (aceitar português ou inglês)
        $data = $this->normalizeUserData($requestData);

        // Validação
        $validation = $this->validateUserData($data);
        if (!$validation['valid']) {
            return $this->errorResponse(400, $validation['message'], "VALIDATION_ERROR", $traceId);
        }

        try {
            // Verificar se email já existe
            if (User::findByEmail($data['email'])) {
                return $this->errorResponse(409, "Email já cadastrado no sistema.", "EMAIL_EXISTS", $traceId);
            }

            // Hash da senha
            $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);

            // Definir permissões padrão se não fornecidas
            if (empty($data['permissions'])) {
                $data['permissions'] = $this->getDefaultPermissionsForRole($data['role']);
            }

            // Converter array de permissões para JSON se necessário
            if (is_array($data['permissions'])) {
                $data['permissions'] = json_encode($data['permissions']);
            }

            $userId = User::create($data);

            if ($userId) {
                $newUser = User::findById($userId);

                // 🟢 AUDIT LOG - Log user creation
                $this->logAudit(
                    $userData->id,
                    'create',
                    'usuarios',
                    $userId,
                    null,
                    $this->formatUserForResponse($newUser)
                );

                // 🔔 NOTIFICATION - Notify creator
                $this->notify(
                    $userData->id,
                    "Usuário Criado",
                    "Usuário {$newUser->nome} foi criado com sucesso.",
                    "success",
                    "/configuracoes/usuarios/{$userId}"
                );

                // Notificarsystem via existing method
                $this->notifyUserCreated($newUser, ['userId' => $userData->id, 'name' => $userData->name]);

                return $this->successResponse($this->formatUserForResponse($newUser), "Usuário criado com sucesso.", 201, $traceId);
            } else {
                return $this->errorResponse(500, "Falha ao criar usuário.", "CREATE_FAILED", $traceId);
            }
        } catch (\PDOException $e) {
            // Log failed attempt
            $this->logAudit($userData->id, 'create_failed', 'usuarios', null, null, ['error' => $e->getMessage()]);
            $mapped = $this->mapPdoError($e);
            return $this->errorResponse($mapped['status'], $mapped['message'], $mapped['code'], $traceId, $this->debugDetails($e));
        } catch (\Throwable $e) {
            // Log failed attempt
            $this->logAudit($userData->id, 'create_failed', 'usuarios', null, null, ['error' => $e->getMessage()]);
            return $this->errorResponse(500, "Erro interno ao criar usuário.", "UNEXPECTED_ERROR", $traceId, $this->debugDetails($e));
        }
    }

    /**
     * Atualizar usuário existente
     * 
     * @param array $headers Cabeçalhos da requisição
     * @param int $userId ID do usuário
     * @param array $requestData Dados para atualização
     * @return array Resposta JSON
     */
    public function update(array $headers, int $userId, array $requestData): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária ou permissão insuficiente.", "UNAUTHORIZED", $traceId);
        }

        // Check permission
        $this->requirePermission($userData, 'usuarios', 'edit');

        // Normalizar dados
        $data = $this->normalizeUserData($requestData);

        // Validar dados
        $validation = $this->validateUserUpdateData($data, $userId);
        if (!$validation['valid']) {
            return $this->errorResponse(400, $validation['message'], "VALIDATION_ERROR", $traceId);
        }

        try {
            $user = User::findById($userId);
            if (!$user) {
                return $this->errorResponse(404, "Usuário não encontrado.", "USER_NOT_FOUND", $traceId);
            }

            // Preparar dados para atualização
            $updateData = [];

            if (isset($data['name'])) $updateData['name'] = $data['name'];
            if (isset($data['email'])) $updateData['email'] = $data['email'];
            if (isset($data['role'])) $updateData['role'] = $data['role'];
            if (isset($data['phone'])) $updateData['phone'] = $this->formatPhone($data['phone']);
            if (isset($data['active'])) $updateData['active'] = (int)$data['active'];

            if (isset($data['password']) && !empty($data['password'])) {
                $updateData['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
            }

            // Tratamento de permissões
            if (isset($data['permissions'])) {
                if (is_array($data['permissions'])) {
                    $updateData['permissions'] = json_encode($data['permissions']);
                } else {
                    $updateData['permissions'] = $data['permissions'];
                }
            }

            $updateData['updated_at'] = date('Y-m-d H:i:s');

            if (empty($updateData)) {
                return $this->successResponse($this->formatUserForResponse($user), "Nenhuma alteração realizada.", 200, $traceId);
            }

            if (User::update($userId, $updateData)) {
                $updatedUser = User::findById($userId);

                // 🟢 AUDIT LOG - Log user update
                $this->logAudit(
                    $userData->id,
                    'update',
                    'usuarios',
                    $userId,
                    $this->formatUserForResponse($user), // Old values
                    $this->formatUserForResponse($updatedUser) // New values
                );

                // 🔔 NOTIFICATION - Notify updater
                $this->notify(
                    $userData->id,
                    "Usuário Atualizado",
                    "Usuário {$updatedUser->nome} foi atualizado com sucesso.",
                    "info",
                    "/configuracoes/usuarios/{$userId}"
                );

                return $this->successResponse($this->formatUserForResponse($updatedUser), "Usuário atualizado com sucesso.", 200, $traceId);
            } else {
                return $this->errorResponse(500, "Falha ao atualizar usuário.", "UPDATE_FAILED", $traceId);
            }
        } catch (\PDOException $e) {
            // Log failed attempt
            $this->logAudit($userData->id, 'update_failed', 'usuarios', $userId, null, ['error' => $e->getMessage()]);
            $mapped = $this->mapPdoError($e);
            return $this->errorResponse($mapped['status'], $mapped['message'], $mapped['code'], $traceId, $this->debugDetails($e));
        } catch (\Throwable $e) {
            // Log failed attempt
            $this->logAudit($userData->id, 'update_failed', 'usuarios', $userId, null, ['error' => $e->getMessage()]);
            return $this->errorResponse(500, "Erro interno ao atualizar usuário.", "UNEXPECTED_ERROR", $traceId, $this->debugDetails($e));
        }
    }

    /**
     * Excluir usuário
     * 
     * @param array $headers Cabeçalhos da requisição
     * @param int $userId ID do usuário
     * @return array Resposta JSON
     */
    public function destroy(array $headers, int $userId): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária ou permissão insuficiente.");
        }

        // Check permission
        $this->requirePermission($userData, 'usuarios', 'delete');

        if ($userId == $userData->id) {
            return $this->errorResponse(400, "Você não pode excluir seu próprio usuário.");
        }

        $user = User::findById($userId);
        if (!$user) {
            return $this->errorResponse(404, "Usuário não encontrado.");
        }

        if (User::delete($userId)) {
            // 🟢 AUDIT LOG - Log user deletion
            $this->logAudit(
                $userData->id,
                'delete',
                'usuarios',
                $userId,
                $this->formatUserForResponse($user), // Old values
                null
            );

            // 🔔 NOTIFICATION - Notify deleter
            $this->notify(
                $userData->id,
                "Usuário Excluído",
                "Usuário {$user->nome} foi excluído com sucesso.",
                "warning",
                "/configuracoes/usuarios"
            );

            return $this->successResponse(null, "Usuário excluído com sucesso.");
        } else {
            return $this->errorResponse(500, "Falha ao excluir usuário.");
        }
    }

    /**
     * Ativar usuário
     * 
     * @param array $headers Cabeçalhos da requisição
     * @param int $userId ID do usuário
     * @return array Resposta JSON
     */
    public function activate(array $headers, int $userId): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);

        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHORIZED", $traceId);
        }

        // Check permission
        $this->requirePermission($userData, 'usuarios', 'edit');

        try {
            $user = User::findById($userId);
            if (!$user) {
                return $this->errorResponse(404, "Usuário não encontrado.", "USER_NOT_FOUND", $traceId);
            }

            if ($user->active == 1) {
                return $this->errorResponse(400, "Usuário já está ativo.", "ALREADY_ACTIVE", $traceId);
            }

            $updateData = [
                'active' => 1,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            if (User::update($userId, $updateData)) {
                $updatedUser = User::findById($userId);

                return $this->successResponse(
                    $this->formatUserForResponse($updatedUser),
                    "Usuário ativado com sucesso.",
                    200,
                    $traceId
                );
            } else {
                return $this->errorResponse(500, "Falha ao ativar usuário.", "UPDATE_FAILED", $traceId);
            }
        } catch (\PDOException $e) {
            $mapped = $this->mapPdoError($e);
            return $this->errorResponse($mapped['status'], $mapped['message'], $mapped['code'], $traceId, $this->debugDetails($e));
        } catch (\Throwable $e) {
            return $this->errorResponse(500, "Erro interno ao ativar usuário.", "UNEXPECTED_ERROR", $traceId, $this->debugDetails($e));
        }
    }

    /**
     * Desativar usuário
     * 
     * @param array $headers Cabeçalhos da requisição
     * @param int $userId ID do usuário
     * @return array Resposta JSON
     */
    public function deactivate(array $headers, int $userId): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);

        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHORIZED", $traceId);
        }

        // Check permission
        $this->requirePermission($userData, 'usuarios', 'edit');

        try {
            // Verificar se não está tentando desativar a si mesmo
            if ($userId == $userData->id) {
                return $this->errorResponse(400, "Você não pode desativar seu próprio usuário.", "SELF_DEACTIVATION", $traceId);
            }

            $user = User::findById($userId);
            if (!$user) {
                return $this->errorResponse(404, "Usuário não encontrado.", "USER_NOT_FOUND", $traceId);
            }

            if ($user->active == 0) {
                return $this->errorResponse(400, "Usuário já está inativo.", "ALREADY_INACTIVE", $traceId);
            }

            // Verificar se é o último admin ativo
            if ($user->funcao === 'Admin') {
                $activeAdminCount = User::countActiveAdmins();
                if ($activeAdminCount <= 1) {
                    return $this->errorResponse(400, "Não é possível desativar o último administrador ativo do sistema.", "LAST_ADMIN", $traceId);
                }
            }

            $updateData = [
                'active' => 0,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            if (User::update($userId, $updateData)) {
                $updatedUser = User::findById($userId);

                return $this->successResponse(
                    $this->formatUserForResponse($updatedUser),
                    "Usuário desativado com sucesso.",
                    200,
                    $traceId
                );
            } else {
                return $this->errorResponse(500, "Falha ao desativar usuário.", "UPDATE_FAILED", $traceId);
            }
        } catch (\PDOException $e) {
            $mapped = $this->mapPdoError($e);
            return $this->errorResponse($mapped['status'], $mapped['message'], $mapped['code'], $traceId, $this->debugDetails($e));
        } catch (\Throwable $e) {
            return $this->errorResponse(500, "Erro interno ao desativar usuário.", "UNEXPECTED_ERROR", $traceId, $this->debugDetails($e));
        }
    }


    /**
     * Ações em lote
     *
     * @param array $headers Cabeçalhos da requisição
     * @param array $requestData Dados da ação em lote
     * @return array Resposta JSON
     */
    public function bulkAction(array $headers, array $requestData): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária ou permissão insuficiente.");
        }

        // Check permission
        $this->requirePermission($userData, 'usuarios', 'edit'); // Assuming bulk actions are mostly edits


        $userIds = $requestData['userIds'] ?? [];
        $action = $requestData['action'] ?? null;

        if (empty($userIds) || !$action) {
            return $this->errorResponse(400, "IDs de usuários e ação são obrigatórios.");
        }

        if (!in_array($action, ['activate', 'deactivate', 'delete'])) {
            return $this->errorResponse(400, "Ação inválida. Use: activate, deactivate ou delete.");
        }

        $successCount = 0;
        $failedIds = [];

        foreach ($userIds as $userId) {
            try {
                $user = User::findById($userId);
                if (!$user || $user->deleted_at) {
                    $failedIds[] = $userId;
                    continue;
                }

                // Verificar se não está tentando afetar a si mesmo em ações críticas
                if ($userId === $userData->id && in_array($action, ['deactivate', 'delete'])) {
                    $failedIds[] = $userId;
                    continue;
                }

                // Verificar proteções para admins
                if ($user->funcao === 'Admin') {
                    $activeAdminCount = User::countActiveAdmins();
                    if ($activeAdminCount <= 1 && in_array($action, ['deactivate', 'delete'])) {
                        $failedIds[] = $userId;
                        continue;
                    }
                }

                $success = false;
                switch ($action) {
                    case 'activate':
                        $success = User::update($userId, [
                            'active' => 1,
                            'updated_at' => date('Y-m-d H:i:s')
                        ]);
                        break;

                    case 'deactivate':
                        $success = User::update($userId, [
                            'active' => 0,
                            'updated_at' => date('Y-m-d H:i:s')
                        ]);
                        break;

                    case 'delete':
                        $success = User::update($userId, [
                            'deleted_at' => date('Y-m-d H:i:s'),
                            'active' => 0,
                            'updated_at' => date('Y-m-d H:i:s')
                        ]);
                        break;
                }

                if ($success) {
                    $successCount++;
                } else {
                    $failedIds[] = $userId;
                }
            } catch (\Exception $e) {
                error_log("Erro ao processar usuário $userId: " . $e->getMessage());
                $failedIds[] = $userId;
            }
        }

        return $this->successResponse([
            'success' => array_diff($userIds, $failedIds),
            'failed' => $failedIds,
            'successCount' => $successCount,
            'totalRequested' => count($userIds)
        ], "Ação em lote processada. {$successCount} usuário(s) processado(s) com sucesso.");
    }

    /**
     * Verificar se email está disponível
     *
     * @param array $headers Cabeçalhos da requisição
     * @param array $queryParams Parâmetros da consulta
     * @return array Resposta JSON
     */
    public function checkEmail(array $headers, array $queryParams): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária ou permissão insuficiente.");
        }

        $email = $queryParams['email'] ?? '';
        $excludeId = isset($queryParams['excludeId']) ? (int)$queryParams['excludeId'] : null;

        if (empty($email)) {
            return $this->errorResponse(400, "Email é obrigatório.");
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->errorResponse(400, "Email inválido.");
        }

        try {
            $existingUser = User::findByEmail($email);
            $exists = $existingUser && (!$excludeId || $existingUser->id !== $excludeId);

            return $this->successResponse(['exists' => $exists]);
        } catch (\Exception $e) {
            return $this->errorResponse(500, "Erro ao verificar email.", $e->getMessage());
        }
    }

    /**
     * Obter permissões e funções disponíveis
     *
     * @param array $headers Cabeçalhos da requisição
     * @return array Resposta JSON
     */
    public function getPermissions(array $headers): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária ou permissão insuficiente.");
        }

        try {
            $permissions = [
                'leads.read',
                'leads.write',
                'leads.assign',
                'propostas.read',
                'propostas.write',
                'propostas.approve',
                'kanban.read',
                'kanban.write',
                'whatsapp.read',
                'whatsapp.write',
                'configuracoes.read',
                'configuracoes.write',
                'usuarios.read',
                'usuarios.write',
                'relatorios.read',
                'relatorios.export'
            ];

            $roles = ['admin', 'gerente', 'vendedor', 'suporte', 'comercial', 'financeiro'];

            return $this->successResponse([
                'permissions' => $permissions,
                'roles' => $roles
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse(500, "Erro ao obter permissões.", $e->getMessage());
        }
    }

    /**
     * Obter estatísticas de usuários
     *
     * @param array $headers Cabeçalhos da requisição
     * @return array Resposta JSON
     */
    public function stats(array $headers): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária ou permissão insuficiente.");
        }

        // Check permission
        $this->requirePermission($userData, 'usuarios', 'view');

        try {
            $stats = User::getStats();

            return $this->successResponse($stats);
        } catch (\Exception $e) {
            return $this->errorResponse(500, "Erro ao obter estatísticas.", $e->getMessage());
        }
    }

    /**
     * Redefinir senha de usuário
     *
     * @param array $headers Cabeçalhos da requisição
     * @param int $userId ID do usuário
     * @return array Resposta JSON
     */
    public function resetPassword(array $headers, int $userId): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária ou permissão insuficiente.");
        }

        // Check permission (admin can reset anyone's password, maybe user can reset own?)
        // If it's a password reset initiated by admin:
        $this->requirePermission($userData, 'usuarios', 'edit');

        try {
            $user = User::findById($userId);
            if (!$user || $user->deleted_at) {
                return $this->errorResponse(404, "Usuário não encontrado.");
            }

            // Gerar senha temporária
            $temporaryPassword = $this->generateTemporaryPassword();
            $hashedPassword = password_hash($temporaryPassword, PASSWORD_DEFAULT);

            $updateData = [
                'senha_hash' => $hashedPassword,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            if (User::update($userId, $updateData)) {
                // Notificar usuário sobre nova senha (opcional)
                $this->notifyPasswordReset($user, $temporaryPassword);

                return $this->successResponse([
                    'message' => 'Senha redefinida com sucesso',
                    'temporaryPassword' => $temporaryPassword
                ], "Senha redefinida com sucesso.");
            } else {
                return $this->errorResponse(500, "Falha ao redefinir senha.");
            }
        } catch (\Exception $e) {
            return $this->errorResponse(500, "Erro interno ao redefinir senha.", $e->getMessage());
        }
    }

    /**
     * Obter perfil do usuário logado
     *
     * @param array $headers Cabeçalhos da requisição
     * @return array Resposta JSON
     */
    public function profile(array $headers): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária.");
        }

        try {
            $user = User::findById($userData->id);
            if (!$user || $user->deleted_at) {
                return $this->errorResponse(404, "Usuário não encontrado.");
            }

            return $this->successResponse($this->formatUserForResponse($user));
        } catch (\Exception $e) {
            return $this->errorResponse(500, "Erro ao obter perfil.", $e->getMessage());
        }
    }

    /**
     * Atualizar perfil do usuário logado
     *
     * @param array $headers Cabeçalhos da requisição
     * @param array $requestData Dados para atualização
     * @return array Resposta JSON
     */
    public function updateProfile(array $headers, array $requestData): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária.");
        }

        if (empty($requestData)) {
            return $this->errorResponse(400, "Nenhum dado fornecido para atualização.");
        }

        // Normalizar dados recebidos
        $requestData = $this->normalizeUserData($requestData);

        try {
            $user = User::findById($userData->userId);
            if (!$user || $user->deleted_at) {
                return $this->errorResponse(404, "Usuário não encontrado.");
            }

            // Validar dados de atualização do perfil
            $validation = $this->validateProfileUpdateData($requestData, $userData->userId);
            if (!$validation['valid']) {
                return $this->errorResponse(400, $validation['message']);
            }

            // Preparar dados para atualização
            $updateData = [];

            if (isset($requestData['name'])) {
                $updateData['name'] = trim($requestData['name']);
            }

            if (isset($requestData['phone'])) {
                $updateData['phone'] = $this->formatPhone($requestData['phone']);
            }

            if (isset($requestData['password']) && !empty($requestData['password'])) {
                $updateData['password'] = password_hash($requestData['password'], PASSWORD_DEFAULT);
            }

            $updateData['updated_at'] = date('Y-m-d H:i:s');

            if (User::update($userData->id, $updateData)) {
                $updatedUser = User::findById($userData->id);

                return $this->successResponse(
                    $this->formatUserForResponse($updatedUser),
                    "Perfil atualizado com sucesso."
                );
            } else {
                return $this->errorResponse(500, "Falha ao atualizar perfil.");
            }
        } catch (\Exception $e) {
            return $this->errorResponse(500, "Erro interno ao atualizar perfil.", $e->getMessage());
        }
    }

    // Métodos auxiliares privados

    /**
     * Validar dados do usuário
     */
    private function validateUserData(array $data): array
    {
        if (empty($data['name'])) {
            return ["valid" => false, "message" => "O nome é obrigatório."];
        }

        if (strlen($data['name']) < 2) {
            return ["valid" => false, "message" => "O nome deve ter pelo menos 2 caracteres."];
        }

        if (!preg_match('/^[a-zA-ZÀ-ÿ\s]+$/', $data['name'])) {
            return ["valid" => false, "message" => "O nome deve conter apenas letras e espaços."];
        }

        if (empty($data['email'])) {
            return ["valid" => false, "message" => "O email é obrigatório."];
        }

        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return ["valid" => false, "message" => "Email inválido."];
        }

        if (empty($data['password'])) {
            return ["valid" => false, "message" => "A senha é obrigatória."];
        }

        if (strlen($data['password']) < 6) {
            return ["valid" => false, "message" => "A senha deve ter pelo menos 6 caracteres."];
        }

        // Removida validação de complexidade para permitir senhas mais simples
        // Validação opcional: senhas fortes são recomendadas mas não obrigatórias

        if (empty($data['role'])) {
            return ["valid" => false, "message" => "A função é obrigatória."];
        }

        if (!in_array($data['role'], ['admin', 'gerente', 'vendedor', 'suporte', 'comercial', 'financeiro'])) {
            return ["valid" => false, "message" => "Função inválida."];
        }

        if (isset($data['phone']) && !empty($data['phone'])) {
            $phone = preg_replace('/\D/', '', $data['phone']);
            if (strlen($phone) < 10 || strlen($phone) > 11) {
                return ["valid" => false, "message" => "Telefone inválido."];
            }
        }

        return ["valid" => true, "message" => ""];
    }

    /**
     * Validar dados de atualização do usuário
     */
    private function validateUserUpdateData(array $data, int $userId): array
    {
        if (isset($data['name'])) {
            if (empty($data['name'])) {
                return ["valid" => false, "message" => "O name não pode estar vazio."];
            }

            if (strlen($data['name']) < 2) {
                return ["valid" => false, "message" => "O name deve ter pelo menos 2 caracteres."];
            }

            if (!preg_match('/^[a-zA-ZÀ-ÿ\s]+$/', $data['name'])) {
                return ["valid" => false, "message" => "O name deve conter apenas letras e espaços."];
            }
        }

        if (isset($data['email'])) {
            if (empty($data['email'])) {
                return ["valid" => false, "message" => "O email não pode estar vazio."];
            }

            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                return ["valid" => false, "message" => "Email inválido."];
            }

            // Verificar se email já existe para outro usuário
            $existingUser = User::findByEmail($data['email']);
            if ($existingUser && $existingUser->id !== $userId) {
                return ["valid" => false, "message" => "Este email já está em uso por outro usuário."];
            }
        }

        if (isset($data['password']) && !empty($data['password'])) {
            if (strlen($data['password']) < 6) {
                return ["valid" => false, "message" => "A senha deve ter pelo menos 6 caracteres."];
            }

            // Removida validação de complexidade para permitir senhas mais simples
        }

        if (isset($data['role'])) {
            if (!in_array($data['role'], ['admin', 'gerente', 'vendedor', 'suporte', 'comercial', 'financeiro'])) {
                return ["valid" => false, "message" => "Função inválida."];
            }
        }

        if (isset($data['phone']) && !empty($data['phone'])) {
            $phone = preg_replace('/\D/', '', $data['phone']);
            if (strlen($phone) < 10 || strlen($phone) > 11) {
                return ["valid" => false, "message" => "Telefone inválido."];
            }
        }

        return ["valid" => true, "message" => ""];
    }

    /**
     * Validar dados de atualização do perfil
     */
    private function validateProfileUpdateData(array $data, int $userId): array
    {
        if (isset($data['name'])) {
            if (empty($data['name'])) {
                return ["valid" => false, "message" => "O nome não pode estar vazio."];
            }

            if (strlen($data['name']) < 2) {
                return ["valid" => false, "message" => "O nome deve ter pelo menos 2 caracteres."];
            }

            if (!preg_match('/^[a-zA-ZÀ-ÿ\s]+$/', $data['nome'])) {
                return ["valid" => false, "message" => "O nome deve conter apenas letras e espaços."];
            }
        }

        if (isset($data['senha']) && !empty($data['senha'])) {
            if (strlen($data['senha']) < 8) {
                return ["valid" => false, "message" => "A senha deve ter pelo menos 8 caracteres."];
            }

            if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]/', $data['senha'])) {
                return ["valid" => false, "message" => "A senha deve conter pelo menos: 1 letra minúscula, 1 maiúscula, 1 número e 1 caractere especial."];
            }

            // Verificar confirmação de senha se fornecida
            if (isset($data['senha_confirmation']) && $data['senha'] !== $data['senha_confirmation']) {
                return ["valid" => false, "message" => "A confirmação da senha não confere."];
            }
        }

        if (isset($data['telefone']) && !empty($data['telefone'])) {
            $phone = preg_replace('/\D/', '', $data['telefone']);
            if (strlen($phone) < 10 || strlen($phone) > 11) {
                return ["valid" => false, "message" => "Telefone inválido."];
            }
        }

        return ["valid" => true, "message" => ""];
    }

    /**
     * Formatar telefone para padrão brasileiro
     */
    private function formatPhone(?string $phone): ?string
    {
        if (empty($phone)) {
            return null;
        }

        $numbers = preg_replace('/\D/', '', $phone);

        if (strlen($numbers) === 11) {
            return '(' . substr($numbers, 0, 2) . ') ' .
                substr($numbers, 2, 5) . '-' .
                substr($numbers, 7);
        } elseif (strlen($numbers) === 10) {
            return '(' . substr($numbers, 0, 2) . ') ' .
                substr($numbers, 2, 4) . '-' .
                substr($numbers, 6);
        }

        return $phone;
    }

    /**
     * Obter permissões para usuário baseado nos dados fornecidos
     */
    private function getPermissionsForUser(array $data): array
    {
        if (isset($data['permissions']) && is_array($data['permissions'])) {
            return $data['permissions'];
        }

        return $this->getDefaultPermissionsForRole($data['role']);
    }

    /**
     * Obter permissões padrão por função
     */
    private function getDefaultPermissionsForRole(string $role): array
    {
        $rolePermissions = [
            'admin' => ['all'],
            'gerente' => [
                'leads.read',
                'leads.write',
                'leads.assign',
                'propostas.read',
                'propostas.write',
                'propostas.approve',
                'kanban.read',
                'kanban.write',
                'whatsapp.read',
                'whatsapp.write',
                'relatorios.read',
                'relatorios.export',
                'usuarios.read'
            ],
            'vendedor' => [
                'leads.read',
                'leads.write',
                'propostas.read',
                'propostas.write',
                'kanban.read',
                'kanban.write',
                'whatsapp.read',
                'whatsapp.write'
            ],
            'suporte' => [
                'leads.read',
                'kanban.read',
                'whatsapp.read',
                'whatsapp.write',
                'configuracoes.read'
            ]
        ];

        return $rolePermissions[$role] ?? [];
    }

    /**
     * Normalizar dados do usuário (aceitar campos em português ou inglês)
     * 
     * @param array $data Dados do usuário
     * @return array Dados normalizados
     */
    private function normalizeUserData(array $data): array
    {
        $normalized = [];

        // Nome (aceitar 'nome' ou 'name')
        if (isset($data['nome'])) {
            $normalized['name'] = $data['nome'];
        } elseif (isset($data['name'])) {
            $normalized['name'] = $data['name'];
        }

        // Email (já em inglês)
        if (isset($data['email'])) {
            $normalized['email'] = $data['email'];
        }

        // Senha (aceitar 'senha' ou 'password')
        if (isset($data['senha'])) {
            $normalized['password'] = $data['senha'];
        } elseif (isset($data['password'])) {
            $normalized['password'] = $data['password'];
        }

        // Função (aceitar 'funcao' ou 'role')
        if (isset($data['funcao'])) {
            $normalized['role'] = $data['funcao'];
        } elseif (isset($data['role'])) {
            $normalized['role'] = $data['role'];
        }

        // Telefone (aceitar 'telefone' ou 'phone')
        if (isset($data['telefone'])) {
            $normalized['phone'] = $data['telefone'];
        } elseif (isset($data['phone'])) {
            $normalized['phone'] = $data['phone'];
        }

        // Ativo (aceitar 'ativo' ou 'active')
        if (isset($data['ativo'])) {
            $normalized['active'] = $data['ativo'];
        } elseif (isset($data['active'])) {
            $normalized['active'] = $data['active'];
        }

        // Permissões (aceitar 'permissoes' ou 'permissions')
        if (isset($data['permissoes'])) {
            $normalized['permissions'] = $data['permissoes'];
        } elseif (isset($data['permissions'])) {
            $normalized['permissions'] = $data['permissions'];
        }

        // ID (para atualizações)
        if (isset($data['id'])) {
            $normalized['id'] = $data['id'];
        }

        return $normalized;
    }

    /**
     * Formatar usuário para resposta da API
     */
    private function formatUserForResponse($user): array
    {
        $permissions = [];
        if (!empty($user->permissions)) {
            $permissions = is_string($user->permissions) ? json_decode($user->permissions, true) : $user->permissions;
        }

        return [
            'id' => (string) $user->id,
            'nome' => $user->name ?? $user->nome,
            'email' => $user->email,
            'funcao' => $user->role ?? $user->funcao,
            'ativo' => (bool) $user->active ?? (bool) $user->ativo,
            'telefone' => $user->phone ?? $user->telefone,
            'permissoes' => $permissions ?? [],
            'dataCriacao' => $user->created_at ?? $user->criado_em,
            'dataAtualizacao' => $user->updated_at ?? $user->atualizado_em,
            'ultimoLogin' => $user->last_login
        ];
    }

    /**
     * Gerar senha temporária
     */
    private function generateTemporaryPassword(): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789@#$%';
        $password = '';

        for ($i = 0; $i < 12; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $password;
    }

    /**
     * Notificar criação de usuário
     */
    private function notifyUserCreated($user, $creatorData): void
    {
        try {
            $this->notificationService->createNotification(
                $creatorData['userId'],
                'create_user',
                'Usuário ' . $user->name . ' Criado no CRM por ' . $creatorData['name'],
                "success",
                '/activate',
                '0'
            );
        } catch (\Exception $e) {
            error_log("Erro ao enviar notificação de criação de usuário: " . $e->getMessage());
        }
    }

    /**
     * Notificar redefinição de senha
     */
    private function notifyPasswordReset(object $user, string $temporaryPassword): void
    {
        try {
            $this->notificationService->createNotification(
                $user->id,
                'password_reset',
                "Sua senha foi redefinida. Nova senha temporária: {$temporaryPassword}",
                "info",
                '/profile',
                '0'
            );
        } catch (\Exception $e) {
            error_log("Erro ao enviar notificação de redefinição de senha: " . $e->getMessage());
        }
    }
}
