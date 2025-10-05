<?php

namespace Apoio19\Crm\Views;

class EmailView
{

    /**
     * Renderiza um template de e-mail, substituindo os placeholders.
     *
     * @param string $templateName Nome do arquivo de template (ex: 'boas_vindas.html')
     * @param array $data Dados para substituir no template (ex: ['nome_usuario' => 'Alan'])
     * @return string|false O conteúdo HTML renderizado ou false se o template não for encontrado.
     */
    public static function render(string $templateName, array $data =[]): string|false
    {

        $templatePath = __DIR__ . "/../../templates/emails/" . $templateName;

        if( !file_exists($templatePath)) {
            echo "Template de e-mail não encontrado: $templatePath";exit;
            return false;
        }

        $content = file_get_contents($templatePath);

        foreach ($data as $key => $value) {
            $content = str_replace("{{" . $key . "}}", htmlspecialchars($value, ENT_QUOTES, 'UTF-8'), $content);
        }

        return $content;
    }

}