<?php
require_once 'database_functions.php';

// エラー表示
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    // POSTデータを取得
    $data = json_decode(file_get_contents('php://input'), true);

    if (isset($data['advice']) && isset($data['image'])) {
        saveFavorite($db, $data['advice'], $data['image']);
        echo json_encode(['status' => 'success']);
    } else {
        throw new Exception('Invalid data received');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}