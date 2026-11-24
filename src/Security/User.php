<?php
namespace App\Security;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\EquatableInterface;
final class User implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface {
    public function __construct(public readonly int $id, public readonly string $email, private string $hash, public readonly string $role, public readonly bool $actif, public readonly ?int $employeId, public readonly string $nom) {}
    public function getUserIdentifier(): string { return $this->email; }
    public function getPassword(): string { return $this->hash; }
    public function setPasswordHash(string $hash): void { $this->hash = $hash; }
    public function getRoles(): array { return ['ROLE_USER',$this->role]; }
    public function isEqualTo(UserInterface $user): bool {
        return $user instanceof self && $this->id === $user->id && $this->email === $user->email && $this->hash === $user->hash && $this->role === $user->role && $this->actif === $user->actif && $this->employeId === $user->employeId;
    }
}
