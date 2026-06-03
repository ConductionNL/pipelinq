<?php

/**
 * Unit tests for the BRP lookup orchestration.
 *
 * Drives BrpLookupService end-to-end with an in-memory OpenRegister fake and a
 * fake BRP client (no live RvIG / Haal-Centraal credential), asserting: the
 * doelbinding gate, the role-based authorization gate (refusals are audited),
 * cache hit vs miss, opt-out mirroring + address shielding, contact-flag
 * updates, and the ADR-005 invariant that no raw BSN is ever persisted or
 * returned.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\Bsn\BrpClientInterface;
use OCA\Pipelinq\Service\Bsn\BrpPersoon;
use OCA\Pipelinq\Service\Bsn\HaalCentraalException;
use OCA\Pipelinq\Service\BrpCacheService;
use OCA\Pipelinq\Service\BrpLookupService;
use OCA\Pipelinq\Service\BsnAuditService;
use OCA\Pipelinq\Service\BsnValidationService;
use OCA\Pipelinq\Service\OptOutService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * In-memory OR ObjectService fake keyed by schema id + object id.
 */
class BrpFakeObjectService
{
    /** @var array<string, array<string, array<string, mixed>>> */
    public array $store = [];

    /** @var int */
    private int $seq = 0;

    /**
     * @param array<string, mixed> $config
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAll(array $config): array
    {
        $filters = $config['filters'] ?? [];
        $schema  = (string) ($filters['schema'] ?? '');
        $rows    = array_values($this->store[$schema] ?? []);

        return array_values(array_filter($rows, function (array $row) use ($filters): bool {
            foreach ($filters as $key => $value) {
                if (in_array($key, ['register', 'schema'], true) === true) {
                    continue;
                }

                if (($row[$key] ?? null) !== $value) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id, string $register, string $schema): ?array
    {
        return $this->store[$schema][$id] ?? null;
    }

    /**
     * @param array<string, mixed> $object
     *
     * @return array<string, mixed>
     */
    public function saveObject(array $object, array $extend, string $register, string $schema, ?string $uuid = null): array
    {
        $id           = $uuid ?? ('id-'.(++$this->seq));
        $object['id'] = $id;
        $this->store[$schema][$id] = $object;

        return $object;
    }

    public function deleteObject(string $register, string $schema, string $uuid): void
    {
        unset($this->store[$schema][$uuid]);
    }
}

/**
 * Configurable fake BRP client.
 */
class BrpFakeClient implements BrpClientInterface
{
    public function __construct(
        private ?BrpPersoon $person,
        private ?\Throwable $throw = null,
    ) {
    }

    public function lookupPersoon(string $bsn): ?BrpPersoon
    {
        if ($this->throw !== null) {
            throw $this->throw;
        }

        return $this->person;
    }

    public function isConfigured(): bool
    {
        return true;
    }
}

/**
 * Tests for BrpLookupService (REQ-BSN-002..006).
 */
class BrpLookupServiceTest extends TestCase
{
    /** @var BrpFakeObjectService */
    private BrpFakeObjectService $objects;

