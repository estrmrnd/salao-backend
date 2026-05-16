<?php

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, PATCH, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

require_once '../config/database.php';

$metodo = $_SERVER['REQUEST_METHOD'];

if ($metodo === 'POST') {

    $body = json_decode(file_get_contents('php://input'), true);

    $cliente_id      = $body['cliente_id']      ?? null;
    $profissional_id = $body['profissional_id'] ?? null;
    $servico_id      = $body['servico_id']      ?? null;
    $data_hora       = $body['data_hora']       ?? null;
    $observacao      = $body['observacao']      ?? '';

    if (!$cliente_id || !$profissional_id || !$servico_id || !$data_hora) {
        http_response_code(400);
        echo json_encode(['erro' => 'Preencha todos os campos obrigatórios']);
        exit;
    }

    $pdo = conectar();

    $servico = $pdo->prepare("SELECT preco, duracao_min FROM servicos WHERE id = ?");
    $servico->execute([$servico_id]);
    $s = $servico->fetch();

    if (!$s) {
        http_response_code(404);
        echo json_encode(['erro' => 'Serviço não encontrado']);
        exit;
    }

    $conflito = $pdo->prepare("
        SELECT id FROM agendamentos
        WHERE profissional_id = ?
        AND status NOT IN ('cancelado')
        AND data_hora < DATE_ADD(?, INTERVAL ? MINUTE)
        AND DATE_ADD(data_hora, INTERVAL duracao_min MINUTE) > ?
    ");
    $conflito->execute([
        $profissional_id,
        $data_hora,
        $s['duracao_min'],
        $data_hora
    ]);

    if ($conflito->fetch()) {
        http_response_code(409);
        echo json_encode(['erro' => 'Horário não disponível']);
        exit;
    }

    $insert = $pdo->prepare("
        INSERT INTO agendamentos
            (cliente_id, profissional_id, servico_id, data_hora, duracao_min, preco_cobrado, observacao)
        VALUES
            (?, ?, ?, ?, ?, ?, ?)
    ");
    $insert->execute([
        $cliente_id,
        $profissional_id,
        $servico_id,
        $data_hora,
        $s['duracao_min'],
        $s['preco'],
        $observacao
    ]);

    $agendamento_id = $pdo->lastInsertId();

    // ── Busca dados completos para o email ──
    $detalhes = $pdo->prepare("
        SELECT
            c.nome AS cliente,
            p.nome AS profissional,
            s.nome AS servico
        FROM clientes c
        JOIN profissionais p ON p.id = ?
        JOIN servicos s      ON s.id = ?
        WHERE c.id = ?
    ");
    $detalhes->execute([$profissional_id, $servico_id, $cliente_id]);
    $d = $detalhes->fetch();

    // ── Busca o salao_id do profissional ──
    $salao_stmt = $pdo->prepare("SELECT salao_id FROM profissionais WHERE id = ?");
    $salao_stmt->execute([$profissional_id]);
    $salao_id = $salao_stmt->fetchColumn();

    // ── Busca só os admins daquele salão ──
    $admins = $pdo->prepare("SELECT email, nome FROM admins WHERE salao_id = ?");
    $admins->execute([$salao_id]);
    $lista_admins = $admins->fetchAll();

    // ── Dispara email para cada admin do salão ──
    if ($d && $lista_admins) {
        $data_formatada = date('d/m/Y \à\s H:i', strtotime($data_hora));
        $assunto = "=?UTF-8?B?" . base64_encode("Novo agendamento: {$d['cliente']}") . "?=";

        foreach ($lista_admins as $admin) {
            $corpo = "Olá, {$admin['nome']}!\n\n"
                . "Um novo agendamento foi solicitado e está aguardando sua confirmação:\n\n"
                . "Cliente:      {$d['cliente']}\n"
                . "Serviço:      {$d['servico']}\n"
                . "Profissional: {$d['profissional']}\n"
                . "Data/hora:    {$data_formatada}\n\n"
                . "Acesse o painel para confirmar ou cancelar.\n";

            $headers = implode("\r\n", [
                "From: Salão <noreply@seudominio.com>",
                "Reply-To: noreply@seudominio.com",
                "Content-Type: text/plain; charset=UTF-8",
                "X-Mailer: PHP/" . phpversion(),
            ]);

            @mail($admin['email'], $assunto, $corpo, $headers);
        }
    }

    http_response_code(201);
    echo json_encode([
        'sucesso'        => true,
        'agendamento_id' => $agendamento_id,
    ]);

} elseif ($metodo === 'GET') {

    $pdo = conectar();

    $cliente_id      = $_GET['cliente_id']      ?? null;
    $profissional_id = $_GET['profissional_id'] ?? null;
    $data            = $_GET['data']            ?? null;
    $periodo         = $_GET['periodo']         ?? null;

    $where  = ['1=1'];
    $params = [];

    if ($cliente_id) {
        $where[]  = 'a.cliente_id = ?';
        $params[] = $cliente_id;
    }

    if ($profissional_id) {
        $where[]  = 'a.profissional_id = ?';
        $params[] = $profissional_id;
    }

    if ($periodo === 'semana') {
        $where[] = 'YEARWEEK(a.data_hora, 1) = YEARWEEK(CURDATE(), 1)';
    } elseif ($periodo === 'mes') {
        $where[] = 'YEAR(a.data_hora) = YEAR(CURDATE()) AND MONTH(a.data_hora) = MONTH(CURDATE())';
    } elseif ($periodo === 'ano') {
        $where[] = 'YEAR(a.data_hora) = YEAR(CURDATE())';
    } elseif ($data) {
        $where[]  = 'DATE(a.data_hora) = ?';
        $params[] = $data;
    }

    $sql = "
        SELECT
            a.id,
            a.data_hora,
            a.duracao_min,
            a.preco_cobrado,
            a.status,
            a.observacao,
            c.nome AS cliente,
            p.nome AS profissional,
            s.nome AS servico
        FROM agendamentos a
        JOIN clientes      c ON c.id = a.cliente_id
        JOIN profissionais p ON p.id = a.profissional_id
        JOIN servicos      s ON s.id = a.servico_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY a.data_hora
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    echo json_encode($stmt->fetchAll());

} elseif ($metodo === 'PATCH') {

    $body = json_decode(file_get_contents('php://input'), true);

    $id     = $body['id']     ?? null;
    $status = $body['status'] ?? null;

    $statusPermitidos = ['pendente', 'confirmado', 'concluido', 'cancelado'];

    if (!$id || !$status || !in_array($status, $statusPermitidos)) {
        http_response_code(400);
        echo json_encode(['erro' => 'ID e status válido são obrigatórios.']);
        exit;
    }

    $pdo  = conectar();
    $stmt = $pdo->prepare("UPDATE agendamentos SET status = ? WHERE id = ?");
    $stmt->execute([$status, $id]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['erro' => 'Agendamento não encontrado.']);
        exit;
    }

    echo json_encode(['sucesso' => true, 'status' => $status]);

} else {

    http_response_code(405);
    echo json_encode(['erro' => 'Método não permitido']);

}