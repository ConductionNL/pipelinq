<?php

/**
 * Unit tests for MasterEntityService.
 *
 * Drives the golden-record survivorship rules end-to-end against an in-memory
 * repository: gold always wins regardless of recency, silver wins when gold is
 * absent, a single uncontested source still populates the record, withdrawn
 * sources are ignored, and source-record link/unlink trigger recomputation.
 * These mirror spec scenarios REQ-MDM-001-01..03.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Mdm
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Mdm;

use OCA\Pipelinq\Service\Mdm\MasterEntityService;
use OCA\Pipelinq\Service\Mdm\TrustConfigurationService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__.'/InMemoryMdmObjectRepository.php';

/**
 * Tests for MasterEntityService golden-record survivorship.
 */
final class MasterEntityServiceTest extends TestCase
{
    /**
     * The in-memory repository.
     *
     * @var InMemoryMdmObjectRepository
     */
    private InMemoryMdmObjectRepository $repo;

    /**
     * The service under test.
     *
     * @var MasterEntityService
     */
    private MasterEntityService $service;

    /**
     * Set up the service stack.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->repo    = new InMemoryMdmObjectRepository();
        $trust         = new TrustConfigurationService($this->repo);
        $this->service = new MasterEntityService($this->repo, $trust, new NullLogger());
    }//end setUp()

    /**
     * Seed a trust-configuration row.
     *
     * @param string $entityType The entity type.
     * @param string $attribute  The attribute.
     * @param string $source     The source system.
     * @param string $tier       The tier.
     *
     * @return void
     */
    private function trust(string $entityType, string $attribute, string $source, string $tier): void
    {
        $this->repo->seed(
            'trustConfiguration',
            $entityType.'-'.$attribute.'-'.$source,
            [
                'entityType'    => $entityType,
                'attribute'     => $attribute,
                'sourceSystem'  => $source,
                'trustTier'     => $tier,
                'effectiveFrom' => '2026-01-01',
            ]
        );
    }//end trust()

    /**
     * Gold tier wins over silver / bronze regardless of recency (REQ-MDM-001-02).
     *
     * @return void
     */
    public function testGoldTierAlwaysWins(): void
    {
        $this->trust('account', 'phone', 'kvk-api', 'gold');
        $this->trust('account', 'phone', 'shillinq-debiteuren', 'silver');
        $this->trust('account', 'phone', 'pipelinq-crm', 'bronze');

        $sources = [
            ['sourceSystem' => 'pipelinq-crm', 'mappedAttributes' => ['phone' => '020-1234567'], 'lastChange' => '2026-06-01T00:00:00Z'],
            ['sourceSystem' => 'shillinq-debiteuren', 'mappedAttributes' => ['phone' => '030-7654321'], 'lastChange' => '2026-05-01T00:00:00Z'],
            ['sourceSystem' => 'kvk-api', 'mappedAttributes' => ['phone' => '088-0000000'], 'lastChange' => '2026-01-01T00:00:00Z'],
        ];

        $result = $this->service->resolveGoldenRecord('account', $sources);

        // KvK (gold) wins even though CRM (bronze) was most recent.
        $this->assertSame('088-0000000', $result['goldenRecord']['phone']);
        $this->assertSame('kvk-api', $result['attributeProvenance']['phone']['sourceSystem']);
        $this->assertSame('gold', $result['attributeProvenance']['phone']['trustTier']);
    }//end testGoldTierAlwaysWins()

    /**
     * Silver wins when no gold source supplies the attribute (REQ-MDM-001-03).
     *
     * @return void
     */
    public function testSilverWinsWhenGoldAbsent(): void
    {
        $this->trust('account', 'billingAddress', 'shillinq-debiteuren', 'silver');
        $this->trust('account', 'billingAddress', 'pipelinq-crm', 'bronze');

        $sources = [
            ['sourceSystem' => 'shillinq-debiteuren', 'mappedAttributes' => ['billingAddress' => 'Bedrijfsplein 10, 5678 XY Utrecht'], 'lastChange' => '2026-04-01T00:00:00Z'],
            ['sourceSystem' => 'pipelinq-crm', 'mappedAttributes' => ['billingAddress' => 'Bedrijfsplein 10, 5678 Utrecht'], 'lastChange' => '2026-05-01T00:00:00Z'],
        ];

        $result = $this->service->resolveGoldenRecord('account', $sources);

        $this->assertSame('Bedrijfsplein 10, 5678 XY Utrecht', $result['goldenRecord']['billingAddress']);
        $this->assertSame('silver', $result['attributeProvenance']['billingAddress']['trustTier']);
    }//end testSilverWinsWhenGoldAbsent()

