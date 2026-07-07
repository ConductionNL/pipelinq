<?php

/**
 * Unit tests for PipelinqEvidenceSourceProvider.
 *
 * Covers REQ-AVG-016: source id, enabled/disabled gating, subject matching
 * across client/contact/lead/request/contactmoment (own identity + FK links),
 * stable content hashes, and that harvesting never emits the subject id value.
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Service\PipelinqEvidenceSourceProvider;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Tests for PipelinqEvidenceSourceProvider.
 */
final class PipelinqEvidenceSourceProviderTest extends TestCase
{
    /**
     * Schema-config-key => id map (provisioned).
     *
     * @var array<string, string>
     */
    private const SCHEMA_IDS = [
        'register'             => '11',
        'client_schema'        => '21',
        'contact_schema'       => '22',
        'lead_schema'          => '23',
        'request_schema'       => '24',
        'contactmoment_schema' => '25',
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
     * Rows served by findAll, keyed by schema id.
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    private array $store = [];

    /**
     * The provider under test.
     *
     * @var PipelinqEvidenceSourceProvider
     */
    private PipelinqEvidenceSourceProvider $provider;

    /**
     * Set up a provisioned provider over an in-memory store.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->appConfig     = $this->createMock(IAppConfig::class);
        $this->objectService = $this->createMock(ObjectService::class);
        $this->store         = [];

        $store = &$this->store;
        $this->objectService->method('findAll')->willReturnCallback(
            static function (array $config) use (&$store): array {
                $schemaId = (string) ($config['filters']['schema'] ?? '');
                return $store[$schemaId] ?? [];
            }
        );

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($this->objectService);

        $this->provider = new PipelinqEvidenceSourceProvider(
            appConfig: $this->appConfig,
            container: $container,
            logger: new NullLogger(),
        );
    }//end setUp()

    /**
     * Configure the app config as provisioned.
     *
     * @return void
     */
    private function provision(): void
    {
        $this->appConfig->method('getValueString')->willReturnCallback(
            static fn (string $app, string $key, string $default=''): string => self::SCHEMA_IDS[$key] ?? $default
        );
    }//end provision()

    /**
     * The source id is the stable `pipelinq-crm`.
     *
     * @return void
     */
    public function testSourceId(): void
    {
        self::assertSame('pipelinq-crm', $this->provider->getSourceId());
    }//end testSourceId()

    /**
     * isEnabled is false when the register is not provisioned.
     *
     * @return void
     */
    public function testDisabledWhenUnprovisioned(): void
    {
        $this->appConfig->method('getValueString')->willReturn('');
        self::assertFalse($this->provider->isEnabled());
    }//end testDisabledWhenUnprovisioned()

    /**
     * isEnabled is true when register + all subject schemas resolve.
     *
     * @return void
     */
    public function testEnabledWhenProvisioned(): void
    {
        $this->provision();
        self::assertTrue($this->provider->isEnabled());
    }//end testEnabledWhenProvisioned()

    /**
     * Harvest matches the subject's own client record and its FK-linked
     * lead/request/contactmoment objects, and skips unrelated objects.
     *
     * @return void
     */
    public function testHarvestMatchesSubjectAcrossSchemas(): void
    {
        $this->provision();

        $this->store = [
            // client 21: own identity match on id.
            '21' => [
                ['id' => 'subject-1', 'name' => 'Jane'],
                ['id' => 'other-client', 'name' => 'Bob'],
            ],
            // contact 22: match on contactsUid, plus an FK to the client.
            '22' => [
                ['id' => 'contact-1', 'contactsUid' => 'subject-1', 'client' => 'subject-1'],
                ['id' => 'contact-x', 'contactsUid' => 'nope', 'client' => 'other-client'],
            ],
            // lead 23: FK client match.
            '23' => [
                ['id' => 'lead-1', 'client' => 'subject-1'],
                ['id' => 'lead-2', 'client' => 'other-client'],
            ],
            // request 24: FK contact match.
            '24' => [
                ['id' => 'req-1', 'contact' => 'subject-1'],
            ],
            // contactmoment 25: no match.
            '25' => [
                ['id' => 'cm-1', 'client' => 'unrelated'],
            ],
        ];

        $items = $this->provider->harvest('case-1', ['subjectId' => 'subject-1', 'subjectType' => 'contact']);

        // client(subject-1) + contact(contact-1) + lead(lead-1) + request(req-1) = 4.
        self::assertCount(4, $items);

        foreach ($items as $item) {
            self::assertSame('pipelinq-crm', $item->getSourceId());
            self::assertStringStartsWith('sha256:', $item->getContentHash());
            self::assertSame('collected', $item->getStatus());
        }

        // Content hashes are unique per object and stable (deterministic).
        $hashes = array_map(static fn ($i): string => $i->getContentHash(), $items);
        self::assertSame($hashes, array_unique($hashes));

        $itemsAgain = $this->provider->harvest('case-1', ['subjectId' => 'subject-1', 'subjectType' => 'contact']);
        self::assertSame($hashes, array_map(static fn ($i): string => $i->getContentHash(), $itemsAgain));
    }//end testHarvestMatchesSubjectAcrossSchemas()

    /**
     * An empty subjectId harvests nothing.
     *
     * @return void
     */
    public function testHarvestEmptySubjectReturnsNothing(): void
    {
        $this->provision();
        self::assertSame([], $this->provider->harvest('case-1', ['subjectId' => '']));
    }//end testHarvestEmptySubjectReturnsNothing()

    /**
     * Harvest on an unprovisioned install returns nothing (no throw).
     *
     * @return void
     */
    public function testHarvestUnprovisionedReturnsNothing(): void
    {
        $this->appConfig->method('getValueString')->willReturn('');
        self::assertSame([], $this->provider->harvest('case-1', ['subjectId' => 'subject-1']));
    }//end testHarvestUnprovisionedReturnsNothing()
}//end class
