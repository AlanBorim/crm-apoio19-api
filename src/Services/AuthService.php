<?php

namespace Apoio19\Crm\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use \UnexpectedValueException;

class AuthService
{
    private string $secretKey;
    private int $expirationTime;
    private string $algo;
    private string $issuer;
    private string $audience;

    /**
     * Constructor.
     *
     * @param array|null $config Optional configuration array. If null, loads from config/jwt.php.
     */
    public function __construct(?array $config = null)
    {
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
     * Generate a JWT token for a user.
     *
     * @param int $userId User ID.
     * @param string $email User email.
     * @param string $role User role.
     * @param string $name User name.
     * @return string|false The generated JWT token or false on failure.
     */
    public function generateToken(int $userId, string $email, string $role, string $name): string|false
    {
        $issuedAt = time();
        $expiration = $issuedAt + $this->expirationTime;

        $payload = [
            "iss" => $this->issuer,
            "aud" => $this->audience,
            "iat" => $issuedAt,
            "exp" => $expiration,
            "data" => [
                "userId" => $userId,
                "email" => $email,
                "role" => $role,
                "userName" => $name
            ]
        ];

        try {
            return JWT::encode($payload, $this->secretKey, $this->algo);
        } catch (\Exception $e) {
            error_log("Erro ao gerar token JWT: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Validate a JWT token.
     *
     * @param string $token The JWT token to validate.
     * @return object|false The decoded payload data on success, false on failure.
     */
    public function validateToken(string $token): object|false
    {
        if (empty($token)) {
            return false;
        }

        try {
            // NOTE: Removed internal setting of JWT::$leeway = 60;
            // Leeway should be managed by the caller (e.g., in tests) if needed.
            $decoded = JWT::decode($token, new Key($this->secretKey, $this->algo));

            // Optional: Add checks for issuer and audience if needed
            // if ($decoded->iss !== $this->issuer || $decoded->aud !== $this->audience) {
            //     error_log("Erro na validação do JWT: Issuer ou Audience inválido.");
            //     return false;
            // }

            return $decoded->data; // Return only the user data part

        } catch (ExpiredException $e) {
            error_log("Erro na validação do JWT: Expired token");
            return false; // Explicitly return false on ExpiredException
        } catch (SignatureInvalidException $e) {
            error_log("Erro na validação do JWT: Signature verification failed");
            return false;
        } catch (UnexpectedValueException $e) {
             error_log("Erro na validação do JWT: " . $e->getMessage()); // Catches wrong number of segments, etc.
             return false;
        } catch (\Exception $e) {
            error_log("Erro inesperado na validação do JWT: " . $e->getMessage());
            return false;
        }
    }
}

