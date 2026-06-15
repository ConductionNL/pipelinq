<?php

/**
 * Unit tests for DuplicateDetectionService.
 *
 * Covers deterministic natural-key matching (confidence 1.0), probabilistic
 * fuzzy matching above and below the configured thresholds, candidate de-dup
 * (deterministic outranks probabilistic on the same pair), and the auto-merge
 * eligibility gate (high confidence + non-overridable trust rule). Mirrors spec
 * scenarios REQ-MDM-002-01/02 and REQ-MDM-003-01/02.
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

use OCA\Pipelinq\Service\Mdm\DuplicateDetectionService;
use OCA\Pipelinq\Service\Mdm\MasterEntityService;
use OCA\Pipelinq\Service\Mdm\TrustConfigurationService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__.'/InMemoryMdmObjectRepository.php';

/**
 * Tests for DuplicateDetectionService.
 */
final class DuplicateDetectionServiceTest extends TestCase
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
     * @var DuplicateDetectionService
     */
    private DuplicateDetectionService $service;

    /**
     * The trust service (for auto-merge tests).
     *
     * @var TrustConfigurationService
     */
    private TrustConfigurationService $trust;

    /**
     * Set up the service stack.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->repo    = new InMemoryMdmObjectRepository();
        $this->trust   = new TrustConfigurationService($this->repo);
        $masterService = new MasterEntityService($this->repo, $this->trust, new NullLogger());
        $this->service = new DuplicateDetectionService($masterService, $this->trust);
    }//end setUp()

    /**
     * Seed an active master entity.
     *
     * @param string               $id     The master id.
     * @param string               $type   The entity type.
     * @param array<string, mixed> $golden The golden record.
     *
     * @return void
     */
    private function entity(string $id, string $type, array $golden): void
    {
        $this->repo->seed(
            'masterEntity',
            $id,
            ['masterId' => $id, 'entityType' => $type, 'status' => 'active', 'goldenRecord' => $golden, 'attributeProvenance' => []]
        );
    }//end entity()

    /**
     * Two entities with the same KvK number are a deterministic duplicate.
     *
     * @return void
     */
    public function testDeterministicKvkMatch(): void
    {
        $this->entity('a', 'account', ['name' => 'Voorbeeld B.V.', 'kvkNumber' => '12345678']);
        $this->entity('b', 'account', ['name' => 'Voorbeeld BV', 'kvkNumber' => '12345678']);

        $candidates = $this->service->detectDuplicates('account');

        $this->assertCount(1, $candidates);
        $this->assertSame('deterministic-key', $candidates[0]['linkageMethod']);
        $this->assertSame(1.0, $candidates[0]['linkageConfidence']);
        $this->assertSame('kvkNumber', $candidates[0]['matchedOn']);
    }//end testDeterministicKvkMatch()

    /**
     * Email collision across two accounts is a deterministic duplicate.
     *
     * @return void
     */
    public function testDeterministicEmailMatch(): void
    {
        $this->entity('x', 'account', ['name' => 'ABC B.V.', 'email' => 'contact@abc.nl']);
        $this->entity('y', 'account', ['name' => 'ABC New B.V.', 'email' => 'contact@abc.nl']);

        $candidates = $this->service->detectDeterministicDuplicates(
            'account',
            [$this->repo->find('masterEntity', 'x'), $this->repo->find('masterEntity', 'y')]
        );

        $this->assertCount(1, $candidates);
        $this->assertSame(1.0, $candidates[0]['linkageConfidence']);
    }//end testDeterministicEmailMatch()

    /**
     * Highly similar names + matching postcode/phone yield a probabilistic match.
     *
     * @return void
     */
    public function testProbabilisticNameMatch(): void
    {
        $this->entity('p1', 'account', ['name' => 'Jansens Bouw BV', 'billingAddress' => '1234AB Amsterdam', 'phone' => '020-1234567']);
        $this->entity('p2', 'account', ['name' => "Jansen's Bouw B.V.", 'billingAddress' => '1234AB Amsterdam', 'phone' => '020-1234567']);

        $entities   = [$this->repo->find('masterEntity', 'p1'), $this->repo->find('masterEntity', 'p2')];
        $candidates = $this->service->detectProbabilisticDuplicates('account', $entities);

        $this->assertCount(1, $candidates);
        $this->assertSame('probabilistic-match', $candidates[0]['linkageMethod']);
        $this->assertGreaterThanOrEqual(0.85, $candidates[0]['linkageConfidence']);
    }//end testProbabilisticNameMatch()

    /**
     * Different names below the threshold produce no candidate (REQ-MDM-003-02).
     *
     * @return void
     */
    public function testProbabilisticBelowThresholdNoCandidate(): void
    {
        $this->entity('d1', 'account', ['name' => 'Acme Industries', 'billingAddress' => 'Dorpsweg 1 Rotterdam']);
        $this->entity('d2', 'account', ['name' => 'Zenith Holdings', 'billingAddress' => 'Kerkstraat 9 Groningen']);

        $entities   = [$this->repo->find('masterEntity', 'd1'), $this->repo->find('masterEntity', 'd2')];
        $candidates = $this->service->detectProbabilisticDuplicates('account', $entities);

        $this->assertCount(0, $candidates);
    }//end testProbabilisticBelowThresholdNoCandidate()

    /**
     * Deterministic outranks probabilistic when both fire on the same pair.
     *
     * @return void
     */
    public function testDeduplicationPrefersDeterministic(): void
    {
        $this->entity('m1', 'account', ['name' => 'Jansens Bouw BV', 'kvkNumber' => '99887766', 'phone' => '020-1112222']);
        $this->entity('m2', 'account', ['name' => "Jansen's Bouw B.V.", 'kvkNumber' => '99887766', 'phone' => '020-1112222']);

        $candidates = $this->service->detectDuplicates('account');

        $this->assertCount(1, $candidates);
        $this->assertSame('deterministic-key', $candidates[0]['linkageMethod']);
        $this->assertSame(1.0, $candidates[0]['linkageConfidence']);
    }//end testDeduplicationPrefersDeterministic()

    /**
     * Merged / non-active entities are excluded from detection.
     *
     * @return void
     */
    public function testMergedEntitiesExcluded(): void
    {
        $this->entity('a', 'account', ['name' => 'Voorbeeld', 'kvkNumber' => '12345678']);
        $this->repo->seed('masterEntity', 'b', ['masterId' => 'b', 'entityType' => 'account', 'status' => 'merged-into-other', 'goldenRecord' => ['kvkNumber' => '12345678'], 'attributeProvenance' => []]);

        $this->assertCount(0, $this->service->detectDuplicates('account'));
    }//end testMergedEntitiesExcluded()

    /**
     * Auto-merge eligibility requires confidence >= 0.95 AND a non-overridable rule.
     *
     * @return void
     */
    public function testAutoMergeEligibilityGate(): void
    {
        $this->repo->seed(
            'trustConfiguration',
            'vat-rule',
            ['entityType' => 'account', 'attribute' => 'vatNumber', 'sourceSystem' => 'kvk-api', 'trustTier' => 'gold', 'manualOverrideAllowed' => false, 'effectiveFrom' => '2026-01-01']
        );

        $eligible = $this->service->isAutoMergeEligible(
            [
                'entityType'        => 'account',
                'linkageConfidence' => 1.0,
                'matchedOn'         => 'vatNumber',
                'intoEntity'        => ['attributeProvenance' => ['vatNumber' => ['sourceSystem' => 'kvk-api']]],
            ]
        );
        $this->assertTrue($eligible);

        // Same key but the rule allows manual override → not auto-merge eligible.
        $this->repo->seed(
            'trustConfiguration',
            'email-rule',
            ['entityType' => 'account', 'attribute' => 'email', 'sourceSystem' => 'crm', 'trustTier' => 'gold', 'manualOverrideAllowed' => true, 'effectiveFrom' => '2026-01-01']
        );
        $notEligible = $this->service->isAutoMergeEligible(
            [
                'entityType'        => 'account',
                'linkageConfidence' => 1.0,
                'matchedOn'         => 'email',
                'intoEntity'        => ['attributeProvenance' => ['email' => ['sourceSystem' => 'crm']]],
            ]
        );
        $this->assertFalse($notEligible);
    }//end testAutoMergeEligibilityGate()

    /**
     * Below the auto-merge threshold is never eligible regardless of rule.
     *
     * @return void
     */
    public function testBelowAutoMergeThresholdNotEligible(): void
    {
        $this->assertFalse(
            $this->service->isAutoMergeEligible(
                ['entityType' => 'account', 'linkageConfidence' => 0.9, 'matchedOn' => 'vatNumber', 'intoEntity' => []]
            )
        );
    }//end testBelowAutoMergeThresholdNotEligible()
}//end class
