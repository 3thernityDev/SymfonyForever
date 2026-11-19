<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\MonsterInstance;
use App\Entity\MonsterTemplate;
use App\Entity\Zone;
use App\Game\WorldCatalog;
use App\Repository\MonsterInstanceRepository;
use App\Repository\MonsterTemplateRepository;
use App\Repository\ZoneRepository;
use Doctrine\ORM\EntityManagerInterface;

final class WorldSeederService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ZoneRepository $zoneRepository,
        private readonly MonsterTemplateRepository $templateRepository,
        private readonly MonsterInstanceRepository $instanceRepository,
        private readonly ReunionTerrainService $terrainService,
    ) {
    }

    /**
     * Seeds or refreshes zones, monster templates, and spawns across all 7 zones of La Réunion.
     *
     * @return array{zones: int, templates: int, monsters: int}
     */
    public function seed(bool $resetMonsters = false): array
    {
        // 1. Seed 7 La Réunion zones
        $zonesCount = 0;
        foreach (WorldCatalog::zones() as $zData) {
            $zone = $this->zoneRepository->find($zData['code']);
            if (!$zone instanceof Zone) {
                $zone = new Zone($zData['code'], $zData['name']);
                $this->em->persist($zone);
            }
            $safeRespawn = $this->terrainService->findNearestWalkable($zData['respawnX'], $zData['respawnZ']);
            $zone->setName($zData['name'])
                ->setSubtitle($zData['subtitle'])
                ->setMinLevel($zData['minLevel'])
                ->setMaxLevel($zData['maxLevel'])
                ->setRespawnX($safeRespawn['x'])
                ->setRespawnZ($safeRespawn['z']);
            ++$zonesCount;
        }

        // 2. Seed Réunion-inspired bestiary templates
        $templateSpecs = [
            // Lagon de l'Ouest (Niv 1-3, non-agressifs près du hub — ~30s à l'auto-attaque, 3-4 skills max)
            ['crabe_recif', 'Crabe de Récif', 1, 200, 10, 5, 42, 10.0, 5.5, 7.5, 2.2, 18, false, false, false, 'crab', '#ef4444', 1.0],
            ['tangue_sauvage', 'Tangue Épineux', 2, 290, 14, 8, 65, 11.0, 5.5, 8.5, 2.0, 20, false, false, false, 'tangue', '#a16207', 1.05],
            ['guepe_cotiere', 'Guêpe des Filaos', 3, 390, 18, 8, 88, 12.0, 6.0, 9.5, 1.8, 22, true, false, false, 'wasp', '#eab308', 1.0],

            // Cirque de Salazie (Niv 3-5)
            ['sanglier_salazie', 'Sanglier de Salazie', 4, 520, 24, 14, 135, 13.0, 5.5, 9.5, 2.0, 24, true, false, false, 'boar', '#78350f', 1.15],
            ['papangue_feroce', 'Papangue Féroce', 5, 650, 30, 14, 175, 15.0, 6.5, 11.0, 1.8, 25, true, false, false, 'bird', '#3f3f46', 1.2],

            // Cirque de Mafate (Niv 4-6)
            ['golem_schiste', 'Golem de Schiste', 6, 820, 35, 22, 235, 14.0, 6.5, 8.0, 2.3, 28, true, false, false, 'golem', '#64748b', 1.35],

            // Cirque de Cilaos (Niv 5-7)
            ['cerf_java', 'Cerf de Java Enragé', 6, 780, 38, 18, 245, 15.0, 6.0, 11.5, 1.9, 26, true, false, false, 'stag', '#92400e', 1.25],
            ['gardien_cilaos', 'Sentinelle des Thermes', 7, 1020, 44, 24, 310, 14.0, 6.5, 9.0, 2.1, 30, true, false, false, 'golem', '#0284c7', 1.4],

            // Sud Sauvage (Niv 6-8)
            ['lezard_obsidienne', 'Lézard d\'Obsidienne', 7, 980, 48, 24, 325, 15.0, 6.0, 10.5, 1.8, 26, true, false, false, 'lizard', '#1e293b', 1.3],

            // Piton des Neiges (Niv 7-9)
            ['colosse_nuages', 'Colosse des Nuages (Élite)', 9, 3200, 68, 34, 620, 17.0, 7.5, 9.5, 2.0, 40, true, true, false, 'golem', '#cbd5e1', 1.85],

            // Piton de la Fournaise (Niv 8-10 + World Boss 10 Joueurs)
            ['molosse_magma', 'Molosse de Magma', 8, 1220, 58, 28, 440, 16.0, 6.5, 10.5, 1.9, 30, true, false, false, 'hound', '#ea580c', 1.35],
            ['seigneur_dolomieu', 'Seigneur du Dolomieu (World Boss)', 10, 28000, 550, 68, 5000, 28.0, 12.5, 9.5, 1.8, 180, true, true, true, 'boss_volcano', '#dc2626', 3.8],
        ];

        $templates = [];
        foreach ($templateSpecs as $spec) {
            if (count($spec) === 17) {
                array_splice($spec, 14, 0, [false]);
            }
            [$code, $name, $lvl, $hp, $atk, $def, $xp, $aggro, $range, $speed, $interval, $respawn, $aggressive, $isBoss, $isWorldBoss, $mesh, $color, $scale] = $spec;
            $tpl = $this->templateRepository->findOneByCode($code);
            if (!$tpl instanceof MonsterTemplate) {
                $tpl = new MonsterTemplate($code, $name);
                $this->em->persist($tpl);
            }
            $tpl->setName($name)
                ->setLevel($lvl)
                ->setMaxHp($hp)
                ->setAttack($atk)
                ->setDefense($def)
                ->setXpReward($xp)
                ->setAggroRadius($aggro)
                ->setAttackRange($range)
                ->setMoveSpeed($speed)
                ->setAttackInterval($interval)
                ->setRespawnSeconds($respawn)
                ->setAggressive($aggressive)
                ->setIsBoss($isBoss)
                ->setIsWorldBoss($isWorldBoss)
                ->setMeshKey($mesh)
                ->setPrimaryColor($color)
                ->setScale($scale);
            $templates[$code] = $tpl;
        }
        $this->em->flush();

        // 3. Seed monster instances across La Réunion if empty (or reset requested)
        $existingCount = $this->instanceRepository->count([]);
        if ($resetMonsters && $existingCount > 0) {
            $this->em->createQuery('DELETE FROM App\Entity\MonsterInstance')->execute();
            $existingCount = 0;
        }

        $spawned = 0;
        if ($existingCount === 0) {
            // [templateCode, zoneCode, x, z]
            $spawns = [
                // Lagon de l'Ouest (autour du camp de Saint-Gilles -585, -95 — facile à trouver immédiatement!)
                ['crabe_recif', 'lagon_ouest', -550.0, -70.0],
                ['crabe_recif', 'lagon_ouest', -540.0, -115.0],
                ['crabe_recif', 'lagon_ouest', -615.0, -45.0],
                ['crabe_recif', 'lagon_ouest', -570.0, -35.0],
                ['crabe_recif', 'lagon_ouest', -525.0, -90.0],
                ['tangue_sauvage', 'lagon_ouest', -495.0, -60.0],
                ['tangue_sauvage', 'lagon_ouest', -480.0, -125.0],
                ['tangue_sauvage', 'lagon_ouest', -515.0, -155.0],
                ['tangue_sauvage', 'lagon_ouest', -545.0, 20.0],
                ['guepe_cotiere', 'lagon_ouest', -440.0, -165.0],
                ['guepe_cotiere', 'lagon_ouest', -460.0, 45.0],
                ['guepe_cotiere', 'lagon_ouest', -420.0, -75.0],

                // Cirque de Salazie
                ['sanglier_salazie', 'salazie', 65.0, -270.0],
                ['sanglier_salazie', 'salazie', 120.0, -310.0],
                ['sanglier_salazie', 'salazie', 80.0, -340.0],
                ['sanglier_salazie', 'salazie', 150.0, -255.0],
                ['papangue_feroce', 'salazie', 40.0, -230.0],
                ['papangue_feroce', 'salazie', 110.0, -220.0],
                ['papangue_feroce', 'salazie', 160.0, -320.0],

                // Cirque de Mafate
                ['papangue_feroce', 'mafate', -285.0, -215.0],
                ['papangue_feroce', 'mafate', -220.0, -270.0],
                ['golem_schiste', 'mafate', -245.0, -235.0],
                ['golem_schiste', 'mafate', -275.0, -265.0],
                ['golem_schiste', 'mafate', -195.0, -220.0],
                ['golem_schiste', 'mafate', -310.0, -245.0],

                // Cirque de Cilaos
                ['cerf_java', 'cilaos', -160.0, 125.0],
                ['cerf_java', 'cilaos', -115.0, 175.0],
                ['cerf_java', 'cilaos', -185.0, 175.0],
                ['cerf_java', 'cilaos', -85.0, 130.0],
                ['gardien_cilaos', 'cilaos', -135.0, 95.0],
                ['gardien_cilaos', 'cilaos', -105.0, 65.0],
                ['gardien_cilaos', 'cilaos', -155.0, 195.0],

                // Sud Sauvage
                ['lezard_obsidienne', 'sud_sauvage', 145.0, 485.0],
                ['lezard_obsidienne', 'sud_sauvage', 195.0, 520.0],
                ['lezard_obsidienne', 'sud_sauvage', 240.0, 470.0],
                ['lezard_obsidienne', 'sud_sauvage', 105.0, 515.0],
                ['lezard_obsidienne', 'sud_sauvage', 275.0, 420.0],

                // Piton des Neiges
                ['gardien_cilaos', 'piton_neiges', -95.0, -25.0],
                ['gardien_cilaos', 'piton_neiges', -45.0, -95.0],
                ['colosse_nuages', 'piton_neiges', -75.0, -65.0],
                ['colosse_nuages', 'piton_neiges', -100.0, -85.0],

                // Plaine des Sables & Piton de la Fournaise
                ['molosse_magma', 'fournaise', 235.0, 210.0],
                ['molosse_magma', 'fournaise', 295.0, 235.0],
                ['molosse_magma', 'fournaise', 355.0, 250.0],
                ['molosse_magma', 'fournaise', 395.0, 290.0],
                ['molosse_magma', 'fournaise', 465.0, 240.0],
                ['molosse_magma', 'fournaise', 420.0, 315.0],
                // WORLD BOSS au cœur du Cratère Dolomieu!
                ['seigneur_dolomieu', 'fournaise', 440.0, 275.0],
            ];

            foreach ($spawns as [$tplCode, $zoneCode, $sx, $sz]) {
                $pos = $this->terrainService->findNearestWalkable($sx, $sz);
                $inst = new MonsterInstance($templates[$tplCode], $zoneCode, $pos['x'], $pos['y'], $pos['z']);
                $this->em->persist($inst);
                ++$spawned;
            }
            $this->em->flush();
        } else {
            // Sync existing IDLE monster HP with updated templates (e.g. upgraded World Boss 28,000 HP)
            $allInstances = $this->instanceRepository->findAll();
            $hasWorldBoss = false;
            foreach ($allInstances as $inst) {
                $tpl = $inst->getTemplate();
                if ($tpl->isWorldBoss()) {
                    $hasWorldBoss = true;
                }
                if ($inst->getState() === MonsterInstance::STATE_IDLE && $inst->getCurrentHp() !== $tpl->getMaxHp()) {
                    $inst->setCurrentHp($tpl->getMaxHp());
                }
            }
            if (!$hasWorldBoss && isset($templates['seigneur_dolomieu'])) {
                $pos = $this->terrainService->findNearestWalkable(440.0, 275.0);
                $wb = new MonsterInstance($templates['seigneur_dolomieu'], 'fournaise', $pos['x'], $pos['y'], $pos['z']);
                $this->em->persist($wb);
                ++$existingCount;
            }
            $this->em->flush();
            $spawned = $existingCount;
        }

        return [
            'zones' => $zonesCount,
            'templates' => count($templates),
            'monsters' => $spawned,
        ];
    }
}
