<?php

/**
 * Unit tests for TrustConfigurationService.
 *
 * Covers tier ranking and degradation, effectiveFrom-aware config selection and
 * the freshness-decay rule that lowers a stale source's tier — the survivorship
 * inputs the golden-record recomputation depends on.
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

use OCA\Pipelinq\Service\Mdm\TrustConfigurationService;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/InMemoryMdmObjectRepository.php';

/**
 * Tests for TrustConfigurationService.
 */
final class TrustConfigurationServiceTest extends TestCase
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
     * @var TrustConfigurationService
     */
    private TrustConfigurationService $service;

    /**
     * Set up the service with an in-memory repository.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->repo    = new InMemoryMdmObjectRepository();
        $this->service = new TrustConfigurationService($this->repo);
    }//end setUp()

    /**
     * Tier ranking orders gold > silver > bronze > discard; unknown ranks -1.
     *
     * @return void
     */
    public function testTierRank(): void
    {
        $this->assertGreaterThan($this->service->tierRank('silver'), $this->service->tierRank('gold'));
        $this->assertGreaterThan($this->service->tierRank('bronze'), $this->service->tierRank('silver'));
        $this->assertGreaterThan($this->service->tierRank('discard'), $this->service->tierRank('bronze'));
        $this->assertSame(-1, $this->service->tierRank('nonsense'));
        $this->assertSame(-1, $this->service->tierRank(null));
    }//end testTierRank()

    /**
     * Degrading a tier lowers it one level; discard is terminal.
     *
     * @return void
     */
    public function testDegradeTier(): void
    {
        $this->assertSame('silver', $this->service->degradeTier('gold'));
        $this->assertSame('bronze', $this->service->degradeTier('silver'));
        $this->assertSame('discard', $this->service->degradeTier('bronze'));
        $this->assertSame('discard', $this->service->degradeTier('discard'));
    }//end testDegradeTier()

    /**
     * Freshness decay lowers the tier once the window is exceeded.
     *
     * @return void
     */
    public function testFreshnessDecayAfterWindow(): void
    {
        // 200 days stale with a 180-day window → degrade gold to silver.
        $this->repo->clock = '2026-06-03T00:00:00Z';
        $tier = $this->service->applyFreshnessDecay('gold', 180, '2025-11-15T00:00:00Z');
        $this->assertSame('silver', $tier);
    }//end testFreshnessDecayAfterWindow()

    /**
     * Freshness decay does not fire within the window or without a window.
     *
     * @return void
     */
    public function testNoFreshnessDecayWithinWindow(): void
    {
        $this->repo->clock = '2026-06-03T00:00:00Z';
        $this->assertSame('gold', $this->service->applyFreshnessDecay('gold', 180, '2026-05-01T00:00:00Z'));
        $this->assertSame('gold', $this->service->applyFreshnessDecay('gold', null, '2020-01-01T00:00:00Z'));
        $this->assertSame('gold', $this->service->applyFreshnessDecay('gold', 180, null));
    }//end testNoFreshnessDecayWithinWindow()

    /**
     * getTrustTier resolves the configured tier and applies decay.
     *
     * @return void
     */
    public function testGetTrustTierWithDecay(): void
    {
        $this->repo->clock = '2026-06-03T00:00:00Z';
        $this->repo->seed(
            'trustConfiguration',
            'tc1',
            [
                'entityType'         => 'account',
                'attribute'          => 'phone',
                'sourceSystem'       => 'pipelinq-crm',
                'trustTier'          => 'gold',
                'freshnessDecayDays' => 180,
                'effectiveFrom'      => '2026-01-01',
            ]
        );

        // Fresh → gold; stale → silver.
        $this->assertSame('gold', $this->service->getTrustTier('account', 'phone', 'pipelinq-crm', '2026-05-20T00:00:00Z'));
        $this->assertSame('silver', $this->service->getTrustTier('account', 'phone', 'pipelinq-crm', '2025-01-01T00:00:00Z'));
    }//end testGetTrustTierWithDecay()

    /**
     * A config with a future effectiveFrom is not yet applicable.
     *
     * @return void
     */
    public function testEffectiveFromGate(): void
    {
        $this->repo->seed(
            'trustConfiguration',
            'tc2',
            [
                'entityType'    => 'account',
                'attribute'     => 'vatNumber',
                'sourceSystem'  => 'kvk-api',
                'trustTier'     => 'gold',
                'effectiveFrom' => '2027-01-01',
            ]
        );

        $this->assertNull(
            $this->service->getTrustConfig('account', 'vatNumber', 'kvk-api', '2026-06-03T00:00:00Z')
        );
    }//end testEffectiveFromGate()

    /**
     * The most recent applicable rule wins when several effectiveFrom rows exist.
     *
     * @return void
     */
    public function testMostRecentEffectiveRuleWins(): void
    {
        $this->repo->seed('trustConfiguration', 'a', ['entityType' => 'account', 'attribute' => 'email', 'sourceSystem' => 'crm', 'trustTier' => 'bronze', 'effectiveFrom' => '2025-01-01']);
        $this->repo->seed('trustConfiguration', 'b', ['entityType' => 'account', 'attribute' => 'email', 'sourceSystem' => 'crm', 'trustTier' => 'gold', 'effectiveFrom' => '2026-01-01']);

        $config = $this->service->getTrustConfig('account', 'email', 'crm', '2026-06-03T00:00:00Z');
        $this->assertSame('gold', $config['trustTier']);
    }//end testMostRecentEffectiveRuleWins()
}//end class
