<?php
namespace App\Controller;
use App\Service\Societe;use App\Service\Store;use App\Security\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;use Symfony\Component\HttpFoundation\Request;use Symfony\Component\HttpFoundation\Response;use Symfony\Component\HttpFoundation\BinaryFileResponse;
final class SocieteController extends AbstractController {
 #[Route('/societe',name:'societe',methods:['GET','POST'])]
 public function index(Request $r,Societe $s,Store $store):Response {
  $u=$this->getUser();if(!$u instanceof User||$u->role!=='ROLE_ADMIN'){throw $this->createAccessDeniedException();}
  if($r->isMethod('POST')){if(!$this->isCsrfTokenValid('societe',(string)$r->request->get('_token'))){throw $this->createAccessDeniedException('Formulaire expiré.');}try{$data=[];foreach(['nom'=>150,'adresse'=>1000,'telephone'=>50,'email'=>180,'identifiant_fiscal'=>100] as $key=>$max){$v=trim((string)$r->request->get($key,''));if(mb_strlen($v)>$max||($key==='nom'&&$v==='')||($key==='email'&&$v!==''&&!filter_var($v,FILTER_VALIDATE_EMAIL))){throw new \DomainException('Champ '.$key.' invalide.');}$data[$key]=$v;}$s->save($data,$r->files->get('logo'),$u->id,$store);$this->addFlash('success','Informations de la société enregistrées.');}catch(\DomainException $e){$this->addFlash('error',$e->getMessage());}return $this->redirectToRoute('societe');}
  return $this->render('societe.html.twig',['societe'=>$s->get()]);
 }
 #[Route('/societe/logo',name:'societe_logo',methods:['GET'])]
 public function logo(Societe $s):Response{$path=$s->logoPath();if(!$path){throw $this->createNotFoundException();}return new BinaryFileResponse($path,200,['Content-Type'=>'image/png']);}
}
