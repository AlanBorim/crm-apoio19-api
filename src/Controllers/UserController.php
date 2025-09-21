<?php

namespace Apoio19\Crm\Controllers;

use Apoio19\Crm\Models\User;
use Apoio19\Crm\Middleware\AuthMiddleware;
use Apoio19\Crm\Services\NotificationService;

/**
 * Controlador para gerenciamento de usuários
 */
class UserController
{
    private AuthMiddleware $authMiddleware;
    private NotificationService $notificationService;

    public function __construct()
    {
        $this->authMiddleware = new AuthMiddleware();
        $this->notificationService = new NotificationService();
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
        $userData = $this->authMiddleware->handle($headers, ["admin", "gerente"]);
        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária ou permissão insuficiente.");
        }

        try {
            // Montar condições dinâmicas
            $conditions = [];
            $params = [];

            // Filtro por função
            if (!empty($queryParams['funcao'])) {
                $conditions[] = "funcao = :funcao";
                $params[':funcao'] = $queryParams['funcao'];
            }

            // Filtro por status ativo
            if (isset($queryParams['ativo']) && $queryParams['ativo'] !== '') {
                $conditions[] = "ativo = :ativo";
                $params[':ativo'] = $queryParams['ativo'] ? '1' : '0';
            }

            // Filtro de busca textual (nome, email)
            if (!empty($queryParams['search'])) {
                $search = '%' . $queryParams['search'] . '%';
                $conditions[] = "(nome LIKE :search1 OR email LIKE :search2)";
                $params[':search1'] = $search;
                $params[':search2'] = $search;
            }



            // Construir WHERE
            $where = '';

            if (!empty($conditions)) {
                $where .= " WHERE " . implode(" AND ", $conditions);
            }

            // Paginação
            $page = isset($queryParams['page']) ? max(1, (int)$queryParams['page']) : 1;
            $limit = isset($queryParams['limit']) ? min(100, max(1, (int)$queryParams['limit'])) : 10;
            $offset = ($page - 1) * $limit;

            // Ordenação
            $sortBy = $queryParams['sort_by'] ?? 'created_at';
            $sortOrder = strtoupper($queryParams['sort_order'] ?? 'DESC');
            $sortOrder = in_array($sortOrder, ['ASC', 'DESC']) ? $sortOrder : 'DESC';

            // Validar campo de ordenação
            $allowedSortFields = ['id', 'name', 'email', 'role', 'active', 'created_at', 'last_login'];
            if (!in_array($sortBy, $allowedSortFields)) {
                $sortBy = 'created_at';
            }

            $orderBy = "ORDER BY {$sortBy} {$sortOrder}";
            $limitClause = "LIMIT {$limit} OFFSET {$offset}";

            // Buscar usuários filtrados
            $users = User::findAllWithWhere($where . " " . $orderBy . " " . $limitClause, $params);

            // Contar total para paginação
            $totalUsers = User::countWithWhere($where, $params);
            $totalPages = ceil($totalUsers / $limit);

            // Formatar dados para resposta
            $formattedUsers = [];
            foreach ($users as $user) {
                $formattedUsers[] = $this->formatUserForResponse($user);
            }

            return $this->successResponse([
                'users' => $formattedUsers,
                'total' => $totalUsers,
                'page' => $page,
                'totalPages' => $totalPages,
                'perPage' => $limit
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse(500, "Erro ao buscar usuários.", $e->getMessage());
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
        $userData = $this->authMiddleware->handle($headers, ["admin"]);
        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária ou permissão insuficiente.");
        }

        // Validação básica
        $validation = $this->validateUserData($requestData);
        if (!$validation['valid']) {
            return $this->errorResponse(400, $validation['message']);
        }

        // Verificar se email já existe
        if (User::findByEmail($requestData['email'])) {
            return $this->errorResponse(409, "Este email já está em uso por outro usuário.");
        }

        try {
            // Preparar dados para criação
            $userDataToCreate = [
                'name' => trim($requestData['nome']),
                'email' => strtolower(trim($requestData['email'])),
                'password' => password_hash($requestData['senha'], PASSWORD_DEFAULT),
                'role' => $requestData['funcao'],
                'active' => isset($requestData['ativo']) ? ($requestData['ativo'] ? 1 : 0) : 1,
                'phone' => $this->formatPhone($requestData['telefone'] ?? null),
                'permissions' => json_encode($this->getPermissionsForUser($requestData)),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];

            $userId = User::create($userDataToCreate);

            if ($userId) {
                $newUser = User::findById($userId);

                // Notificar usuário criado (opcional)
                $this->notifyUserCreated($newUser, $userData);

                return $this->successResponse(
                    $this->formatUserForResponse($newUser),
                    "Usuário criado com sucesso.",
                    201
                );
            } else {
                return $this->errorResponse(500, "Falha ao criar usuário.");
            }
        } catch (\Exception $e) {
            return $this->errorResponse(500, "Erro interno ao criar usuário.", $e->getMessage());
        }
    }

    /**
     * Exibir detalhes de um usuário específico
     *
     * @param array $headers Cabeçalhos da requisição
     * @param int $userId ID do usuário
     * @return array Resposta JSON
     */
    public function show(array $headers, int $userId): array
    {
        $userData = $this->authMiddleware->handle($headers, ["admin", "gerente"]);
        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária ou permissão insuficiente.");
        }

        try {
            $user = User::findById($userId);
            if (!$user || $user->deleted_at) {
                return $this->errorResponse(404, "Usuário não encontrado.");
            }

            return $this->successResponse($this->formatUserForResponse($user));
        } catch (\Exception $e) {
            return $this->errorResponse(500, "Erro ao buscar usuário.", $e->getMessage());
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
        $userData = $this->authMiddleware->handle($headers, ["admin"]);
        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária ou permissão insuficiente.");
        }

        if (empty($requestData)) {
            return $this->errorResponse(400, "Nenhum dado fornecido para atualização.");
        }

        try {
            $user = User::findById($userId);
            if (!$user || $user->active === '0') {
                return $this->errorResponse(404, "Usuário não encontrado.");
            }

            // Validar dados de atualização
            $validation = $this->validateUserUpdateData($requestData, $userId);
            if (!$validation['valid']) {
                return $this->errorResponse(400, $validation['message']);
            }

            // Preparar dados para atualização
            $updateData = [];

            if (isset($requestData['name'])) {
                $updateData['name'] = trim($requestData['nome']);
            }

            if (isset($requestData['senha']) && !empty($requestData['senha'])) {
                $updateData['password'] = password_hash($requestData['senha'], PASSWORD_DEFAULT);
            }

            if (isset($requestData['funcao'])) {
                $updateData['role'] = $requestData['funcao'];
            }

            if (isset($requestData['ativo'])) {
                $updateData['active'] = $requestData['ativo'] ? 1 : 0;
            }

            if (isset($requestData['telefone'])) {
                $updateData['phone'] = $this->formatPhone($requestData['telefone']);
            }

            if (isset($requestData['permissoes'])) {
                $updateData['permissions'] = json_encode($requestData['permissoes']);
            } elseif (isset($requestData['funcao'])) {
                // Se mudou a função mas não especificou permissões, usar padrão da função
                $updateData['permissions'] = json_encode($this->getDefaultPermissionsForRole($requestData['funcao']));
            }

            $updateData['updated_at'] = date('Y-m-d H:i:s');

            if (User::update($userId, $updateData)) {
                $updatedUser = User::findById($userId);

                return $this->successResponse(
                    $this->formatUserForResponse($updatedUser),
                    "Usuário atualizado com sucesso."
                );
            } else {
                return $this->errorResponse(500, "Falha ao atualizar usuário.");
            }
        } catch (\Exception $e) {
            return $this->errorResponse(500, "Erro interno ao atualizar usuário.", $e->getMessage());
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
        $userData = $this->authMiddleware->handle($headers, ["admin"]);
        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária ou permissão insuficiente.");
        }

        try {
            $user = User::findById($userId);
            if (!$user) {
                return $this->errorResponse(404, "Usuário não encontrado.");
            }

            if ($user->ativo) {
                return $this->errorResponse(400, "Usuário já está ativo.");
            }

            $updateData = [
                'active' => '1',
                'updated_at' => date('Y-m-d H:i:s')
            ];

            if (User::update($userId, $updateData)) {
                $updatedUser = User::findById($userId);

                $this->notificationService->createNotification(
                    'activate_user',
                    'Usuário Ativado',
                    "Seu usuário foi ativado com sucesso.",
                    [$updatedUser->id],
                    '/activate',
                    'user',
                    $user->id,
                    true
                );

                return $this->successResponse(
                    $this->formatUserForResponse($updatedUser),
                    "Usuário ativado com sucesso."
                );
            } else {
                return $this->errorResponse(500, "Falha ao ativar usuário.");
            }
        } catch (\Exception $e) {
            return $this->errorResponse(500, "Erro interno ao ativar usuário.", $e->getMessage());
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
        $userData = $this->authMiddleware->handle($headers, ["admin"]);
        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária ou permissão insuficiente.");
        }

        try {
            $user = User::findById($userId);
            if (!$user) {
                return $this->errorResponse(404, "Usuário não encontrado.");
            }

            if (!$user->active) {
                return $this->errorResponse(400, "Usuário já está inativo.");
            }

            // Verificar se não é o último admin ativo
            if ($user->funcao === 'admin') {
                $activeAdminCount = User::countActiveAdmins();
                if ($activeAdminCount <= 1) {
                    return $this->errorResponse(400, "Não é possível desativar o último administrador.");
                }
            }

            $updateData = [
                'active' => '0',
                'updated_at' => date('Y-m-d H:i:s')
            ];

            if (User::update($userId, $updateData)) {
                $updatedUser = User::findById($userId);

                $this->notificationService->createNotification(
                    'deactivate_user',
                    'Usuário Desativado',
                    "Seu usuário foi desativado com sucesso.",
                    [$updatedUser->id],
                    '/deactivate',
                    'user',
                    $user->id,
                    true
                );

                return $this->successResponse(
                    $this->formatUserForResponse($updatedUser),
                    "Usuário desativado com sucesso."
                );
            } else {
                return $this->errorResponse(500, "Falha ao desativar usuário.");
            }
        } catch (\Exception $e) {
            return $this->errorResponse(500, "Erro interno ao desativar usuário.", $e->getMessage());
        }
    }

