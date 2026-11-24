<?php
namespace App\Command;
use App\Service\Evolution;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
#[AsCommand(name:'app:evoluer',description:'Appliquer les tickets durables, tarifs et horaires sur PostgreSQL.')]
final class EvolutionCommand extends Command {
 public function __construct(private readonly Evolution $evolution){parent::__construct();}
 protected function execute(InputInterface $input,OutputInterface $output):int {
  $io=new SymfonyStyle($input,$output);
  try{$this->evolution->migrate();$io->success('Évolution appliquée. Régularisez la catégorie des anciens tickets et configurez les horaires avant service.');return 0;}
  catch(\Throwable $e){$io->error($e->getMessage());return 1;}
 }
}
