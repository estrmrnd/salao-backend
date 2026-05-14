<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

require_once '../config/database.php';

$slug = $_GET['slug'] ?? null;

if (!$slug) {
    http_response_code(400);
    echo json_encode(['erro' => 'Slug é obrigatório']);
    exit;
}

$pdo = conectar();
$stmt = $pdo->prepare("SELECT id, nome, slug, logo_url FROM saloes WHERE slug = ? LIMIT 1");
$stmt->execute([$slug]);
$salao = $stmt->fetch();

if (!$salao) {
    http_response_code(404);
    echo json_encode(['erro' => 'Salão não encontrado']);
    exit;
}

echo json_encode($salao);