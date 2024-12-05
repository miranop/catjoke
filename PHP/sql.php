<?php
//DBへの接続と作成
$db = new SQLite3('favorites.sqlite', SQLITE3_OPEN_CREATE | SQLITE3_OPEN_READWRITE);
$db->enableExceptions(true);

//テーブルの作成
$db->query('CREATE TABLE IF NOT EXISTS "favorite"(
    "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    "advice" TEXT,
    "image" TEXT,
    "likes" INTEGER DEFAULT 0,
    "created_at" DATETIME DEFAULT CURRENT_TIMESTAMP
)');

//テーブルにデータを保存する関数
function saveFavorite($db, $advice, $image)
{
    $stmt = $db->prepare('INSERT INTO "favorites" (advice, image) VALUES (:advice, :image)');
    $stmt->bindValue(':advice', $advice);
    $stmt->bindValue(':image', $image);
    return $stmt->execute();
}

//呼び出す関数
function getFavorites($db)
{
    $result = $db->query('SELECT * FROM "favorites" ORDER BY likes DESC');
    $favorites = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $favorites[] = $row;
    }
    return $favorites;
}

?>