<?php
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(strpos($path,'/api/')===0) { require __DIR__.'/public/index.php'; return true; }
$dist=realpath(__DIR__.'/../dist');
if($dist) {
  $file=realpath($dist.'/'.ltrim($path,'/'));
  if($file && strpos($file,$dist.DIRECTORY_SEPARATOR)===0 && is_file($file)) {
    $types=['js'=>'text/javascript','css'=>'text/css','svg'=>'image/svg+xml','png'=>'image/png','json'=>'application/json'];
    $ext=pathinfo($file,PATHINFO_EXTENSION);
    header('Content-Type: '.($types[$ext]??'application/octet-stream'));header('X-Content-Type-Options: nosniff');readfile($file);return true;
  }
  header('Content-Type: text/html; charset=utf-8');readfile($dist.'/index.html');return true;
}
http_response_code(404);echo 'Frontend not built. Run npm run build, or use Vite at http://127.0.0.1:5173';