    /**
     * Build a lookup service wired to fakes.
     *
     * @param BrpClientInterface $client     The BRP client fake.
     * @param bool               $authorised Whether the actor is authorised.
     *
     * @return BrpLookupService The service under test.
     */
    private function makeService(BrpClientInterface $client, bool $authorised = true): BrpLookupService
    {
        $this->objects = new BrpFakeObjectService();

        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturnCallback(function (string $id) {
            if ($id === 'OCA\OpenRegister\Service\ObjectService') {
                return $this->objects;
            }

            return null;
        });

        $appConfig = $this->createStub(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            static function (string $app, string $key, string $default = '') {
                $map = [
                    'register'                 => 'reg-1',
                    'contact_schema'           => 'contact',
                    'brpPersoon_schema'        => 'brpPersoon',
                    'brpLookupVerzoek_schema'  => 'brpLookupVerzoek',
                    'bsnAuditRecord_schema'    => 'bsnAuditRecord',
                    'optOutVlag_schema'        => 'optOutVlag',
                ];

                return $map[$key] ?? $default;
            }
        );

        $groupManager = $this->createStub(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturn($authorised);
        $groupManager->method('isInGroup')->willReturn(false);

        $logger = $this->createStub(LoggerInterface::class);
        $random = $this->createStub(ISecureRandom::class);
        $random->method('generate')->willReturn('test-secret');

        $cache  = new BrpCacheService($container, $appConfig, $logger);
        $audit  = new BsnAuditService($container, $appConfig, $random, $logger);
        $optOut = new OptOutService($container, $appConfig, $logger);

        return new BrpLookupService(
            $container,
            $appConfig,
            $groupManager,
            new BsnValidationService(),
            $client,
            $cache,
            $audit,
            $optOut,
            $logger
        );
    }

    /**
     * A complete person fixture for a successful lookup.
     *
     * @param string $indicatieGeheim The secrecy indicator.
     *
     * @return BrpPersoon The fixture person.
     */
    private function person(string $indicatieGeheim = '0'): BrpPersoon
    {
        return new BrpPersoon(
            bsnGemaskeerd: '***45678*',
            voornamen: 'Maria Wilhelmina',
            geslachtsnaam: 'Berg',
            geboortedatum: '1978-03-22',
            geslacht: 'vrouw',
            indicatieGeheim: $indicatieGeheim,
            verblijfplaats: ['straat' => 'Lange Voorhout', 'huisnummer' => 14, 'postcode' => '2514 EA', 'woonplaats' => "'s-Gravenhage"],
        );
    }

    /**
     * Valid params for a lookup.
     *
     * @return array<string, mixed> The params.
     */
    private function params(): array
    {
        return [
            'bsn'          => '123456782',
            'verzoekreden' => 'Behandeling AVG-inzageverzoek artikel 15',
            'doelbinding'  => 'Publieke taak — Wet BRP art. 3.3',
            'contactId'    => 'contact-1',
            'verzoekId'    => 'verzoek-1',
        ];
    }

    /**
     * An unauthorised actor is refused (403) and the refusal is audited.
     *
     * @return void
     */
    public function testUnauthorisedActorIsRefusedAndAudited(): void
    {
        $service = $this->makeService(new BrpFakeClient($this->person()), authorised: false);

        try {
            $service->lookup($this->params(), 'mallory');
            $this->fail('Expected OCSForbiddenException');
        } catch (OCSForbiddenException $e) {
            $this->assertStringContainsString('niet bevoegd', $e->getMessage());
        }

        $audit = $this->objects->store['bsnAuditRecord'] ?? [];
        $this->assertCount(1, $audit);
        $record = array_values($audit)[0];
        $this->assertSame('geweigerd-onbevoegd', $record['uitkomst']);
        $this->assertSame('***45678*', $record['bsnGemaskeerd']);
    }

    /**
     * Missing doelbinding is rejected with 400 before any lookup.
     *
     * @return void
     */
    public function testMissingDoelbindingIsRejected(): void
    {
        $service = $this->makeService(new BrpFakeClient($this->person()));
        $params  = $this->params();
        $params['doelbinding'] = '';

        $this->expectException(OCSBadRequestException::class);
        $service->lookup($params, 'alice');
    }

    /**
     * A successful lookup caches the person, updates the contact, audits, and
     * never persists or returns a raw BSN.
     *
     * @return void
     */
    public function testSuccessfulLookupPersistsAuditAndUpdatesContact(): void
    {
        // Seed a contact so the flag update has a target.
        $this->objects = new BrpFakeObjectService();
        $service = $this->makeService(new BrpFakeClient($this->person()));
        $this->objects->store['contact']['contact-1'] = ['id' => 'contact-1', 'name' => 'Maria'];

        $result = $service->lookup($this->params(), 'alice');

        $this->assertFalse($result['responseInCache']);
        $this->assertFalse($result['geheimhouding']);
        $this->assertSame('Maria Wilhelmina', $result['persoon']['voornamen']);

        // Audit written with masked BSN, success outcome.
        $audit = array_values($this->objects->store['bsnAuditRecord'] ?? []);
        $this->assertCount(1, $audit);
        $this->assertSame('geslaagd', $audit[0]['uitkomst']);

        // Contact flags updated.
        $contact = $this->objects->store['contact']['contact-1'];
        $this->assertTrue($contact['verifiedBSN']);
        $this->assertNotSame('', (string) $contact['brpPersoonId']);

        // No raw BSN anywhere in the persisted store or the response.
        $blob = json_encode([$this->objects->store, $result]);
        $this->assertStringNotContainsString('123456782', (string) $blob);
    }

    /**
     * A second lookup within TTL is served from cache and still audited.
     *
     * @return void
     */
    public function testSecondLookupIsServedFromCache(): void
    {
        $service = $this->makeService(new BrpFakeClient($this->person()));
        $this->objects->store['contact']['contact-1'] = ['id' => 'contact-1'];

        $service->lookup($this->params(), 'alice');
        $second = $service->lookup($this->params(), 'alice');

        $this->assertTrue($second['responseInCache']);
        // Two audit records (both bevragingen herleidbaar), one cached person.
        $this->assertCount(2, $this->objects->store['bsnAuditRecord'] ?? []);
        $this->assertCount(1, $this->objects->store['brpPersoon'] ?? []);
    }

    /**
     * A secrecy indication mirrors an OptOutVlag and shields the address.
     *
     * @return void
     */
    public function testGeheimhoudingShieldsAddressAndRecordsOptOut(): void
    {
        $service = $this->makeService(new BrpFakeClient($this->person('1')));
        $this->objects->store['contact']['contact-1'] = ['id' => 'contact-1'];

        $result = $service->lookup($this->params(), 'alice');

        $this->assertTrue($result['geheimhouding']);
        $this->assertNull($result['persoon']['verblijfplaats']);
        $this->assertTrue($result['persoon']['adresAfgeschermd']);
        $this->assertCount(1, $this->objects->store['optOutVlag'] ?? []);

        $contact = $this->objects->store['contact']['contact-1'];
        $this->assertTrue($contact['geheimhouding']);
    }

    /**
     * A not-found lookup audits niet-gevonden and surfaces a clean 400.
     *
     * @return void
     */
    public function testNotFoundIsAudited(): void
    {
        $service = $this->makeService(new BrpFakeClient(null));

        try {
            $service->lookup($this->params(), 'alice');
            $this->fail('Expected OCSBadRequestException');
        } catch (OCSBadRequestException $e) {
            $this->assertStringContainsString('niet aangetroffen', $e->getMessage());
        }

        $audit = array_values($this->objects->store['bsnAuditRecord'] ?? []);
        $this->assertSame('niet-gevonden', $audit[0]['uitkomst']);
        $this->assertSame(404, $audit[0]['responseCode']);
    }

    /**
     * An upstream error audits uitkomst=fout and returns a generic message.
     *
     * @return void
     */
    public function testUpstreamErrorIsAuditedAndGeneric(): void
    {
        $service = $this->makeService(new BrpFakeClient(null, new HaalCentraalException('boom', 503)));

        try {
            $service->lookup($this->params(), 'alice');
            $this->fail('Expected OCSBadRequestException');
        } catch (OCSBadRequestException $e) {
            $this->assertStringContainsString('niet bereikbaar', $e->getMessage());
            $this->assertStringNotContainsString('boom', $e->getMessage());
        }

        $audit = array_values($this->objects->store['bsnAuditRecord'] ?? []);
        $this->assertSame('fout', $audit[0]['uitkomst']);
        $this->assertSame(503, $audit[0]['responseCode']);
    }
}//end class
