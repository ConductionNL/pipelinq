<?php

/**
 * Unit tests for DemoSeedService.
 *
 * Covers REQ-SETUP-PIP-008: seeding on a clean install (linked demo set),
 * idempotent re-run (no duplicates), removal scoping (deletes exactly the
 * [Demo]-marked set, never real data), archival retention, and the
 * unprovisioned-install guard.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/align-claims-and-first-hour/specs/first-time-setup/spec.md#requirement-req-setup-pip-008--optional-demo-data-seed
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Service\ContactVcardService;
use OCA\Pipelinq\Service\DemoSeedService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for DemoSeedService.
 *
 * @spec openspec/changes/align-claims-and-first-hour/specs/first-time-setup/spec.md#requirement-req-setup-pip-008--optional-demo-data-seed
 */
class DemoSeedServiceTest extends TestCase
{
    /**
     * Schema-id map used by the provisioned config (schema config key => id).
     *
     * @var array<string, string>
     */
    private const SCHEMA_IDS = [
        'client_schema'        => '21',
        'lead_schema'          => '22',
        'request_schema'       => '23',
        'contactmoment_schema' => '24',
    ];

    /**
     * Section => [schema id, lookup field] (mirrors the service SECTIONS).
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const SECTION_SCHEMAS = [
        'clients'         => ['21', 'name'],
        'leads'           => ['22', 'title'],
        'requests'        => ['23', 'title'],
        'contactmomenten' => ['24', 'subject'],
    ];

    /**
     * Mocked app config.
     *
     * @var IAppConfig&MockObject
     */
    private IAppConfig $appConfig;

    /**
     * Mocked container.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface $container;

    /**
     * Mocked OpenRegister ObjectService.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService $objectService;

    /**
     * Mocked contact-first identity provisioner.
     *
     * @var ContactVcardService&MockObject
     */
    private ContactVcardService $contactVcardService;

    /**
     * Service under test.
     *
     * @var DemoSeedService
     */
    private DemoSeedService $service;

    /**
     * Wire the service with a provisioned config + container by default.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->appConfig           = $this->createMock(IAppConfig::class);
        $this->container           = $this->createMock(ContainerInterface::class);
        $this->objectService       = $this->createMock(ObjectService::class);
        $this->contactVcardService = $this->createMock(ContactVcardService::class);

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($this->objectService);

        // Contact-first provisioning succeeds by default with a stable NC uid.
        $this->contactVcardService->method('provisionContactFromForm')
            ->willReturnCallback(
                static fn (array $form, string $objectType): array => [
                    'contactsUid' => 'nc-contact-'.md5((string) $form['name']),
                    'name'        => (string) $form['name'],
                    'email'       => (string) ($form['email'] ?? ''),
                    'phone'       => (string) ($form['phone'] ?? ''),
                ]
            );

        $this->service = new DemoSeedService(
            appConfig: $this->appConfig,
            container: $this->container,
            contactVcardService: $this->contactVcardService,
            logger: $this->createMock(LoggerInterface::class),
        );
    }//end setUp()

    /**
     * Configure register + schema ids as provisioned.
     *
     * @return void
     */
    private function provisionConfig(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturnCallback(
                static function (string $app, string $key, string $default=''): string {
                    $values = array_merge(['register' => '11'], self::SCHEMA_IDS);
                    return $values[$key] ?? $default;
                }
            );
    }//end provisionConfig()

    /**
     * Load the shipped seed definitions (the service reads the same file).
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private static function definitions(): array
    {
        $path = dirname(__DIR__, 3).'/lib/Settings/demo_seed_data.json';
        return json_decode((string) file_get_contents($path), true);
    }//end definitions()

    /**
     * Build the rendered-row store an already-seeded install would return,
     * keyed by schema id, including a non-demo decoy row per schema.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private static function seededStore(): array
    {
        $store = [];
        foreach (self::SECTION_SCHEMAS as $section => [$schemaId, $lookupField]) {
            $rows = [];
            foreach (self::definitions()[$section] as $i => $definition) {
                $rows[] = [
                    'id'         => $section.'-uuid-'.$i,
                    $lookupField => (string) $definition['data'][$lookupField],
                ];
            }

            // Decoy: a real (non-demo) object that must never be touched.
            $rows[] = [
                'id'         => $section.'-real-object',
                $lookupField => 'Real '.$section.' record',
            ];

            $store[$schemaId] = $rows;
        }

        return $store;
    }//end seededStore()

    /**
     * Wire findAll to serve rows from a mutable store keyed by schema id.
     *
     * @param array<string, array<int, array<string, mixed>>> $store The row store (by reference).
     *
     * @return void
     */
    private function mockFindAllFromStore(array &$store): void
    {
        $this->objectService->method('findAll')
            ->willReturnCallback(
                static function (array $config) use (&$store): array {
                    $schemaId = (string) ($config['filters']['schema'] ?? '');
                    return $store[$schemaId] ?? [];
                }
            );
    }//end mockFindAllFromStore()

