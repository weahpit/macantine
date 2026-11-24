<?php
namespace App\Service;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
final class Societe {
 public function __construct(private readonly Connection $db,#[Autowire('%kernel.project_dir%')]private readonly string $dir){}
 public function get():array{return $this->db->fetchAssociative('SELECT * FROM societe WHERE id=1')?:['nom'=>'','adresse'=>'','telephone'=>'','email'=>'','identifiant_fiscal'=>'','logo'=>null];}
 public function logoPath():?string{$name=$this->get()['logo'];return $name&&preg_match('/^[a-f0-9]{32}\.png$/',$name)&&is_file($this->dir.'/var/societe/'.$name)?$this->dir.'/var/societe/'.$name:null;}
 public function save(array $data,?UploadedFile $file,int $user,Store $store):void {
  $old=$this->logoPath();$new=null;
  if($file){if(!$file->isValid()||$file->getSize()>2*1024*1024){throw new \DomainException('Logo invalide ou supérieur à 2 Mo.');}$mime=(new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname());$size=@getimagesize($file->getPathname());if(!in_array($mime,['image/jpeg','image/png','image/webp'],true)||!$size||$size[0]*$size[1]>6000000){throw new \DomainException('Logo JPEG, PNG ou WebP, 6 mégapixels maximum.');}$img=@imagecreatefromstring(file_get_contents($file->getPathname()));if(!$img){throw new \DomainException('Logo illisible.');}$scale=min(1,800/max($size[0],$size[1]));$out=imagecreatetruecolor(max(1,(int)($size[0]*$scale)),max(1,(int)($size[1]*$scale)));imagealphablending($out,false);imagesavealpha($out,true);imagefill($out,0,0,imagecolorallocatealpha($out,255,255,255,127));imagecopyresampled($out,$img,0,0,0,0,imagesx($out),imagesy($out),imagesx($img),imagesy($img));$dir=$this->dir.'/var/societe';if(!is_dir($dir)&&!mkdir($dir,0770,true)){throw new \DomainException('Dossier logo non inscriptible.');}$name=bin2hex(random_bytes(16)).'.png';$new=$dir.'/'.$name;if(!imagepng($out,$new)){throw new \DomainException('Stockage du logo impossible.');}$data['logo']=$name;unset($img,$out);}
  try{$this->db->transactional(function()use($data,$user,$store){$this->db->update('societe',$data,['id'=>1]);$store->audit($user,'SOCIETE','Informations et identité visuelle');});}catch(\Throwable $e){if($new){@unlink($new);}throw $e;}if($new&&$old&&$old!==$new){@unlink($old);}
 }
}
