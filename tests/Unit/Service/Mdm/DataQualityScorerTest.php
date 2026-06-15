<?php

/**
 * Unit tests for DataQualityScorer.
 *
 * Verifies the three components (completeness, freshness, agreement), the
 * weighted overall score and edge cases (no sources, missing provenance).
 * Mirrors spec scenarios REQ-MDM-007-01..04.
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

use OCA\Pipelinq\Service\Mdm\DataQualityScorer;
use OCA\Pipelinq\Service\Mdm\MasterEntityService;
use OCA\Pipelinq\Service\Mdm\TrustConfigurationService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__.'/InMemoryMdmObjectRepository.php';

/**
 * Tests for DataQualityScorer.
 */
final class DataQualityScorerTest extends TestCase
{
    /**
     * The in-memory repository.
     *
     * @var InMemoryMdmObjectRepository
     */
    private InMemoryMdmObjectRepository $repo;

    /**
     * The scorer under test.
     *
     * @var DataQualityScorer
     */
    private DataQualityScorer $scorer;

    /**
     * Set up the scorer stack.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->repo   = new InMemoryMdmObjectRepository();
        $trust        = new TrustConfigurationService($this->repo);
        $master       = new MasterEntityService($this->repo, $trust, new NullLogger());
        $this->scorer = new DataQualityScorer($this->repo, $master);
    }//end setUp()

    /**
     * Completeness = filled required / total required (REQ-MDM-007-01).
     *
     * @return void
     */
    public function testCompleteness(): void
    {
        $entity = ['entityType' => 'account', 'goldenRecord' => ['name' => 'Voorbeeld', 'kvkNumber' => '']];
        // account requires [name, kvkNumber]; only name filled → 0.5.
        $this->assertEqualsWithDelta(0.5, $this->scorer->completeness($entity), 0.001);

        $full = ['entityType' => 'account', 'goldenRecord' => ['name' => 'Voorbeeld', 'kvkNumber' => '12345678']];
        $this->assertSame(1.0, $this->scorer->completeness($full));
    }//end testCompleteness()

    /**
     * Freshness = exp(-days/180): 1.0 today, ~0.85 at 30 days (REQ-MDM-007-02).
     *
     * @return void
     */
    public function testFreshness(): void
    {
        $asOf   = '2026-06-03T00:00:00Z';
        $entity = ['attributeProvenance' => ['name' => ['lastUpdated' => '2026-05-04T00:00:00Z']]];
        // 30 days → exp(-30/180) ≈ 0.846.
        $this->assertEqualsWithDelta(0.846, $this->scorer->freshness($entity, $asOf), 0.01);

        $today = ['attributeProvenance' => ['name' => ['lastUpdated' => $asOf]]];
        $this->assertEqualsWithDelta(1.0, $this->scorer->freshness($today, $asOf), 0.001);
    }//end testFreshness()

    /**
     * Agreement = 1 - conflicting/total attributes (REQ-MDM-007-03).
     *
     * @return void
     */
    public function testAgreement(): void
    {
        $entity  = ['entityType' => 'account'];
        $sources = [
            ['mappedAttributes' => ['name' => 'A', 'email' => 'a@x.nl', 'phone' => '1', 'city' => 'AMS', 'web' => 'a.nl']],
            ['mappedAttributes' => ['name' => 'A', 'email' => 'b@x.nl', 'phone' => '2', 'city' => 'AMS', 'web' => 'a.nl']],
        ];
        // 5 attributes, email + phone conflict → 1 - 2/5 = 0.6.
        $this->assertEqualsWithDelta(0.6, $this->scorer->agreement($entity, $sources), 0.001);
    }//end testAgreement()

    /**
     * No source records → perfect agreement (nothing conflicts).
     *
     * @return void
     */
    public function testAgreementNoSources(): void
    {
        $this->assertSame(1.0, $this->scorer->agreement(['entityType' => 'account'], []));
    }//end testAgreementNoSources()

    /**
     * Missing provenance → freshness 0.
     *
     * @return void
     */
    public function testFreshnessNoProvenance(): void
    {
        $this->assertSame(0.0, $this->scorer->freshness(['attributeProvenance' => []], '2026-06-03T00:00:00Z'));
    }//end testFreshnessNoProvenance()

    /**
     * Overall = completeness*0.3 + freshness*0.4 + agreement*0.3 (REQ-MDM-007-04).
     *
     * @return void
     */
    public function testOverallWeightedScore(): void
    {
        $this->repo->clock = '2026-06-03T00:00:00Z';

        $entity = [
            'entityType'          => 'account',
            'goldenRecord'        => ['name' => 'Voorbeeld', 'kvkNumber' => '12345678', 'email' => 'info@x.nl'],
            'attributeProvenance' => ['name' => ['lastUpdated' => '2026-05-04T00:00:00Z']],
        ];
        $sources = [
            ['mappedAttributes' => ['name' => 'Voorbeeld', 'kvkNumber' => '12345678', 'email' => 'a@x.nl', 'phone' => '1', 'web' => 'x.nl']],
            ['mappedAttributes' => ['name' => 'Voorbeeld', 'kvkNumber' => '12345678', 'email' => 'b@x.nl', 'phone' => '2', 'web' => 'x.nl']],
        ];

        // completeness=1.0, freshness≈0.846, agreement=0.6.
        // overall = 1*0.3 + 0.846*0.4 + 0.6*0.3 ≈ 0.818 → 0.82.
        $score = $this->scorer->score($entity, $sources);
        $this->assertEqualsWithDelta(0.82, $score, 0.01);
    }//end testOverallWeightedScore()

    /**
     * scoreEntity persists the score and bumps lastReviewedAt on big changes.
     *
     * @return void
     */
    public function testScoreEntityPersists(): void
    {
        $this->repo->clock = '2026-06-03T00:00:00Z';
        $this->repo->seed(
            'masterEntity',
            'm1',
            [
                'masterId'            => 'm1',
                'entityType'          => 'account',
                'dataQualityScore'    => 0.0,
                'goldenRecord'        => ['name' => 'Voorbeeld', 'kvkNumber' => '12345678'],
                'attributeProvenance' => ['name' => ['lastUpdated' => '2026-06-03T00:00:00Z']],
            ]
        );

        $score = $this->scorer->scoreEntity('m1');

        $this->assertNotNull($score);
        $stored = $this->repo->find('masterEntity', 'm1');
        $this->assertSame($score, $stored['dataQualityScore']);
        $this->assertSame('2026-06-03T00:00:00Z', $stored['lastReviewedAt']);
    }//end testScoreEntityPersists()
}//end class
