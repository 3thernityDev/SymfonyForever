<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Character;
use App\Entity\MonsterInstance;
use App\Entity\MonsterTemplate;
use App\Entity\Zone;
use App\Game\NpcRegistry;
use App\Game\WorldCatalog;
use App\Repository\MonsterInstanceRepository;
use App\Repository\MonsterTemplateRepository;
use App\Repository\ZoneRepository;
use Doctrine\ORM\EntityManagerInterface;

final class WorldSeederService
{
    private readonly NpcRegistry $npcRegistry;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ZoneRepository $zoneRepository,
        private readonly MonsterTemplateRepository $templateRepository,
        private readonly MonsterInstanceRepository $instanceRepository,
        private readonly ReunionTerrainService $terrainService,
        ?NpcRegistry $npcRegistry = null,
    ) {
        $this->npcRegistry = $npcRegistry ?? new NpcRegistry();
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

        // 3. Detect legacy 2048-scale or sparse monster seed so existing worlds upgrade cleanly
        $existingCount = $this->instanceRepository->count([]);
        if ($existingCount > 0 && !$resetMonsters) {
            $legacyScaleReset = false;
            foreach ($this->instanceRepository->findAll() as $inst) {
                if ($inst->getTemplate()->isWorldBoss() && $inst->getSpawnX() < 1000.0) {
                    $legacyScaleReset = true;
                    break;
                }
            }

            if ($legacyScaleReset) {
                $resetMonsters = true;
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
            } elseif ($existingCount < 250) {
                // Upgrade earlier sparse monster seed to full island-wide density while keeping players in place
                $resetMonsters = true;
            }
        }

        if ($resetMonsters && $existingCount > 0) {
            $this->em->createQuery('DELETE FROM App\Entity\MonsterInstance')->execute();
            $existingCount = 0;
        }

        $spawned = 0;
        if ($existingCount === 0) {
            // [templateCode, zoneCode, x, z]: curated quest hubs & bosses + procedural island-wide density
            $spawns = [
                // Lagon de l'Ouest starter & quest spots around Saint-Gilles
                ['crabe_recif', 'lagon_ouest', -1995.0, -305.0],
                ['crabe_recif', 'lagon_ouest', -1975.0, -345.0],
                ['crabe_recif', 'lagon_ouest', -2105.0, -265.0],
                ['crabe_recif', 'lagon_ouest', -2015.0, -250.0],
                ['crabe_recif', 'lagon_ouest', -1945.0, -315.0],
                ['crabe_recif', 'lagon_ouest', -1985.0, -220.0],
                ['crabe_recif', 'lagon_ouest', -1925.0, -395.0],
                ['crabe_recif', 'lagon_ouest', -2085.0, -210.0],
                ['tangue_sauvage', 'lagon_ouest', -1890.0, -310.0],
                ['tangue_sauvage', 'lagon_ouest', -1855.0, -375.0],
                ['tangue_sauvage', 'lagon_ouest', -1895.0, -445.0],
                ['tangue_sauvage', 'lagon_ouest', -1935.0, -185.0],
                ['tangue_sauvage', 'lagon_ouest', -1805.0, -255.0],
                ['tangue_sauvage', 'lagon_ouest', -1775.0, -390.0],

                // Cirque de Salazie quest cluster
                ['sanglier_salazie', 'salazie', 250.0, -960.0],
                ['sanglier_salazie', 'salazie', 390.0, -1080.0],
                ['sanglier_salazie', 'salazie', 340.0, -1120.0],
                ['papangue_feroce', 'salazie', 190.0, -860.0],

                // Cirque de Mafate quest cluster
                ['golem_schiste', 'mafate', -910.0, -870.0],
                ['golem_schiste', 'mafate', -800.0, -920.0],
                ['papangue_feroce', 'mafate', -1020.0, -770.0],

                // Cirque de Cilaos quest cluster
                ['cerf_java', 'cilaos', -525.0, 540.0],
                ['cerf_java', 'cilaos', -390.0, 620.0],
                ['gardien_cilaos', 'cilaos', -495.0, 380.0],

                // Sud Sauvage quest cluster
                ['lezard_obsidienne', 'sud_sauvage', 665.0, 1790.0],
                ['lezard_obsidienne', 'sud_sauvage', 505.0, 1705.0],
                ['lezard_obsidienne', 'sud_sauvage', 765.0, 1735.0],

                // Piton des Neiges high massif & summit elite guards
                ['colosse_nuages', 'piton_neiges', -262.5, -227.5],
                ['colosse_nuages', 'piton_neiges', -340.0, -295.0],
                ['colosse_nuages', 'piton_neiges', -175.0, -195.0],
                ['gardien_cilaos', 'piton_neiges', -325.0, -85.0],
                ['gardien_cilaos', 'piton_neiges', -160.0, -320.0],
                ['gardien_cilaos', 'piton_neiges', -410.0, -205.0],
                ['gardien_cilaos', 'piton_neiges', -115.0, -145.0],
                ['gardien_cilaos', 'piton_neiges', -285.0, -385.0],

                // Plaine des Sables & Cratère Dolomieu World Boss
                ['molosse_magma', 'fournaise', 1180.0, 845.0],
                ['molosse_magma', 'fournaise', 1030.0, 820.0],
                ['molosse_magma', 'fournaise', 1320.0, 935.0],
                // WORLD BOSS au cœur du Cratère Dolomieu!
                ['seigneur_dolomieu', 'fournaise', 1540.0, 962.5],
            ];

            $this->appendIslandWideSpawns($spawns);

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

    /**
     * Distributes creatures across every walkable part of La Réunion (grid 210m with jitter)
     * while preserving safe camp bubbles around Quest NPCs, respawn plazas, and the Dolomieu arena.
     *
     * @param list<array{string, string, float, float}> $spawns
     */
    private function appendIslandWideSpawns(array &$spawns): void
    {
        $safeCenters = [];
        $zones = WorldCatalog::zones();
        foreach ($zones as $z) {
            $safeCenters[] = [$z['respawnX'], $z['respawnZ'], 55.0];
        }
        foreach ($this->npcRegistry->all() as $npc) {
            $safeCenters[] = [$npc->x, $npc->z, 55.0];
        }
        // Keep Cratère Dolomieu World Boss arena free of stray trash mobs
        $safeCenters[] = [WorldCatalog::scaleCoord(440.0), WorldCatalog::scaleCoord(275.0), 90.0];

        $lagon = $zones['lagon_ouest'];
        $piton = $zones['piton_neiges'];
        $seed = 9742026;
        $nextRand = static function () use (&$seed): float {
            $seed = (int) (($seed * 1664525 + 1013904223) % 4294967296);

            return ($seed & 0x7FFFFFFF) / 2147483647.0;
        };

        $step = 210.0;
        for ($gz = -3350.0; $gz <= 3350.0; $gz += $step) {
            for ($gx = -3350.0; $gx <= 3350.0; $gx += $step) {
                $cx = $gx + ($nextRand() - 0.5) * 150.0;
                $cz = $gz + ($nextRand() - 0.5) * 150.0;

                if (!$this->terrainService->isWalkable($cx, $cz)) {
                    continue;
                }

                $tooClose = false;
                foreach ($safeCenters as [$sx, $sz, $minR]) {
                    if (hypot($cx - $sx, $cz - $sz) < $minR) {
                        $tooClose = true;
                        break;
                    }
                }
                if ($tooClose) {
                    continue;
                }

                foreach ($spawns as [, , $ex, $ez]) {
                    if (hypot($cx - $ex, $cz - $ez) < 68.0) {
                        $tooClose = true;
                        break;
                    }
                }
                if ($tooClose) {
                    continue;
                }

                $zoneCode = $this->terrainService->getZoneCode($cx, $cz);
                $roll = $nextRand();
                $tplCode = match ($zoneCode) {
                    'lagon_ouest' => (static function () use ($cx, $cz, $lagon, $roll): string {
                        $d = hypot($cx - $lagon['respawnX'], $cz - $lagon['respawnZ']);
                        if ($d < 260.0) {
                            return 'crabe_recif';
                        }
                        if ($d < 620.0) {
                            return $roll < 0.72 ? 'tangue_sauvage' : 'crabe_recif';
                        }

                        return $roll < 0.78 ? 'guepe_cotiere' : 'tangue_sauvage';
                    })(),
                    'salazie' => $roll < 0.55 ? 'sanglier_salazie' : 'papangue_feroce',
                    'mafate' => $roll < 0.60 ? 'golem_schiste' : 'papangue_feroce',
                    'cilaos' => $roll < 0.55 ? 'cerf_java' : 'gardien_cilaos',
                    'sud_sauvage' => 'lezard_obsidienne',
                    'piton_neiges' => hypot($cx - $piton['centerX'], $cz - $piton['centerZ']) < 240.0 && $roll < 0.25
                        ? 'colosse_nuages'
                        : 'gardien_cilaos',
                    'fournaise' => 'molosse_magma',
                    default => 'tangue_sauvage',
                };

                $spawns[] = [$tplCode, $zoneCode, round($cx, 1), round($cz, 1)];
            }
        }
    }
}
