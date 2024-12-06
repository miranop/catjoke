<?php
use DeepL\Translator;
require_once __DIR__ . '/../vendor/autoload.php';
//文字を取得する
function fetchTextAPI($url)
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json'
        ]
    ]);
    $res = curl_exec($ch);
    if ($res == false) {
        curl_close($ch);
        return null;
    }
    curl_close($ch);

    $data = json_decode($res, true);
    return $data['slip']['advice'];
}
//画像を取得する
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

//翻訳するAPIの作成
function translateJA($text) {
    $envPath = __DIR__ . '/../.env';
    
    if (!file_exists($envPath)) {
        throw new Exception('.env file not found');
    }
    
    $envContent = parse_ini_file($envPath);
    $authKey = $envContent['API_KEY'];
    
    $translator = new \DeepL\Translator($authKey);
    $result = $translator->translateText($text, null, 'ja');
    
    return $result->text;
}


//猫にアドバイスをもらえるAPIの作成
function getCatAndAdvice()
{
    $advice = fetchTextAPI('https://api.adviceslip.com/advice');
    $translatedAdvice = translateJA($advice);
    $cat = fetchImageAPI('https://cataas.com/cat?height=500');
    
    return json_encode([
        'advice' => $translatedAdvice,
        'catImage' => 'data:image/jpeg;base64,' . base64_encode($cat)
    ]);
}
?>