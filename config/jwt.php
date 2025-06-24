<?php

// config/jwt.php

// Prioritize environment variables, especially for sensitive data like the secret.
// Provide defaults only as a last resort and ensure they are NOT used in production.
$secret = $_ENV["JWT_SECRET"] ?? "valor_padrao_inseguro_trocar_em_producao";
$expiration = (int)($_ENV["JWT_EXPIRATION"] ?? 3600); // Default to 1 hour

if ($secret === "valor_padrao_inseguro_trocar_em_producao" && ($_ENV["APP_ENV"] ?? "production") !== "testing") {
    // Add a warning or error log if the default secret is used outside of testing
    error_log("AVISO: Chave secreta JWT não configurada ou usando valor padrão em config/jwt.php. Gere uma chave segura!", 0);
}

return [
    "secret" => $secret,
    "expiration" => $expiration, // Tempo de expiração em segundos
    "algo" => "HS256",
    "issuer" => "Apoio19 CRM",
    "audience" => "Apoio19 CRM Users"
];

