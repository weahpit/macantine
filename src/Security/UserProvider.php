<?php
namespace App\Security;
use App\Service\Store;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
/** @implements UserProviderInterface<User> */
final class UserProvider implements UserProviderInterface, PasswordUpgraderInterface {
    public function __construct(private readonly Store $store) {}
    public function loadUserByIdentifier(string $identifier): User {
        $matches=$this->store->db->fetchAllAssociative('SELECT * FROM utilisateur WHERE LOWER(TRIM(email))=?',[strtolower(trim($identifier))]);
        $r=count($matches)===1?$matches[0]:false;
        if (!$r) { $e=new UserNotFoundException();$e->setUserIdentifier($identifier);throw $e; }
        return new User((int)$r['id'],$r['email'],$r['mot_de_passe'],$r['role'],(bool)$r['actif'],$r['employe_id']!==null?(int)$r['employe_id']:null,$r['nom']);
    }
    public function refreshUser(UserInterface $user): UserInterface {
        if (!$user instanceof User) { throw new UnsupportedUserException(); }
        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }
    public function supportsClass(string $class): bool { return $class===User::class; }
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void {
        if (!$user instanceof User) { throw new UnsupportedUserException('Type utilisateur non pris en charge.'); }
        $this->store->db->update('utilisateur', ['mot_de_passe' => $newHashedPassword], ['id' => $user->id]);
        $user->setPasswordHash($newHashedPassword);
    }
}