    /**
     * Excluir usuário (soft delete)
     *
     * @param array $headers Cabeçalhos da requisição
     * @param int $userId ID do usuário
     * @return array Resposta JSON
     */
    public function destroy(array $headers, int $userId): array
    {
        $userData = $this->authMiddleware->handle($headers, ["admin"]);
        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária ou permissão insuficiente.");
        }

        try {
            $user = User::findById($userId);
            if (!$user || $user->deleted_at) {
                return $this->errorResponse(404, "Usuário não encontrado.");
            }

            // Verificar se não é o último admin
            if ($user->funcao === 'Admin') {
                $activeAdminCount = User::countActiveAdmins();
                if ($activeAdminCount <= 1) {
                    return $this->errorResponse(400, "Não é possível excluir o último administrador.");
                }
            }

            // Verificar se o usuário não está tentando excluir a si mesmo
            if ($userId === $userData->userId) {
                return $this->errorResponse(400, "Você não pode excluir sua própria conta.");
            }

            $updateData = [
                'deleted_at' => date('Y-m-d H:i:s'),
                'ativo' => 0,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            if (User::update($userId, $updateData)) {
                return $this->successResponse(null, "Usuário excluído com sucesso.");
            } else {
                return $this->errorResponse(500, "Falha ao excluir usuário.");
            }
        } catch (\Exception $e) {
            return $this->errorResponse(500, "Erro interno ao excluir usuário.", $e->getMessage());
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
        $userData = $this->authMiddleware->handle($headers, ["admin"]);
        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária ou permissão insuficiente.");
        }

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
                if ($userId === $userData->userId && in_array($action, ['deactivate', 'delete'])) {
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
                            'ativo' => 1,
                            'updated_at' => date('Y-m-d H:i:s')
                        ]);
                        break;

