<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');

require_once '../config/database.php';

$profissional_id = $_GET['profissional_id'] ?? null;
$data            = $_GET['data']            ?? null;
$duracao_min     = $_GET['duracao_min']     ?? 60;

if (!$profissional_id || !$data) {
    http_response_code(400);
    echo json_encode(['erro' => 'Informe profissional_id e data']);
    exit;
}

$pdo = conectar();

$dia_semana = date('w', strtotime($data));

$horario = $pdo->prepare("
    SELECT inicio, fim 
    FROM horarios 
    WHERE profissional_id = ? AND dia_semana = ?
");
$horario->execute([$profissional_id, $dia_semana]);
$expediente = $horario->fetch();

if (!$expediente) {
    echo json_encode([]);
    exit;
}

$bloqueio = $pdo->prepare("
    SELECT inicio, fim 
    FROM bloqueios 
    WHERE profissional_id = ? AND data = ?
");
$bloqueio->execute([$profissional_id, $data]);
$bloqueios = $bloqueio->fetchAll();

$agendamento = $pdo->prepare("
    SELECT TIME(data_hora) AS inicio, duracao_min 
    FROM agendamentos 
    WHERE profissional_id = ? 
    AND DATE(data_hora) = ?
    AND status NOT IN ('cancelado')
");
$agendamento->execute([$profissional_id, $data]);
$agendados = $agendamento->fetchAll();

function estaOcupado($hora, $duracao, $bloqueios, $agendados) {
    $inicio  = strtotime($hora);
    $fim     = $inicio + ($duracao * 60);

    foreach ($bloqueios as $b) {
        $bInicio = strtotime($b['inicio']);
        $bFim    = strtotime($b['fim']);
        if ($inicio < $bFim && $fim > $bInicio) return true;
    }

    foreach ($agendados as $a) {
        $aInicio = strtotime($a['inicio']);
        $aFim    = $aInicio + ($a['duracao_min'] * 60);
        if ($inicio < $aFim && $fim > $aInicio) return true;
    }

    return false;
}

$slots        = [];
$intervalo    = 30;
$horaAtual    = strtotime($data . ' ' . $expediente['inicio']);
$horaFim      = strtotime($data . ' ' . $expediente['fim']);
$agora        = time();

while ($horaAtual + ($duracao_min * 60) <= $horaFim) {
    $hora = date('H:i', $horaAtual);

    if ($horaAtual > $agora && !estaOcupado($hora, $duracao_min, $bloqueios, $agendados)) {
        $slots[] = $hora;
    }

    $horaAtual += $intervalo * 60;
}

echo json_encode($slots);