<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Game\ReunionMapGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:world:generate-map',
    description: 'Génère la heightmap 16-bit, la carte des biomes, la mini-carte et le cache binaire de La Réunion.',
)]
final class GenerateReunionMapCommand extends Command
{
    public function __construct(
        private readonly ReunionMapGenerator $generator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Génération de la carte 3D de La Réunion (SymfonyForever)');

        $start = microtime(true);
        $paths = $this->generator->generate();
        $elapsed = round(microtime(true) - $start, 2);

        $io->success(sprintf('Carte de La Réunion générée en %.2fs !', $elapsed));
        $io->listing([
            'Heightmap RG16 : '.$paths['heightmap'],
            'Biomes & Zones : '.$paths['biomes'],
            'Mini-carte : '.$paths['minimap'],
            'Cache serveur : '.$paths['binary'],
        ]);

        return Command::SUCCESS;
    }
}
