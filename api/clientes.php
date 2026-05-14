<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

require_once '../config/database.php';

$metodo = $_SERVER['REQUEST_METHOD'];

if ($metodo === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);

    $nome     = trim($body['nome']     ?? '');
    $telefone = trim($body['telefone'] ?? '');
    $email    = trim($body['email']    ?? '');

    if (!$nome || !$telefone) {
        http_response_code(400);
        echo json_encode(['erro' => 'Nome e telefone são obrigatórios.']);
        exit;
    }

    $pdo = conectar();

    // Se vier e-mail, verifica se já existe um cliente com ele
    if ($email) {
        $existe = $pdo->prepare("SELECT id FROM clientes WHERE email = ?");
        $existe->execute([$email]);
        $cliente = $existe->fetch();

        if ($cliente) {
            // Atualiza nome e telefone e devolve o id existente
            $upd = $pdo->prepare("UPDATE clientes SET nome = ?, telefone = ? WHERE id = ?");
            $upd->execute([$nome, $telefone, $cliente['id']]);

            echo json_encode(['id' => (int) $cliente['id'], 'existente' => true]);
            exit;
        }
    }


    $insert = $pdo->prepare("
        INSERT INTO clientes (nome, telefone, email, senha_hash)
        VALUES (?, ?, ?, ?)
    ");
    $insert->execute([
        $nome,
        $telefone,
        $email ?: null,
        password_hash(uniqid(), PASSWORD_DEFAULT), // senha provisória
    ]);

    echo json_encode(['id' => (int) $pdo->lastInsertId(), 'existente' => false]);

} elseif ($metodo === 'GET') {
    $pdo = conectar();

    $id    = $_GET['id']    ?? null;
    $email = $_GET['email'] ?? null;

    if ($id) {
        $stmt = $pdo->prepare("SELECT id, nome, telefone, email, criado_em FROM clientes WHERE id = ?");
        $stmt->execute([$id]);
        $cliente = $stmt->fetch();

        if (!$cliente) {
            http_response_code(404);
            echo json_encode(['erro' => 'Cliente não encontrado.']);
            exit;
        }

        echo json_encode($cliente);

    } elseif ($email) {
        $stmt = $pdo->prepare("SELECT id, nome, telefone, email, criado_em FROM clientes WHERE email = ?");
        $stmt->execute([$email]);
        $cliente = $stmt->fetch();

        if (!$cliente) {
            http_response_code(404);
            echo json_encode(['erro' => 'Cliente não encontrado.']);
            exit;
        }

        echo json_encode($cliente);

    } else {
        http_response_code(400);
        echo json_encode(['erro' => 'Informe id ou email para buscar.']);
    }

} else {
    http_response_code(405);
    echo json_encode(['erro' => 'Método não permitido.']);
}