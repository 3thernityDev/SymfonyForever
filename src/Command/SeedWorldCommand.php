<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Game\WorldSeederService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:world:seed',
    description: 'Peuple les 7 zones de La Réunion et le bestiaire du monde SymfonyForever.',
)]
final class SeedWorldCommand extends Command
{
    public function __construct(
        private readonly WorldSeederService $seeder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('reset-monsters', null, InputOption::VALUE_NONE, 'Réinitialise tous les monstres vivants du monde.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $reset = (bool) $input->getOption('reset-monsters');

        $result = $this->seeder->seed($reset);
        $io->success(sprintf(
            'Monde SymfonyForever initialisé : %d zones, %d types de créatures, %d monstres placés sur La Réunion.',
            $result['zones'],
            $result['templates'],
            $result['monsters'],
        ));

        return Command::SUCCESS;
    }
}
