<?php
namespace App\Controller;
use App\Service\Store;use App\Service\Societe;use App\Security\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;use Symfony\Component\Routing\Attribute\Route;use Symfony\Component\HttpFoundation\Request;use Symfony\Component\HttpFoundation\Response;use Symfony\Component\HttpFoundation\JsonResponse;use Symfony\Component\HttpFoundation\BinaryFileResponse;use Symfony\Component\DependencyInjection\Attribute\Autowire;use Symfony\Component\RateLimiter\RateLimiterFactory;use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Endroid\QrCode\QrCode;use Endroid\QrCode\Writer\PngWriter;
#[Route('/api/mobile')]
final class MobileController extends AbstractController {
 public function __construct(private readonly Store $s,#[Autowire('%kernel.environment%')]private readonly string $env){}
 private function secure(Request $r):void{if($this->env==='prod'&&!$r->isSecure()){throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('HTTPS requis.');}}
 private function employee(Request $r):array{
  $this->secure($r);$header=$r->headers->get('Authorization','');if(!preg_match('/^Bearer ([a-f0-9]{64})$/',$header,$m)){throw new \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException('Bearer','Connexion requise.');}
  $u=$this->s->db->fetchAssociative("SELECT e.id AS employe_id,e.nom,e.prenoms,e.matricule,e.pin_hash,t.password_fingerprint FROM mobile_token t JOIN employe e ON e.id=t.employe_id WHERE t.token_hash=? AND t.expire_le>? AND e.actif=1 AND e.pin_hash IS NOT NULL",[hash('sha256',$m[1]),$this->s->now()]);if(!$u||!hash_equals($u['password_fingerprint'],hash('sha256',$u['pin_hash']))){throw new \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException('Bearer','Session mobile expirée, PIN modifié ou accès retiré. Reconnectez-vous.');}$u['nom']=trim($u['nom'].' '.$u['prenoms']);return $u;
 }
 private function rows(int $employee,int $page):array{return $this->s->db->fetchAllAssociative('SELECT t.id,t.numero,t.date_emission,t.statut,t.categorie_nom,t.prix_employe,t.subvention,p.nom AS plat_indicatif FROM ticket t LEFT JOIN plat p ON p.id=t.plat_indicatif_id WHERE t.employe_id=? ORDER BY t.id DESC LIMIT 30 OFFSET '.(($page-1)*30),[$employee]);}
 private function ticket(int $id,int $employee):array{$t=$this->s->db->fetchAssociative('SELECT * FROM ticket WHERE id=? AND employe_id=?',[$id,$employee]);if(!$t){throw $this->createNotFoundException();}return $t;}
 #[Route('/connexion',name:'mobile_connexion',methods:['POST'])]
 public function login(Request $r,UserPasswordHasherInterface $hasher,#[Autowire(service:'limiter.mobile_login')]RateLimiterFactory $limiter):JsonResponse {
  $this->secure($r);$limit=$limiter->create((string)$r->getClientIp())->consume(1);if(!$limit->isAccepted()){return $this->json(['erreur'=>'Trop de tentatives. Réessayez dans deux minutes.'],429);}
  try{$body=$r->toArray();}catch(\Throwable){return $this->json(['erreur'=>'Requête JSON invalide.'],400);}
  $raw=$body['telephone']??'';$pin=$body['pin']??'';$invalid=['erreur'=>'Saisissez votre numéro de téléphone et votre PIN à 4 chiffres.'];
  if(!is_string($raw)||!is_string($pin)||strlen($raw)>40||!preg_match('/^\d{4}$/',$pin)){return $this->json($invalid,400);}
  try{$phone=$this->s->phone($raw);}catch(\InvalidArgumentException){$phone=null;}if($phone===null){return $this->json($invalid,400);}
  $dummy=new User(0,'','','ROLE_EMPLOYE',true,null,'');
  // Verrou de ligne : les essais sur un même compte sont sérialisés (5 échecs = blocage 30 minutes).
  $res=$this->s->db->transactional(function()use($phone,$pin,$hasher,$dummy){
   $e=$this->s->db->fetchAssociative('SELECT id,nom,prenoms,actif,pin_hash,pin_bloque_jusqua FROM employe WHERE telephone=? FOR UPDATE',[$phone]);
   if(!$e||!(int)$e['actif']||!$e['pin_hash']){$hasher->hashPassword($dummy,$pin);return ['etat'=>'refuse'];}
   $now=$this->s->now();if($e['pin_bloque_jusqua']&&$e['pin_bloque_jusqua']>$now){return ['etat'=>'bloque'];}
   $u=new User((int)$e['id'],$phone,(string)$e['pin_hash'],'ROLE_EMPLOYE',true,(int)$e['id'],(string)$e['nom']);
   if(!$hasher->isPasswordValid($u,$pin)){
    $until=(new \DateTimeImmutable($now))->modify('+30 minutes')->format('Y-m-d H:i:s');
    $this->s->db->executeStatement('UPDATE employe SET pin_echecs=CASE WHEN pin_echecs+1>=5 THEN 0 ELSE pin_echecs+1 END,pin_bloque_jusqua=CASE WHEN pin_echecs+1>=5 THEN ? ELSE pin_bloque_jusqua END WHERE id=?',[$until,(int)$e['id']]);
    $this->s->audit(null,'ECHEC_CONNEXION_MOBILE','Employé #'.$e['id']);return ['etat'=>'refuse'];
   }
   $this->s->db->executeStatement('UPDATE employe SET pin_echecs=0,pin_bloque_jusqua=NULL WHERE id=?',[(int)$e['id']]);
   $token=bin2hex(random_bytes(32));$expires=(new \DateTimeImmutable($now))->modify('+30 days')->format('Y-m-d H:i:s');
   $this->s->db->executeStatement('DELETE FROM mobile_token WHERE expire_le<=?',[$now]);
   $this->s->db->insert('mobile_token',['utilisateur_id'=>null,'employe_id'=>(int)$e['id'],'token_hash'=>hash('sha256',$token),'password_fingerprint'=>hash('sha256',(string)$e['pin_hash']),'cree_le'=>$now,'expire_le'=>$expires]);
   $this->s->audit(null,'CONNEXION_MOBILE','Employé #'.$e['id']);
   return ['etat'=>'ok','jeton'=>$token,'expire_le'=>$expires,'employe'=>['id'=>(int)$e['id'],'nom'=>trim($e['nom'].' '.$e['prenoms'])]];
  });
  if($res['etat']==='bloque'){return $this->json(['erreur'=>'Trop d’essais incorrects. Réessayez dans 30 minutes ou demandez un nouveau PIN à la RH.'],429);}
  if($res['etat']!=='ok'){return $this->json(['erreur'=>'Connexion refusée. Vérifiez votre numéro et votre PIN, ou contactez la RH.'],401);}
  return $this->json(['jeton'=>$res['jeton'],'expire_le'=>$res['expire_le'],'employe'=>$res['employe']]);
 }
 #[Route('/tickets',name:'mobile_tickets',methods:['GET'])]
 public function tickets(Request $r,Societe $company):JsonResponse{$u=$this->employee($r);$page=max(1,min(100000,$r->query->getInt('page',1)));$rows=$this->rows((int)$u['employe_id'],$page);$societe=$company->get();unset($societe['logo']);$societe['logo_disponible']=$company->logoPath()!==null;return $this->json(['tickets'=>$rows,'page'=>$page,'total'=>(int)$this->s->db->fetchOne('SELECT COUNT(*) FROM ticket WHERE employe_id=?',[$u['employe_id']]),'employe'=>['nom'=>$u['nom'],'matricule'=>$u['matricule']],'societe'=>$societe]);}
 #[Route('/tickets/{id}/qr',name:'mobile_ticket_qr',methods:['GET'],requirements:['id'=>'\d+'])]
 public function qr(Request $r,int $id):Response{$u=$this->employee($r);$t=$this->ticket($id,(int)$u['employe_id']);$png=(new PngWriter())->write(new QrCode(data:'CANTINE:T:'.$t['token'],size:360,margin:20));return new Response($png->getString(),200,['Content-Type'=>'image/png']);}
 #[Route('/logo',name:'mobile_logo',methods:['GET'])]
 public function logo(Request $r,Societe $s):Response{$this->employee($r);$path=$s->logoPath();if(!$path){throw $this->createNotFoundException();}return new BinaryFileResponse($path,200,['Content-Type'=>'image/png']);}
 #[Route('/deconnexion',name:'mobile_deconnexion',methods:['POST'])]
 public function logout(Request $r):JsonResponse{$this->employee($r);$token=substr($r->headers->get('Authorization'),7);$this->s->db->delete('mobile_token',['token_hash'=>hash('sha256',$token)]);return $this->json(['ok'=>true]);}
}
