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

    http_response_code(201);
    echo json_encode([
        'sucesso'        => true,
        'agendamento_id' => $pdo->lastInsertId()
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
        $where[]  = 'YEARWEEK(a.data_hora, 1) = YEARWEEK(CURDATE(), 1)';
    } elseif ($periodo === 'mes') {
        $where[]  = 'YEAR(a.data_hora) = YEAR(CURDATE()) AND MONTH(a.data_hora) = MONTH(CURDATE())';
    } elseif ($periodo === 'ano') {
        $where[]  = 'YEAR(a.data_hora) = YEAR(CURDATE())';
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
            c.nome  AS cliente,
            p.nome  AS profissional,
            s.nome  AS servico
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

    $pdo = conectar();

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