<?php
namespace App\Command;
use App\Service\Store;
use App\Security\UserProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
#[AsCommand(name:'app:mot-de-passe',description:'Réinitialiser un mot de passe depuis le serveur.')]
final class PasswordCommand extends Command {
    public function __construct(private readonly Store $store,private readonly UserProvider $provider,private readonly UserPasswordHasherInterface $hasher) { parent::__construct(); }
    protected function configure(): void { $this->addArgument('email',InputArgument::REQUIRED); }
    protected function execute(InputInterface $input,OutputInterface $output): int {
        $io=new SymfonyStyle($input,$output);
        if (!$input->isInteractive()) { $io->error('Session interactive requise.');return Command::FAILURE; }
        $u=$this->provider->loadUserByIdentifier($input->getArgument('email'));
        $p=$io->askHidden('Nouveau mot de passe (12 caractères minimum)',fn($v)=>strlen((string)$v)>=12?$v:throw new \RuntimeException('12 caractères minimum.'));
        if ($io->askHidden('Confirmez')!==$p) { $io->error('Confirmation différente.');return Command::FAILURE; }
        $this->store->db->update('utilisateur',['mot_de_passe'=>$this->hasher->hashPassword($u,$p)],['id'=>$u->id]);
        $this->store->audit($u->id,'REINITIALISATION_MOT_DE_PASSE','Depuis la console');
        $io->success('Mot de passe modifié.');return Command::SUCCESS;
    }
}
