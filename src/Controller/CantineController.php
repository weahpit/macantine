<?php
namespace App\Controller;
use App\Service\Store;
use App\Service\Cantine;
use App\Security\User;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
final class CantineController extends AbstractController {
 public function __construct(private readonly Store $s,private readonly Cantine $c,#[Autowire('%kernel.project_dir%')]private readonly string $projectDir){}
 private function actor():User{$u=$this->getUser();if(!$u instanceof User){throw $this->createAccessDeniedException();}return $u;}
 private function permit(array $roles):void{if(!in_array($this->actor()->role,$roles,true)){throw $this->createAccessDeniedException('Action non autorisée.');}}
 private function csrf(Request $r,string $key):void{if(!$this->isCsrfTokenValid($key,(string)$r->request->get('_token'))){throw $this->createAccessDeniedException('Formulaire expiré.');}}
 private function text(Request $r,string $key,int $max=100,bool $required=true):string{$v=trim((string)$r->request->get($key,''));if(($required && $v==='')||mb_strlen($v)>$max){throw new \DomainException('Champ '.$key.' invalide.');}return $v;}
 private function fail(\Throwable $e,string $duplicate='Doublon refusé : cette valeur existe déjà.'):void{if($e instanceof \DomainException||$e instanceof \InvalidArgumentException||$e instanceof \UnexpectedValueException){$this->addFlash('error',$e->getMessage());}elseif($e instanceof \Doctrine\DBAL\Exception\UniqueConstraintViolationException){$this->addFlash('error',$duplicate);}elseif($e instanceof \Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException){$this->addFlash('error','Référence invalide : un élément sélectionné n’existe plus ou est utilisé ailleurs.');}else{throw $e;}}
 private function ownedTicket(int $id):array{$t=$this->c->ticket($id);if(!$t){throw $this->createNotFoundException();}if(!in_array($this->actor()->role,['ROLE_ADMIN','ROLE_RH'],true) && (int)$t['employe_id']!==(int)$this->actor()->employeId){throw $this->createAccessDeniedException();}return $t;}
 private function ownedBadge(int $id):array{$e=$this->s->db->fetchAssociative('SELECT e.*,s.nom AS service_nom FROM employe e JOIN service s ON s.id=e.service_id WHERE e.id=?',[$id]);if(!$e){throw $this->createNotFoundException();}if(!in_array($this->actor()->role,['ROLE_ADMIN','ROLE_RH'],true) && $this->actor()->employeId!==$id){throw $this->createAccessDeniedException();}return $e;}
 #[Route('/',name:'accueil',methods:['GET'])]
 public function home():Response {
  if($this->actor()->role==='ROLE_EMPLOYE'){return $this->redirectToRoute('tickets');}
  $end=$this->c->today();$start=(new \DateTimeImmutable($end))->modify('-6 days')->format('Y-m-d');$report=$this->c->report($start,$end);
  $rows=$this->s->db->fetchAllAssociative('SELECT SUBSTRING(date_encaissement,1,10) AS jour,COUNT(*) AS repas,SUM(montant) AS montant FROM encaissement WHERE SUBSTRING(date_encaissement,1,10) BETWEEN ? AND ? GROUP BY SUBSTRING(date_encaissement,1,10) ORDER BY jour',[$start,$end]);
  $series=[];$max=1;for($i=0;$i<7;$i++){$d=(new \DateTimeImmutable($start))->modify('+'.$i.' days')->format('Y-m-d');$v=0;$n=0;foreach($rows as $row){if($row['jour']===$d){$v=(int)$row['montant'];$n=(int)$row['repas'];}}$series[]=['date'=>$d,'montant'=>$v,'repas'=>$n];$max=max($max,$v);}
  return $this->render('accueil.html.twig',['report'=>$report,'series'=>$series,'max'=>$max,'today'=>$end,'platsJour'=>$this->c->menu($end),'disponibles'=>$this->s->db->fetchOne("SELECT COUNT(*) FROM ticket WHERE statut='DISPONIBLE'")]);
 }
 #[Route('/tickets',name:'tickets',methods:['GET','POST'])]
 public function tickets(Request $r):Response {
  if($r->isMethod('POST')){$this->permit(['ROLE_ADMIN','ROLE_RH']);$this->csrf($r,'emission');try{$id=$this->c->issue($r->request->getInt('employe_id'),$r->request->getInt('plat_id'),$this->actor()->id);$this->addFlash('success','Ticket délivré. Aucun paiement chez la RH : règlement à la cantine.');return $this->redirectToRoute('imprimer_ticket',['id'=>$id]);}catch(\Throwable $e){$this->fail($e,'Doublon refusé : un seul ticket délivré par employé et par jour.');}return $this->redirectToRoute('tickets');}
  $u=$this->actor();$params=[];$where='1=1';if($u->role==='ROLE_EMPLOYE'){$where.=' AND t.employe_id=?';$params[]=$u->employeId??0;}
  $q=trim((string)$r->query->get('q',''));if($q!==''){$where.=' AND (t.numero LIKE ? OR e.matricule LIKE ? OR e.nom LIKE ?)';array_push($params,'%'.$q.'%','%'.$q.'%','%'.$q.'%');}
  $status=(string)$r->query->get('statut','');if(in_array($status,['DISPONIBLE','UTILISE','ANNULE'],true)){$where.=' AND t.statut=?';$params[]=$status;}
  $page=max(1,$r->query->getInt('page',1));$sql=' FROM ticket t JOIN employe e ON e.id=t.employe_id LEFT JOIN plat p ON p.id=t.plat_indicatif_id WHERE '.$where;
  $total=$this->s->db->fetchOne('SELECT COUNT(*)'.$sql,$params);$rows=$this->s->db->fetchAllAssociative('SELECT t.*,e.matricule,e.nom,e.prenoms,p.nom AS plat_indicatif_nom'.$sql.' ORDER BY t.id DESC LIMIT 50 OFFSET '.(($page-1)*50),$params);
  return $this->render('tickets.html.twig',['tickets'=>$rows,'plats'=>$this->c->plats(true),'platsJour'=>$this->c->menu($this->c->today()),'today'=>$this->c->today(),'employes'=>in_array($u->role,['ROLE_ADMIN','ROLE_RH'],true)?$this->s->employes():[],'categories'=>$this->c->categories(),'q'=>$q,'statut'=>$status,'page'=>$page,'total'=>$total]);
 }
 #[Route('/tickets/{id}/{action}',name:'ticket_action',methods:['POST'],requirements:['id'=>'\d+','action'=>'annuler|reactiver|regulariser'])]
 public function ticketAction(int $id,string $action,Request $r):Response {
  $this->permit(['ROLE_ADMIN','ROLE_RH']);$this->csrf($r,'ticket_'.$id);
  try{if($action==='regulariser'){$this->c->regularize($id,$r->request->getInt('categorie_id'),$this->actor()->id);}else{$this->s->db->transactional(function()use($id,$action){$old=$action==='annuler'?'DISPONIBLE':'ANNULE';$new=$action==='annuler'?'ANNULE':'DISPONIBLE';$n=$this->s->db->executeStatement('UPDATE ticket SET statut=? WHERE id=? AND statut=?',[$new,$id,$old]);if(!$n){throw new \DomainException('Changement refusé : ticket déjà consommé ou statut incompatible.');}$this->s->audit($this->actor()->id,'STATUT_TICKET','Ticket #'.$id.' : '.$new);});}$this->addFlash('success','Ticket mis à jour.');}catch(\Throwable $e){$this->fail($e);}return $this->redirectToRoute('tickets');
 }
 #[Route('/controle',name:'controle',methods:['GET','POST'])]
 public function control(Request $r):Response {
  $this->permit(['ROLE_ADMIN','ROLE_CANTINE']);$ticket=null;$error=null;$candidates=[];$code='';
  if($r->isMethod('POST')){$this->csrf($r,'controle');$code=trim((string)$r->request->get('code',''));$id=false;
   if(str_starts_with($code,'CANTINE:T:')){$id=$this->s->db->fetchOne('SELECT id FROM ticket WHERE token=?',[substr($code,10)]);}
   elseif(str_starts_with($code,'CANTINE:B:')){
    $employee=$this->s->db->fetchOne('SELECT id FROM employe WHERE badge_token=?',[substr($code,10)]);
    if(!$employee){$error='Badge invalide ou renouvelé.';}else{$error=$this->c->workError((int)$employee);if(!$error){$rows=$this->s->db->fetchAllAssociative("SELECT id FROM ticket WHERE employe_id=? AND statut='DISPONIBLE' ORDER BY date_emission,id",[$employee]);foreach($rows as $row){$candidates[]=$this->c->ticket((int)$row['id']);}if(!$candidates){$error='Aucun ticket disponible.';}}}
   }else{$id=$this->s->db->fetchOne('SELECT id FROM ticket WHERE numero=?',[strtoupper($code)]);}
   if($id){$ticket=$this->c->ticket((int)$id);$error=$this->c->validity($ticket);}elseif(!$error && !$candidates){$error='Ticket invalide.';}
  }
  $menu=$this->c->menu($this->c->today());return $this->render('controle.html.twig',['ticket'=>$ticket,'error'=>$error,'candidates'=>$candidates,'menu'=>$menu,'code'=>$code]);
 }
 #[Route('/servir/{id}',name:'servir',methods:['POST'],requirements:['id'=>'\d+'])]
 public function serve(int $id,Request $r):Response {
  $this->permit(['ROLE_ADMIN','ROLE_CANTINE']);$this->csrf($r,'servir_'.$id);
  try{$this->c->consume($id,$r->request->getInt('plat_id'),(string)$r->request->get('mode_paiement'),$this->actor()->id);$this->addFlash('success','Paiement encaissé et repas servi. Le ticket est désormais utilisé.');}catch(\Throwable $e){$this->fail($e);}return $this->redirectToRoute('controle');
 }
 #[Route('/imprimer/ticket/{id}',name:'imprimer_ticket',methods:['GET'],requirements:['id'=>'\d+'])]
 public function printTicket(int $id,Request $r,\App\Service\Societe $societe):Response{$format=(string)$r->query->get('format','80');if(!in_array($format,['57','80'],true)){throw $this->createNotFoundException();}return $this->render('impression.html.twig',['kind'=>'ticket','item'=>$this->ownedTicket($id),'societe'=>$societe->get(),'format'=>$format]);}
 #[Route('/badge/{id}',name:'badge',methods:['GET'],requirements:['id'=>'\d+'])]
 public function badge(int $id,\App\Service\Societe $societe):Response{return $this->render('impression.html.twig',['kind'=>'badge','item'=>$this->ownedBadge($id),'societe'=>$societe->get(),'format'=>'badge']);}
 #[Route('/qr/{kind}/{id}',name:'qr',methods:['GET'],requirements:['kind'=>'ticket|badge','id'=>'\d+'])]
 public function qr(string $kind,int $id):Response{$data=$kind==='ticket'?'CANTINE:T:'.$this->ownedTicket($id)['token']:'CANTINE:B:'.$this->ownedBadge($id)['badge_token'];$png=(new PngWriter())->write(new QrCode(data:$data,size:320,margin:16));return new Response($png->getString(),200,['Content-Type'=>'image/png']);}
 #[Route('/menus',name:'menus',methods:['GET','POST'])]
 public function menus(Request $r):Response {
  if($r->isMethod('POST')){$this->permit(['ROLE_ADMIN','ROLE_CANTINE']);$this->csrf($r,'menus');try{
   $kind=(string)$r->request->get('nature');$ids=array_values(array_unique(array_map('intval',$r->request->all('plats'))));$all=array_column($this->c->plats(true),'id');foreach($ids as $id){if(!in_array($id,$all)){throw new \DomainException('Plat invalide.');}}
   $this->s->db->transactional(function()use($kind,$r,$ids){if($kind==='semaine'){$day=$r->request->getInt('jour');if($day<1||$day>7){throw new \DomainException('Jour invalide.');}$this->s->db->delete('menu_semaine',['jour'=>$day]);foreach($ids as $id){$this->s->db->insert('menu_semaine',['jour'=>$day,'plat_id'=>$id]);}}
    else{$date=$this->c->date($this->text($r,'date_menu',10));$this->s->db->delete('menu_date',['date_menu'=>$date]);if($kind==='retablir'){$this->s->db->delete('menu_exception',['date_menu'=>$date]);}else{if(!$this->s->db->fetchOne('SELECT date_menu FROM menu_exception WHERE date_menu=?',[$date])){$this->s->db->insert('menu_exception',['date_menu'=>$date]);}foreach($ids as $id){$this->s->db->insert('menu_date',['date_menu'=>$date,'plat_id'=>$id]);}}}$this->s->audit($this->actor()->id,'PROGRAMMATION_MENU',$kind);});$this->addFlash('success','Programmation enregistrée.');
   }catch(\Throwable $e){$this->fail($e);}return $this->redirectToRoute('menus');}
  $date=(string)$r->query->get('date',$this->c->today());try{$this->c->date($date);}catch(\Throwable $e){throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException($e->getMessage());}
  return $this->render('menus.html.twig',['menu'=>$this->c->menu($date),'plats'=>$this->c->plats(true),'date'=>$date,'programme'=>$this->s->db->fetchAllAssociative('SELECT m.jour,p.nom,c.nom AS categorie_nom FROM menu_semaine m JOIN plat p ON p.id=m.plat_id JOIN categorie_tarifaire c ON c.id=p.categorie_id ORDER BY m.jour,p.nom')]);
 }
 #[Route('/plats',name:'plats',methods:['GET','POST'])]
 public function plates(Request $r):Response {
  $this->permit(['ROLE_ADMIN','ROLE_CANTINE']);
  if($r->isMethod('POST')){$this->csrf($r,'plats');try{
   $kind=(string)$r->request->get('nature');$id=$r->request->getInt('id');
   if($kind==='categorie'){$pub=$r->request->getInt('prix_public');$price=$r->request->getInt('prix_employe');if($pub<0||$price<0||$price>$pub){throw new \DomainException('Prix invalides.');}$data=['nom'=>$this->text($r,'nom'),'prix_public'=>$pub,'prix_employe'=>$price,'actif'=>$r->request->get('actif')?1:0];$this->s->db->transactional(function()use($id,$data){if($id){$this->s->db->update('categorie_tarifaire',$data,['id'=>$id]);}else{$this->s->db->insert('categorie_tarifaire',$data);}$this->s->audit($this->actor()->id,'GRILLE_TARIFAIRE',$data['nom']);});}
   else{$cat=$r->request->getInt('categorie_id');if(!$this->s->db->fetchOne('SELECT id FROM categorie_tarifaire WHERE id=?',[$cat])){throw new \DomainException('Catégorie invalide.');}$data=['nom'=>$this->text($r,'nom',150),'description'=>$this->text($r,'description',3000,false),'categorie_id'=>$cat,'actif'=>$r->request->get('actif')?1:0];
    $file=$r->files->get('photo');if($file){if(!$file->isValid()||$file->getSize()>5*1024*1024){throw new \DomainException('Photo invalide ou supérieure à 5 Mo.');}$mime=(new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname());if(!in_array($mime,['image/jpeg','image/png','image/webp'],true)){throw new \DomainException('Photo JPEG, PNG ou WebP uniquement.');}$size=getimagesize($file->getPathname());if(!$size||$size[0]>6000||$size[1]>6000||$size[0]*$size[1]>12000000){throw new \DomainException('Dimensions photo invalides (6000 px par côté et 12 mégapixels maximum).');}$img=imagecreatefromstring(file_get_contents($file->getPathname()));if(!$img){throw new \DomainException('Image illisible.');}$scale=min(1,1200/max($size[0],$size[1]));$dest=imagecreatetruecolor(max(1,(int)($size[0]*$scale)),max(1,(int)($size[1]*$scale)));imagefill($dest,0,0,imagecolorallocate($dest,255,255,255));imagecopyresampled($dest,$img,0,0,0,0,imagesx($dest),imagesy($dest),$size[0],$size[1]);$dir=$this->projectDir.'/var/photos';if(!is_dir($dir)){mkdir($dir,0770,true);}$name=bin2hex(random_bytes(16)).'.jpg';if(!imagejpeg($dest,$dir.'/'.$name,85)){throw new \DomainException('Échec de stockage photo.');}$data['photo']=$name;unset($img,$dest);}
    $this->s->db->transactional(function()use($id,$data){if($id){$this->s->db->update('plat',$data,['id'=>$id]);}else{$this->s->db->insert('plat',$data);}$this->s->audit($this->actor()->id,'PLAT',$data['nom']);});}
   $this->addFlash('success','Enregistrement effectué.');}catch(\Throwable $e){$this->fail($e);}return $this->redirectToRoute('plats');}
  $edit=$r->query->getInt('modifier');return $this->render('plats.html.twig',['plats'=>$this->c->plats(),'categories'=>$this->c->categories(),'edit'=>$edit?$this->s->db->fetchAssociative('SELECT * FROM plat WHERE id=?',[$edit]):null]);
 }
 #[Route('/photo/{id}',name:'photo',methods:['GET'],requirements:['id'=>'\d+'])]
 public function photo(int $id):Response{$name=$this->s->db->fetchOne('SELECT photo FROM plat WHERE id=?',[$id]);if(!$name||!preg_match('/^[a-f0-9]{32}\.jpg$/',$name)||!is_file($this->projectDir.'/var/photos/'.$name)){return new BinaryFileResponse($this->projectDir.'/public/plat-defaut.svg',200,['Content-Type'=>'image/svg+xml']);}return new BinaryFileResponse($this->projectDir.'/var/photos/'.$name,200,['Content-Type'=>'image/jpeg']);}
 private function schedulePage(Request $r,string $route,string $page):Response {
  $this->permit(['ROLE_ADMIN','ROLE_RH']);
  if($r->isMethod('POST')){$this->csrf($r,'horaires');try{$kind=(string)$r->request->get('nature');
   $this->s->db->transactional(function()use($r,$kind){
    if($kind==='type'){$this->s->db->insert('type_horaire',['nom'=>$this->text($r,'nom')]);}
    elseif($kind==='plage'){$type=$r->request->getInt('type_horaire_id');$day=$r->request->getInt('jour');$start=$this->text($r,'debut',5);$end=$this->text($r,'fin',5);if($day<1||$day>7||!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$start)||!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$end)){throw new \DomainException('Plage horaire invalide.');}$this->s->db->insert('plage_horaire',['type_horaire_id'=>$type,'jour'=>$day,'debut'=>$start,'fin'=>$end]);}
    elseif(in_array($kind,['affectation','absence'],true)){$emp=$r->request->getInt('employe_id');if(!$this->s->db->fetchOne('SELECT id FROM employe WHERE id=? FOR UPDATE',[$emp])){throw new \DomainException('Employé inconnu.');}
     if($kind==='affectation'){$start=$this->c->date($this->text($r,'date_debut',10));$end=$this->text($r,'date_fin',10,false);if($end!==''){$this->c->date($end);if($end<$start){throw new \DomainException('Période inversée.');}}if($this->s->db->fetchOne('SELECT id FROM affectation_horaire WHERE employe_id=? AND date_debut<=? AND (date_fin IS NULL OR date_fin>=?)',[$emp,$end?:'9999-12-31',$start])){throw new \DomainException('Une affectation couvre déjà cette période.');}$this->s->db->insert('affectation_horaire',['employe_id'=>$emp,'type_horaire_id'=>$r->request->getInt('type_horaire_id'),'date_debut'=>$start,'date_fin'=>$end?:null]);}
     else{$start=str_replace('T',' ',$this->text($r,'debut',16)).':00';$end=str_replace('T',' ',$this->text($r,'fin',16)).':00';foreach([$start,$end] as $dt){$parsed=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$dt);if(!$parsed||$parsed->format('Y-m-d H:i:s')!==$dt){throw new \DomainException('Date/heure invalide.');}}if($end<=$start){throw new \DomainException('Période d’absence inversée.');}$this->s->db->insert('absence',['employe_id'=>$emp,'debut'=>$start,'fin'=>$end,'motif'=>$this->text($r,'motif',150)]);}}
    elseif(str_starts_with($kind,'supprimer_')){$map=['supprimer_plage'=>'plage_horaire','supprimer_affectation'=>'affectation_horaire','supprimer_absence'=>'absence'];if(!isset($map[$kind])){throw new \DomainException('Action invalide.');}$table=$map[$kind];$id=$r->request->getInt('id');if($table!=='plage_horaire'){$emp=$this->s->db->fetchOne('SELECT employe_id FROM '.$table.' WHERE id=?',[$id]);if($emp){$this->s->db->fetchOne('SELECT id FROM employe WHERE id=? FOR UPDATE',[$emp]);}}$this->s->db->delete($table,['id'=>$id]);}
    else{throw new \DomainException('Action invalide.');}
    $this->s->audit($this->actor()->id,'HORAIRES',$kind);
   });$this->addFlash('success','Programmation mise à jour.');}catch(\Throwable $e){$this->fail($e);}return $this->redirectToRoute($route);}
  return $this->render($page==='apercu'?'horaires.html.twig':'horaires_page.html.twig',['page'=>$page,'employes'=>$this->s->employes(),'types'=>$this->s->db->fetchAllAssociative('SELECT * FROM type_horaire ORDER BY nom'),'plages'=>$this->s->db->fetchAllAssociative('SELECT p.*,t.nom AS type_nom FROM plage_horaire p JOIN type_horaire t ON t.id=p.type_horaire_id ORDER BY t.nom,p.jour,p.debut'),'affectations'=>$this->s->db->fetchAllAssociative('SELECT a.*,e.nom,e.prenoms,e.matricule,t.nom AS type_nom FROM affectation_horaire a JOIN employe e ON e.id=a.employe_id JOIN type_horaire t ON t.id=a.type_horaire_id ORDER BY e.nom,a.date_debut'),'absences'=>$this->s->db->fetchAllAssociative('SELECT a.*,e.nom,e.prenoms,e.matricule FROM absence a JOIN employe e ON e.id=a.employe_id ORDER BY a.debut DESC')]);
 }
 #[Route('/horaires',name:'horaires',methods:['GET','POST'])]
 public function schedules(Request $r):Response{return $this->schedulePage($r,'horaires','apercu');}
 #[Route('/horaires/types',name:'horaires_types',methods:['GET','POST'])]
 public function scheduleTypes(Request $r):Response{return $this->schedulePage($r,'horaires_types','types');}
 #[Route('/horaires/plages',name:'horaires_plages',methods:['GET','POST'])]
 public function scheduleRanges(Request $r):Response{return $this->schedulePage($r,'horaires_plages','plages');}
 #[Route('/horaires/affectations',name:'horaires_affectations',methods:['GET','POST'])]
 public function scheduleAssignments(Request $r):Response{return $this->schedulePage($r,'horaires_affectations','affectations');}
 #[Route('/horaires/absences',name:'horaires_absences',methods:['GET','POST'])]
 public function scheduleAbsences(Request $r):Response{return $this->schedulePage($r,'horaires_absences','absences');}
 private function filteredReport(Request $r):array {
  $this->permit(['ROLE_ADMIN','ROLE_RH','ROLE_COMPTABILITE']);try{return $this->c->report((string)$r->query->get('debut',substr($this->c->today(),0,7).'-01'),(string)$r->query->get('fin',$this->c->today()),$r->query->getInt('employe_id'),$r->query->getInt('service_id'),$r->query->getInt('direction_id'),$r->query->getInt('categorie_id'));}catch(\DomainException|\InvalidArgumentException|\UnexpectedValueException $e){throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException($e->getMessage());}
 }
 #[Route('/statistiques',name:'statistiques',methods:['GET'])]
 public function reports(Request $r,\App\Service\Societe $societe):Response{$report=$this->filteredReport($r);return $this->render('statistiques.html.twig',['societe'=>$societe->get(),'hasLogo'=>$societe->logoPath()!==null,'report'=>$report,'employes'=>$this->s->employes(),'services'=>$this->s->db->fetchAllAssociative('SELECT * FROM service ORDER BY nom'),'directions'=>$this->s->db->fetchAllAssociative('SELECT * FROM direction ORDER BY nom'),'categories'=>$this->c->categories(),'filtres'=>$r->query->all()]);}
 #[Route('/export/{format}',name:'export',methods:['GET'],requirements:['format'=>'pdf|xlsx'])]
 public function export(Request $r,string $format,\App\Service\Societe $societe):Response {
  $report=$this->filteredReport($r);$this->s->audit($this->actor()->id,'EXPORT_'.strtoupper($format),$report['debut'].' / '.$report['fin']);
  $co=$societe->get();$logo=$societe->logoPath();
  if($format==='pdf'){$options=new Options();$options->set('isRemoteEnabled',false);$options->set('isPhpEnabled',false);$options->set('isJavascriptEnabled',false);$pdf=new Dompdf($options);$pdf->loadHtml($this->renderView('rapport_pdf.html.twig',['report'=>$report,'societe'=>$co,'logo'=>$logo?'data:image/png;base64,'.base64_encode((string)file_get_contents($logo)):null]));$pdf->setPaper('A4','landscape');$pdf->render();return new Response($pdf->output(),200,['Content-Type'=>'application/pdf','Content-Disposition'=>'attachment; filename="etat-cantine.pdf"']);}
  $book=new Spreadsheet();$summary=$book->getActiveSheet();$summary->setTitle('Synthèse');$row=1;
  // Entête société : logo (si présent) puis nom, adresse, téléphone, e-mail, identifiant fiscal. Texte écrit en chaîne explicite (jamais interprété comme formule).
  if($logo){$img=new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();$img->setName('Logo');$img->setPath($logo);$img->setHeight(60);$img->setCoordinates('A1');$img->setWorksheet($summary);foreach([1,2,3] as $h){$summary->getRowDimension($h)->setRowHeight(20);}$row=4;}
  $first=$row;foreach([$co['nom'],$co['adresse'],$co['telephone'],$co['email'],$co['identifiant_fiscal']] as $line){if((string)$line!==''){$summary->setCellValueExplicit('A'.$row,(string)$line,DataType::TYPE_STRING);$row++;}}
  if($row>$first){$summary->getStyle('A'.$first)->getFont()->setBold(true)->setSize(14);$row++;}
  $summary->fromArray([['État cantine',$report['debut'],$report['fin']],['Tickets délivrés',$report['totaux']['tickets']],['Repas encaissés',$report['totaux']['repas']],['Encaissement employé FCFA',$report['totaux']['encaisse']],['Subvention théorique FCFA',$report['totaux']['subvention']],['Valeur publique FCFA',$report['totaux']['public']]],null,'A'.$row);
  $summary->getStyle('A'.$row)->getFont()->setBold(true);
  foreach(['Par jour'=>$report['jours'],'Tickets'=>$report['tickets'],'Encaissements'=>$report['paiements']] as $name=>$rows){$sheet=$book->createSheet();$sheet->setTitle($name);if($rows){$headers=array_keys($rows[0]);$sheet->fromArray($headers);foreach($rows as $i=>$rw){foreach(array_values($rw) as $j=>$v){$cell=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($j+1).($i+2);$sheet->setCellValueExplicit($cell,is_int($v)||is_float($v)?$v:($v===null?'':(string)$v),is_int($v)||is_float($v)?DataType::TYPE_NUMERIC:DataType::TYPE_STRING);}}$sheet->setAutoFilter($sheet->calculateWorksheetDimension());}$sheet->freezePane('A2');$sheet->getStyle('1:1')->getFont()->setBold(true);}
  foreach($book->getAllSheets() as $sheet){foreach(range('A','Z') as $col){$sheet->getColumnDimension($col)->setWidth(22);}}
  $dir=$this->projectDir.'/var/exports';if(!is_dir($dir)){mkdir($dir,0770,true);}$file=tempnam($dir,'etat-');try{(new Xlsx($book))->save($file);$bytes=file_get_contents($file);}finally{@unlink($file);$book->disconnectWorksheets();}return new Response($bytes,200,['Content-Type'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','Content-Disposition'=>'attachment; filename="etat-cantine.xlsx"']);
 }
    #[Route('/connexion',name:'connexion',methods:['GET','POST'])]
    public function login(AuthenticationUtils $auth): Response {
        if ($this->getUser()) { return $this->redirectToRoute('accueil'); }
        return $this->render('connexion.html.twig',['last'=>$auth->getLastUsername(),'error'=>$auth->getLastAuthenticationError()]);
    }
    #[Route('/deconnexion',name:'deconnexion',methods:['POST'])]
    public function logout(): never { throw new \LogicException('Interception par le pare-feu.'); }
    #[Route('/employes',name:'employes',methods:['GET','POST'])]
    public function employees(Request $r): Response {
        $this->permit(['ROLE_ADMIN','ROLE_RH']);
        if ($r->isMethod('POST')) {
            $this->csrf($r,'employe');
            try {
                $id=$r->request->getInt('id');$service=$r->request->getInt('service_id');
                if (!$this->s->db->fetchOne('SELECT id FROM service WHERE id=?',[$service])) { throw new \InvalidArgumentException('Choisissez un service existant.'); }
                $data=['matricule'=>$this->text($r,'matricule',50),'nom'=>$this->text($r,'nom',100),'prenoms'=>$this->text($r,'prenoms',150),'service_id'=>$service,'telephone'=>$this->s->phone((string)$r->request->get('telephone','')),'actif'=>$r->request->get('actif')?1:0];
                $this->s->db->transactional(function() use($id,$data){
                    if($id){if(!$this->s->db->fetchOne('SELECT id FROM employe WHERE id=? FOR UPDATE',[$id])){throw new \InvalidArgumentException('Employé introuvable.');}$this->s->db->update('employe',$data,['id'=>$id]);}
                    else{$data['badge_token']=bin2hex(random_bytes(32));$this->s->db->insert('employe',$data);}
                    $this->s->audit($this->actor()->id,$id?'MODIFICATION_EMPLOYE':'CREATION_EMPLOYE',$data['matricule']);
                });
                $this->addFlash('success','Employé enregistré.');
            } catch (\Throwable $e) { $this->fail($e,'Matricule ou numéro de téléphone déjà utilisé par un autre employé.'); }
            return $this->redirectToRoute('employes');
        }
        $edit=$r->query->getInt('modifier');$employee=$edit?$this->s->db->fetchAssociative('SELECT * FROM employe WHERE id=?',[$edit]):null;
        return $this->render('employes.html.twig',['q'=>(string)$r->query->get('q',''),'employes'=>$this->s->employes(trim((string)$r->query->get('q',''))),'services'=>$this->s->db->fetchAllAssociative('SELECT s.*,d.nom AS direction_nom FROM service s JOIN direction d ON d.id=s.direction_id ORDER BY d.nom,s.nom'),'edit'=>$employee]);
    }
    #[Route('/employes/{id}/pin',name:'employe_pin',methods:['POST'],requirements:['id'=>'\d+'])]
    public function employeePin(int $id,Request $r,UserPasswordHasherInterface $hasher): Response {
        $this->permit(['ROLE_ADMIN','ROLE_RH']);$this->csrf($r,'pin_'.$id);
        try{
            $e=$this->s->db->fetchAssociative('SELECT id,matricule,nom,prenoms,telephone FROM employe WHERE id=?',[$id]);if(!$e){throw new \DomainException('Employé introuvable.');}
            if((string)$r->request->get('nature')==='retirer'){
                $this->s->db->transactional(function()use($id,$e){$this->s->db->update('employe',['pin_hash'=>null,'pin_echecs'=>0,'pin_bloque_jusqua'=>null,'pin_modifie_le'=>$this->s->now()],['id'=>$id]);$this->s->db->delete('mobile_token',['employe_id'=>$id]);$this->s->audit($this->actor()->id,'RETRAIT_ACCES_MOBILE',$e['matricule']);});
                $this->addFlash('success','Accès mobile retiré pour '.$e['nom'].' '.$e['prenoms'].'. Ses sessions sont fermées.');
            }else{
                if(!$e['telephone']){throw new \DomainException('Renseignez d’abord le numéro de téléphone de l’employé (bouton Modifier).');}
                $pin=trim((string)$r->request->get('pin',''));
                if($pin===''){$pin=$this->s->randomPin();}elseif(!preg_match('/^\d{4}$/',$pin)||$this->s->trivialPin($pin)){throw new \DomainException('Le PIN doit comporter 4 chiffres et ne pas être une suite évidente (0000, 1234…).');}
                $hash=$hasher->hashPassword(new User($id,(string)$e['telephone'],'','ROLE_EMPLOYE',true,$id,(string)$e['nom']),$pin);
                $this->s->db->transactional(function()use($id,$e,$hash){$this->s->db->update('employe',['pin_hash'=>$hash,'pin_echecs'=>0,'pin_bloque_jusqua'=>null,'pin_modifie_le'=>$this->s->now()],['id'=>$id]);$this->s->db->delete('mobile_token',['employe_id'=>$id]);$this->s->audit($this->actor()->id,'DEFINITION_PIN_MOBILE',$e['matricule']);});
                $this->addFlash('success','PIN de '.$e['nom'].' '.$e['prenoms'].' : '.$pin.' — à communiquer à l’employé (téléphone '.$e['telephone'].'). Il ne sera plus affiché.');
            }
        }catch(\Throwable $t){$this->fail($t);}
        return $this->redirectToRoute('employes');
    }
    #[Route('/employes/{id}/renouveler-badge',name:'badge_renouveler',methods:['POST'],requirements:['id'=>'\d+'])]
    public function rotateBadge(int $id,Request $r): Response {
        $this->permit(['ROLE_ADMIN','ROLE_RH']);$this->csrf($r,'badge_'.$id);
        $this->s->db->transactional(function()use($id){
            $n=$this->s->db->update('employe',['badge_token'=>bin2hex(random_bytes(32))],['id'=>$id]);
            if(!$n){throw $this->createNotFoundException();}$this->s->audit($this->actor()->id,'RENOUVELLEMENT_BADGE','Employé #'.$id);
        });
        $this->addFlash('success','Nouveau badge créé. L’ancien code est désormais invalide.');return $this->redirectToRoute('badge',['id'=>$id]);
    }
    private function settingsPage(Request $r,string $route,string $page): Response {
        $this->permit($page==='types'?['ROLE_ADMIN','ROLE_RH','ROLE_CANTINE']:['ROLE_ADMIN','ROLE_RH']);
        if($r->isMethod('POST')){
            $this->csrf($r,'parametres');
            try{
                $kind=$this->text($r,'nature',30);
                $this->permit($kind==='repas'?['ROLE_ADMIN','ROLE_CANTINE']:['ROLE_ADMIN','ROLE_RH']);
                $this->s->db->transactional(function()use($kind,$r){
                    if($kind==='direction'){$this->s->db->insert('direction',['nom'=>$this->text($r,'nom')]);}
                    elseif($kind==='service'){
                        $d=$r->request->getInt('direction_id');if(!$this->s->db->fetchOne('SELECT id FROM direction WHERE id=?',[$d])){throw new \InvalidArgumentException('Direction invalide.');}
                        $this->s->db->insert('service',['nom'=>$this->text($r,'nom'),'direction_id'=>$d]);
                    }elseif($kind==='repas'){
                        $id=$r->request->getInt('id');$data=['nom'=>$this->text($r,'nom'),'actif'=>$r->request->get('actif')?1:0];
                        if($id){if(!$this->s->db->fetchOne('SELECT id FROM type_repas WHERE id=?',[$id])){throw new \InvalidArgumentException('Repas inconnu.');}$this->s->db->update('type_repas',$data,['id'=>$id]);}else{$this->s->db->insert('type_repas',$data);}
                    }else{throw new \InvalidArgumentException('Paramètre inconnu.');}
                    $this->s->audit($this->actor()->id,'PARAMETRAGE',$kind);
                });$this->addFlash('success','Paramètre enregistré.');
            }catch(\Throwable $e){$this->fail($e);}
            return $this->redirectToRoute($route);
        }
        return $this->render('organisation.html.twig',['page'=>$page,'directions'=>$this->s->db->fetchAllAssociative('SELECT * FROM direction ORDER BY nom'),'services'=>$this->s->db->fetchAllAssociative('SELECT s.*,d.nom AS direction_nom FROM service s JOIN direction d ON d.id=s.direction_id ORDER BY s.nom'),'types'=>$this->s->types()]);
    }
    #[Route('/organisation/directions',name:'org_directions',methods:['GET','POST'])]
    public function orgDirections(Request $r): Response { return $this->settingsPage($r,'org_directions','directions'); }
    #[Route('/organisation/services',name:'org_services',methods:['GET','POST'])]
    public function orgServices(Request $r): Response { return $this->settingsPage($r,'org_services','services'); }
    #[Route('/organisation/types-repas',name:'org_types',methods:['GET','POST'])]
    public function orgTypes(Request $r): Response { return $this->settingsPage($r,'org_types','types'); }
    #[Route('/parametres',name:'parametres',methods:['GET'])]
    public function settings(): Response { return $this->redirectToRoute($this->actor()->role==='ROLE_CANTINE'?'org_types':'org_directions'); }

    #[Route('/utilisateurs',name:'utilisateurs',methods:['GET','POST'])]
    public function users(Request $r,UserPasswordHasherInterface $hasher): Response {
        $this->permit(['ROLE_ADMIN']);
        if($r->isMethod('POST')){
            $this->csrf($r,'utilisateur');
            try{
                $id=$r->request->getInt('id');$email=strtolower($this->text($r,'email',180));$nom=$this->text($r,'nom',150);$role=$this->text($r,'role',30);$active=$r->request->get('actif')?1:0;$employee=$r->request->getInt('employe_id')?:null;
                if(!filter_var($email,FILTER_VALIDATE_EMAIL)){throw new \InvalidArgumentException('Adresse e-mail invalide.');}
                if(!in_array($role,['ROLE_ADMIN','ROLE_RH','ROLE_CANTINE','ROLE_COMPTABILITE','ROLE_EMPLOYE'],true)){throw new \InvalidArgumentException('Rôle invalide.');}
                if($employee && !$this->s->db->fetchOne('SELECT id FROM employe WHERE id=?',[$employee])){throw new \InvalidArgumentException('Employé inexistant.');}
                if($role==='ROLE_EMPLOYE' && !$employee){throw new \InvalidArgumentException('Un compte employé doit être lié à un employé.');}
                if($id===$this->actor()->id && (!$active || $role!=='ROLE_ADMIN')){throw new \InvalidArgumentException('Vous ne pouvez pas désactiver votre propre accès administrateur.');}
                if($id && !$this->s->db->fetchOne('SELECT id FROM utilisateur WHERE id=?',[$id])){throw new \InvalidArgumentException('Compte introuvable.');}
                $data=['email'=>$email,'nom'=>$nom,'role'=>$role,'actif'=>$active,'employe_id'=>$employee];$password=(string)$r->request->get('mot_de_passe','');
                if(!$id || $password!==''){
                    if(strlen($password)<12){throw new \InvalidArgumentException('Mot de passe : 12 caractères minimum.');}
                    $data['mot_de_passe']=$hasher->hashPassword(new User(0,$email,'',$role,true,$employee,$nom),$password);
                }
                $this->s->db->transactional(function()use($id,$data,$email){if($id){$this->s->db->update('utilisateur',$data,['id'=>$id]);}else{$this->s->db->insert('utilisateur',$data);}$this->s->audit($this->actor()->id,'ENREGISTREMENT_COMPTE',$email);});$this->addFlash('success','Compte enregistré.');
            }catch(\Throwable $e){$this->fail($e,'Adresse e-mail déjà utilisée, ou employé déjà associé à un autre compte.');}return $this->redirectToRoute('utilisateurs');
        }
        $id=$r->query->getInt('modifier');return $this->render('utilisateurs.html.twig',['users'=>$this->s->db->fetchAllAssociative('SELECT id,email,nom,role,actif,employe_id FROM utilisateur ORDER BY nom'),'employes'=>$this->s->employes(),'edit'=>$id?$this->s->db->fetchAssociative('SELECT id,email,nom,role,actif,employe_id FROM utilisateur WHERE id=?',[$id]):null]);
    }
    #[Route('/journal',name:'journal',methods:['GET'])]
    public function audit(Request $r): Response {
        $this->permit(['ROLE_ADMIN']);$page=max(1,$r->query->getInt('page',1));
        return $this->render('journal.html.twig',['rows'=>$this->s->db->fetchAllAssociative('SELECT a.*,u.nom AS agent_nom FROM audit a LEFT JOIN utilisateur u ON u.id=a.utilisateur_id ORDER BY a.id DESC LIMIT 100 OFFSET '.(($page-1)*100)),'page'=>$page]);
    }}
