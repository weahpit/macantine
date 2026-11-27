<?php
namespace App\Service;
use Doctrine\DBAL\Connection;
final class Cantine {
 public function __construct(public readonly Connection $db,private readonly Store $store,private readonly string $timezone){}
 public function now():string{return $this->store->now();}
 public function today():string{return $this->store->today();}
 public function date(string $value):string{return $this->store->date($value);}
 public function categories():array{return $this->db->fetchAllAssociative('SELECT * FROM categorie_tarifaire ORDER BY prix_public');}
 public function plats(bool $active=false):array{return $this->db->fetchAllAssociative('SELECT p.*,c.nom AS categorie_nom,c.prix_public,c.prix_employe,c.actif AS categorie_actif FROM plat p JOIN categorie_tarifaire c ON c.id=p.categorie_id'.($active?' WHERE p.actif=1 AND c.actif=1':'').' ORDER BY p.nom');}
 public function menu(string $date):array {
  $this->date($date);$override=$this->db->fetchOne('SELECT date_menu FROM menu_exception WHERE date_menu=?',[$date]);
  if($override){$ids=array_column($this->db->fetchAllAssociative('SELECT plat_id FROM menu_date WHERE date_menu=?',[$date]),'plat_id');}
  else{$day=(int)(new \DateTimeImmutable($date))->format('N');$ids=array_column($this->db->fetchAllAssociative('SELECT plat_id FROM menu_semaine WHERE jour=?',[$day]),'plat_id');}
  return array_values(array_filter($this->plats(true),fn($p)=>in_array($p['id'],$ids)));
 }
 public function ticket(int $id):array|false{return $this->db->fetchAssociative('SELECT t.*,e.matricule,e.nom,e.prenoms,e.actif AS employe_actif,e.service_id,s.direction_id,s.nom AS service_nom,d.nom AS direction_nom,p.nom AS plat_indicatif_nom FROM ticket t JOIN employe e ON e.id=t.employe_id JOIN service s ON s.id=e.service_id JOIN direction d ON d.id=s.direction_id LEFT JOIN plat p ON p.id=t.plat_indicatif_id WHERE t.id=?',[$id]);}
 public function workError(int $employee,?\DateTimeImmutable $now=null):?string {
  $now=$now??new \DateTimeImmutable('now',new \DateTimeZone($this->timezone));$now=$now->setTimezone(new \DateTimeZone($this->timezone));$stamp=$now->format('Y-m-d H:i:s');
  if(!$this->db->fetchOne('SELECT id FROM employe WHERE id=? AND actif=1',[$employee])){return 'Travailleur inactif ou inconnu.';}
  if($this->db->fetchOne('SELECT id FROM absence WHERE employe_id=? AND debut<=? AND fin>?',[$employee,$stamp,$stamp])){return 'Travailleur déclaré absent sur cette période.';}
  // Évaluer aussi la plage commencée la veille pour les quarts de nuit.
  foreach([$now->format('Y-m-d'),$now->modify('-1 day')->format('Y-m-d')] as $anchor){
   $day=(int)(new \DateTimeImmutable($anchor))->format('N');
   $rows=$this->db->fetchAllAssociative('SELECT p.debut,p.fin FROM affectation_horaire a JOIN plage_horaire p ON p.type_horaire_id=a.type_horaire_id WHERE a.employe_id=? AND a.date_debut<=? AND (a.date_fin IS NULL OR a.date_fin>=?) AND p.jour=?',[$employee,$anchor,$anchor,$day]);
   foreach($rows as $p){$start=new \DateTimeImmutable($anchor.' '.$p['debut'],new \DateTimeZone($this->timezone));$end=new \DateTimeImmutable($anchor.' '.$p['fin'],new \DateTimeZone($this->timezone));if($end<=$start){$end=$end->modify('+1 day');}if($now>=$start && $now<$end){return null;}}
  }
  return 'Hors période de travail autorisée : vérifiez le type horaire, les affectations et les plages.';
 }
 public function validity(array $t):?string {
  if($t['statut']==='UTILISE'){return 'Ticket déjà utilisé.';}
  if($t['statut']!=='DISPONIBLE'){return 'Ticket annulé ou indisponible.';}
  if(!$t['categorie_id'] || $t['prix_employe']===null){return 'Ancien ticket à régulariser par la RH : catégorie tarifaire manquante.';}
  if(!(int)$t['employe_actif']){return 'Travailleur désactivé.';}
  return $this->workError((int)$t['employe_id']);
 }
 public function issue(int $employee,int $plate,int $agent):int {
  return $this->db->transactional(function()use($employee,$plate,$agent){
   if(!$this->db->fetchOne('SELECT id FROM employe WHERE id=? AND actif=1',[$employee])){throw new \DomainException('Employé inactif ou inconnu.');}
   $p=$this->db->fetchAssociative('SELECT p.*,c.nom AS categorie_nom,c.prix_public,c.prix_employe FROM plat p JOIN categorie_tarifaire c ON c.id=p.categorie_id WHERE p.id=? AND p.actif=1 AND c.actif=1',[$plate]);if(!$p){throw new \DomainException('Plat ou catégorie indisponible.');}
   if($this->db->fetchOne('SELECT id FROM ticket WHERE employe_id=? AND date_emission=?',[$employee,$this->today()])){throw new \DomainException('Cet employé a déjà reçu un ticket aujourd’hui, même si celui-ci a été annulé.');}
   $type=(int)$this->db->fetchOne('SELECT id FROM type_repas ORDER BY id LIMIT 1');if(!$type){throw new \DomainException('Initialisez les types de repas.');}
   // Verrou transactionnel : génération et réservation atomiques des numéros courts.
   $this->db->executeStatement('SELECT pg_advisory_xact_lock(73621044)');$numero=null;for($attempt=0;$attempt<128;$attempt++){$candidate=chr(random_int(65,90)).chr(random_int(65,90)).sprintf('%04d',random_int(0,9999));if(!$this->db->fetchOne('SELECT id FROM ticket WHERE numero=?',[$candidate])){$numero=$candidate;break;}}if($numero===null){throw new \DomainException('Impossible de réserver un numéro unique. Réessayez.');}
   $row=['numero'=>$numero,'token'=>bin2hex(random_bytes(32)),'employe_id'=>$employee,'date_repas'=>$this->today(),'date_emission'=>$this->today(),'type_repas_id'=>$type,'statut'=>'DISPONIBLE','cree_le'=>$this->now(),'cree_par'=>$agent,'categorie_id'=>$p['categorie_id'],'categorie_nom'=>$p['categorie_nom'],'plat_indicatif_id'=>$plate,'prix_public'=>$p['prix_public'],'prix_employe'=>$p['prix_employe'],'subvention'=>(int)$p['prix_public']-(int)$p['prix_employe']];
   $this->db->insert('ticket',$row);$id=(int)$this->db->fetchOne('SELECT id FROM ticket WHERE numero=?',[$row['numero']]);$this->store->audit($agent,'DELIVRANCE_TICKET',$row['numero'].' ; paiement différé à la cantine');return $id;
  });
 }
 public function regularize(int $id,int $category,int $agent):void {
  $this->db->transactional(function()use($id,$category,$agent){$c=$this->db->fetchAssociative('SELECT * FROM categorie_tarifaire WHERE id=? AND actif=1',[$category]);if(!$c){throw new \DomainException('Catégorie inconnue.');}
   $n=$this->db->executeStatement("UPDATE ticket SET categorie_id=?,categorie_nom=?,prix_public=?,prix_employe=?,subvention=? WHERE id=? AND statut='DISPONIBLE' AND categorie_id IS NULL",[$category,$c['nom'],$c['prix_public'],$c['prix_employe'],(int)$c['prix_public']-(int)$c['prix_employe'],$id]);if(!$n){throw new \DomainException('Ce ticket ne peut être régularisé.');}$this->store->audit($agent,'REGULARISATION_TICKET','Ticket #'.$id.' ; catégorie '.$c['nom']);});
 }
 public function consume(int $id,int $plate,string $mode,int $agent):void {
  if(!in_array($mode,['ESPECES','MOBILE_MONEY','CARTE'],true)){throw new \DomainException('Mode de paiement invalide.');}
  $this->db->transactional(function()use($id,$plate,$mode,$agent){
   $t=$this->ticket($id);if(!$t){throw new \DomainException('Ticket inconnu.');}
   // Verrou employé : sérialise service/désactivation/affectations administrées par l’application.
   $this->db->fetchOne('SELECT id FROM employe WHERE id=? FOR UPDATE',[$t['employe_id']]);
   $error=$this->validity($t);if($error){throw new \DomainException($error);}
   $p=null;foreach($this->menu($this->today()) as $candidate){if((int)$candidate['id']===$plate){$p=$candidate;break;}}
   if(!$p || (int)$p['categorie_id']!==(int)$t['categorie_id']){throw new \DomainException('Choisissez un plat au menu du jour de la catégorie du ticket.');}
   $n=$this->db->executeStatement("UPDATE ticket SET statut='UTILISE' WHERE id=? AND statut='DISPONIBLE'",[$id]);if($n!==1){throw new \DomainException('Ticket déjà utilisé ou validation concurrente.');}
   $cons=['ticket_id'=>$id,'employe_id'=>$t['employe_id'],'type_repas_id'=>$t['type_repas_id'],'date_repas'=>$this->today(),'servi_le'=>$this->now(),'agent_id'=>$agent,'matricule'=>$t['matricule'],'employe_nom'=>$t['nom'].' '.$t['prenoms'],'service_nom'=>$t['service_nom'],'direction_nom'=>$t['direction_nom'],'service_id'=>$t['service_id'],'direction_id'=>$t['direction_id'],'repas_nom'=>$t['categorie_nom'],'plat_servi_id'=>$plate,'plat_servi_nom'=>$p['nom'],'categorie_nom'=>$t['categorie_nom']];
   $this->db->insert('consommation',$cons);$cid=(int)$this->db->fetchOne('SELECT id FROM consommation WHERE ticket_id=?',[$id]);
   $this->db->insert('encaissement',['ticket_id'=>$id,'consommation_id'=>$cid,'employe_id'=>$t['employe_id'],'date_encaissement'=>$this->now(),'agent_id'=>$agent,'montant'=>$t['prix_employe'],'prix_public'=>$t['prix_public'],'subvention'=>$t['subvention'],'categorie_nom'=>$t['categorie_nom'],'mode_paiement'=>$mode]);
   $this->store->audit($agent,'ENCAISSEMENT_ET_SERVICE',$t['numero'].' ; '.$t['prix_employe'].' FCFA ; '.$mode);
  });
 }
 public function report(string $start,string $end,int $employee=0,int $service=0,int $direction=0,int $category=0):array {
  $this->date($start);$this->date($end);if($start>$end){throw new \DomainException('Période invalide.');}
  $where='t.date_emission BETWEEN ? AND ?';$params=[$start,$end];$filter='';$fp=[];
  foreach(['t.employe_id'=>$employee,'e.service_id'=>$service,'s.direction_id'=>$direction,'t.categorie_id'=>$category] as $col=>$v){if($v){$filter.=' AND '.$col.'=?';$fp[]=$v;}}
  $base=' FROM ticket t JOIN employe e ON e.id=t.employe_id JOIN service s ON s.id=e.service_id JOIN direction d ON d.id=s.direction_id';
  $tickets=$this->db->fetchAllAssociative('SELECT t.numero,t.date_emission,t.statut,t.categorie_nom,t.prix_public,t.prix_employe,t.subvention,e.matricule,e.nom,e.prenoms,s.nom AS service_nom,d.nom AS direction_nom'.$base.' WHERE '.$where.$filter.' ORDER BY t.date_emission,t.numero',array_merge($params,$fp));
  $moneyFilter='';$moneyParams=[];foreach(['t.employe_id'=>$employee,'COALESCE(c.service_id,e.service_id)'=>$service,'COALESCE(c.direction_id,s.direction_id)'=>$direction,'t.categorie_id'=>$category] as $col=>$v){if($v){$moneyFilter.=' AND '.$col.'=?';$moneyParams[]=$v;}}
  $money=$this->db->fetchAllAssociative('SELECT x.*,t.numero,e.matricule,c.employe_nom,c.service_nom,c.direction_nom,c.plat_servi_nom'.$base.' JOIN encaissement x ON x.ticket_id=t.id JOIN consommation c ON c.id=x.consommation_id WHERE SUBSTRING(x.date_encaissement,1,10) BETWEEN ? AND ?'.$moneyFilter.' ORDER BY x.date_encaissement',array_merge($params,$moneyParams));
  $totals=['tickets'=>count($tickets),'repas'=>count($money),'encaisse'=>0,'public'=>0,'subvention'=>0];foreach($money as $m){$totals['encaisse']+=(int)$m['montant'];$totals['public']+=(int)$m['prix_public'];$totals['subvention']+=(int)$m['subvention'];}
  // Montants par jour (jours avec émission ou encaissement) et cumul progressif sur la période.
  $days=[];$blank=['tickets'=>0,'repas'=>0,'encaisse'=>0,'public'=>0,'subvention'=>0];
  foreach($tickets as $t){$d=$t['date_emission'];$days[$d]??=$blank;$days[$d]['tickets']++;}
  foreach($money as $m){$d=substr($m['date_encaissement'],0,10);$days[$d]??=$blank;$days[$d]['repas']++;$days[$d]['encaisse']+=(int)$m['montant'];$days[$d]['public']+=(int)$m['prix_public'];$days[$d]['subvention']+=(int)$m['subvention'];}
  ksort($days);$cum=['repas'=>0,'encaisse'=>0,'public'=>0,'subvention'=>0];$jours=[];
  foreach($days as $d=>$v){foreach($cum as $k=>$_){$cum[$k]+=$v[$k];}$jours[]=['jour'=>$d,'tickets_delivres'=>$v['tickets'],'repas_servis'=>$v['repas'],'encaisse_fcfa'=>$v['encaisse'],'valeur_publique_fcfa'=>$v['public'],'subvention_fcfa'=>$v['subvention'],'cumul_repas'=>$cum['repas'],'cumul_encaisse_fcfa'=>$cum['encaisse'],'cumul_valeur_publique_fcfa'=>$cum['public'],'cumul_subvention_fcfa'=>$cum['subvention']];}
  return ['tickets'=>$tickets,'paiements'=>$money,'jours'=>$jours,'totaux'=>$totals,'debut'=>$start,'fin'=>$end];
 }
}
