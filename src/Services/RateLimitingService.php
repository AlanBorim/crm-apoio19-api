<?php

namespace Apoio19\Crm\Services;

use Apoio19\Crm\Models\Database;
use \PDO;
use \PDOException;

class RateLimitingService
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
    }

    /**
     * Undocumented function
     *
     * @param string $ip
     * @param string $account
     * @param string $device
     * @return boolean
     */
    public function isAllowed(string $ip, string $account, string $device): bool
    {
        $rules = [
            'ip'      => ['limit' => 20, 'window' => 900, 'block' => 900],
            'account' => ['limit' => 5,  'window' => 300, 'block' => 1800],
            'device'  => ['limit' => 3,  'window' => 120, 'block' => 600],
        ];

        foreach ($rules as $scope => $cfg) {

            $col = $scope === 'ip' ? 'ip_address' : ($scope === 'account' ? 'user_account' : 'device_fp');

            $sql = "SELECT COUNT(*) 
                FROM login_rate_limit
                WHERE $col = :identifier
                  AND failed_at >= DATE_SUB(NOW(), INTERVAL {$cfg['window']} SECOND)";

            $stmt = $this->pdo->prepare($sql);

            $stmt->execute([':identifier' => $$scope]);

            if ((int)$stmt->fetchColumn() >= $cfg['limit']) {
                // Verifica se ainda está no período de bloqueio
                $sql2 = "SELECT MAX(failed_at) 
                     FROM login_rate_limit
                     WHERE $col = :identifier
                       AND failed_at >= DATE_SUB(NOW(), INTERVAL {$cfg['block']} SECOND)";

                $stmt2 = $this->pdo->prepare($sql2);

                $stmt2->execute([':identifier' => $$scope]);

                if ($stmt2->fetchColumn()) {
                    return false; // ainda bloqueado
                }
            }
        }
        return true;
    }

    /**
     * Record an attempt for a specific action from an IP address.
     *
     * @param string $ipAddress The IP address making the request.
     * @param string $actionType A unique identifier for the action being limited.
     * @return bool True on success, false on failure.
     */
    public function recordAttempt(string $ipAddress, string $actionType): bool
    {
        $sql = "INSERT INTO rate_limit_attempts (ip_address, action_type) VALUES (:ip_address, :action_type)";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(":ip_address", $ipAddress);
            $stmt->bindParam(":action_type", $actionType);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Erro ao registrar tentativa de rate limit para {$ipAddress} / {$actionType}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Clean up old rate limit attempts (can be run periodically).
     * 
     * @param int $maxAgeSeconds The maximum age of records to keep (e.g., 86400 for 24 hours).
     * @return int|false The number of deleted rows or false on failure.
     */
    public function cleanupOldAttempts(int $maxAgeSeconds = 86400): int|false
    {
        $sql = "DELETE FROM rate_limit_attempts WHERE attempt_timestamp < DATE_SUB(NOW(), INTERVAL :max_age SECOND)";
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(":max_age", $maxAgeSeconds, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log("Erro ao limpar registros antigos de rate limit: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Convenience method to check and record an attempt in one call.
     *
     * @param string $ipAddress
     * @param string $actionType
     * @param int $limit
     * @param int $period
     * @return bool True if the attempt was allowed and recorded, false otherwise.
     */
    public function checkAndRecord(string $ipAddress, string $actionType, int $limit, int $period): bool
    {

        if ($this->isAllowed($ipAddress, $actionType, $limit, $period)) {
            $this->recordAttempt($ipAddress, $actionType);
            return true;
        } else {
            // Log that the rate limit was exceeded
            AuditLogService::log(
                null,
                "rate_limit_excedido",
                null, // No specific user ID usually for rate limiting checks
                null,
                null,
                ['Message' => "Limite de taxa excedido para ação '{$actionType}'."],
                $ipAddress,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            );
            return false;
        }
    }
}
