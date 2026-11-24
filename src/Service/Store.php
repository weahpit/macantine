<?php
namespace App\Service;
use Doctrine\DBAL\Connection;
final class Store {
    public function __construct(public readonly Connection $db, private readonly string $timezone) {}
    public function now(): string { return (new \DateTimeImmutable('now',new \DateTimeZone($this->timezone)))->format('Y-m-d H:i:s'); }
    public function today(): string { return substr($this->now(),0,10); }
    public function date(string $value): string {
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        if (!$d || $d->format('Y-m-d') !== $value) { throw new \InvalidArgumentException('Date invalide.'); }
        return $value;
    }
    public function audit(?int $user, string $action, string $detail=''): void {
        $this->db->insert('audit',['utilisateur_id'=>$user,'action'=>$action,'detail'=>$detail,'cree_le'=>$this->now()]);
    }
    public function employes(string $q=''): array {
        $sql='SELECT e.*,s.nom AS service_nom,d.nom AS direction_nom FROM employe e JOIN service s ON s.id=e.service_id JOIN direction d ON d.id=s.direction_id';
        $params=[];if($q!==''){$sql.=' WHERE e.matricule LIKE ? OR e.nom LIKE ? OR e.prenoms LIKE ?';$params=['%'.$q.'%','%'.$q.'%','%'.$q.'%'];}
        return $this->db->fetchAllAssociative($sql.' ORDER BY e.nom,e.prenoms',$params);
    }
    public function types(bool $actifs=false): array { return $this->db->fetchAllAssociative('SELECT * FROM type_repas'.($actifs?' WHERE actif=1':'').' ORDER BY id'); }
}
