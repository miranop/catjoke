<?php
function fetchTextAPI($url)
{
    //cURLセッションの初期化
    $ch = curl_init();

    //URLとオプションの設定
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json'
        ]
    ]);

    //URLの情報を取得して返す
    $res = curl_exec($ch);

    //エラー処理
    if ($res == false) {
        $error = curl_error($ch);
        curl_close($ch);
        return json_encode([
            'status' => 'eroor',
            'message' => "Request failed: $error"
        ]);
    }
    //接続を終わらせる
    curl_close($ch);
    //json形式にデコードする
    $data = json_decode($res, true);

    //成功したのでデータを送信する
    return json_encode([
        'status' => 'success',
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);

}

function fetchImageAPI($url)
{
    //cURLセッションの初期化
    $ch = curl_init();

    //URLとオプションの設定
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: image',
        ]
    ]);

    $res = curl_exec($ch);

    if ($res === false) {
        return null;
    }

    curl_close($ch);
    return $res;
}

//猫にアドバイスをもらえるAPIの作成
function getCatAndAdvice()
{
    $advice = json_decode(fetchTextAPI('https://api.adviceslip.com/advice'), true);
    $cat = base64_encode(fetchImageAPI('https://cataas.com/cat'));

    return json_encode([
        'advice' => $advice['slip']['advice'],
        'catImage' => 'data:image/jpeg;base64,' . $cat
    ]);

}

header('Content-Type: application/json; charset=utf-8');
echo getCatAndAdvice();
?>