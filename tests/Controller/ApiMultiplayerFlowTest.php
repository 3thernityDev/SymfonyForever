<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Character;
use App\Entity\User;
use App\Game\CharacterClass;
use App\Game\WorldCatalog;
use App\Service\Game\StatCalculator;
use App\Service\Game\WorldSeederService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiMultiplayerFlowTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->em->getConfiguration()->setUseSchemaEditorApi(true);
        $schemaTool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        self::getContainer()->get(WorldSeederService::class)->seed();
    }

    public function testManifestSyncCombatChatAndTravelOverHttp(): void
    {
        // 1. Public manifest check
        $this->client->request('GET', '/api/game/manifest');
        self::assertResponseIsSuccessful();
        $manifest = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('SymfonyForever', $manifest['gameTitle']);
        self::assertCount(7, $manifest['zones']);
        self::assertCount(7, $manifest['trails']);
        foreach ($manifest['trails'] as $trail) {
            self::assertGreaterThanOrEqual(4, count($trail));
            foreach ($trail as $pt) {
                self::assertArrayHasKey('x', $pt);
                self::assertArrayHasKey('z', $pt);
            }
        }
        self::assertCount(3, $manifest['classes']);
        self::assertCount(12, $manifest['skills']);

        // 2. Authenticate a user with a Tank character at Saint-Gilles
        $hub = WorldCatalog::zones()['lagon_ouest'];
        $user = (new User())->setUsername('reunion_player')->setEmail('p@reunion.re')->setPassword('unused');
        $this->em->persist($user);

        $char = (new Character($user, 'PitonGuard', CharacterClass::TANK))
            ->setPosX($hub['respawnX'])->setPosY(12.0)->setPosZ($hub['respawnZ'])->setZoneCode('lagon_ouest');
        $stats = self::getContainer()->get(StatCalculator::class)->compute($char);
        $char->setCurrentHp($stats['maxHp'])->setCurrentMp($stats['maxMp']);
        $this->em->persist($char);
        $this->em->flush();

        $this->client->loginUser($user);

        // 3. Play view renders 200
        $this->client->request('GET', '/play/'.$char->getId());
        self::assertResponseIsSuccessful();

        // 4. HTTP World Sync returns self, zone & nearby monsters
        $this->client->request('POST', '/api/world/sync', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'characterId' => $char->getId(),
            'x' => $hub['respawnX'],
            'z' => $hub['respawnZ'],
            'rotY' => 0.5,
            'isMoving' => true,
            'lastEventId' => 0,
            'lastChatId' => 0,
        ]));
        self::assertResponseIsSuccessful();
        $sync = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('lagon_ouest', $sync['zone']['code']);
        self::assertGreaterThanOrEqual(1, $sync['zonePlayerCount']);
        self::assertGreaterThanOrEqual(1.0, $sync['respawnMultiplier']);
        self::assertIsArray($sync['atmosphere']);
        self::assertArrayHasKey('timeOfDay', $sync['atmosphere']);
        self::assertArrayHasKey('weather', $sync['atmosphere']);
        self::assertArrayHasKey('isNight', $sync['atmosphere']);
        self::assertSame(720.0, (float) $sync['atmosphere']['dayCycleSeconds']);
        self::assertNotEmpty($sync['monsters']);

        // Pick closest monster & move next to it using the current kernel's EntityManager
        $targetMonster = $sync['monsters'][0];
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $freshChar = $em->find(Character::class, $char->getId());
        self::assertInstanceOf(Character::class, $freshChar);
        $freshChar->setPosX((float) $targetMonster['x'] + 2.0)
            ->setPosZ((float) $targetMonster['z'])
            ->setCurrentMp(40);
        $em->flush();
        $this->client->request('POST', '/api/combat/attack', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'characterId' => $char->getId(),
            'targetType' => 'MONSTER',
            'targetId' => $targetMonster['id'],
        ]));
        self::assertResponseIsSuccessful();
        $aa = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($aa['ok']);
        self::assertSame(15, $aa['resourceGain']);
        self::assertSame(55, $aa['self']['mp']);
        self::assertSame('RAGE', $aa['self']['resourceType']);

        // 5b. Cast shield_bash over HTTP (/api/combat/cast) -> consumes 20 Rage (55 -> 35)
        $this->client->request('POST', '/api/combat/cast', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'characterId' => $char->getId(),
            'skillCode' => 'shield_bash',
            'targetType' => 'MONSTER',
            'targetId' => $targetMonster['id'],
        ]));
        self::assertResponseIsSuccessful();
        $cast = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($cast['ok']);
        self::assertSame(35, $cast['self']['mp']);
        self::assertNotEmpty($cast['events']);
        self::assertSame('DAMAGE', $cast['events'][0]['eventType']);

        // 6. Send chat message and verify it shows up in sync
        $this->client->request('POST', '/api/chat/send', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'characterId' => $char->getId(),
            'channel' => 'ZONE',
            'content' => 'Oté La Réunion !',
        ]));
        self::assertResponseIsSuccessful();

        $this->client->request('POST', '/api/world/sync', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'characterId' => $char->getId(),
            'x' => $char->getPosX(),
            'z' => $char->getPosZ(),
            'rotY' => 0.0,
            'isMoving' => false,
            'lastEventId' => 0,
            'lastChatId' => 0,
        ]));
        $sync2 = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertNotEmpty($sync2['chat']);
        self::assertSame('Oté La Réunion !', $sync2['chat'][0]['content']);

        // 7. Upgrade a skill over HTTP (/api/character/upgrade-skill) using earned skill points
        $em2 = self::getContainer()->get(EntityManagerInterface::class);
        $c2 = $em2->find(Character::class, $char->getId());
        self::assertInstanceOf(Character::class, $c2);
        $c2->setLevel(3)->setSkillPoints(1);
        $em2->flush();

        $this->client->request('POST', '/api/character/upgrade-skill', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'characterId' => $char->getId(),
            'skillCode' => 'shield_bash',
        ]));
        self::assertResponseIsSuccessful();
        $upg = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($upg['ok']);
        self::assertSame(2, $upg['newRank']);
        self::assertSame(0, $upg['self']['skillPoints']);
        self::assertSame(2, $upg['self']['skillLevels']['shield_bash']);

        // 8. Secret /iddad chat command grants +1,000,000 gold and promotes character to level 60 without broadcasting to chat
        $em2->clear();
        $cBefore = $em2->find(Character::class, $char->getId());
        self::assertInstanceOf(Character::class, $cBefore);
        self::assertSame(3, $cBefore->getLevel());
        $goldBefore = $cBefore->getGold();
        $statPtsBefore = $cBefore->getStatPoints();
        $skillPtsBefore = $cBefore->getSkillPoints();
        $vitBefore = $cBefore->getVitality();

        $this->client->request('POST', '/api/chat/send', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'characterId' => $char->getId(),
            'channel' => 'PARTY',
            'content' => '/iddad',
        ]));
        self::assertResponseIsSuccessful();
        $cheat = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($cheat['ok']);
        self::assertSame('iddad', $cheat['command']);
        self::assertSame(60, $cheat['self']['level']);
        self::assertSame($goldBefore + 1_000_000, $cheat['self']['gold']);
        self::assertSame($goldBefore + 1_000_000, $cheat['inventory']['gold']);
        self::assertSame($cheat['self']['maxHp'], $cheat['self']['hp']);

        $em2->clear();
        $cAfter = $em2->find(Character::class, $char->getId());
        self::assertInstanceOf(Character::class, $cAfter);
        self::assertSame(60, $cAfter->getLevel());
        self::assertSame($goldBefore + 1_000_000, $cAfter->getGold());
        self::assertSame($statPtsBefore + (57 * 3), $cAfter->getStatPoints());
        self::assertSame($skillPtsBefore + 19, $cAfter->getSkillPoints());
        self::assertGreaterThan($vitBefore, $cAfter->getVitality());

        // Calling /iddad again at level 60 grants another +1,000,000 gold without duplicating level-up points
        $vitAt60 = $cAfter->getVitality();
        $statPtsAt60 = $cAfter->getStatPoints();
        $skillPtsAt60 = $cAfter->getSkillPoints();

        $this->client->request('POST', '/api/chat/send', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'characterId' => $char->getId(),
            'channel' => 'ZONE',
            'content' => '/IDDAD',
        ]));
        self::assertResponseIsSuccessful();
        $cheat2 = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($cheat2['ok']);
        self::assertSame(60, $cheat2['self']['level']);
        self::assertSame($goldBefore + 2_000_000, $cheat2['self']['gold']);

        $em2->clear();
        $cAfter2 = $em2->find(Character::class, $char->getId());
        self::assertInstanceOf(Character::class, $cAfter2);
        self::assertSame(60, $cAfter2->getLevel());
        self::assertSame($statPtsAt60, $cAfter2->getStatPoints());
        self::assertSame($skillPtsAt60, $cAfter2->getSkillPoints());
        self::assertSame($vitAt60, $cAfter2->getVitality());

        $this->client->request('POST', '/api/world/sync', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'characterId' => $char->getId(),
            'x' => $cAfter->getPosX(),
            'z' => $cAfter->getPosZ(),
            'rotY' => 0.0,
            'isMoving' => false,
            'lastEventId' => 0,
            'lastChatId' => 0,
        ]));
        self::assertResponseIsSuccessful();
        $sync3 = json_decode((string) $this->client->getResponse()->getContent(), true);
        $contents = array_column($sync3['chat'], 'content');
        self::assertNotContains('/iddad', $contents);
    }

    public function testSharedAtmosphereAndIddadAdminPanelApi(): void
    {
        $lagon = WorldCatalog::zones()['lagon_ouest'];
        $fournaise = WorldCatalog::zones()['fournaise'];

        $user1 = (new User())->setUsername('atmo_admin')->setEmail('admin@reunion.re')->setPassword('unused');
        $user2 = (new User())->setUsername('atmo_peer')->setEmail('peer@reunion.re')->setPassword('unused');
        $this->em->persist($user1);
        $this->em->persist($user2);

        $char1 = (new Character($user1, 'LagonScout', CharacterClass::DPS))
            ->setPosX($lagon['respawnX'])->setPosY(12.0)->setPosZ($lagon['respawnZ'])->setZoneCode('lagon_ouest');
        $char2 = (new Character($user2, 'VolcanoWalker', CharacterClass::HEAL))
            ->setPosX($fournaise['respawnX'])->setPosY(42.0)->setPosZ($fournaise['respawnZ'])->setZoneCode('fournaise');

        $stats = self::getContainer()->get(StatCalculator::class);
        $s1 = $stats->compute($char1);
        $s2 = $stats->compute($char2);
        $char1->setCurrentHp($s1['maxHp'])->setCurrentMp($s1['maxMp']);
        $char2->setCurrentHp($s2['maxHp'])->setCurrentMp($s2['maxMp']);

        $this->em->persist($char1);
        $this->em->persist($char2);
        $this->em->flush();

        // 1. Before /iddad, admin endpoint is forbidden (403) and initial atmosphere is shared between zones
        $this->client->loginUser($user1);
        $this->client->request('POST', '/api/admin/atmosphere', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'weather' => 'STORM',
        ]));
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/api/world/sync', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'characterId' => $char1->getId(),
            'x' => $lagon['respawnX'],
            'z' => $lagon['respawnZ'],
        ]));
        self::assertResponseIsSuccessful();
        $sync1 = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user2);
        $this->client->request('POST', '/api/world/sync', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'characterId' => $char2->getId(),
            'x' => $fournaise['respawnX'],
            'z' => $fournaise['respawnZ'],
        ]));
        self::assertResponseIsSuccessful();
        $sync2 = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame($sync1['atmosphere']['weather'], $sync2['atmosphere']['weather']);
        self::assertEqualsWithDelta($sync1['atmosphere']['timeOfDay'], $sync2['atmosphere']['timeOfDay'], 0.05);
        self::assertFalse($sync2['atmosphere']['isWeatherOverridden']);
        self::assertFalse($sync2['atmosphere']['isTimeFrozen']);

        // 2. User 1 sends /iddad -> unlocks admin panel & returns current shared atmosphere
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user1);
        $this->client->request('POST', '/api/chat/send', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'characterId' => $char1->getId(),
            'channel' => 'ZONE',
            'content' => '/iddad',
        ]));
        self::assertResponseIsSuccessful();
        $iddadResp = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($iddadResp['ok']);
        self::assertTrue($iddadResp['adminUnlocked']);
        self::assertIsArray($iddadResp['atmosphere']);

        // Play view reflects unlocked admin panel attribute in HTML
        $this->client->request('GET', '/play/'.$char1->getId());
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-admin-unlocked="1"', $html);
        self::assertStringContainsString('id="admin-atmosphere-panel"', $html);

        // 3. User 1 updates global weather and freezes global time at 23:00
        $this->client->request('POST', '/api/admin/atmosphere', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'weather' => 'SANDSTORM',
            'timeOfDay' => 23.0,
            'freezeTime' => true,
        ]));
        self::assertResponseIsSuccessful();
        $upd = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($upd['ok']);
        self::assertSame('SANDSTORM', $upd['atmosphere']['weather']);
        self::assertEqualsWithDelta(23.0, $upd['atmosphere']['timeOfDay'], 0.01);
        self::assertTrue($upd['atmosphere']['isNight']);
        self::assertTrue($upd['atmosphere']['isTimeFrozen']);
        self::assertTrue($upd['atmosphere']['isWeatherOverridden']);

        // Validation check (422) on invalid weather / hour
        $this->client->request('POST', '/api/admin/atmosphere', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'weather' => 'BLIZZARD',
        ]));
        self::assertResponseStatusCodeSame(422);

        $this->client->request('POST', '/api/admin/atmosphere', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'timeOfDay' => 25.0,
        ]));
        self::assertResponseStatusCodeSame(422);

        // 4. User 2 in Fournaise immediately receives SANDSTORM & frozen 23:00, and still cannot call admin endpoint
        $this->client->loginUser($user2);
        $this->client->request('POST', '/api/world/sync', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'characterId' => $char2->getId(),
            'x' => $fournaise['respawnX'],
            'z' => $fournaise['respawnZ'],
        ]));
        self::assertResponseIsSuccessful();
        $peerSync = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('SANDSTORM', $peerSync['atmosphere']['weather']);
        self::assertEqualsWithDelta(23.0, $peerSync['atmosphere']['timeOfDay'], 0.01);
        self::assertTrue($peerSync['atmosphere']['isNight']);
        self::assertTrue($peerSync['atmosphere']['isTimeFrozen']);
        self::assertTrue($peerSync['atmosphere']['isWeatherOverridden']);

        $this->client->request('POST', '/api/admin/atmosphere', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'weather' => 'CLEAR',
        ]));
        self::assertResponseStatusCodeSame(403);

        // 5. User 1 unfreezes and resets all overrides to auto -> User 2 returns to automatic shared atmosphere
        $this->client->loginUser($user1);
        $this->client->request('POST', '/api/admin/atmosphere', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'freezeTime' => false,
        ]));
        self::assertResponseIsSuccessful();
        $unfreeze = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($unfreeze['atmosphere']['isTimeFrozen']);
        self::assertTrue($unfreeze['atmosphere']['isTimeOverridden']);

        $this->client->request('POST', '/api/admin/atmosphere', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'action' => 'reset',
        ]));
        self::assertResponseIsSuccessful();
        $reset = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($reset['atmosphere']['isWeatherOverridden']);
        self::assertFalse($reset['atmosphere']['isTimeOverridden']);
        self::assertFalse($reset['atmosphere']['isTimeFrozen']);

        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user2);
        $this->client->request('POST', '/api/world/sync', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'characterId' => $char2->getId(),
            'x' => $fournaise['respawnX'],
            'z' => $fournaise['respawnZ'],
        ]));
        self::assertResponseIsSuccessful();
        $peerResetSync = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($peerResetSync['atmosphere']['isWeatherOverridden']);
        self::assertFalse($peerResetSync['atmosphere']['isTimeOverridden']);
        self::assertFalse($peerResetSync['atmosphere']['isTimeFrozen']);
    }
}
