<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Character;
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

        // 3. Detect legacy 2048-scale database so existing worlds migrate cleanly to 7168-scale coordinates
        $existingCount = $this->instanceRepository->count([]);
        if ($existingCount > 0 && !$resetMonsters) {
            foreach ($this->instanceRepository->findAll() as $inst) {
                if ($inst->getTemplate()->isWorldBoss() && $inst->getSpawnX() < 1000.0) {
                    $resetMonsters = true;
                    break;
                }
            }
            if ($resetMonsters) {
                // Remove old-scale bots & relocate existing human characters to their zone's new respawn point
                $this->em->createQuery('DELETE FROM App\Entity\Character c WHERE c.isBot = true')->execute();
                $zonesMeta = WorldCatalog::zones();
                /** @var list<Character> $humans */
                $humans = $this->em->createQuery('SELECT c FROM App\Entity\Character c WHERE c.isBot = false')->getResult();
                foreach ($humans as $human) {
                    $zMeta = $zonesMeta[$human->getZoneCode()] ?? $zonesMeta['lagon_ouest'];
                    $safe = $this->terrainService->findNearestWalkable($zMeta['respawnX'], $zMeta['respawnZ']);
                    $human->setPosX($safe['x'])
                        ->setPosY($safe['y'])
                        ->setPosZ($safe['z']);
                }
            }
        }

        if ($resetMonsters && $existingCount > 0) {
            $this->em->createQuery('DELETE FROM App\Entity\MonsterInstance')->execute();
            $existingCount = 0;
        }

        $spawned = 0;
        if ($existingCount === 0) {
            // [templateCode, zoneCode, x, z] across the enlarged 7168 x 7168 Réunion island (~105 creatures)
            $spawns = [
                // Lagon de l'Ouest (~24 mobs: starter beach crabs + tangues + coastal wasps along trails)
                ['crabe_recif', 'lagon_ouest', -2005.0, -305.0],
                ['crabe_recif', 'lagon_ouest', -1985.0, -345.0],
                ['crabe_recif', 'lagon_ouest', -2085.0, -275.0],
                ['crabe_recif', 'lagon_ouest', -2025.0, -265.0],
                ['crabe_recif', 'lagon_ouest', -1955.0, -320.0],
                ['crabe_recif', 'lagon_ouest', -1995.0, -230.0],
                ['crabe_recif', 'lagon_ouest', -1935.0, -385.0],
                ['crabe_recif', 'lagon_ouest', -2065.0, -220.0],
                ['tangue_sauvage', 'lagon_ouest', -1900.0, -300.0],
                ['tangue_sauvage', 'lagon_ouest', -1865.0, -365.0],
                ['tangue_sauvage', 'lagon_ouest', -1895.0, -425.0],
                ['tangue_sauvage', 'lagon_ouest', -1935.0, -195.0],
                ['tangue_sauvage', 'lagon_ouest', -1810.0, -260.0],
                ['tangue_sauvage', 'lagon_ouest', -1780.0, -380.0],
                ['tangue_sauvage', 'lagon_ouest', -1855.0, -140.0],
                ['tangue_sauvage', 'lagon_ouest', -1745.0, -210.0],
                ['guepe_cotiere', 'lagon_ouest', -1680.0, -450.0],
                ['guepe_cotiere', 'lagon_ouest', -1730.0, -80.0],
                ['guepe_cotiere', 'lagon_ouest', -1610.0, -290.0],
                ['guepe_cotiere', 'lagon_ouest', -1540.0, -520.0],
                ['guepe_cotiere', 'lagon_ouest', -1660.0, 80.0],
                ['guepe_cotiere', 'lagon_ouest', -1520.0, -180.0],
                ['guepe_cotiere', 'lagon_ouest', -1420.0, -410.0],
                ['guepe_cotiere', 'lagon_ouest', -1490.0, 210.0],

                // Cirque de Salazie (~15 mobs)
                ['sanglier_salazie', 'salazie', 250.0, -960.0],
                ['sanglier_salazie', 'salazie', 390.0, -1080.0],
                ['sanglier_salazie', 'salazie', 300.0, -1160.0],
                ['sanglier_salazie', 'salazie', 460.0, -920.0],
                ['sanglier_salazie', 'salazie', 210.0, -1100.0],
                ['sanglier_salazie', 'salazie', 505.0, -1010.0],
                ['sanglier_salazie', 'salazie', 350.0, -880.0],
                ['sanglier_salazie', 'salazie', 165.0, -990.0],
                ['papangue_feroce', 'salazie', 190.0, -830.0],
                ['papangue_feroce', 'salazie', 385.0, -790.0],
                ['papangue_feroce', 'salazie', 515.0, -1140.0],
                ['papangue_feroce', 'salazie', 280.0, -1220.0],
                ['papangue_feroce', 'salazie', 435.0, -860.0],
                ['papangue_feroce', 'salazie', 145.0, -1040.0],
                ['papangue_feroce', 'salazie', 490.0, -780.0],

                // Cirque de Mafate (~14 mobs)
                ['papangue_feroce', 'mafate', -1020.0, -770.0],
                ['papangue_feroce', 'mafate', -780.0, -950.0],
                ['papangue_feroce', 'mafate', -1190.0, -810.0],
                ['papangue_feroce', 'mafate', -640.0, -910.0],
                ['papangue_feroce', 'mafate', -930.0, -990.0],
                ['papangue_feroce', 'mafate', -710.0, -730.0],
                ['golem_schiste', 'mafate', -860.0, -825.0],
                ['golem_schiste', 'mafate', -960.0, -920.0],
                ['golem_schiste', 'mafate', -690.0, -780.0],
                ['golem_schiste', 'mafate', -1090.0, -860.0],
                ['golem_schiste', 'mafate', -810.0, -900.0],
                ['golem_schiste', 'mafate', -995.0, -720.0],
                ['golem_schiste', 'mafate', -600.0, -980.0],
                ['golem_schiste', 'mafate', -1210.0, -740.0],

                // Cirque de Cilaos (~15 mobs)
                ['cerf_java', 'cilaos', -560.0, 440.0],
                ['cerf_java', 'cilaos', -405.0, 610.0],
                ['cerf_java', 'cilaos', -645.0, 605.0],
                ['cerf_java', 'cilaos', -310.0, 455.0],
                ['cerf_java', 'cilaos', -515.0, 725.0],
                ['cerf_java', 'cilaos', -730.0, 520.0],
                ['cerf_java', 'cilaos', -235.0, 610.0],
                ['cerf_java', 'cilaos', -615.0, 345.0],
                ['gardien_cilaos', 'cilaos', -475.0, 340.0],
                ['gardien_cilaos', 'cilaos', -365.0, 265.0],
                ['gardien_cilaos', 'cilaos', -540.0, 680.0],
                ['gardien_cilaos', 'cilaos', -215.0, 480.0],
                ['gardien_cilaos', 'cilaos', -690.0, 415.0],
                ['gardien_cilaos', 'cilaos', -390.0, 750.0],
                ['gardien_cilaos', 'cilaos', -450.0, 245.0],

                // Sud Sauvage (~12 mobs)
                ['lezard_obsidienne', 'sud_sauvage', 505.0, 1690.0],
                ['lezard_obsidienne', 'sud_sauvage', 680.0, 1815.0],
                ['lezard_obsidienne', 'sud_sauvage', 840.0, 1645.0],
                ['lezard_obsidienne', 'sud_sauvage', 370.0, 1795.0],
                ['lezard_obsidienne', 'sud_sauvage', 960.0, 1480.0],
                ['lezard_obsidienne', 'sud_sauvage', 210.0, 1580.0],
                ['lezard_obsidienne', 'sud_sauvage', 585.0, 1890.0],
                ['lezard_obsidienne', 'sud_sauvage', 765.0, 1735.0],
                ['lezard_obsidienne', 'sud_sauvage', 435.0, 1560.0],
                ['lezard_obsidienne', 'sud_sauvage', 910.0, 1830.0],
                ['lezard_obsidienne', 'sud_sauvage', 175.0, 1465.0],
                ['lezard_obsidienne', 'sud_sauvage', 640.0, 1545.0],

                // Piton des Neiges (~10 mobs)
                ['gardien_cilaos', 'piton_neiges', -335.0, -90.0],
                ['gardien_cilaos', 'piton_neiges', -160.0, -330.0],
                ['gardien_cilaos', 'piton_neiges', -410.0, -210.0],
                ['gardien_cilaos', 'piton_neiges', -95.0, -145.0],
                ['gardien_cilaos', 'piton_neiges', -290.0, -395.0],
                ['gardien_cilaos', 'piton_neiges', -195.0, 10.0],
                ['colosse_nuages', 'piton_neiges', -262.5, -227.5],
                ['colosse_nuages', 'piton_neiges', -350.0, -295.0],
                ['colosse_nuages', 'piton_neiges', -170.0, -195.0],
                ['colosse_nuages', 'piton_neiges', -285.0, -125.0],

                // Plaine des Sables & Piton de la Fournaise (~14 mobs + World Boss)
                ['molosse_magma', 'fournaise', 825.0, 735.0],
                ['molosse_magma', 'fournaise', 1030.0, 820.0],
                ['molosse_magma', 'fournaise', 1240.0, 875.0],
                ['molosse_magma', 'fournaise', 1380.0, 1015.0],
                ['molosse_magma', 'fournaise', 1620.0, 840.0],
                ['molosse_magma', 'fournaise', 1470.0, 1105.0],
                ['molosse_magma', 'fournaise', 560.0, 680.0],
                ['molosse_magma', 'fournaise', 710.0, 790.0],
                ['molosse_magma', 'fournaise', 950.0, 695.0],
                ['molosse_magma', 'fournaise', 1140.0, 765.0],
                ['molosse_magma', 'fournaise', 1295.0, 960.0],
                ['molosse_magma', 'fournaise', 1410.0, 800.0],
                ['molosse_magma', 'fournaise', 1580.0, 1080.0],
                ['molosse_magma', 'fournaise', 1190.0, 1060.0],
                // WORLD BOSS au cœur du Cratère Dolomieu!
                ['seigneur_dolomieu', 'fournaise', 1540.0, 962.5],
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
                $pos = $this->terrainService->findNearestWalkable(1540.0, 962.5);
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
