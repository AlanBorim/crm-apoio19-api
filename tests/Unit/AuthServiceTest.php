<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Apoio19\Crm\Services\AuthService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class AuthServiceTest extends TestCase
{
    private AuthService $authService;
    private string $testSecret = "test_secret_key_for_auth_service";
    private int $testExpiration = 3600;
    private array $testConfig;

    protected function setUp(): void
    {
        // Use a specific config for testing to avoid relying on global state like $_ENV
        $this->testConfig = [
            "secret" => $this->testSecret,
            "expiration" => $this->testExpiration,
            "algo" => "HS256",
            "issuer" => "Apoio19 CRM Test",
            "audience" => "Apoio19 CRM Test Users"
        ];
        $this->authService = new AuthService($this->testConfig);
        // Reset leeway before each test that might modify it
        JWT::$leeway = 60; 
    }

    public function testGenerateTokenCreatesValidJwt(): void
    {
        $userId = 1;
        $email = "test@example.com";
        $role = "Admin";
        $name = "Test User";

        $token = $this->authService->generateToken($userId, $email, $role, $name);

        $this->assertIsString($token);
        $this->assertNotEmpty($token);

        // Decode the token to verify its payload
        try {
            JWT::$leeway = 0; // Disable leeway for precise checking in this test
            $decoded = JWT::decode($token, new Key($this->testSecret, "HS256"));
            $this->assertIsObject($decoded);
            $this->assertObjectHasProperty("iss", $decoded);
            $this->assertObjectHasProperty("aud", $decoded);
            $this->assertObjectHasProperty("iat", $decoded);
            $this->assertObjectHasProperty("exp", $decoded);
            $this->assertObjectHasProperty("data", $decoded);
            
            $this->assertEquals($this->testConfig["issuer"], $decoded->iss);
            $this->assertEquals($this->testConfig["audience"], $decoded->aud);
            $this->assertIsInt($decoded->iat);
            $this->assertIsInt($decoded->exp);
            $this->assertEquals($decoded->iat + $this->testExpiration, $decoded->exp);

            $this->assertIsObject($decoded->data);
            $this->assertEquals($userId, $decoded->data->userId);
            $this->assertEquals($email, $decoded->data->email);
            $this->assertEquals($role, $decoded->data->role);
            $this->assertEquals($name, $decoded->data->userName);

        } catch (\Exception $e) {
            $this->fail("JWT decoding failed: " . $e->getMessage());
        }
    }

    public function testValidateTokenReturnsDataForValidToken(): void
    {
        $userId = 2;
        $email = "another@test.com";
        $role = "Comercial";
        $name = "Another User";

        // Generate a valid token first
        $token = $this->authService->generateToken($userId, $email, $role, $name);
        $this->assertNotEmpty($token);

        // Validate it
        $validationResult = $this->authService->validateToken($token);

        $this->assertIsObject($validationResult);
        $this->assertEquals($userId, $validationResult->userId);
        $this->assertEquals($email, $validationResult->email);
        $this->assertEquals($role, $validationResult->role);
        $this->assertEquals($name, $validationResult->userName);
    }

    public function testValidateTokenReturnsFalseForInvalidToken(): void
    {
        $invalidToken = "this.is.not.a.valid.token";
        $validationResult = $this->authService->validateToken($invalidToken);
        $this->assertFalse($validationResult);
    }

    public function testValidateTokenReturnsFalseForExpiredToken(): void
    {
        // Generate a token with a very short expiration (e.g., -1 second)
        $issuedAt = time() - 10; // Ensure iat is also in the past
        $payload = [
            "iss" => $this->testConfig["issuer"],
            "aud" => $this->testConfig["audience"],
            "iat" => $issuedAt,
            "exp" => $issuedAt - 1, // Expired in the past
            "data" => [
                "userId" => 3,
                "email" => "expired@test.com",
                "role" => "Financeiro",
                "userName" => "Expired User"
            ]
        ];
        $expiredToken = JWT::encode($payload, $this->testSecret, "HS256");

        // Ensure leeway doesn't interfere with testing expiration
        JWT::$leeway = 0;
        $validationResult = $this->authService->validateToken($expiredToken);
        // The validateToken method catches the ExpiredException and returns false.
        $this->assertFalse($validationResult, "Validation should return false for an expired token.");
    }
    
    protected function tearDown(): void
    {
        // Reset leeway after tests
        JWT::$leeway = 60; 
    }
}

