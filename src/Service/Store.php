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
        $sql='SELECT e.*,(e.pin_hash IS NOT NULL) AS pin_defini,s.nom AS service_nom,d.nom AS direction_nom FROM employe e JOIN service s ON s.id=e.service_id JOIN direction d ON d.id=s.direction_id';
        $params=[];if($q!==''){$sql.=' WHERE e.matricule LIKE ? OR e.nom LIKE ? OR e.prenoms LIKE ?';$params=['%'.$q.'%','%'.$q.'%','%'.$q.'%'];}
        return $this->db->fetchAllAssociative($sql.' ORDER BY e.nom,e.prenoms',$params);
    }
    /** Normalise un numéro (chiffres seuls, sans +225/00225). Retourne null si vide, lève une exception si invalide. */
    public function phone(string $raw): ?string {
        $d=preg_replace('/\D+/','',$raw);if($d===''){return null;}
        if(str_starts_with($d,'00')){$d=substr($d,2);}
        if(str_starts_with($d,'225')&&strlen($d)>10){$d=substr($d,3);}
        if(!preg_match('/^\d{8,15}$/',$d)){throw new \InvalidArgumentException('Numéro de téléphone invalide.');}
        return $d;
    }
    public function trivialPin(string $pin): bool { return preg_match('/^(\d)\1{3}$/',$pin)===1||in_array($pin,['0123','1234','2345','3456','4567','5678','6789','3210','4321','5432','6543','7654','8765','9876'],true); }
    public function randomPin(): string { do{$pin=sprintf('%04d',random_int(0,9999));}while($this->trivialPin($pin));return $pin; }
    public function types(bool $actifs=false): array { return $this->db->fetchAllAssociative('SELECT * FROM type_repas'.($actifs?' WHERE actif=1':'').' ORDER BY id'); }
}
