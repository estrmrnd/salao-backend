<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

require_once '../config/database.php';

$metodo = $_SERVER['REQUEST_METHOD'];
$pdo = conectar();

if ($metodo === 'GET') {
    $stmt = $pdo->query("SELECT id, nome FROM categorias ORDER BY nome");
    echo json_encode($stmt->fetchAll());

} elseif ($metodo === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);

    $nome      = trim($body['nome']      ?? '');
    $descricao = trim($body['descricao'] ?? '');
    $salao_id  = 1;

    if (!$nome) {
        http_response_code(400);
        echo json_encode(['erro' => 'Nome da categoria é obrigatório.']);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO categorias (salao_id, nome, descricao) VALUES (?, ?, ?)");
    $stmt->execute([$salao_id, $nome, $descricao]);

    http_response_code(201);
    echo json_encode(['sucesso' => true, 'id' => (int) $pdo->lastInsertId()]);

} else {
    http_response_code(405);
    echo json_encode(['erro' => 'Método não permitido.']);
}