<?php
require dirname(__DIR__).'/vendor/autoload.php';
use App\Infrastructure\ConnectionFactory;use App\Service\Store;use App\Service\Cantine;
$url=getenv('DATABASE_URL');if(!$url||!preg_match('~/_?[^/?]*_recette(?:\?|$)~',$url)||getenv('CANTINE_RECETTE_DESTRUCTIVE')!=='OUI'){exit(2);}
$db=ConnectionFactory::create($url);$c=new Cantine($db,new Store($db,'Africa/Abidjan'),'Africa/Abidjan');$deadline=(float)getenv('CANTINE_RACE_START');if($deadline-microtime(true)>5){exit(2);}while(microtime(true)<$deadline){usleep(1000);}
try{if(($argv[1]??'')==='issue'){$id=$c->issue((int)$argv[2],1,2);echo json_encode(['ok'=>true,'id'=>$id]);}elseif(($argv[1]??'')==='consume'){$c->consume((int)$argv[2],1,'ESPECES',3);echo json_encode(['ok'=>true]);}else{exit(2);}}
catch(DomainException|Doctrine\DBAL\Exception\UniqueConstraintViolationException $e){echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);}
