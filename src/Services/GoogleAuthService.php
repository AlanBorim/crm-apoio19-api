<?php

namespace Apoio19\Crm\Services;

use Google\Client;
use Google\Service\Oauth2;
use Google\Service\Calendar;
use Apoio19\Crm\Models\Database;
use \PDO;
use \PDOException;
use \Exception;

class GoogleAuthService
{
    private Client $client;
    private string $redirectUri;

    public function __construct()
    {
        // Load environment variables (using a simple approach here, consider a library like vlucas/phpdotenv)
        // In a real app, ensure .env is loaded securely
        $envPath = __DIR__ . 

'/../../.env

'; // Assuming .env is in the project root
        if (file_exists($envPath)) {
            $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos(trim($line), 

'#

') === 0) continue;
                list($name, $value) = explode(

'=

', $line, 2);
                $_ENV[trim($name)] = trim($value);
            }
        }

        $clientId = $_ENV[

'GOOGLE_CLIENT_ID

'] ?? 

'SEU_CLIENT_ID_AQUI

';
        $clientSecret = $_ENV[

'GOOGLE_CLIENT_SECRET

'] ?? 

'SEU_CLIENT_SECRET_AQUI

';
        $this->redirectUri = $_ENV[

'GOOGLE_REDIRECT_URI

'] ?? 

'SUA_URI_DE_REDIRECIONAMENTO_AQUI

';

        $this->client = new Client();
        $this->client->setClientId($clientId);
        $this->client->setClientSecret($clientSecret);
        $this->client->setRedirectUri($this->redirectUri);
        $this->client->setAccessType(

'offline

'); // Request refresh token
        $this->client->setPrompt(

'consent

'); // Force consent screen for refresh token
        $this->client->addScope(Calendar::CALENDAR_EVENTS); // Scope for calendar events
        $this->client->addScope(Oauth2::USERINFO_EMAIL); // Scope for user email (optional)
        $this->client->addScope(Oauth2::USERINFO_PROFILE); // Scope for user profile (optional)
    }

    /**
     * Get the Google Client instance.
     *
     * @return Client
     */
    public function getClient(): Client
    {
        return $this->client;
    }

    /**
     * Generate the Google OAuth 2.0 Authorization URL.
     *
     * @return string
     */
    public function createAuthUrl(): string
    {
        return $this->client->createAuthUrl();
    }

    /**
     * Handle the OAuth 2.0 callback, exchange code for tokens, and store them.
     *
     * @param string $authCode The authorization code received from Google.
     * @param int $userId The ID of the CRM user to associate the tokens with.
     * @return bool True on success, false on failure.
     */
    public function handleCallback(string $authCode, int $userId): bool
    {
        try {
            $accessToken = $this->client->fetchAccessTokenWithAuthCode($authCode);
            
            if (isset($accessToken[

'error

'])) {
                throw new Exception(

'Erro ao obter access token: 

' . $accessToken[

'error_description

']);
            }

            // Store the tokens
            return $this->storeTokens($userId, $accessToken);

        } catch (Exception $e) {
            error_log(

'Erro no callback do Google OAuth: 

' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get an authenticated Google Client for a specific user.
     * Handles token retrieval and refresh.
     *
     * @param int $userId
     * @return Client|null Authenticated client or null if no valid token exists.
     */
    public function getAuthenticatedClientForUser(int $userId): ?Client
    {
        $tokens = $this->getTokens($userId);

        if (!$tokens) {
            return null; // User hasn't authenticated or tokens expired without refresh token
        }

        $this->client->setAccessToken($tokens);

        // Check if the access token has expired.
        if ($this->client->isAccessTokenExpired()) {
            // If there's a refresh token, try to refresh the access token.
            $refreshToken = $this->client->getRefreshToken();
            if ($refreshToken) {
                try {
                    $newAccessToken = $this->client->fetchAccessTokenWithRefreshToken($refreshToken);
                    if (isset($newAccessToken[

'error

'])) {
                        error_log(

'Erro ao atualizar token para usuário ID {$userId}: 

' . $newAccessToken[

'error_description

']);
                        // Consider deleting the invalid token record here
                        $this->deleteTokens($userId);
                        return null;
                    }
                    // Store the new tokens (including potentially a new refresh token)
                    $this->storeTokens($userId, $newAccessToken);
                    $this->client->setAccessToken($newAccessToken);
                } catch (Exception $e) {
                    error_log(

'Exceção ao atualizar token para usuário ID {$userId}: 

' . $e->getMessage());
                     // Consider deleting the invalid token record here
                    $this->deleteTokens($userId);
                    return null;
                }
            } else {
                // No refresh token available, user needs to re-authenticate
                error_log(

'Token expirado e sem refresh token para usuário ID {$userId}

');
                $this->deleteTokens($userId); // Clean up expired token without refresh
                return null;
            }
        }

        return $this->client;
    }

    /**
     * Store Google OAuth tokens for a user in the database.
     *
     * @param int $userId
     * @param array $accessToken Access token array from Google Client.
     * @return bool
     */
    private function storeTokens(int $userId, array $accessToken): bool
    {
        $sql = "INSERT INTO google_auth_tokens (usuario_id, access_token, refresh_token, expires_in, scope, token_type, created_at) 
                VALUES (:usuario_id, :access_token, :refresh_token, :expires_in, :scope, :token_type, :created_at)
                ON DUPLICATE KEY UPDATE 
                access_token = VALUES(access_token), 
                refresh_token = VALUES(refresh_token), 
                expires_in = VALUES(expires_in), 
                scope = VALUES(scope), 
                token_type = VALUES(token_type), 
                created_at = VALUES(created_at),
                atualizado_em_ts = NOW()";

        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);

            $refreshToken = $accessToken[

'refresh_token

'] ?? $this->getRefreshTokenFromDb($userId); // Keep existing refresh token if not provided
            $scope = $accessToken[

'scope

'] ?? 

''

;
            $createdAt = $accessToken[

'created

'] ?? time();

            $stmt->bindParam(

':usuario_id

', $userId, PDO::PARAM_INT);
            $stmt->bindParam(

':access_token

', $accessToken[

'access_token

']);
            $stmt->bindParam(

':refresh_token

', $refreshToken);
            $stmt->bindParam(

':expires_in

', $accessToken[

'expires_in

'], PDO::PARAM_INT);
            $stmt->bindParam(

':scope

', $scope);
            $stmt->bindParam(

':token_type

', $accessToken[

'token_type

']);
            $stmt->bindParam(

':created_at

', $createdAt, PDO::PARAM_INT);

            return $stmt->execute();
        } catch (PDOException $e) {
            error_log(

'Erro ao salvar tokens do Google para usuário ID {$userId}: 

' . $e->getMessage());
            return false;
        }
    }

    /**
     * Retrieve Google OAuth tokens for a user from the database.
     *
     * @param int $userId
     * @return array|null Token array or null if not found.
     */
    private function getTokens(int $userId): ?array
    {
        $sql = "SELECT access_token, refresh_token, expires_in, scope, token_type, created_at 
                FROM google_auth_tokens 
                WHERE usuario_id = :usuario_id LIMIT 1";
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(

':usuario_id

', $userId, PDO::PARAM_INT);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result) {
                // Ensure types are correct for Google Client
                $result[

'expires_in

'] = (int)$result[

'expires_in

'];
                $result[

'created

'] = (int)$result[

'created_at

']; 
                unset($result[

'created_at

']); // Remove the original DB column name
                return $result;
            }
        } catch (PDOException $e) {
            error_log(

'Erro ao buscar tokens do Google para usuário ID {$userId}: 

' . $e->getMessage());
        }
        return null;
    }
    
    /**
     * Retrieve only the refresh token for a user from the database.
     *
     * @param int $userId
     * @return string|null Refresh token or null if not found.
     */
    private function getRefreshTokenFromDb(int $userId): ?string
    {
        $sql = "SELECT refresh_token FROM google_auth_tokens WHERE usuario_id = :usuario_id LIMIT 1";
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(

':usuario_id

', $userId, PDO::PARAM_INT);
            $stmt->execute();
            $result = $stmt->fetchColumn();
            return $result ?: null;
        } catch (PDOException $e) {
            error_log(

'Erro ao buscar refresh token do Google para usuário ID {$userId}: 

' . $e->getMessage());
        }
        return null;
    }

    /**
     * Delete Google OAuth tokens for a user from the database.
     *
     * @param int $userId
     * @return bool
     */
    public function deleteTokens(int $userId): bool
    {
        $sql = "DELETE FROM google_auth_tokens WHERE usuario_id = :usuario_id";
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(

':usuario_id

', $userId, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log(

'Erro ao deletar tokens do Google para usuário ID {$userId}: 

' . $e->getMessage());
            return false;
        }
    }
}

