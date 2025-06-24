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
     * Check if an action from a specific IP is allowed based on rate limits.
     *
     * @param string $ipAddress The IP address making the request.
     * @param string $actionType A unique identifier for the action being limited (e.g., "login_attempt", "api_general").
     * @param int $limit The maximum number of allowed attempts.
     * @param int $period The time period in seconds (e.g., 3600 for 1 hour).
     * @return bool True if the action is allowed, false if the limit is exceeded.
     */
    public function isAllowed(string $ipAddress, string $actionType, int $limit, int $period): bool
    {
        $sql = "SELECT COUNT(*) 
                FROM rate_limit_attempts 
                WHERE ip_address = :ip_address 
                  AND action_type = :action_type 
                  AND attempt_timestamp >= DATE_SUB(NOW(), INTERVAL :period SECOND)";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(":ip_address", $ipAddress);
            $stmt->bindParam(":action_type", $actionType);
            $stmt->bindParam(":period", $period, PDO::PARAM_INT);
            $stmt->execute();
            
            $count = (int)$stmt->fetchColumn();
            
            return $count < $limit;

        } catch (PDOException $e) {
            error_log("Erro ao verificar rate limit para {$ipAddress} / {$actionType}: " . $e->getMessage());
            // Fail open or closed? Failing open might be safer in case of DB issues, 
            // but failing closed is stricter for security. Let's fail open for now.
            return true; 
        }
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
                "rate_limit_excedido", 
                null, // No specific user ID usually for rate limiting checks
                null, 
                null, 
                "Limite de taxa excedido para ação '{$actionType}'.", 
                $ipAddress
            );
            return false;
        }
     }
}

