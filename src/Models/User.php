<?php

namespace Apoio19\Crm\Models;

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
     * Find a user by email.
     *
     * @param string $email
     * @return User|null
     */
    public static function findByEmail(string $email): ?User
    {
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = :email LIMIT 1");
            $stmt->bindParam(":email", $email);
            $stmt->execute();
            $userData = $stmt->fetch();

            if ($userData) {
                $user = new self();
                $user->id = (int)$userData["id"];
                $user->nome = $userData["nome"];
                $user->email = $userData["email"];
                $user->senha_hash = $userData["senha_hash"];
                $user->funcao  = $userData["funcao"];
                $user->ativo = $userData["ativo"];
                $user->criado_em = $userData["criado_em"];
                $user->atualizado_em = $userData["atualizado_em"];
                return $user;
            }
        } catch (\PDOException $e) {
            error_log("Erro ao buscar usuário por email: " . $e->getMessage());
            // Handle exception appropriately
        }
        return null;
    }

    /**
     * Create a new user.
     *
     * @param string $nome
     * @param string $email
     * @param string $password
     * @param string $funcao
     * @return int|false The ID of the new user or false on failure.
     */
    public static function create(string $nome, string $email, string $password, string $funcao = 'Comercial'): int|false
    {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        if (!$hashedPassword) {
            error_log("Erro ao gerar hash da senha.");
            return false;
        }

        try {
            $pdo = Database::getInstance();
            $sql = "INSERT INTO usuarios (nome, email, senha_hash, funcao) VALUES (:nome, :email, :senha_hash, :funcao)";
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(":nome", $nome);
            $stmt->bindParam(":email", $email);
            $stmt->bindParam(":senha_hash", $hashedPassword);
            $stmt->bindParam(":funcao", $funcao);
            
            if ($stmt->execute()) {
                return (int)$pdo->lastInsertId();
            }
        } catch (\PDOException $e) {
            error_log("Erro ao criar usuário: " . $e->getMessage());
            // Handle exception appropriately (e.g., check for duplicate email)
        }
        return false;
    }

    /**
     * Verify user password.
     *
     * @param string $password
     * @return bool
     */
    public function verifyPassword(string $password): bool
    {
        return password_verify($password, $this->senha_hash);
    }
}