                    case 'deactivate':
                        $success = User::update($userId, [
                            'ativo' => 0,
                            'updated_at' => date('Y-m-d H:i:s')
                        ]);
                        break;

                    case 'delete':
                        $success = User::update($userId, [
                            'deleted_at' => date('Y-m-d H:i:s'),
                            'ativo' => 0,
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
        $userData = $this->authMiddleware->handle($headers, ["admin", "gerente"]);
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
        $userData = $this->authMiddleware->handle($headers, ["admin", "gerente"]);
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
        $userData = $this->authMiddleware->handle($headers, ["admin", "gerente"]);
        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária ou permissão insuficiente.");
        }

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
        $userData = $this->authMiddleware->handle($headers, ["admin"]);
        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária ou permissão insuficiente.");
        }

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
            $user = User::findById($userData->userId);
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

            if (isset($requestData['nome'])) {
                $updateData['nome'] = trim($requestData['nome']);
            }

            if (isset($requestData['telefone'])) {
                $updateData['telefone'] = $this->formatPhone($requestData['telefone']);
            }

            if (isset($requestData['senha']) && !empty($requestData['senha'])) {
                $updateData['senha_hash'] = password_hash($requestData['senha'], PASSWORD_DEFAULT);
            }

            $updateData['updated_at'] = date('Y-m-d H:i:s');

