<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

try {
    $pdo = new PDO(
        "mysql:host=localhost;dbname=sigap_expedientes;charset=utf8mb4",
        'admin_sql', 'MpSj*30673',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]
    );
    $row = $pdo->query("SELECT MAX(updated_at) AS ultima FROM expediente")->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['ok'=>true, 'ultima_sync'=>$row['ultima']??null]);
} catch (Exception $e) {
    echo json_encode(['ok'=>false, 'ultima_sync'=>null]);
}
