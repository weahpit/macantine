<?php
// Exécuter : php tests/metier.php (après composer install). Base SQLite temporaire supprimée à la fin.
require dirname(__DIR__).'/vendor/autoload.php';
use App\Infrastructure\ConnectionFactory;
use App\Service\Store;
$dir=dirname(__DIR__).'/var';if(!is_dir($dir)){mkdir($dir,0770,true);}
$file=tempnam($dir,'tests-');$db=ConnectionFactory::create('sqlite:///'. $file);$s=new Store($db,'Africa/Abidjan');$checks=0;
function check(bool $ok,string $label):void {global $checks;if(!$ok){throw new RuntimeException('ÉCHEC : '.$label);}echo 'OK : '.$label.PHP_EOL;$checks++;}
function refused(callable $fn,string $label):void {try{$fn();}catch(DomainException|InvalidArgumentException){check(true,$label);return;}throw new RuntimeException('ÉCHEC : '.$label);}
try {
    $schema=[
      'CREATE TABLE direction(id INTEGER PRIMARY KEY,nom TEXT)',
      'CREATE TABLE service(id INTEGER PRIMARY KEY,nom TEXT,direction_id INTEGER)',
      'CREATE TABLE employe(id INTEGER PRIMARY KEY,matricule TEXT UNIQUE,nom TEXT,prenoms TEXT,service_id INTEGER,actif INTEGER,badge_token TEXT UNIQUE)',
      'CREATE TABLE utilisateur(id INTEGER PRIMARY KEY,nom TEXT)',
      'CREATE TABLE type_repas(id INTEGER PRIMARY KEY,nom TEXT,actif INTEGER)',
      'CREATE TABLE ticket(id INTEGER PRIMARY KEY AUTOINCREMENT,numero TEXT UNIQUE,token TEXT UNIQUE,employe_id INTEGER,date_repas TEXT,type_repas_id INTEGER,statut TEXT,cree_le TEXT,cree_par INTEGER,UNIQUE(employe_id,date_repas,type_repas_id))',
      'CREATE TABLE consommation(id INTEGER PRIMARY KEY AUTOINCREMENT,ticket_id INTEGER UNIQUE,employe_id INTEGER,type_repas_id INTEGER,date_repas TEXT,servi_le TEXT,agent_id INTEGER,matricule TEXT,employe_nom TEXT,service_nom TEXT,direction_nom TEXT,repas_nom TEXT)',
      'CREATE TABLE audit(id INTEGER PRIMARY KEY AUTOINCREMENT,utilisateur_id INTEGER,action TEXT,detail TEXT,cree_le TEXT)'
    ];foreach($schema as $sql){$db->executeStatement($sql);}
    $db->insert('direction',['id'=>1,'nom'=>'Direction test']);$db->insert('service',['id'=>1,'nom'=>'Service test','direction_id'=>1]);
    $db->insert('employe',['id'=>1,'matricule'=>'TEST01','nom'=>'Employé','prenoms'=>'Test','service_id'=>1,'actif'=>1,'badge_token'=>str_repeat('a',64)]);
    $db->insert('utilisateur',['id'=>1,'nom'=>'Agent test']);foreach([1=>'Déjeuner',2=>'Dîner',3=>'Collation'] as $id=>$nom){$db->insert('type_repas',['id'=>$id,'nom'=>$nom,'actif'=>1]);}
    $date=$s->today();check($s->issue(1,$date,1,1),'Émission initiale');check(!$s->issue(1,$date,1,1),'Doublon employé/date/repas ignoré');
    $id=(int)$db->fetchOne('SELECT id FROM ticket WHERE type_repas_id=1');$t=$s->ticket($id);check($s->validity($t)===null,'Ticket du jour valide');
    check($s->cancel($id,1),'Annulation disponible');check(!$s->issue(1,$date,1,1),'Annulation ne permet pas un second ticket');refused(fn()=>$s->consume($id,1),'Ticket annulé refusé');
    check($s->restore($id,1),'Réactivation du même ticket');
    $ticketId=(int)$db->fetchOne('SELECT id FROM ticket WHERE token=?',[$t['token']]);
    $badgeId=(int)$db->fetchOne('SELECT t.id FROM ticket t JOIN employe e ON e.id=t.employe_id WHERE e.badge_token=? AND t.date_repas=? AND t.type_repas_id=?',[str_repeat('a',64),$date,1]);
    check($ticketId===$badgeId,'Badge et ticket pointent vers le même droit');$s->consume($ticketId,1);refused(fn()=>$s->consume($badgeId,1),'Badge refusé après consommation du ticket');check((int)$db->fetchOne('SELECT COUNT(*) FROM consommation')===1,'Un seul repas enregistré');
    check(!$s->restore($id,1),'Ticket utilisé non réactivable');
    $future=(new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');check($s->issue(1,$future,1,1),'Autre jour autorisé');$futureId=(int)$db->fetchOne('SELECT id FROM ticket WHERE date_repas=?',[$future]);refused(fn()=>$s->consume($futureId,1),'Ticket futur refusé');
    check($s->issue(1,$date,2,1),'Autre type de repas autorisé');$second=(int)$db->fetchOne('SELECT id FROM ticket WHERE date_repas=? AND type_repas_id=2',[$date]);
    $db->update('employe',['actif'=>0],['id'=>1]);refused(fn()=>$s->consume($second,1),'Employé désactivé refusé');$db->update('employe',['actif'=>1],['id'=>1]);
    $db->update('type_repas',['actif'=>0],['id'=>2]);refused(fn()=>$s->consume($second,1),'Repas désactivé refusé');$db->update('type_repas',['actif'=>1],['id'=>2]);
    // Une erreur d'enregistrement ne doit pas consommer le ticket.
    $db->executeStatement("CREATE TRIGGER echec BEFORE INSERT ON consommation BEGIN SELECT RAISE(ABORT,'Erreur simulée'); END");
    try{$s->consume($second,1);throw new RuntimeException('Le déclencheur devrait refuser.');}catch(\Doctrine\DBAL\Exception){}
    check($s->ticket($second)['statut']==='DISPONIBLE','Transaction annulée : ticket conservé disponible');$db->executeStatement('DROP TRIGGER echec');
    $s->consume($second,1);check((int)$db->fetchOne('SELECT COUNT(*) FROM consommation')===2,'Second type de repas consommé une fois');
    refused(fn()=>$s->issue(1,'2026-02-30',1,1),'Date inexistante refusée');
    $db->update('service',['nom'=>'Nouveau service'],['id'=>1]);check($db->fetchOne('SELECT service_nom FROM consommation WHERE ticket_id=?',[$id])==='Service test','Historique indépendant du changement de service');
    echo "$checks contrôles métier réussis.".PHP_EOL;
} finally { $db->close();@unlink($file); }