            if (User::update($userData->userId, $updateData)) {
                $updatedUser = User::findById($userData->userId);

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
        if (empty($data['nome'])) {
            return ["valid" => false, "message" => "O nome é obrigatório."];
        }

        if (strlen($data['nome']) < 2) {
            return ["valid" => false, "message" => "O nome deve ter pelo menos 2 caracteres."];
        }

        if (!preg_match('/^[a-zA-ZÀ-ÿ\s]+$/', $data['nome'])) {
            return ["valid" => false, "message" => "O nome deve conter apenas letras e espaços."];
        }

        if (empty($data['email'])) {
            return ["valid" => false, "message" => "O email é obrigatório."];
        }

        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return ["valid" => false, "message" => "Email inválido."];
        }

        if (empty($data['senha'])) {
            return ["valid" => false, "message" => "A senha é obrigatória."];
        }

        if (strlen($data['senha']) < 8) {
            return ["valid" => false, "message" => "A senha deve ter pelo menos 8 caracteres."];
        }

        if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]/', $data['senha'])) {
            return ["valid" => false, "message" => "A senha deve conter pelo menos: 1 letra minúscula, 1 maiúscula, 1 número e 1 caractere especial."];
        }

        if (empty($data['funcao'])) {
            return ["valid" => false, "message" => "A função é obrigatória."];
        }

        if (!in_array($data['funcao'], ['admin', 'gerente', 'vendedor', 'suporte', 'comercial', 'financeiro'])) {
            return ["valid" => false, "message" => "Função inválida."];
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
            if (strlen($data['password']) < 8) {
                return ["valid" => false, "message" => "A password deve ter pelo menos 8 caracteres."];
            }

            if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]/', $data['password'])) {
                return ["valid" => false, "message" => "A password deve conter pelo menos: 1 letra minúscula, 1 maiúscula, 1 número e 1 caractere especial."];
            }
        }

        if (isset($data['funcao'])) {
            if (!in_array($data['funcao'], ['admin', 'gerente', 'vendedor', 'suporte', 'comercial', 'financeiro'])) {
                return ["valid" => false, "message" => "Função inválida."];
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
     * Validar dados de atualização do perfil
     */
    private function validateProfileUpdateData(array $data, int $userId): array
    {
        if (isset($data['nome'])) {
            if (empty($data['nome'])) {
                return ["valid" => false, "message" => "O nome não pode estar vazio."];
            }

            if (strlen($data['nome']) < 2) {
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
        if (isset($data['permissoes']) && is_array($data['permissoes'])) {
            return $data['permissoes'];
        }

        return $this->getDefaultPermissionsForRole($data['funcao']);
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
                'user_created',
                'Conta Criada',
                "Sua conta foi criada no sistema CRM. Bem-vindo(a)!",
                [$user->id],
                '/profile',
                'user',
                $user->id,
                true
            );
        } catch (\Exception $e) {
            error_log("Erro ao enviar notificação de criação de usuário: " . $e->getMessage());
        }
    }

    /**
     * Notificar redefinição de senha
     */
    private function notifyPasswordReset($user, string $temporaryPassword): void
    {
        try {
            $this->notificationService->createNotification(
                'password_reset',
                'Senha Redefinida',
                "Sua senha foi redefinida. Nova senha temporária: {$temporaryPassword}",
                [$user->id],
                '/profile',
                'user',
                $user->id,
                true
            );
        } catch (\Exception $e) {
            error_log("Erro ao enviar notificação de redefinição de senha: " . $e->getMessage());
        }
    }

    /**
     * Resposta de sucesso padronizada
     */
    private function successResponse($data = null, string $message = "Operação realizada com sucesso.", int $code = 200): array
    {
        http_response_code($code);
        $response = ["success" => true, "message" => $message];

        if ($data !== null) {
            $response["data"] = $data;
        }

        return $response;
    }

    /**
     * Resposta de erro padronizada
     */
    private function errorResponse(int $code, string $message, string $details = null): array
    {
        http_response_code($code);
        $response = ["success" => false, "error" => $message];

        if ($details !== null) {
            $response["details"] = $details;
        }

        return $response;
    }
}
