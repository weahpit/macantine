<?php
namespace App\EventSubscriber;
use App\Service\Store;
use App\Security\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
final class SecuritySubscriber implements EventSubscriberInterface {
    public function __construct(private readonly Store $store) {}
    public static function getSubscribedEvents(): array { return [LoginSuccessEvent::class=>'login',LoginFailureEvent::class=>'failure',LogoutEvent::class=>'logout','kernel.response'=>'headers']; }
    public function login(LoginSuccessEvent $event): void {
        $u=$event->getUser();if ($u instanceof User) { $this->store->audit($u->id,'CONNEXION'); }
    }
    public function failure(LoginFailureEvent $event): void {
        $badge=$event->getPassport()?->getBadge(UserBadge::class);
        $identifier=$badge instanceof UserBadge?mb_substr($badge->getUserIdentifier(),0,100):'inconnu';
        $this->store->audit(null,'ECHEC_CONNEXION',$identifier);
    }
    public function logout(LogoutEvent $event): void {
        $u=$event->getToken()?->getUser();if ($u instanceof User) { $this->store->audit($u->id,'DECONNEXION'); }
    }
    public function headers(ResponseEvent $event): void {
        $h=$event->getResponse()->headers;
        $h->set('X-Content-Type-Options','nosniff');$h->set('X-Frame-Options','DENY');$h->set('Referrer-Policy','no-referrer');
        $h->set('Cache-Control','no-store, private');
        $h->set('Content-Security-Policy',"default-src 'self'; img-src 'self'; style-src 'self'; script-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    }
}