    /**
     * Build a saveObject return value carrying a uuid.
     *
     * @param string $uuid The uuid.
     *
     * @return object Object exposing getUuid().
     */
    private static function savedEntity(string $uuid): object
    {
        return new class ($uuid) {
            /**
             * @param string $uuid The uuid to expose.
             */
            public function __construct(private string $uuid)
            {
            }

            /**
             * @return string The uuid.
             */
            public function getUuid(): string
            {
                return $this->uuid;
            }
        };
    }//end savedEntity()

    /**
     * Seeding a clean install creates the full linked demo set.
     *
     * @return void
     *
     * @spec openspec/changes/align-claims-and-first-hour/specs/first-time-setup/spec.md#requirement-req-setup-pip-008--optional-demo-data-seed
     */
    public function testSeedOnCleanInstallCreatesLinkedDemoSet(): void
    {
        $this->provisionConfig();

        // Nothing exists yet.
        $this->objectService->method('findAll')->willReturn([]);

        $savedPayloads = [];
        $sequence      = 0;
        $this->objectService->method('saveObject')
            ->willReturnCallback(
                function (array $data, array $extend, string $register, string $schema) use (&$savedPayloads, &$sequence): object {
                    $sequence++;
                    $uuid            = 'uuid-'.$schema.'-'.$sequence;
                    $savedPayloads[] = ['schema' => $schema, 'data' => $data, 'uuid' => $uuid];
                    return self::savedEntity($uuid);
                }
            );

        $result = $this->service->seed();

        self::assertTrue($result['success']);
        self::assertSame(5, $result['created']['clients']);
        self::assertSame(6, $result['created']['leads']);
        self::assertSame(8, $result['created']['requests']);
        self::assertSame(12, $result['created']['contactmomenten']);
        self::assertSame(0, array_sum($result['skipped']));

        // Every seeded object carries the demo marker on its lookup field.
        foreach ($savedPayloads as $payload) {
            $lookup = $payload['data']['name'] ?? ($payload['data']['title'] ?? ($payload['data']['subject'] ?? ''));
            self::assertStringStartsWith(DemoSeedService::DEMO_PREFIX, $lookup);
        }

        // Every client carries the contact-first provisioned NC contact uid.
        foreach ($savedPayloads as $payload) {
            if ($payload['schema'] === '21') {
                self::assertStringStartsWith('nc-contact-', (string) $payload['data']['contactsUid']);
            }
        }

        // Leads and contactmomenten are linked to seeded client uuids.
        $clientUuids = array_column(
            array_filter($savedPayloads, static fn (array $p): bool => $p['schema'] === '21'),
            'uuid'
        );
        $leads       = array_filter($savedPayloads, static fn (array $p): bool => $p['schema'] === '22');
        foreach ($leads as $lead) {
            self::assertContains($lead['data']['client'], $clientUuids);
        }

        // Date placeholders are resolved to concrete dates.
        $firstLead = array_values($leads)[0]['data'];
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $firstLead['expectedCloseDate']);
    }//end testSeedOnCleanInstallCreatesLinkedDemoSet()

    /**
     * Re-running the seed creates no duplicates (idempotency).
     *
     * @return void
     *
     * @spec openspec/changes/align-claims-and-first-hour/specs/first-time-setup/spec.md#requirement-req-setup-pip-008--optional-demo-data-seed
     */
    public function testSeedIsIdempotentOnRerun(): void
    {
        $this->provisionConfig();

        $store = self::seededStore();
        $this->mockFindAllFromStore($store);

        $this->objectService->expects(self::never())->method('saveObject');

        $result = $this->service->seed();

        self::assertTrue($result['success']);
        self::assertSame(0, array_sum($result['created']));
        self::assertSame(31, array_sum($result['skipped']));
    }//end testSeedIsIdempotentOnRerun()

    /**
     * Removal deletes exactly the seeded set — never the non-demo decoys.
     *
     * @return void
     *
     * @spec openspec/changes/align-claims-and-first-hour/specs/first-time-setup/spec.md#requirement-req-setup-pip-008--optional-demo-data-seed
     */
    public function testRemoveDeletesExactlyTheSeededSet(): void
    {
        $this->provisionConfig();

        $store = self::seededStore();
        $this->mockFindAllFromStore($store);

        $deleted = [];
        $this->objectService->method('deleteObject')
            ->willReturnCallback(
                static function (string $uuid) use (&$deleted, &$store): bool {
                    $deleted[] = $uuid;
                    // Drop the row from the store so the removal loop terminates.
                    foreach ($store as $schemaId => $rows) {
                        $store[$schemaId] = array_values(
                            array_filter($rows, static fn (array $r): bool => $r['id'] !== $uuid)
                        );
                    }

                    return true;
                }
            );

        $result = $this->service->remove();

        self::assertTrue($result['success']);
        self::assertSame(31, array_sum($result['removed']));
        self::assertCount(31, $deleted);

        // The non-demo decoy rows are never deleted.
        foreach ($deleted as $uuid) {
            self::assertStringNotContainsString('real-object', $uuid);
        }

        foreach (self::SECTION_SCHEMAS as [$schemaId]) {
            self::assertCount(1, $store[$schemaId]);
        }
    }//end testRemoveDeletesExactlyTheSeededSet()

    /**
     * Archival (append-only) schemas retain their rows instead of failing.
     *
     * @return void
     *
     * @spec openspec/changes/align-claims-and-first-hour/specs/first-time-setup/spec.md#requirement-req-setup-pip-008--optional-demo-data-seed
     */
    public function testRemoveRetainsArchivalSchemaRows(): void
    {
        $this->provisionConfig();

        $store = self::seededStore();
        $this->mockFindAllFromStore($store);

        $this->objectService->method('deleteObject')
            ->willReturnCallback(
                static function (string $uuid, string|int|null $register=null, string|int|null $schema=null) use (&$store): bool {
                    if ((string) $schema === '24') {
                        throw new \Exception('SCHEMA_ARCHIVAL_IMMUTABLE: contactmoment declares x-openregister-archival');
                    }

                    foreach ($store as $schemaId => $rows) {
                        $store[$schemaId] = array_values(
                            array_filter($rows, static fn (array $r): bool => $r['id'] !== $uuid)
                        );
                    }

                    return true;
                }
            );

        $result = $this->service->remove();

        self::assertTrue($result['success']);
        self::assertSame(12, $result['retained']['contactmomenten']);
        self::assertSame(0, $result['removed']['contactmomenten']);
        self::assertSame(19, array_sum($result['removed']));
    }//end testRemoveRetainsArchivalSchemaRows()

    /**
     * Removal skips objects that no longer exist without failing.
     *
     * @return void
     */
    public function testRemoveSkipsMissingObjects(): void
    {
        $this->provisionConfig();

        $this->objectService->method('findAll')->willReturn([]);
        $this->objectService->expects(self::never())->method('deleteObject');

        $result = $this->service->remove();

        self::assertTrue($result['success']);
        self::assertSame(0, array_sum($result['removed']));
    }//end testRemoveSkipsMissingObjects()

    /**
     * Seeding an unprovisioned install fails cleanly with a message.
     *
     * @return void
     */
    public function testSeedFailsWhenRegisterNotProvisioned(): void
    {
        $this->appConfig->method('getValueString')->willReturn('');

        $result = $this->service->seed();

        self::assertFalse($result['success']);
        self::assertNotSame('', (string) $result['message']);
    }//end testSeedFailsWhenRegisterNotProvisioned()

    /**
     * Seeding fails cleanly when Nextcloud Contacts cannot provision identities.
     *
     * @return void
     */
    public function testSeedFailsWhenContactProvisioningUnavailable(): void
    {
        $this->provisionConfig();
        $this->objectService->method('findAll')->willReturn([]);

        // Fresh mocks: provisioning always fails.
        $failingVcard = $this->createMock(ContactVcardService::class);
        $failingVcard->method('provisionContactFromForm')->willReturn(null);

        $service = new DemoSeedService(
            appConfig: $this->appConfig,
            container: $this->container,
            contactVcardService: $failingVcard,
            logger: $this->createMock(LoggerInterface::class),
        );

        $result = $service->seed();

        self::assertFalse($result['success']);
        self::assertStringContainsString('Contacts', (string) $result['message']);
    }//end testSeedFailsWhenContactProvisioningUnavailable()
}//end class
