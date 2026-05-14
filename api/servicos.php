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
    $salao_id = $_GET['salao_id'] ?? null;

    $where = $salao_id ? 'WHERE s.salao_id = ?' : '';
    $params = $salao_id ? [$salao_id] : [];

    $stmt = $pdo->prepare("
        SELECT
            s.id, s.nome, s.descricao, s.preco,
            s.duracao_min, s.ativo, s.categoria_id,
            c.nome AS categoria
        FROM servicos s
        JOIN categorias c ON c.id = s.categoria_id
        $where
        ORDER BY c.nome, s.nome
    ");
    $stmt->execute($params);
    echo json_encode($stmt->fetchAll());

} elseif ($metodo === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);

    $nome         = trim($body['nome']        ?? '');
    $descricao    = trim($body['descricao']   ?? '');
    $preco        = $body['preco']            ?? null;
    $duracao_min  = $body['duracao_min']      ?? null;
    $categoria_id = $body['categoria_id']     ?? null;
    $salao_id     = 1; // fixo por enquanto, virá do token JWT futuramente

    if (!$nome || !$preco || !$duracao_min || !$categoria_id) {
        http_response_code(400);
        echo json_encode(['erro' => 'Nome, preço, duração e categoria são obrigatórios.']);
        exit;
    }

    $stmt = $pdo->prepare("
        INSERT INTO servicos (salao_id, categoria_id, nome, descricao, preco, duracao_min, ativo)
        VALUES (?, ?, ?, ?, ?, ?, 1)
    ");
    $stmt->execute([$salao_id, $categoria_id, $nome, $descricao, $preco, $duracao_min]);

    http_response_code(201);
    echo json_encode(['sucesso' => true, 'id' => (int) $pdo->lastInsertId()]);

} elseif ($metodo === 'PUT') {
    $body = json_decode(file_get_contents('php://input'), true);

    $id           = $body['id']           ?? null;
    $nome         = trim($body['nome']    ?? '');
    $descricao    = trim($body['descricao'] ?? '');
    $preco        = $body['preco']        ?? null;
    $duracao_min  = $body['duracao_min']  ?? null;
    $categoria_id = $body['categoria_id'] ?? null;
    $ativo        = isset($body['ativo']) ? (int) $body['ativo'] : 1;

    if (!$id || !$nome || !$preco || !$duracao_min || !$categoria_id) {
        http_response_code(400);
        echo json_encode(['erro' => 'ID, nome, preço, duração e categoria são obrigatórios.']);
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE servicos
        SET categoria_id = ?, nome = ?, descricao = ?, preco = ?, duracao_min = ?, ativo = ?
        WHERE id = ?
    ");
    $stmt->execute([$categoria_id, $nome, $descricao, $preco, $duracao_min, $ativo, $id]);

    echo json_encode(['sucesso' => true]);

} else {
    http_response_code(405);
    echo json_encode(['erro' => 'Método não permitido.']);
}