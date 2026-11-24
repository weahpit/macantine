<?php
namespace App\Security;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
final class UserChecker implements UserCheckerInterface {
    public function checkPreAuth(UserInterface $user): void { if ($user instanceof User && !$user->actif) { throw new DisabledException('Compte désactivé.'); } }
    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void {}
}
