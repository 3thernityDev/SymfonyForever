<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Character;
use App\Entity\User;
use App\Game\CharacterClass;
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
        self::assertCount(3, $manifest['classes']);
        self::assertCount(12, $manifest['skills']);

        // 2. Authenticate a user with a Tank character at Saint-Gilles
        $user = (new User())->setUsername('reunion_player')->setEmail('p@reunion.re')->setPassword('unused');
        $this->em->persist($user);

        $char = (new Character($user, 'PitonGuard', CharacterClass::TANK))
            ->setPosX(-550.0)->setPosY(12.0)->setPosZ(-70.0)->setZoneCode('lagon_ouest');
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
            'x' => -550.0,
            'z' => -70.0,
            'rotY' => 0.5,
            'isMoving' => true,
            'lastEventId' => 0,
            'lastChatId' => 0,
        ]));
        self::assertResponseIsSuccessful();
        $sync = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('lagon_ouest', $sync['zone']['code']);
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
    }
}
