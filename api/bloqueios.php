<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

require_once '../config/database.php';

$metodo = $_SERVER['REQUEST_METHOD'];
$pdo = conectar();

if ($metodo === 'GET') {
    $profissional_id = $_GET['profissional_id'] ?? null;
    $data            = $_GET['data']            ?? null;

    $where  = ['1=1'];
    $params = [];

    if ($profissional_id) {
        $where[]  = 'b.profissional_id = ?';
        $params[] = $profissional_id;
    }
    if ($data) {
        $where[]  = 'b.data = ?';
        $params[] = $data;
    }

    $stmt = $pdo->prepare("
        SELECT
            b.id,
            b.data,
            b.inicio,
            b.fim,
            b.motivo,
            b.profissional_id,
            p.nome AS profissional
        FROM bloqueios b
        LEFT JOIN profissionais p ON p.id = b.profissional_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY b.data, b.inicio
    ");
    $stmt->execute($params);
    echo json_encode($stmt->fetchAll());

} elseif ($metodo === 'POST') {
    $body            = json_decode(file_get_contents('php://input'), true);
    $profissional_id = $body['profissional_id'] ?? null; // null = salão inteiro
    $data            = $body['data']            ?? null;
    $dia_inteiro     = (bool) ($body['dia_inteiro'] ?? false);
    $inicio          = $dia_inteiro ? '00:00:00' : ($body['inicio'] ?? null);
    $fim             = $dia_inteiro ? '23:59:00' : ($body['fim']    ?? null);
    $motivo          = trim($body['motivo'] ?? '');

    if (!$data || !$inicio || !$fim) {
        http_response_code(400);
        echo json_encode(['erro' => 'Data, início e fim são obrigatórios.']);
        exit;
    }

    // Se for salão inteiro, cria um bloqueio para cada profissional ativo
    if (!$profissional_id) {
        $profs = $pdo->query("SELECT id FROM profissionais WHERE ativo = 1")->fetchAll();
        foreach ($profs as $p) {
            $pdo->prepare("
                INSERT INTO bloqueios (profissional_id, data, inicio, fim, motivo)
                VALUES (?, ?, ?, ?, ?)
            ")->execute([$p['id'], $data, $inicio, $fim, $motivo]);
        }
        echo json_encode(['sucesso' => true, 'tipo' => 'salao']);
        exit;
    }

    $stmt = $pdo->prepare("
        INSERT INTO bloqueios (profissional_id, data, inicio, fim, motivo)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$profissional_id, $data, $inicio, $fim, $motivo]);

    http_response_code(201);
    echo json_encode(['sucesso' => true, 'id' => (int) $pdo->lastInsertId()]);

} elseif ($metodo === 'DELETE') {
    $body = json_decode(file_get_contents('php://input'), true);
    $id   = $body['id'] ?? null;

    if (!$id) {
        http_response_code(400);
        echo json_encode(['erro' => 'ID é obrigatório.']);
        exit;
    }

    $pdo->prepare("DELETE FROM bloqueios WHERE id = ?")->execute([$id]);
    echo json_encode(['sucesso' => true]);

} else {
    http_response_code(405);
    echo json_encode(['erro' => 'Método não permitido.']);
}