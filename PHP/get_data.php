<?php
require_once 'api.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $result = getCatAndAdvice();
    $data = json_decode($result, true);

    // デバッグ用にデータの内容を確認
    error_log(print_r($data, true));

    echo $result;
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}