<?php

namespace Apoio19\Crm\Controllers;

use Apoio19\Crm\Models\TarefaUsuario;
use Apoio19\Crm\Middleware\AuthMiddleware;

class TarefaUsuarioController
{
    private $authMiddleware;

    public function __construct()
    {
        $this->authMiddleware = new AuthMiddleware();
    }

    /**
     * Listar tarefas
     * Admin vê todas, usuário vê apenas as suas
     */
    public function index(array $headers, array $queryParams = [])
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            return ["error" => "Não autorizado"];
        }

        // Se passar ?mine=true, retorna apenas as tarefas do usuário logado, mesmo se for admin
        if (isset($queryParams['mine']) && $queryParams['mine'] === 'true') {
            return TarefaUsuario::getByUserId($userData->userId);
        }

        if ($userData->role === 'admin') {
            return TarefaUsuario::all();
        } else {
            return TarefaUsuario::getByUserId($userData->userId);
        }
    }

    /**
     * Criar nova tarefa
     */
    public function store(array $headers, $data)
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            return ["error" => "Não autorizado"];
        }

        // Garantir que data seja um array
        $data = (array) $data;

        // Validar dados obrigatórios
        if (empty($data['titulo'])) {
            return ["error" => "O título é obrigatório"];
        }

        // Forçar o ID do usuário logado se não for admin
        // Se for admin, pode criar para outro usuário (opcional, aqui assumimos que cria para si ou para o especificado)
        if ($userData->role !== 'admin' || empty($data['usuario_id'])) {
            $data['usuario_id'] = $userData->userId;
        }

        $id = TarefaUsuario::create($data);
        if ($id) {
            return ["success" => true, "id" => $id, "message" => "Tarefa criada com sucesso"];
        }
        return ["error" => "Erro ao criar tarefa"];
    }

    /**
     * Exibir uma tarefa específica
     */
    public function show(array $headers, int $id)
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            return ["error" => "Não autorizado"];
        }

        $tarefa = TarefaUsuario::find($id);
        if (!$tarefa) {
            return ["error" => "Tarefa não encontrada"];
        }

        // Verificar permissão: Admin ou dono da tarefa
        if ($userData->role !== 'admin' && $tarefa->usuario_id != $userData->userId) {
            return ["error" => "Acesso negado"];
        }

        return $tarefa;
    }

    /**
     * Atualizar tarefa
     */
    public function update(array $headers, int $id, $data)
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            return ["error" => "Não autorizado"];
        }

        // Garantir que data seja um array
        $data = (array) $data;

        $tarefa = TarefaUsuario::find($id);
        if (!$tarefa) {
            return ["error" => "Tarefa não encontrada"];
        }

        // Verificar permissão: Admin ou dono da tarefa
        if ($userData->role !== 'admin' && $tarefa->usuario_id != $userData->userId) {
            return ["error" => "Acesso negado"];
        }

        if (TarefaUsuario::update($id, $data)) {
            return ["success" => true, "message" => "Tarefa atualizada com sucesso"];
        }
        return ["error" => "Erro ao atualizar tarefa"];
    }

    /**
     * Excluir tarefa
     */
    public function destroy(array $headers, int $id)
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            return ["error" => "Não autorizado"];
        }

        $tarefa = TarefaUsuario::find($id);
        if (!$tarefa) {
            return ["error" => "Tarefa não encontrada"];
        }

        // Verificar permissão: Admin ou dono da tarefa
        if ($userData->role !== 'admin' && $tarefa->usuario_id != $userData->userId) {
            return ["error" => "Acesso negado"];
        }

        if (TarefaUsuario::delete($id)) {
            return ["success" => true, "message" => "Tarefa excluída com sucesso"];
        }
        return ["error" => "Erro ao excluir tarefa"];
    }
}
