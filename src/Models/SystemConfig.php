<?php

namespace Apoio19\Crm\Models;

use PDO;

class SystemConfig
{

    /**
     * Buscar uma configuração por chave
     */
    public static function get(string $key): ?array
    {
        $pdo = Database::getInstance();
        $sql = "SELECT * FROM system_configs WHERE config_key = :key LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':key', $key);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result && $result['config_type'] === 'json') {
            $result['config_value'] = json_decode($result['config_value'], true);
        }

        return $result ?: null;
    }

    /**
     * Buscar múltiplas configurações por prefixo
     */
    public static function getByPrefix(string $prefix): array
    {
        $pdo = Database::getInstance();
        $sql = "SELECT * FROM system_configs WHERE config_key LIKE :prefix";
        $stmt = $pdo->prepare($sql);
        $likePrefix = $prefix . '%';
        $stmt->bindParam(':prefix', $likePrefix);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($results as &$result) {
            if ($result['config_type'] === 'json') {
                $result['config_value'] = json_decode($result['config_value'], true);
            }
        }

        return $results;
    }

    /**
     * Salvar ou atualizar uma configuração
     */
    public static function set(string $key, $value, string $type = 'string', ?int $userId = null): bool
    {
        $pdo = Database::getInstance();

        // Converter para JSON se necessário
        if ($type === 'json' && is_array($value)) {
            $value = json_encode($value);
        }

        $sql = "INSERT INTO system_configs (config_key, config_value, config_type, updated_by) 
                VALUES (:key, :value, :type, :userId)
                ON DUPLICATE KEY UPDATE 
                    config_value = :valueUpdate,
                    config_type = :typeUpdate,
                    updated_by = :userIdUpdate,
                    updated_at = CURRENT_TIMESTAMP";

        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':key', $key);
        $stmt->bindParam(':value', $value);
        $stmt->bindParam(':type', $type);
        $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
        $stmt->bindParam(':valueUpdate', $value);
        $stmt->bindParam(':typeUpdate', $type);
        $stmt->bindParam(':userIdUpdate', $userId, PDO::PARAM_INT);

        return $stmt->execute();
    }

    /**
     * Deletar uma configuração
     */
    public static function delete(string $key): bool
    {
        $pdo = Database::getInstance();
        $sql = "DELETE FROM system_configs WHERE config_key = :key";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':key', $key);

        return $stmt->execute();
    }

    /**
     * Buscar todas as configurações de layout
     */
    public static function getLayoutConfig(): array
    {
        $configs = self::getByPrefix('layout.');

        $result = [
            'nomeEmpresa' => 'Apoio19 CRM',
            'logo' => '/logo.png',
            'logoIcon' => '/logo-icon.png',
            'corPrimaria' => '#f97316',
            'tema' => 'light',
            'configuracoesDashboard' => [
                'layout' => 'grid',
                'widgets' => ['leads', 'propostas', 'faturamento', 'tarefas']
            ]
        ];

        foreach ($configs as $config) {
            $key = str_replace('layout.', '', $config['config_key']);
            if ($key === 'dashboard') {
                $result['configuracoesDashboard'] = $config['config_value'];
            } elseif ($key === 'logo_icon') {
                // Mapear logo_icon do banco para logoIcon no retorno
                $result['logoIcon'] = $config['config_value'];
            } else {
                $result[$key] = $config['config_value'];
            }
        }

        return $result;
    }

    /**
     * Salvar todas as configurações de layout
     */
    public static function setLayoutConfig(array $config, ?int $userId = null): bool
    {
        $pdo = Database::getInstance();

        try {
            $pdo->beginTransaction();

            if (isset($config['nomeEmpresa'])) {
                self::set('layout.nomeEmpresa', $config['nomeEmpresa'], 'string', $userId);
            }

            if (isset($config['logo'])) {
                self::set('layout.logo', $config['logo'], 'string', $userId);
            }

            if (isset($config['logoIcon'])) {
                self::set('layout.logo_icon', $config['logoIcon'], 'string', $userId);
            }

            if (isset($config['corPrimaria'])) {
                self::set('layout.corPrimaria', $config['corPrimaria'], 'string', $userId);
            }

            if (isset($config['tema'])) {
                self::set('layout.tema', $config['tema'], 'string', $userId);
            }

            if (isset($config['configuracoesDashboard'])) {
                self::set('layout.dashboard', $config['configuracoesDashboard'], 'json', $userId);
            }

            $pdo->commit();
            return true;
        } catch (\Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Erro ao salvar configurações de layout: " . $e->getMessage());
            return false;
        }
    }
}
