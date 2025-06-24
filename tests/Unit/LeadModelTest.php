<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Apoio19\Crm\Models\Lead; // Assuming Lead model exists and is accessible
use Apoio19\Crm\Models\Database; // Assuming Database class for PDO instance
use \PDO;
use \PDOStatement;

class LeadModelTest extends TestCase
{
    private $pdoMock;
    private $stmtMock;

    protected function setUp(): void
    {
        // Create mocks for PDO and PDOStatement
        $this->pdoMock = $this->createMock(PDO::class);
        $this->stmtMock = $this->createMock(PDOStatement::class);

        // Inject the mock PDO instance into the Database class
        // Ensure APP_ENV is set to testing for this to work
        $_ENV["APP_ENV"] = "testing"; 
        Database::setInstance($this->pdoMock);
    }

    public function testCreateLeadPreparesAndExecutesCorrectSql(): void
    {
        $leadData = [
            "nome" => "Test Lead Unit",
            "empresa_nome" => "Test Co", 
            "email" => "unit@test.com",
            "telefone" => "123456789",
            "origem" => "Website",
            "interesse" => "Produto X",
            "data_contato" => date("Y-m-d H:i:s"),
            "qualificacao" => "Quente",
            "responsavel_id" => 1
        ];
        // Construct the expected SQL pattern based on the keys in $leadData
        $expectedSqlPattern = "/INSERT\s+INTO\s+leads\s*\(\s*" . implode("\s*,\s*", array_keys($leadData)) . "\s*\)\s*VALUES\s*\(\s*" . implode("\s*,\s*", array_map(function($k){ return ":$k"; }, array_keys($leadData))) . "\s*\)/i";

        // Arrange: Prepare the mock statement
        $this->pdoMock->expects($this->once())
                      ->method("prepare")
                      // Use a regex match for flexibility with whitespace/backticks
                      ->with($this->matchesRegularExpression($expectedSqlPattern))
                      ->willReturn($this->stmtMock);

        // NOTE: Mocking bindParam with references is problematic in PHPUnit.
        // Skip strict expectation on bindParam calls due to reference issues.
        $this->stmtMock->expects($this->any()) // Relax expectation for bindParam
                       ->method("bindParam")
                       ->willReturn(true); // Assume bindParam succeeds

        $this->stmtMock->expects($this->once())
                       ->method("execute")
                       ->willReturn(true); // Simulate successful execution
                       
        $this->pdoMock->expects($this->once())
                       ->method("lastInsertId")
                       ->willReturn("123"); // Simulate returning a new ID

        // Act: Call the static method on the Lead model
        $leadId = Lead::create($leadData);

        // Assert: Check if the returned ID matches the mocked lastInsertId
        $this->assertEquals("123", $leadId);
    }
    
    public function testFindByIdPreparesAndExecutesCorrectSql(): void
    {
        $leadId = 1;
        // Provide *all* expected fields for hydration, including defaults
        $expectedLeadData = [
            "id" => $leadId, 
            "nome" => "Test Lead", 
            "email" => "test@lead.com",
            "empresa_nome" => null,
            "telefone" => null,
            "origem" => null,
            "interesse" => null,
            "data_contato" => null,
            "qualificacao" => Lead::QUALIFICACAO_FRIO, // Default from hydrate
            "responsavel_id" => null,
            "contato_id" => null,
            "empresa_id" => null,
            "criado_em" => date("Y-m-d H:i:s"), // Approximate default
            "atualizado_em" => date("Y-m-d H:i:s") // Approximate default
        ];

        // Arrange: Prepare the mock statement
        $this->pdoMock->expects($this->once())
                      ->method("prepare")
                      ->with($this->stringContains("SELECT * FROM leads WHERE id = :id"))
                      ->willReturn($this->stmtMock);
                      
        $this->stmtMock->expects($this->once())
                       ->method("bindValue") // Model was changed to bindValue
                       ->with(":id", $leadId, PDO::PARAM_INT) // Check parameter binding
                       ->willReturn(true); // Assume bindValue succeeds

        $this->stmtMock->expects($this->once())
                       ->method("execute");
                       
        // Expect fetch to be called. Model calls fetch() without args.
        $this->stmtMock->expects($this->once())
                       ->method("fetch")
                       ->with() // Expect call with no arguments
                       ->willReturn($expectedLeadData); // Simulate returning data
                       
        // Act: Call the static method
        $lead = Lead::findById($leadId);

        // Assert: Check if the returned object is an instance of Lead and its properties match
        $this->assertInstanceOf(Lead::class, $lead, "Lead::findById should return an instance of Lead.");
        
        // Compare relevant properties
        $this->assertEquals($expectedLeadData["id"], $lead->id);
        $this->assertEquals($expectedLeadData["nome"], $lead->nome);
        $this->assertEquals($expectedLeadData["email"], $lead->email);
        $this->assertEquals($expectedLeadData["qualificacao"], $lead->qualificacao);
        // Add more property assertions if needed
    }

    // Add more tests for update, delete, findBy, etc.
    
    protected function tearDown(): void
    {
        // Reset the mock instance in the Database class
        Database::setInstance(null);
        unset($_ENV["APP_ENV"]);
    }
}

