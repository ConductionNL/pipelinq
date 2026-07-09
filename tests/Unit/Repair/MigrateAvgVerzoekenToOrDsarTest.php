<?php

/**
 * Unit tests for MigrateAvgVerzoekenToOrDsar.
 *
 * Covers REQ-AVG-017: the article/status mapping matrix, extension + denial +
 * evidence + redaction mapping, the structured NL notes block, idempotency via
 * the `migratedTo` marker, and the unprovisioned no-op.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Repair
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Repair;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Repair\MigrateAvgVerzoekenToOrDsar;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Tests for MigrateAvgVerzoekenToOrDsar.
 */
final class MigrateAvgVerzoekenToOrDsarTest extends TestCase
{
    /**
     * Schema-config-key => id.
     *
     * @var array<string, string>
     */
    private const IDS = [
        'register'              => '11',
        'avgVerzoek_schema'     => '40',
        'bewijsItem_schema'     => '41',
        'weigering_schema'      => '42',
        'redactieActie_schema'  => '43',
    ];

    /**
     * Mocked app config.
     *
     * @var IAppConfig&MockObject
     */
    private IAppConfig $appConfig;

    /**
     * Mocked ObjectService.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService $objectService;

    /**
     * findAll store keyed by schema id.
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    private array $store = [];

    /**
     * Objects saved into OR's DSAR register (schema slug key).
     *
     * @var array<int, array<string, mixed>>
     */
    private array $savedCases = [];

    /**
     * avgVerzoek objects re-saved with the migratedTo marker.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $markedSources = [];

    /**
     * The repair step under test.
     *
     * @var MigrateAvgVerzoekenToOrDsar
     */
    private MigrateAvgVerzoekenToOrDsar $step;

