<?php

namespace Apoio19\Crm\Models;

use PDO;
use PDOException;
use Apoio19\Crm\Models\Database;

// Placeholder for Database connection logic
// In a real application, use PDO or an ORM (like Eloquent, Doctrine)
class User
{
    public int $id;
    public string $nome;
    public string $email;
    public string $senha_hash;
    public string $funcao;
    public string $ativo;
    public ?string $token_2fa_secreto;
    public string $criado_em;
    public string $atualizado_em;

    /**
     * Criar novo usuário
     *
     * @param array $data Dados do usuário
     * @return int|false ID do usuário criado ou false em caso de erro
     */
    public static function create(array $data)
    {
        try {
            $pdo = Database::getInstance();

            $sql = "INSERT INTO users (nome, email, senha_hash, funcao, ativo, telefone, permissoes, created_at, updated_at) 
                    VALUES (:nome, :email, :senha_hash, :funcao, :ativo, :telefone, :permissoes, :created_at, :updated_at)";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':nome' => $data['nome'],
                ':email' => $data['email'],
                ':senha_hash' => $data['senha_hash'],
                ':funcao' => $data['funcao'],
                ':ativo' => $data['ativo'] ?? 1,
                ':telefone' => $data['telefone'] ?? null,
                ':permissoes' => $data['permissoes'] ?? null,
                ':created_at' => $data['created_at'],
                ':updated_at' => $data['updated_at']
            ]);

