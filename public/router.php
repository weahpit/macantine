<?php
// Routeur réservé au serveur PHP de développement.
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$file=realpath(__DIR__.rawurldecode($path ?: '/'));
if($file && str_starts_with($file,__DIR__.DIRECTORY_SEPARATOR) && is_file($file) && in_array(pathinfo($file,PATHINFO_EXTENSION),['css','png','jpg','ico'],true)){return false;}
require __DIR__.'/index.php';