    /**
     * Conflict resolution by tier picks the silver value over bronze (REQ-MDM-001-01).
     *
     * @return void
     */
    public function testConflictResolvesToHigherTier(): void
    {
        $this->trust('account', 'phone', 'pipelinq-crm', 'bronze');
        $this->trust('account', 'phone', 'shillinq-debiteuren', 'silver');

        $sources = [
            ['sourceSystem' => 'pipelinq-crm', 'mappedAttributes' => ['phone' => '020-1234567'], 'lastChange' => '2026-05-01T00:00:00Z'],
            ['sourceSystem' => 'shillinq-debiteuren', 'mappedAttributes' => ['phone' => '030-7654321'], 'lastChange' => '2026-02-01T00:00:00Z'],
        ];

        $result = $this->service->resolveGoldenRecord('account', $sources);

        $this->assertSame('030-7654321', $result['goldenRecord']['phone']);
    }//end testConflictResolvesToHigherTier()

    /**
     * An uncontested source with no trust config still populates the record (bronze default).
     *
     * @return void
     */
    public function testUnconfiguredSourceDefaultsToBronze(): void
    {
        $sources = [
            ['sourceSystem' => 'some-system', 'mappedAttributes' => ['name' => 'Solo Source'], 'lastChange' => '2026-05-01T00:00:00Z'],
        ];

        $result = $this->service->resolveGoldenRecord('account', $sources);

        $this->assertSame('Solo Source', $result['goldenRecord']['name']);
        $this->assertSame('bronze', $result['attributeProvenance']['name']['trustTier']);
    }//end testUnconfiguredSourceDefaultsToBronze()

    /**
     * Withdrawn source records do not compete.
     *
     * @return void
     */
    public function testWithdrawnSourceIgnored(): void
    {
        $this->trust('account', 'email', 'crm', 'gold');

        $sources = [
            ['sourceSystem' => 'crm', 'mappedAttributes' => ['email' => 'old@x.nl'], 'lastChange' => '2026-05-01T00:00:00Z', 'withdrawn' => true],
            ['sourceSystem' => 'other', 'mappedAttributes' => ['email' => 'new@x.nl'], 'lastChange' => '2026-05-01T00:00:00Z'],
        ];

        $result = $this->service->resolveGoldenRecord('account', $sources);

        $this->assertSame('new@x.nl', $result['goldenRecord']['email']);
    }//end testWithdrawnSourceIgnored()

    /**
     * Empty / null attribute values never win.
     *
     * @return void
     */
    public function testEmptyValuesDoNotCompete(): void
    {
        $this->trust('account', 'phone', 'gold-src', 'gold');
        $this->trust('account', 'phone', 'silver-src', 'silver');

        $sources = [
            ['sourceSystem' => 'gold-src', 'mappedAttributes' => ['phone' => ''], 'lastChange' => '2026-05-01T00:00:00Z'],
            ['sourceSystem' => 'silver-src', 'mappedAttributes' => ['phone' => '030-1234567'], 'lastChange' => '2026-05-01T00:00:00Z'],
        ];

        $result = $this->service->resolveGoldenRecord('account', $sources);

        $this->assertSame('030-1234567', $result['goldenRecord']['phone']);
    }//end testEmptyValuesDoNotCompete()

    /**
     * recomputeGoldenRecord persists the resolved record on the entity.
     *
     * @return void
     */
    public function testRecomputePersists(): void
    {
        $this->trust('contact', 'name', 'crm', 'gold');
        $this->repo->seed('masterEntity', 'm1', ['masterId' => 'm1', 'entityType' => 'contact', 'goldenRecord' => [], 'attributeProvenance' => []]);
        $this->repo->seed('sourceRecord', 's1', ['sourceRecordId' => 's1', 'currentMasterEntity' => 'm1', 'sourceSystem' => 'crm', 'mappedAttributes' => ['name' => 'Maria Jansen'], 'lastChange' => '2026-05-01T00:00:00Z']);

        $updated = $this->service->recomputeGoldenRecord('m1');

        $this->assertSame('Maria Jansen', $updated['goldenRecord']['name']);
        $this->assertSame('Maria Jansen', $this->repo->find('masterEntity', 'm1')['goldenRecord']['name']);
    }//end testRecomputePersists()

    /**
     * Unlinking a source record withdraws it and recomputes the former master.
     *
     * @return void
     */
    public function testUnlinkRecomputes(): void
    {
        $this->trust('contact', 'name', 'crm', 'gold');
        $this->repo->seed('masterEntity', 'm1', ['masterId' => 'm1', 'entityType' => 'contact', 'goldenRecord' => ['name' => 'Maria'], 'attributeProvenance' => []]);
        $this->repo->seed('sourceRecord', 's1', ['sourceRecordId' => 's1', 'currentMasterEntity' => 'm1', 'sourceSystem' => 'crm', 'mappedAttributes' => ['name' => 'Maria'], 'lastChange' => '2026-05-01T00:00:00Z']);

        $this->service->unlinkSourceRecord('s1');

        $this->assertTrue($this->repo->find('sourceRecord', 's1')['withdrawn']);
        // With no live source, the golden record is now empty.
        $this->assertSame([], $this->repo->find('masterEntity', 'm1')['goldenRecord']);
    }//end testUnlinkRecomputes()
}//end class