    /**
     * Wire the step over an in-memory OR ObjectService.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->appConfig     = $this->createMock(IAppConfig::class);
        $this->objectService = $this->createMock(ObjectService::class);
        $this->store         = [];
        $this->savedCases    = [];
        $this->markedSources = [];

        $this->appConfig->method('getValueString')->willReturnCallback(
            static fn (string $app, string $key, string $default=''): string => self::IDS[$key] ?? $default
        );

        $store = &$this->store;
        $this->objectService->method('findAll')->willReturnCallback(
            static function (array $config) use (&$store): array {
                $schemaId = (string) ($config['filters']['schema'] ?? '');
                return $store[$schemaId] ?? [];
            }
        );

        $savedCases    = &$this->savedCases;
        $markedSources = &$this->markedSources;
        $store2        = &$this->store;
        $seq           = 0;
        $this->objectService->method('saveObject')->willReturnCallback(
            static function (array $data, array $extend, $register, $schema, $uuid) use (&$savedCases, &$markedSources, &$store2, &$seq): object {
                if ((string) $schema === 'dataSubjectRequest') {
                    $seq++;
                    $data['id']   = 'dsar-'.$seq;
                    $savedCases[] = $data;
                    return self::entity('dsar-'.$seq);
                }

                // avgVerzoek re-save carrying the migratedTo marker.
                $markedSources[] = $data;
                // Reflect the marker back into the store so a re-run skips it.
                foreach (($store2['40'] ?? []) as $i => $row) {
                    if (($row['id'] ?? null) === ($data['id'] ?? null)) {
                        $store2['40'][$i] = $data;
                    }
                }
                return self::entity((string) ($data['id'] ?? 'x'));
            }
        );

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($this->objectService);

        $this->step = new MigrateAvgVerzoekenToOrDsar(
            appConfig: $this->appConfig,
            container: $container,
            logger: new NullLogger(),
        );
    }//end setUp()

    /**
     * Build an entity exposing getUuid().
     *
     * @param string $uuid The uuid.
     *
     * @return object The entity double.
     */
    private static function entity(string $uuid): object
    {
        return new class ($uuid) {
            /**
             * @param string $uuid The uuid.
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
    }//end entity()

    /**
     * A full avgVerzoek with satellites maps across every field group.
     *
     * @return void
     */
    public function testMapsFullVerzoek(): void
    {
        $this->store = [
            '40' => [
                [
                    'id'                => 'v1',
                    'kenmerk'           => 'AVG-2026-1',
                    'ingediendOp'       => '2026-01-01T00:00:00Z',
                    'ingediendVia'      => 'web',
                    'verzoekerContact'  => 'contact-9',
                    'artikel'           => 'art-15',
                    'specifiekeVraag'   => 'all data',
                    'scope'             => ['crm'],
                    'wettelijkeTermijnVerloopt' => '2026-02-01T00:00:00Z',
                    'verlengdMet'       => 30,
                    'verlengingsgrond'  => 'complex',
                    'status'            => 'in-behandeling',
                    'behandelaar'       => 'steward-a',
                    'dpiaFlag'          => true,
                    'retentieTot'       => '2027-01-01T00:00:00Z',
                ],
            ],
            '41' => [
                ['id' => 'b1', 'verzoekId' => 'v1', 'bronApp' => 'pipelinq-crm', 'contentHash' => 'sha256:abc'],
                ['id' => 'b2', 'verzoekId' => 'v1', 'bronApp' => 'pipelinq-crm', 'contentHash' => 'sha256:def'],
            ],
            '42' => [
                ['id' => 'w1', 'verzoekId' => 'v1', 'grond' => 'buitensporig', 'weigering' => 'no', 'toelichtingAvg23' => 'x'],
            ],
            '43' => [
                ['id' => 'r1', 'verzoekId' => 'v1', 'veldpad' => 'email', 'voorWaarde' => 'a@b', 'naWaarde' => '***', 'grond' => 'third-party-data'],
            ],
        ];

        $this->step->run($this->createStub(IOutput::class));

        self::assertCount(1, $this->savedCases);
        $case = $this->savedCases[0];

        self::assertSame('contact-9', $case['subjectId']);
        self::assertSame('contact', $case['subjectType']);
        self::assertSame('NL', $case['jurisdiction']);
        self::assertSame('access', $case['type']);
        self::assertSame('in-progress', $case['status']);
        self::assertSame('2026-01-01T00:00:00Z', $case['receivedAt']);
        self::assertSame('steward-a', $case['handler']);
        self::assertTrue($case['dpiaRequired']);
        self::assertSame('2026-02-01T00:00:00Z', $case['dueAt']);
        self::assertSame('2027-01-01T00:00:00Z', $case['retainUntil']);
        self::assertStringStartsWith('2026-03-03', $case['extendedUntil']);
        self::assertSame('complex', $case['extensionReason']);
        self::assertSame('excessive-request', $case['denialGround']);
        self::assertCount(2, $case['evidence']);
        self::assertSame('sha256:abc', $case['evidence'][0]['contentHash']);
        self::assertCount(1, $case['redactions']);
        self::assertSame('email', $case['redactions'][0]['field']);

        // NL extras preserved in the structured notes block.
        $notes = json_decode((string) $case['notes'], true);
        self::assertSame('pipelinq/avgVerzoek', $notes['migratedFrom']);
        self::assertSame('AVG-2026-1', $notes['kenmerk']);
        self::assertSame('web', $notes['ingediendVia']);

        // Source marked migrated.
        self::assertCount(1, $this->markedSources);
        self::assertSame('dsar-1', $this->markedSources[0]['migratedTo']);
    }//end testMapsFullVerzoek()

    /**
     * Article + status mapping matrix, including geen-avg and afgerond/outcome.
     *
     * @return void
     */
    public function testArticleAndStatusMatrix(): void
    {
        $this->store = [
            '40' => [
                ['id' => 'a', 'artikel' => 'art-17', 'status' => 'ingediend'],
                ['id' => 'b', 'artikel' => 'art-16', 'status' => 'afgerond', 'uitkomst' => 'geweigerd'],
                ['id' => 'c', 'artikel' => 'geen-avg', 'status' => 'ingediend'],
                ['id' => 'd', 'artikel' => 'art-20', 'status' => 'afgerond', 'uitkomst' => 'toegekend'],
            ],
        ];

        $this->step->run($this->createStub(IOutput::class));

        $byType = [];
        foreach ($this->savedCases as $c) {
            $byType[] = [$c['type'], $c['status']];
        }

        self::assertContains(['erasure', 'received'], $byType);
        self::assertContains(['rectification', 'refused'], $byType);
        self::assertContains(['access', 'closed'], $byType);       // geen-avg
        self::assertContains(['portability', 'fulfilled'], $byType);
    }//end testArticleAndStatusMatrix()

    /**
     * A re-run migrates nothing (idempotent via migratedTo marker).
     *
     * @return void
     */
    public function testIdempotentRerun(): void
    {
        $this->store = [
            '40' => [
                ['id' => 'v1', 'artikel' => 'art-15', 'status' => 'ingediend'],
            ],
        ];

        $this->step->run($this->createStub(IOutput::class));
        self::assertCount(1, $this->savedCases);

        // Re-run: the source now carries migratedTo (reflected into the store).
        $this->savedCases = [];
        $this->step->run($this->createStub(IOutput::class));
        self::assertCount(0, $this->savedCases);
    }//end testIdempotentRerun()

    /**
     * An unprovisioned install is a no-op.
     *
     * @return void
     */
    public function testUnprovisionedNoOp(): void
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturn('');
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($this->objectService);

        $step = new MigrateAvgVerzoekenToOrDsar($appConfig, $container, new NullLogger());
        $step->run($this->createStub(IOutput::class));

        self::assertCount(0, $this->savedCases);
    }//end testUnprovisionedNoOp()
}//end class
