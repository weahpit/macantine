<?php
namespace App\EventSubscriber;
use App\Service\Store;
use App\Security\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
final class SecuritySubscriber implements EventSubscriberInterface {
    public function __construct(private readonly Store $store) {}
    public static function getSubscribedEvents(): array { return [LoginSuccessEvent::class=>'login','kernel.response'=>'headers']; }
    public function login(LoginSuccessEvent $event): void {
        $u=$event->getUser();if ($u instanceof User) { $this->store->audit($u->id,'CONNEXION'); }
    }
    public function headers(ResponseEvent $event): void {
        $h=$event->getResponse()->headers;
        $h->set('X-Content-Type-Options','nosniff');$h->set('X-Frame-Options','DENY');$h->set('Referrer-Policy','no-referrer');
        $h->set('Cache-Control','no-store, private');
        $h->set('Content-Security-Policy',"default-src 'self'; img-src 'self'; style-src 'self'; script-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    }
}
