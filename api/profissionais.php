<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

require_once '../config/database.php';

$metodo = $_SERVER['REQUEST_METHOD'];
$pdo = conectar();

if ($metodo === 'GET') {
    $servico_id   = $_GET['servico_id']   ?? null;
    $apenasAtivos = !isset($_GET['todos']);

    $where = $apenasAtivos ? 'WHERE ativo = 1' : '';

    $salao_id = $_GET['salao_id'] ?? null;

if ($servico_id) {
    $sql = "
        SELECT p.id, p.nome, p.especialidade, p.foto_url
        FROM profissionais p
        JOIN profissional_servico ps ON ps.profissional_id = p.id
        WHERE ps.servico_id = ?
        AND p.ativo = 1
    ";
    $params = [$servico_id];
    if ($salao_id) {
        $sql .= " AND p.salao_id = ?";
        $params[] = $salao_id;
    }
    $sql .= " ORDER BY p.nome";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode($stmt->fetchAll());
    exit;
}

    if ($servico_id) {
        $stmt = $pdo->prepare("
            SELECT p.id, p.nome, p.especialidade, p.foto_url
            FROM profissionais p
            JOIN profissional_servico ps ON ps.profissional_id = p.id
            WHERE ps.servico_id = ?
            AND p.ativo = 1
            ORDER BY p.nome
        ");
        $stmt->execute([$servico_id]);
        echo json_encode($stmt->fetchAll());
        exit;
    }

    $profissionais = $pdo->query("
        SELECT id, nome, especialidade, foto_url, ativo
        FROM profissionais
        $where
        ORDER BY nome
    ")->fetchAll();

    foreach ($profissionais as &$p) {
        $stmt = $pdo->prepare("
            SELECT s.id, s.nome, s.preco, s.duracao_min
            FROM servicos s
            JOIN profissional_servico ps ON ps.servico_id = s.id
            WHERE ps.profissional_id = ?
        ");
        $stmt->execute([$p['id']]);
        $p['servicos'] = $stmt->fetchAll();
    }

    echo json_encode($profissionais);

} elseif ($metodo === 'POST') {
    $body        = json_decode(file_get_contents('php://input'), true);
    $nome        = trim($body['nome']         ?? '');
    $especialidade = trim($body['especialidade'] ?? '');
    $foto_url    = trim($body['foto_url']     ?? '');
    $servicos    = $body['servicos']          ?? [];
    $salao_id    = 1;

    if (!$nome) {
        http_response_code(400);
        echo json_encode(['erro' => 'Nome é obrigatório.']);
        exit;
    }

    $stmt = $pdo->prepare("
        INSERT INTO profissionais (salao_id, nome, especialidade, foto_url, ativo)
        VALUES (?, ?, ?, ?, 1)
    ");
    $stmt->execute([$salao_id, $nome, $especialidade, $foto_url ?: null]);
    $id = (int) $pdo->lastInsertId();

    // Vincula serviços
    foreach ($servicos as $servico_id) {
        $pdo->prepare("INSERT INTO profissional_servico (profissional_id, servico_id) VALUES (?, ?)")
            ->execute([$id, $servico_id]);
    }

    http_response_code(201);
    echo json_encode(['sucesso' => true, 'id' => $id]);

} elseif ($metodo === 'PUT') {
    $body          = json_decode(file_get_contents('php://input'), true);
    $id            = $body['id']            ?? null;
    $nome          = trim($body['nome']     ?? '');
    $especialidade = trim($body['especialidade'] ?? '');
    $foto_url      = trim($body['foto_url'] ?? '');
    $ativo         = isset($body['ativo']) ? (int) $body['ativo'] : 1;
    $servicos      = $body['servicos']      ?? [];

    if (!$id || !$nome) {
        http_response_code(400);
        echo json_encode(['erro' => 'ID e nome são obrigatórios.']);
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE profissionais
        SET nome = ?, especialidade = ?, foto_url = ?, ativo = ?
        WHERE id = ?
    ");
    $stmt->execute([$nome, $especialidade, $foto_url ?: null, $ativo, $id]);

    // Atualiza vínculos de serviços
    $pdo->prepare("DELETE FROM profissional_servico WHERE profissional_id = ?")->execute([$id]);
    foreach ($servicos as $servico_id) {
        $pdo->prepare("INSERT INTO profissional_servico (profissional_id, servico_id) VALUES (?, ?)")
            ->execute([$id, $servico_id]);
    }

    echo json_encode(['sucesso' => true]);

} else {
    http_response_code(405);
    echo json_encode(['erro' => 'Método não permitido.']);
}