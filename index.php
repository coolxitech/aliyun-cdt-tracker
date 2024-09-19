<?php

require_once 'AliyunTrafficCheck.php';

$format = $_GET['format'] ?? null;
$format = (PHP_SAPI === 'cli') ? 'text' : $format;
if ($format == 'json') {
    header('Content-Type: application/json; charset=utf-8');
} elseif ($format == 'text') {
    header('Content-Type: text/plain; charset=utf-8');
} else {
    header('Content-Type: text/html; charset=utf-8');
}
$aliyunTrafficCheck = new AliyunTrafficCheck();
echo $aliyunTrafficCheck->check($format);