            return $pdo->lastInsertId();
        } catch (PDOException $e) {
            error_log("Erro ao criar usuário: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Buscar usuário por ID
     *
     * @param int $id ID do usuário
     * @return object|null Dados do usuário ou null se não encontrado
     */
    public static function findById(int $id): ?object
    {
        try {
            $pdo = Database::getInstance();

            $sql = "SELECT * FROM users WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':id' => $id]);

            $result = $stmt->fetch(PDO::FETCH_OBJ);
            return $result ?: null;
        } catch (PDOException $e) {
            error_log("Erro ao buscar usuário por ID: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Buscar usuário por email
     *
     * @param string $email Email do usuário
     * @return object|null Dados do usuário ou null se não encontrado
     */
    public static function findByEmail(string $email): ?object
    {
        try {
            $pdo = Database::getInstance();

            $sql = "SELECT * FROM users WHERE email = :email LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':email' => $email]);
            $userData = $stmt->fetch();

            if ($userData) {
                $user = new self();
                $user->id = (int)$userData["id"];
                $user->nome = $userData["name"];
                $user->email = $userData["email"];
                $user->senha_hash = $userData["password"];
                $user->funcao  = $userData["role"];
                $user->ativo = $userData["active"];
                $user->criado_em = $userData["created_at"];
                $user->atualizado_em = $userData["updated_at"];
                
            }
            return $user ?? null;
        } catch (PDOException $e) {
            error_log("Erro ao buscar usuário por email: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Buscar todos os usuários com condições WHERE
     *
     * @param string $where Cláusula WHERE
     * @param array $params Parâmetros para a query
     * @return array Lista de usuários
     */
    public static function findAllWithWhere(string $where = "", array $params = []): array
    {
        try {
            $pdo = Database::getInstance();

            $sql = "SELECT * FROM users " . $where;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            return $stmt->fetchAll(PDO::FETCH_OBJ);
        } catch (PDOException $e) {
            error_log("Erro ao buscar usuários: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Contar usuários com condições WHERE
     *
     * @param string $where Cláusula WHERE
     * @param array $params Parâmetros para a query
     * @return int Número de usuários
     */
    public static function countWithWhere(string $where = "", array $params = []): int
    {
        try {
            $pdo = Database::getInstance();

            $sql = "SELECT COUNT(*) as total FROM users " . $where;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $result = $stmt->fetch(PDO::FETCH_OBJ);
            return (int) $result->total;
        } catch (PDOException $e) {
            error_log("Erro ao contar usuários: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Atualizar usuário
     *
     * @param int $id ID do usuário
     * @param array $data Dados para atualização
     * @return bool Sucesso da operação
     */
    public static function update(int $id, array $data): bool
    {
        try {
            $pdo = Database::getInstance();

            // Construir query dinamicamente baseada nos campos fornecidos
            $fields = [];
            $params = [':id' => $id];

            foreach ($data as $key => $value) {
                $fields[] = "$key = :$key";
                $params[":$key"] = $value;
            }

            if (empty($fields)) {
                return false;
            }

            $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = :id";
            $stmt = $pdo->prepare($sql);

            return $stmt->execute($params);
        } catch (PDOException $e) {
            error_log("Erro ao atualizar usuário: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Excluir usuário (hard delete)
     *
     * @param int $id ID do usuário
     * @return bool Sucesso da operação
     */
    public static function delete(int $id): bool
    {
        try {
            $pdo = Database::getInstance();

            $sql = "DELETE FROM users WHERE id = :id";
            $stmt = $pdo->prepare($sql);

            return $stmt->execute([':id' => $id]);
        } catch (PDOException $e) {
            error_log("Erro ao excluir usuário: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Contar administradores ativos
     *
     * @return int Número de administradores ativos
     */
    public static function countActiveAdmins(): int
    {
        try {
            $pdo = Database::getInstance();

            $sql = "SELECT COUNT(*) as total FROM users WHERE funcao = 'Admin' AND ativo = 1 AND deleted_at IS NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();

            $result = $stmt->fetch(PDO::FETCH_OBJ);
            return (int) $result->total;
        } catch (PDOException $e) {
            error_log("Erro ao contar administradores ativos: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Obter estatísticas de usuários
     *
     * @return array Estatísticas dos usuários
     */
    public static function getStats(): array
    {
        try {
            $pdo = Database::getInstance();

            // Total de usuários (excluindo deletados)
            $sql = "SELECT COUNT(*) as total FROM users WHERE deleted_at IS NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $total = $stmt->fetch(PDO::FETCH_OBJ)->total;

            // Usuários ativos
            $sql = "SELECT COUNT(*) as active FROM users WHERE ativo = 1 AND deleted_at IS NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $active = $stmt->fetch(PDO::FETCH_OBJ)->active;

            // Usuários inativos
            $sql = "SELECT COUNT(*) as inactive FROM users WHERE ativo = 0 AND deleted_at IS NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $inactive = $stmt->fetch(PDO::FETCH_OBJ)->inactive;

            // Usuários por função
            $sql = "SELECT funcao, COUNT(*) as count FROM users WHERE deleted_at IS NULL GROUP BY funcao";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $roleStats = $stmt->fetchAll(PDO::FETCH_OBJ);

            $byRole = [];
            foreach ($roleStats as $stat) {
                $byRole[$stat->funcao] = (int) $stat->count;
            }

            // Usuários com login recente (últimos 7 dias)
            $sql = "SELECT COUNT(*) as recent FROM users WHERE ultimo_login >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND deleted_at IS NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $recentLogins = $stmt->fetch(PDO::FETCH_OBJ)->recent;

            return [
                'total' => (int) $total,
                'active' => (int) $active,
                'inactive' => (int) $inactive,
                'byRole' => $byRole,
                'recentLogins' => (int) $recentLogins
            ];
        } catch (PDOException $e) {
            error_log("Erro ao obter estatísticas de usuários: " . $e->getMessage());
            return [
                'total' => 0,
                'active' => 0,
                'inactive' => 0,
                'byRole' => [],
                'recentLogins' => 0
            ];
        }
    }

    /**
     * Verificar se usuário tem permissão específica
     *
     * @param int $userId ID do usuário
     * @param string $permission Permissão a verificar
     * @return bool Se o usuário tem a permissão
     */
    public static function hasPermission(int $userId, string $permission): bool
    {
        try {
            $user = self::findById($userId);
            if (!$user || !$user->ativo || $user->deleted_at) {
                return false;
            }

            // Administradores têm todas as permissões
            if ($user->funcao === 'Admin') {
                return true;
            }

            $userPermissions = [];
            if (!empty($user->permissoes)) {
                $userPermissions = is_string($user->permissoes) ?
                    json_decode($user->permissoes, true) : $user->permissoes;
            }

            // Se tem permissão 'all', pode tudo
            if (in_array('all', $userPermissions)) {
                return true;
            }

            // Verificar se tem a permissão específica
            return in_array($permission, $userPermissions);
        } catch (\Exception $e) {
            error_log("Erro ao verificar permissão: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Atualizar último login do usuário
     *
     * @param int $userId ID do usuário
     * @return bool Sucesso da operação
     */
    public static function updateLastLogin(int $userId): bool
    {
        try {
            return self::update($userId, [
                'ultimo_login' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            error_log("Erro ao atualizar último login: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Buscar usuários por função
     *
     * @param string $role Função do usuário
     * @param bool $activeOnly Se deve buscar apenas usuários ativos
     * @return array Lista de usuários
     */
    public static function findByRole(string $role, bool $activeOnly = true): array
    {
        try {
            $pdo = Database::getInstance();

            $conditions = ["funcao = :role", "deleted_at IS NULL"];
            $params = [':role' => $role];

            if ($activeOnly) {
                $conditions[] = "ativo = 1";
            }

            $sql = "SELECT * FROM users WHERE " . implode(" AND ", $conditions) . " ORDER BY nome";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            return $stmt->fetchAll(PDO::FETCH_OBJ);
        } catch (PDOException $e) {
            error_log("Erro ao buscar usuários por função: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Buscar usuários ativos
     *
     * @return array Lista de usuários ativos
     */
    public static function findActive(): array
    {
        try {
            $pdo = Database::getInstance();

            $sql = "SELECT * FROM users WHERE ativo = 1 AND deleted_at IS NULL ORDER BY nome";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_OBJ);
        } catch (PDOException $e) {
            error_log("Erro ao buscar usuários ativos: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Verificar se email já existe (excluindo um ID específico)
     *
     * @param string $email Email a verificar
     * @param int|null $excludeId ID para excluir da verificação
     * @return bool Se o email já existe
     */
    public static function emailExists(string $email, ?int $excludeId = null): bool
    {
        try {
            $pdo = Database::getInstance();

            $conditions = ["email = :email", "deleted_at IS NULL"];
            $params = [':email' => $email];

            if ($excludeId !== null) {
                $conditions[] = "id != :exclude_id";
                $params[':exclude_id'] = $excludeId;
            }

            $sql = "SELECT COUNT(*) as count FROM users WHERE " . implode(" AND ", $conditions);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $result = $stmt->fetch(PDO::FETCH_OBJ);
            return (int) $result->count > 0;
        } catch (PDOException $e) {
            error_log("Erro ao verificar se email existe: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Buscar usuários com login recente
     *
     * @param int $days Número de dias para considerar como recente
     * @return array Lista de usuários com login recente
     */
    public static function findWithRecentLogin(int $days = 7): array
    {
        try {
            $pdo = Database::getInstance();

            $sql = "SELECT * FROM users 
                    WHERE ultimo_login >= DATE_SUB(NOW(), INTERVAL :days DAY) 
                    AND deleted_at IS NULL 
                    ORDER BY ultimo_login DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([':days' => $days]);

            return $stmt->fetchAll(PDO::FETCH_OBJ);
        } catch (PDOException $e) {
            error_log("Erro ao buscar usuários com login recente: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Obter permissões padrão por função
     *
     * @param string $role Função do usuário
     * @return array Permissões padrão
     */
    public static function getDefaultPermissionsForRole(string $role): array
    {
        $rolePermissions = [
            'Admin' => ['all'],
            'Gerente' => [
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
            'Vendedor' => [
                'leads.read',
                'leads.write',
                'propostas.read',
                'propostas.write',
                'kanban.read',
                'kanban.write',
                'whatsapp.read',
                'whatsapp.write'
            ],
            'Suporte' => [
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
     * Validar senha do usuário
     *
     * @param int $userId ID do usuário
     * @param string $password Senha a validar
     * @return bool Se a senha está correta
     */
    public static function validatePassword(int $userId, string $password): bool
    {
        try {
            $user = self::findById($userId);
            if (!$user) {
                return false;
            }

            return password_verify($password, $user->senha_hash);
        } catch (\Exception $e) {
            error_log("Erro ao validar senha: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Buscar usuários para seleção (ID e nome apenas)
     *
     * @param bool $activeOnly Se deve buscar apenas usuários ativos
     * @return array Lista simplificada de usuários
     */
    public static function findForSelection(bool $activeOnly = true): array
    {
        try {
            $pdo = Database::getInstance();

            $conditions = ["deleted_at IS NULL"];
            $params = [];

            if ($activeOnly) {
                $conditions[] = "ativo = 1";
            }

            $sql = "SELECT id, nome, email, funcao FROM users WHERE " . implode(" AND ", $conditions) . " ORDER BY nome";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            return $stmt->fetchAll(PDO::FETCH_OBJ);
        } catch (PDOException $e) {
            error_log("Erro ao buscar usuários para seleção: " . $e->getMessage());
            return [];
        }
    }
}
