<?php
require_once 'api.php';
header('Content-Type: application/json; charset=utf-8');

$result = getCatAndAdvice();
echo $result;