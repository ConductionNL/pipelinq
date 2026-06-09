<?php

/**
 * Unit tests for ContactBetrokkeneMapper.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Stuf
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-010
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Stuf;

use OCA\Pipelinq\Service\Stuf\ContactBetrokkeneMapper;
use OCA\Pipelinq\Service\Stuf\StufRegisterAccess;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ContactBetrokkeneMapper.
 */
class ContactBetrokkeneMapperTest extends TestCase
{
    private ContactBetrokkeneMapper $mapper;

    /**
     * @var StufRegisterAccess&MockObject
     */
    private StufRegisterAccess $register;

    /**
     * Existing mapping returned by findOne.
     *
     * @var array|null
     */
    private ?array $existingMapping = null;

    /**
     * Saved mappings (last writes win).
     *
     * @var array
     */
    private array $saved = [];

    /**
     * Set up.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $logger         = $this->createMock(LoggerInterface::class);
        $this->register = $this->createMock(StufRegisterAccess::class);
        $this->register->method('findOne')->willReturnCallback(
            fn () => $this->existingMapping
        );
        $captures = &$this->saved;
        $this->register->method('saveObject')->willReturnCallback(
            function (string $schema, array $data) use (&$captures): array {
                $data['id']         = $data['id'] ?? 'map-test';
                $captures[$schema][] = $data;
                return $data;
            }
        );
        $this->mapper = new ContactBetrokkeneMapper($this->register, $logger);
    }//end setUp()

    /**
     * @return void
     */
    public function testBsnExtractionFromFlatField(): void
    {
        $this->assertSame('123456789', $this->mapper->bsnFromContact(['bsn' => '123456789']));
        $this->assertSame('987654321', $this->mapper->bsnFromContact(['identifiers' => ['bsn' => '987654321']]));
        $this->assertNull($this->mapper->bsnFromContact(['email' => 'x@example.com']));
    }//end testBsnExtractionFromFlatField()

    /**
     * @return void
     */
    public function testFindOrCreateReusesExistingMapping(): void
    {
        $this->existingMapping = ['id' => 'm-1', 'externIdentificatie' => 'NPS-999'];
        $bsn = $this->mapper->findOrCreateBetrokkene(
            contact: ['id' => 'c-1', 'bsn' => '111111111'],
            endpoint: ['id' => 'ep-1'],
            lookupCallable: fn () => 'should-not-be-called'
        );
        $this->assertSame('NPS-999', $bsn);
    }//end testFindOrCreateReusesExistingMapping()

    /**
     * @return void
     */
    public function testFindOrCreateUsesLookupResult(): void
    {
        $bsn = $this->mapper->findOrCreateBetrokkene(
            contact: ['id' => 'c-2', 'bsn' => '222222222'],
            endpoint: ['id' => 'ep-2'],
            lookupCallable: fn (string $b, array $ep) => 'NPS-FROM-LOOKUP'
        );
        $this->assertSame('NPS-FROM-LOOKUP', $bsn);
        $this->assertNotEmpty($this->saved[StufRegisterAccess::SCHEMA_MAPPING] ?? []);
        $saved = end($this->saved[StufRegisterAccess::SCHEMA_MAPPING]);
        $this->assertSame('NPS-FROM-LOOKUP', $saved['externIdentificatie']);
    }//end testFindOrCreateUsesLookupResult()

    /**
     * @return void
     */
    public function testFindOrCreateFallbackOnLookupMissReusesBsn(): void
    {
        $bsn = $this->mapper->findOrCreateBetrokkene(
            contact: ['id' => 'c-3', 'bsn' => '333333333'],
            endpoint: ['id' => 'ep-3'],
            lookupCallable: fn () => null
        );
        $this->assertSame('333333333', $bsn);
        $saved = end($this->saved[StufRegisterAccess::SCHEMA_MAPPING]);
        $this->assertSame('333333333', $saved['externIdentificatie']);
    }//end testFindOrCreateFallbackOnLookupMissReusesBsn()

    /**
     * @return void
     */
    public function testFindOrCreateReturnsEmptyWhenNoBsn(): void
    {
        $bsn = $this->mapper->findOrCreateBetrokkene(
            contact: ['id' => 'c-4'],
            endpoint: ['id' => 'ep-4'],
            lookupCallable: fn () => 'never'
        );
        $this->assertSame('', $bsn);
    }//end testFindOrCreateReturnsEmptyWhenNoBsn()
}//end class
