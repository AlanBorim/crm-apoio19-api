<?php

namespace Apoio19\Crm\Models;

use PDO;
use PDOException;
use Apoio19\Crm\Models\Database;

class SystemToken
{
    public int $id;
    public string $name;
    public string $token;
    public int $user_id;
    public string $permissions;
    public int $active;
    public string $created_at;
    public string $updated_at;
    public ?string $last_used_at = null;

    /**
     * Buscar todos os tokens de sistema com informações do usuário associado
     *
     * @return array
     */
    public static function findAll(): array
    {
        try {
            $pdo = Database::getInstance();
            $sql = "SELECT t.*, u.name as user_name, u.email as user_email 
                    FROM system_tokens t 
                    JOIN users u ON t.user_id = u.id 
                    ORDER BY t.created_at DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Erro ao buscar tokens de sistema: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Buscar token de sistema por ID
     *
     * @param int $id
     * @return array|null
     */
    public static function findById(int $id): ?array
    {
        try {
            $pdo = Database::getInstance();
            $sql = "SELECT * FROM system_tokens WHERE id = :id LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':id' => $id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ?: null;
        } catch (PDOException $e) {
            error_log("Erro ao buscar token de sistema por ID: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Buscar token de sistema ativo por string do token
     *
     * @param string $token
     * @return array|null
     */
    public static function findByToken(string $token): ?array
    {
        try {
            $pdo = Database::getInstance();
            $sql = "SELECT * FROM system_tokens WHERE token = :token AND active = 1 LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':token' => $token]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ?: null;
        } catch (PDOException $e) {
            error_log("Erro ao buscar token de sistema por string: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Criar novo token de sistema
     *
     * @param array $data Contém ['name', 'user_id', 'permissions']
     * @return array|false O registro recém-criado (incluindo o token gerado) ou false em caso de erro
     */
    public static function create(array $data)
    {
        try {
            $pdo = Database::getInstance();

            // Gerar token criptograficamente seguro e único com prefixo identificador
            $tokenString = 'crm_sys_' . bin2hex(random_bytes(32));

            // Garantir que as permissões estejam em formato string (JSON)
            $permissionsJson = is_array($data['permissions']) 
                ? json_encode($data['permissions']) 
                : $data['permissions'];

            $sql = "INSERT INTO system_tokens (name, token, user_id, permissions, active, created_at, updated_at) 
                    VALUES (:name, :token, :user_id, :permissions, :active, NOW(), NOW())";

            $stmt = $pdo->prepare($sql);
            $success = $stmt->execute([
                ':name' => $data['name'],
                ':token' => $tokenString,
                ':user_id' => (int)$data['user_id'],
                ':permissions' => $permissionsJson,
                ':active' => isset($data['active']) ? (int)$data['active'] : 1
            ]);

            if ($success) {
                $id = $pdo->lastInsertId();
                return self::findById((int)$id);
            }
            return false;
        } catch (PDOException $e) {
            error_log("Erro ao criar token de sistema: " . $e->getMessage());
            return false;
        } catch (\Exception $e) {
            error_log("Erro genérico ao gerar token de sistema: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Atualizar dados de um token de sistema existente
     *
     * @param int $id
     * @param array $data
     * @return bool
     */
    public static function update(int $id, array $data): bool
    {
        try {
            $pdo = Database::getInstance();

            $fields = [];
            $params = [':id' => $id];

            // Atualizar campos selecionados
            if (isset($data['name'])) {
                $fields[] = "name = :name";
                $params[':name'] = $data['name'];
            }
            if (isset($data['active'])) {
                $fields[] = "active = :active";
                $params[':active'] = (int)$data['active'];
            }
            if (isset($data['permissions'])) {
                $fields[] = "permissions = :permissions";
                $params[':permissions'] = is_array($data['permissions']) 
                    ? json_encode($data['permissions']) 
                    : $data['permissions'];
            }
            if (isset($data['user_id'])) {
                $fields[] = "user_id = :user_id";
                $params[':user_id'] = (int)$data['user_id'];
            }

            if (empty($fields)) {
                return false;
            }

            $sql = "UPDATE system_tokens SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            error_log("Erro ao atualizar token de sistema: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Deletar/revogar um token de sistema
     *
     * @param int $id
     * @return bool
     */
    public static function delete(int $id): bool
    {
        try {
            $pdo = Database::getInstance();
            $sql = "DELETE FROM system_tokens WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            return $stmt->execute([':id' => $id]);
        } catch (PDOException $e) {
            error_log("Erro ao deletar token de sistema: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Atualizar a data e hora do último uso do token
     *
     * @param int $id
     * @return bool
     */
    public static function updateLastUsed(int $id): bool
    {
        try {
            $pdo = Database::getInstance();
            $sql = "UPDATE system_tokens SET last_used_at = NOW() WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            return $stmt->execute([':id' => $id]);
        } catch (PDOException $e) {
            error_log("Erro ao atualizar último uso do token: " . $e->getMessage());
            return false;
        }
    }
}
