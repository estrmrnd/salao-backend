<?php
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['erro' => 'Método não permitido']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);

if (!isset($body['email']) || !isset($body['senha'])) {
    http_response_code(400);
    echo json_encode(['erro' => 'Email e senha são obrigatórios']);
    exit;
}

$pdo = conectar();

$stmt = $pdo->prepare('SELECT * FROM admins WHERE email = ? LIMIT 1');
$stmt->execute([$body['email']]);
$admin = $stmt->fetch();

if (!$admin || !password_verify($body['senha'], $admin['senha_hash'])) {
    http_response_code(401);
    echo json_encode(['erro' => 'Credenciais inválidas']);
    exit;
}

echo json_encode([
    'id'       => $admin['id'],
    'nome'     => $admin['nome'],
    'email'    => $admin['email'],
    'salao_id' => $admin['salao_id'],
    'papel'    => $admin['papel'],
]);