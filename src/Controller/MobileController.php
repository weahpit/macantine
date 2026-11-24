<?php
namespace App\Controller;
use App\Service\Store;use App\Service\Societe;use App\Security\User;use App\Security\UserProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;use Symfony\Component\Routing\Attribute\Route;use Symfony\Component\HttpFoundation\Request;use Symfony\Component\HttpFoundation\Response;use Symfony\Component\HttpFoundation\JsonResponse;use Symfony\Component\HttpFoundation\BinaryFileResponse;use Symfony\Component\DependencyInjection\Attribute\Autowire;use Symfony\Component\RateLimiter\RateLimiterFactory;use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Endroid\QrCode\QrCode;use Endroid\QrCode\Writer\PngWriter;
#[Route('/api/mobile')]
final class MobileController extends AbstractController {
 public function __construct(private readonly Store $s,#[Autowire('%kernel.environment%')]private readonly string $env){}
 private function secure(Request $r):void{if($this->env==='prod'&&!$r->isSecure()){throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('HTTPS requis.');}}
 private function employee(Request $r):array{
  $this->secure($r);$header=$r->headers->get('Authorization','');if(!preg_match('/^Bearer ([a-f0-9]{64})$/',$header,$m)){throw new \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException('Bearer','Connexion requise.');}
  $u=$this->s->db->fetchAssociative("SELECT u.id,u.employe_id,u.nom,e.matricule,u.mot_de_passe,t.password_fingerprint FROM mobile_token t JOIN utilisateur u ON u.id=t.utilisateur_id JOIN employe e ON e.id=u.employe_id WHERE t.token_hash=? AND t.expire_le>? AND u.actif=1 AND u.role='ROLE_EMPLOYE' AND e.actif=1",[hash('sha256',$m[1]),$this->s->now()]);if(!$u||!hash_equals($u['password_fingerprint'],hash('sha256',$u['mot_de_passe']))){throw new \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException('Bearer','Session mobile expirée ou compte indisponible.');}return $u;
 }
 private function rows(int $employee,int $page):array{return $this->s->db->fetchAllAssociative('SELECT t.id,t.numero,t.date_emission,t.statut,t.categorie_nom,t.prix_employe,t.subvention,p.nom AS plat_indicatif FROM ticket t LEFT JOIN plat p ON p.id=t.plat_indicatif_id WHERE t.employe_id=? ORDER BY t.id DESC LIMIT 30 OFFSET '.(($page-1)*30),[$employee]);}
 private function ticket(int $id,int $employee):array{$t=$this->s->db->fetchAssociative('SELECT * FROM ticket WHERE id=? AND employe_id=?',[$id,$employee]);if(!$t){throw $this->createNotFoundException();}return $t;}
 #[Route('/connexion',name:'mobile_connexion',methods:['POST'])]
 public function login(Request $r,UserProvider $provider,UserPasswordHasherInterface $hasher,#[Autowire(service:'limiter.mobile_login')]RateLimiterFactory $limiter):JsonResponse {
  $this->secure($r);$limit=$limiter->create((string)$r->getClientIp())->consume(1);if(!$limit->isAccepted()){return $this->json(['erreur'=>'Trop de tentatives. Réessayez dans deux minutes.'],429);}
  try{$body=$r->toArray();}catch(\Throwable){return $this->json(['erreur'=>'Requête JSON invalide.'],400);}$email=$body['email']??'';$password=$body['mot_de_passe']??'';if(!is_string($email)||!is_string($password)||strlen($email)>180||strlen($password)>4096){return $this->json(['erreur'=>'Identifiants invalides.'],400);}
  try{$u=$provider->loadUserByIdentifier($email);$valid=$hasher->isPasswordValid($u,$password);}catch(\Symfony\Component\Security\Core\Exception\UserNotFoundException){$valid=false;$u=null;}
  if(!$valid||!$u||!$u->actif||$u->role!=='ROLE_EMPLOYE'||!$u->employeId||!$this->s->db->fetchOne('SELECT id FROM employe WHERE id=? AND actif=1',[$u->employeId])){return $this->json(['erreur'=>'Connexion refusée. Vérifiez vos identifiants et votre compte employé.'],401);}
  $token=bin2hex(random_bytes(32));$expires=(new \DateTimeImmutable($this->s->now()))->modify('+30 days')->format('Y-m-d H:i:s');$this->s->db->transactional(function()use($u,$token,$expires){$this->s->db->executeStatement('DELETE FROM mobile_token WHERE expire_le<=?',[$this->s->now()]);$this->s->db->insert('mobile_token',['utilisateur_id'=>$u->id,'token_hash'=>hash('sha256',$token),'password_fingerprint'=>hash('sha256',$u->getPassword()),'cree_le'=>$this->s->now(),'expire_le'=>$expires]);$this->s->audit($u->id,'CONNEXION_MOBILE');});return $this->json(['jeton'=>$token,'expire_le'=>$expires,'employe'=>['id'=>$u->employeId,'nom'=>$u->nom]]);
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
