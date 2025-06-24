<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Apoio19\Crm\Models\User;
use Apoio19\Crm\Models\Database;
use Apoio19\Crm\Controllers\AuthController;
use \PDO;

// IMPORTANT: This test assumes a configured test database or 
// that it runs against the main DB with test data that will be cleaned up.
// Using a dedicated test database and transactions/rollbacks is highly recommended.

class AuthControllerIntegrationTest extends TestCase
{
    private static PDO $pdo;
    private AuthController $authController;
    private static string $testUserEmail = "integration.test@example.com";
    private static string $testUserPassword = "password123";
    private static int $testUserId;

    public static function setUpBeforeClass(): void
    {
        // Establish connection (ideally to a test database)
        // Use environment variables defined in phpunit.xml or a test .env file
        // For this example, we assume Database::getInstance() connects correctly.
        self::$pdo = Database::getInstance(); 

        // Clean up any previous test user
        self::deleteTestUser();

        // Create a test user directly in the database
        $nome = "Integration Test User";
        $senha_hash = password_hash(self::$testUserPassword, PASSWORD_DEFAULT);
        $funcao = "Comercial";
        $ativo = true;

        $sql = "INSERT INTO usuarios (nome, email, senha_hash, funcao, ativo) VALUES (:nome, :email, :senha_hash, :funcao, :ativo)";
        $stmt = self::$pdo->prepare($sql);
        $stmt->bindParam(":nome", $nome);
        $stmt->bindParam(":email", self::$testUserEmail);
        $stmt->bindParam(":senha_hash", $senha_hash);
        $stmt->bindParam(":funcao", $funcao);
        $stmt->bindParam(":ativo", $ativo, PDO::PARAM_BOOL);
        $stmt->execute();
        self::$testUserId = (int)self::$pdo->lastInsertId();
        
        // Set JWT secret for testing
        $_ENV["JWT_SECRET"] = $_ENV["JWT_SECRET"] ?? "test_secret_integration";
        $_ENV["JWT_EXPIRATION"] = $_ENV["JWT_EXPIRATION"] ?? "3600";
    }

    protected function setUp(): void
    {
        $this->authController = new AuthController();
        // Reset rate limiting for the test IP before each test
        $ip = "127.0.0.1"; // Simulate request IP
        $_SERVER["REMOTE_ADDR"] = $ip;
        self::$pdo->exec("DELETE FROM rate_limit_attempts WHERE ip_address = ".$this->pdoMock->quote($ip));
    }

    public function testLoginSuccessWithValidCredentials(): void
    {
        $requestData = [
            "email" => self::$testUserEmail,
            "senha" => self::$testUserPassword
        ];

        // Simulate HTTP response code setting (PHPUnit doesn't handle this directly)
        // We check the return array structure instead.
        $response = $this->authController->login($requestData);

        $this->assertArrayHasKey("token", $response);
        $this->assertIsString($response["token"]);
        $this->assertNotEmpty($response["token"]);
        $this->assertArrayHasKey("user", $response);
        $this->assertEquals(self::$testUserId, $response["user"]["id"]);
        $this->assertEquals(self::$testUserEmail, $response["user"]["email"]);
        
        // Check audit log (optional but good)
        $logStmt = self::$pdo->prepare("SELECT * FROM audit_logs WHERE acao = :acao AND usuario_id = :uid ORDER BY timestamp DESC LIMIT 1");
        $logStmt->execute([":acao" => "login_sucesso", ":uid" => self::$testUserId]);
        $logEntry = $logStmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($logEntry, "Audit log for successful login not found.");
    }

    public function testLoginFailureWithInvalidPassword(): void
    {
        $requestData = [
            "email" => self::$testUserEmail,
            "senha" => "wrongpassword"
        ];

        $response = $this->authController->login($requestData);

        $this->assertArrayHasKey("error", $response);
        $this->assertEquals("Credenciais inválidas.", $response["error"]);
        $this->assertArrayNotHasKey("token", $response);
        
        // Check audit log
        $logStmt = self::$pdo->prepare("SELECT * FROM audit_logs WHERE acao = :acao AND usuario_id = :uid ORDER BY timestamp DESC LIMIT 1");
        $logStmt->execute([":acao" => "login_falha", ":uid" => self::$testUserId]);
        $logEntry = $logStmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($logEntry, "Audit log for failed login (wrong password) not found.");
        $this->assertStringContainsString("Senha incorreta", $logEntry["detalhes"]);
    }
    
    public function testLoginFailureWithNonExistentUser(): void
    {
        $requestData = [
            "email" => "nonexistent@example.com",
            "senha" => "anypassword"
        ];

        $response = $this->authController->login($requestData);

        $this->assertArrayHasKey("error", $response);
        $this->assertEquals("Credenciais inválidas ou usuário inativo.", $response["error"]);
        $this->assertArrayNotHasKey("token", $response);
        
        // Check audit log (usuario_id will be NULL here)
        $logStmt = self::$pdo->prepare("SELECT * FROM audit_logs WHERE acao = :acao AND detalhes LIKE :email ORDER BY timestamp DESC LIMIT 1");
        $logStmt->execute([":acao" => "login_falha", ":email" => "%nonexistent@example.com%"]);
        $logEntry = $logStmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($logEntry, "Audit log for failed login (non-existent user) not found.");
        $this->assertNull($logEntry["usuario_id"]);
    }
    
    public function testLoginRateLimitExceeded(): void
    {
         $requestData = [
            "email" => self::$testUserEmail,
            "senha" => "wrongpassword"
        ];
        $limit = 5; // Match the limit in AuthController
        
        // Simulate exceeding the limit
        for ($i = 0; $i < $limit; $i++) {
            $this->authController->login($requestData); // These attempts will fail and be recorded
        }
        
        // The next attempt should be blocked
        $response = $this->authController->login($requestData);
        
        $this->assertArrayHasKey("error", $response);
        $this->assertEquals("Muitas tentativas de login. Tente novamente mais tarde.", $response["error"]);
        
        // Check audit log for rate limit block
        $logStmt = self::$pdo->prepare("SELECT * FROM audit_logs WHERE acao = :acao AND ip_address = :ip ORDER BY timestamp DESC LIMIT 1");
        $logStmt->execute([":acao" => "login_bloqueado_rate_limit", ":ip" => $_SERVER["REMOTE_ADDR"]]);
        $logEntry = $logStmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($logEntry, "Audit log for rate limit block not found.");
    }

    public static function tearDownAfterClass(): void
    {
        // Clean up the test user
        self::deleteTestUser();
        // Clean up rate limit entries for the test IP
        self::$pdo->exec("DELETE FROM rate_limit_attempts WHERE ip_address = ".$this->pdoMock->quote($_SERVER["REMOTE_ADDR"] ?? "127.0.0.1"));
        self::$pdo = null; // Close connection
    }

    private static function deleteTestUser(): void
    {
        if (self::$pdo) {
            $stmt = self::$pdo->prepare("DELETE FROM usuarios WHERE email = :email");
            $stmt->bindParam(":email", self::$testUserEmail);
            $stmt->execute();
        }
    }
}

