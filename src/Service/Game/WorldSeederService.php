<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Character;
use App\Entity\MonsterInstance;
use App\Entity\MonsterTemplate;
use App\Entity\Zone;
use App\Game\MonsterScaling;
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

        // 2. Seed Réunion endemic & emblematic bestiary templates (Levels 1–60 + Lv 65 World Boss)
        $templateSpecs = [
            // Lagon de l'Ouest (Niv 1-10)
            ['crabe_recif', 'Crabe de Récif', 1, MonsterScaling::ARCHETYPE_BALANCED, 10.0, 5.5, 7.5, 2.2, 18, false, false, false, 'crab', '#ef4444', 1.0],
            ['tangue_sauvage', 'Tangue Épineux', 3, MonsterScaling::ARCHETYPE_BALANCED, 11.0, 5.5, 8.5, 2.0, 20, false, false, false, 'tangue', '#a16207', 1.05],
            ['guepe_cotiere', 'Guêpe des Filaos', 5, MonsterScaling::ARCHETYPE_AGILE, 12.0, 6.0, 9.5, 1.8, 22, true, false, false, 'wasp', '#eab308', 1.0],
            ['endormi_bourbon', 'Endormi de Bourbon', 7, MonsterScaling::ARCHETYPE_ARMORED, 12.5, 6.0, 8.0, 2.1, 22, true, false, false, 'chameleon', '#10b981', 1.15],
            ['paille_en_queue', 'Paille-en-Queue Furieux', 9, MonsterScaling::ARCHETYPE_AGILE, 14.0, 6.5, 11.0, 1.8, 24, true, false, false, 'tropicbird', '#f8fafc', 1.2],

            // Cirque de Salazie (Niv 10-18)
            ['tec_tec_foret', 'Tec-Tec des Cascades', 11, MonsterScaling::ARCHETYPE_AGILE, 13.0, 6.0, 10.5, 1.75, 22, true, false, false, 'songbird', '#f97316', 1.05],
            ['sanglier_salazie', 'Sanglier de Salazie', 13, MonsterScaling::ARCHETYPE_BRUISER, 13.5, 5.5, 9.5, 2.0, 24, true, false, false, 'boar', '#78350f', 1.18],
            ['zoizo_lunettes', 'Oiseau Blanc de Bourbon', 15, MonsterScaling::ARCHETYPE_AGILE, 14.0, 6.0, 11.0, 1.8, 24, true, false, false, 'songbird', '#cbd5e1', 1.1],
            ['papangue_feroce', 'Papangue Féroce', 17, MonsterScaling::ARCHETYPE_AGILE, 15.0, 6.5, 11.5, 1.8, 25, true, false, false, 'bird', '#3f3f46', 1.25],

            // Cirque de Mafate (Niv 18-27)
            ['tuit_tuit', 'Tuit-Tuit des Remparts', 19, MonsterScaling::ARCHETYPE_AGILE, 14.5, 6.5, 11.0, 1.8, 25, true, false, false, 'songbird', '#94a3b8', 1.15],
            ['gecko_bourbon', 'Gecko Vert de Bourbon', 22, MonsterScaling::ARCHETYPE_AGILE, 14.0, 6.0, 10.5, 1.85, 26, true, false, false, 'gecko', '#22c55e', 1.2],
            ['golem_schiste', 'Golem de Schiste', 25, MonsterScaling::ARCHETYPE_ARMORED, 14.5, 6.5, 8.2, 2.3, 28, true, false, false, 'golem', '#64748b', 1.35],
            ['bibe_mafate', 'Bibe Géante de Mafate', 27, MonsterScaling::ARCHETYPE_BRUISER, 15.0, 6.5, 10.0, 1.85, 28, true, false, false, 'spider', '#facc15', 1.3],

            // Cirque de Cilaos (Niv 27-36)
            ['oiseau_vert_cilaos', 'Oiseau Lunettes Vert', 29, MonsterScaling::ARCHETYPE_AGILE, 14.5, 6.0, 11.0, 1.8, 26, true, false, false, 'songbird', '#65a30d', 1.15],
            ['cerf_java', 'Cerf de Java Enragé', 31, MonsterScaling::ARCHETYPE_BRUISER, 15.0, 6.0, 11.5, 1.9, 26, true, false, false, 'stag', '#92400e', 1.28],
            ['merle_peyi', 'Merle Péi des Thermes', 33, MonsterScaling::ARCHETYPE_AGILE, 15.0, 6.5, 11.5, 1.8, 28, true, false, false, 'bird', '#475569', 1.25],
            ['gardien_cilaos', 'Sentinelle des Thermes', 36, MonsterScaling::ARCHETYPE_ARMORED, 14.5, 6.5, 9.0, 2.1, 30, true, false, false, 'golem', '#0284c7', 1.4],

            // Sud Sauvage (Niv 36-45)
            ['gecko_manapany', 'Gecko Vert de Manapany', 38, MonsterScaling::ARCHETYPE_AGILE, 15.0, 6.0, 11.0, 1.8, 26, true, false, false, 'gecko', '#16a34a', 1.25],
            ['tortue_bourbon', 'Tortue Géante de Bourbon', 40, MonsterScaling::ARCHETYPE_ARMORED, 14.0, 6.0, 7.2, 2.3, 30, true, false, false, 'tortoise', '#57534e', 1.45],
            ['lezard_obsidienne', 'Lézard d\'Obsidienne', 42, MonsterScaling::ARCHETYPE_BRUISER, 15.5, 6.0, 10.5, 1.8, 28, true, false, false, 'lizard', '#1e293b', 1.32],
            ['scolopendre_basalte', 'Scolopendre du Grand Brûlé', 45, MonsterScaling::ARCHETYPE_BRUISER, 16.0, 6.5, 10.5, 1.75, 30, true, false, false, 'centipede', '#991b1b', 1.35],

            // Piton des Neiges (Niv 45-54)
            ['petrel_barau', 'Pétrel de Barau', 47, MonsterScaling::ARCHETYPE_AGILE, 16.0, 6.5, 12.0, 1.8, 28, true, false, false, 'tropicbird', '#334155', 1.3],
            ['petrel_noir', 'Pétrel Noir de Bourbon', 50, MonsterScaling::ARCHETYPE_AGILE, 16.5, 6.5, 12.0, 1.75, 30, true, false, false, 'bird', '#18181b', 1.35],
            ['papillon_salazie', 'Papillon La Pâture', 52, MonsterScaling::ARCHETYPE_AGILE, 15.5, 6.5, 11.5, 1.8, 30, true, false, false, 'butterfly', '#06b6d4', 1.35],
            ['colosse_nuages', 'Colosse des Nuages (Élite)', 54, MonsterScaling::ARCHETYPE_ELITE, 17.5, 7.5, 9.5, 2.0, 42, true, true, false, 'golem', '#cbd5e1', 1.85],

            // Piton de la Fournaise (Niv 53-60 + World Boss Niv 65)
            ['roussette_bourbon', 'Roussette Noire de Bourbon', 55, MonsterScaling::ARCHETYPE_AGILE, 16.5, 6.5, 11.5, 1.8, 30, true, false, false, 'bat', '#27272a', 1.35],
            ['ibis_reunion', 'Solitaire de La Réunion', 57, MonsterScaling::ARCHETYPE_BRUISER, 16.5, 6.5, 10.5, 1.85, 30, true, false, false, 'ibis', '#e2e8f0', 1.4],
            ['molosse_magma', 'Molosse de Magma', 59, MonsterScaling::ARCHETYPE_BRUISER, 17.0, 6.5, 10.5, 1.9, 32, true, false, false, 'hound', '#ea580c', 1.38],
            ['chouette_bourbon', 'Petit-Duc de Bourbon', 60, MonsterScaling::ARCHETYPE_BALANCED, 17.5, 7.0, 11.5, 1.85, 34, true, false, false, 'bird', '#7c2d12', 1.45],
            ['seigneur_dolomieu', 'Seigneur du Dolomieu (World Boss)', 65, MonsterScaling::ARCHETYPE_WORLD_BOSS, 28.0, 12.5, 9.5, 1.8, 180, true, true, true, 'boss_volcano', '#dc2626', 3.8],
        ];

        $templates = [];
        foreach ($templateSpecs as $spec) {
            [$code, $name, $lvl, $archetype, $aggro, $range, $speed, $interval, $respawn, $aggressive, $isBoss, $isWorldBoss, $mesh, $color, $scale] = $spec;
            $scaled = MonsterScaling::computeStats($lvl, $archetype);
            $tpl = $this->templateRepository->findOneByCode($code);
            if (!$tpl instanceof MonsterTemplate) {
                $tpl = new MonsterTemplate($code, $name);
                $this->em->persist($tpl);
            }
            $tpl->setName($name)
                ->setLevel($lvl)
                ->setMaxHp($scaled['maxHp'])
                ->setAttack($scaled['attack'])
                ->setDefense($scaled['defense'])
                ->setXpReward($scaled['xpReward'])
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

        // 3. Detect legacy 2048-scale, sparse seed, or outdated bestiary so existing worlds upgrade cleanly
        $existingCount = $this->instanceRepository->count([]);
        if ($existingCount > 0 && !$resetMonsters) {
            $legacyScaleReset = false;
            foreach ($this->instanceRepository->findAll() as $inst) {
                if ($inst->getTemplate()->isWorldBoss() && $inst->getSpawnX() < 1000.0) {
                    $legacyScaleReset = true;
                    break;
                }
            }

            $distinctTemplates = (int) $this->em->createQuery('SELECT COUNT(DISTINCT m.template) FROM App\Entity\MonsterInstance m')
                ->getSingleScalarResult();

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
            } elseif ($existingCount < 250 || $distinctTemplates < count($templates)) {
                // Upgrade earlier sparse/old bestiary seed to full 1-60 island fauna while keeping human players in place
                $resetMonsters = true;
                if ($distinctTemplates < count($templates)) {
                    $this->em->createQuery('DELETE FROM App\Entity\Character c WHERE c.isBot = true')->execute();
                }
            }
        }

        if ($resetMonsters && $existingCount > 0) {
            $this->em->createQuery('DELETE FROM App\Entity\MonsterInstance')->execute();
            $existingCount = 0;
        }

        $spawned = 0;
        if ($existingCount === 0) {
            // [templateCode, zoneCode, x, z]: curated quest hubs & endemic fauna clusters + procedural island-wide density
            $spawns = [
                // Lagon de l'Ouest (Niv 1-10): calm passive starter camp + endemic coastal wildlife
                ['crabe_recif', 'lagon_ouest', -1995.0, -305.0],
                ['crabe_recif', 'lagon_ouest', -1975.0, -345.0],
                ['crabe_recif', 'lagon_ouest', -2105.0, -265.0],
                ['crabe_recif', 'lagon_ouest', -2015.0, -250.0],
                ['crabe_recif', 'lagon_ouest', -1945.0, -315.0],
                ['crabe_recif', 'lagon_ouest', -1985.0, -220.0],
                ['tangue_sauvage', 'lagon_ouest', -1890.0, -310.0],
                ['tangue_sauvage', 'lagon_ouest', -1855.0, -375.0],
                ['tangue_sauvage', 'lagon_ouest', -1895.0, -445.0],
                ['guepe_cotiere', 'lagon_ouest', -1790.0, -265.0],
                ['endormi_bourbon', 'lagon_ouest', -1740.0, -420.0],
                ['paille_en_queue', 'lagon_ouest', -1760.0, -140.0],

                // Cirque de Salazie (Niv 10-18)
                ['tec_tec_foret', 'salazie', 255.0, -920.0],
                ['sanglier_salazie', 'salazie', 250.0, -960.0],
                ['sanglier_salazie', 'salazie', 390.0, -1080.0],
                ['zoizo_lunettes', 'salazie', 435.0, -1010.0],
                ['papangue_feroce', 'salazie', 190.0, -860.0],

                // Cirque de Mafate (Niv 18-27)
                ['tuit_tuit', 'mafate', -965.0, -790.0],
                ['gecko_bourbon', 'mafate', -820.0, -750.0],
                ['golem_schiste', 'mafate', -910.0, -870.0],
                ['golem_schiste', 'mafate', -800.0, -920.0],
                ['bibe_mafate', 'mafate', -1020.0, -770.0],

                // Cirque de Cilaos (Niv 27-36)
                ['oiseau_vert_cilaos', 'cilaos', -560.0, 470.0],
                ['cerf_java', 'cilaos', -525.0, 540.0],
                ['cerf_java', 'cilaos', -390.0, 620.0],
                ['merle_peyi', 'cilaos', -365.0, 440.0],
                ['gardien_cilaos', 'cilaos', -495.0, 380.0],

                // Sud Sauvage (Niv 36-45)
                ['gecko_manapany', 'sud_sauvage', 465.0, 1650.0],
                ['tortue_bourbon', 'sud_sauvage', 595.0, 1785.0],
                ['lezard_obsidienne', 'sud_sauvage', 665.0, 1790.0],
                ['lezard_obsidienne', 'sud_sauvage', 505.0, 1705.0],
                ['scolopendre_basalte', 'sud_sauvage', 765.0, 1735.0],

                // Piton des Neiges (Niv 45-54)
                ['petrel_barau', 'piton_neiges', -325.0, -85.0],
                ['petrel_barau', 'piton_neiges', -115.0, -145.0],
                ['petrel_noir', 'piton_neiges', -160.0, -320.0],
                ['petrel_noir', 'piton_neiges', -285.0, -385.0],
                ['papillon_salazie', 'piton_neiges', -410.0, -205.0],
                ['papillon_salazie', 'piton_neiges', -175.0, -195.0],
                ['colosse_nuages', 'piton_neiges', -262.5, -227.5],
                ['colosse_nuages', 'piton_neiges', -340.0, -295.0],

                // Piton de la Fournaise (Niv 53-60 + World Boss Niv 65)
                ['roussette_bourbon', 'fournaise', 990.0, 790.0],
                ['ibis_reunion', 'fournaise', 1110.0, 745.0],
                ['molosse_magma', 'fournaise', 1180.0, 845.0],
                ['molosse_magma', 'fournaise', 1030.0, 820.0],
                ['chouette_bourbon', 'fournaise', 1320.0, 935.0],
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
                        if ($d < 220.0) {
                            return $roll < 0.65 ? 'crabe_recif' : 'tangue_sauvage';
                        }
                        if ($d < 520.0) {
                            return match (true) {
                                $roll < 0.30 => 'crabe_recif',
                                $roll < 0.65 => 'tangue_sauvage',
                                default => 'guepe_cotiere',
                            };
                        }

                        return match (true) {
                            $roll < 0.25 => 'tangue_sauvage',
                            $roll < 0.50 => 'guepe_cotiere',
                            $roll < 0.78 => 'endormi_bourbon',
                            default => 'paille_en_queue',
                        };
                    })(),
                    'salazie' => match (true) {
                        $roll < 0.25 => 'tec_tec_foret',
                        $roll < 0.55 => 'sanglier_salazie',
                        $roll < 0.80 => 'zoizo_lunettes',
                        default => 'papangue_feroce',
                    },
                    'mafate' => match (true) {
                        $roll < 0.24 => 'tuit_tuit',
                        $roll < 0.50 => 'gecko_bourbon',
                        $roll < 0.78 => 'golem_schiste',
                        default => 'bibe_mafate',
                    },
                    'cilaos' => match (true) {
                        $roll < 0.22 => 'oiseau_vert_cilaos',
                        $roll < 0.50 => 'cerf_java',
                        $roll < 0.76 => 'merle_peyi',
                        default => 'gardien_cilaos',
                    },
                    'sud_sauvage' => match (true) {
                        $roll < 0.25 => 'gecko_manapany',
                        $roll < 0.52 => 'tortue_bourbon',
                        $roll < 0.78 => 'lezard_obsidienne',
                        default => 'scolopendre_basalte',
                    },
                    'piton_neiges' => hypot($cx - $piton['centerX'], $cz - $piton['centerZ']) < 240.0 && $roll < 0.22
                        ? 'colosse_nuages'
                        : match (true) {
                            $roll < 0.36 => 'petrel_barau',
                            $roll < 0.70 => 'petrel_noir',
                            default => 'papillon_salazie',
                        },
                    'fournaise' => match (true) {
                        $roll < 0.26 => 'roussette_bourbon',
                        $roll < 0.52 => 'ibis_reunion',
                        $roll < 0.78 => 'molosse_magma',
                        default => 'chouette_bourbon',
                    },
                    default => 'tangue_sauvage',
                };

                $spawns[] = [$tplCode, $zoneCode, round($cx, 1), round($cz, 1)];
            }
        }
    }
}
