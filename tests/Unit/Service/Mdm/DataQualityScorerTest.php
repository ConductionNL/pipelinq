<?php

/**
 * Unit tests for DataQualityScorer.
 *
 * The completeness / format / freshness dimensions are delegated to
 * OpenRegister's x-openregister-quality annotation (materialised as
 * `qualityScore`); these tests cover the parts that stay app-side: the
 * cross-source agreement term, the OR-quality read, the blend, and the
 * persistence of the blended score into `dataQualityScore`.
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
     * orQuality reads OpenRegister's materialised qualityScore, clamped [0,1].
     *
     * @return void
     */
    public function testOrQualityReadsMaterialisedScore(): void
    {
        $this->assertSame(0.87, $this->scorer->orQuality(['qualityScore' => 0.87]));
        // Absent → 0.0 (not yet materialised).
        $this->assertSame(0.0, $this->scorer->orQuality([]));
        // Out-of-range clamps.
        $this->assertSame(1.0, $this->scorer->orQuality(['qualityScore' => 1.5]));
        $this->assertSame(0.0, $this->scorer->orQuality(['qualityScore' => -0.2]));
    }//end testOrQualityReadsMaterialisedScore()

    /**
     * Agreement = 1 - conflicting/total attributes (stays app-side).
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
     * Withdrawn source records do not contribute to the conflict tally.
     *
     * @return void
     */
    public function testAgreementIgnoresWithdrawn(): void
    {
        $entity  = ['entityType' => 'account'];
        $sources = [
            ['mappedAttributes' => ['name' => 'A', 'email' => 'a@x.nl']],
            ['withdrawn' => true, 'mappedAttributes' => ['name' => 'B', 'email' => 'z@x.nl']],
        ];
        // The withdrawn record is skipped → no conflict → 1.0.
        $this->assertSame(1.0, $this->scorer->agreement($entity, $sources));
    }//end testAgreementIgnoresWithdrawn()

    /**
     * Overall = orQuality*0.7 + agreement*0.3 (the blend).
     *
     * @return void
     */
    public function testOverallBlend(): void
    {
        $entity = [
            'entityType'   => 'account',
            'qualityScore' => 0.9,
        ];
        $sources = [
            ['mappedAttributes' => ['name' => 'Voorbeeld', 'kvkNumber' => '12345678', 'email' => 'a@x.nl', 'phone' => '1', 'web' => 'x.nl']],
            ['mappedAttributes' => ['name' => 'Voorbeeld', 'kvkNumber' => '12345678', 'email' => 'b@x.nl', 'phone' => '2', 'web' => 'x.nl']],
        ];

        // agreement: 5 attrs, email+phone conflict → 0.6.
        // overall = 0.9*0.7 + 0.6*0.3 = 0.63 + 0.18 = 0.81.
        $score = $this->scorer->score($entity, $sources);
        $this->assertEqualsWithDelta(0.81, $score, 0.01);
    }//end testOverallBlend()

    /**
     * scoreEntity persists the blended score and bumps lastReviewedAt on big changes.
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
                'masterId'         => 'm1',
                'entityType'       => 'account',
                'dataQualityScore' => 0.0,
                'qualityScore'     => 1.0,
                'goldenRecord'     => ['name' => 'Voorbeeld', 'kvkNumber' => '12345678'],
            ]
        );

        $score = $this->scorer->scoreEntity('m1');

        $this->assertNotNull($score);
        // No linked sources → agreement 1.0; orQuality 1.0 → blend 1.0.
        $this->assertEqualsWithDelta(1.0, $score, 0.001);
        $stored = $this->repo->find('masterEntity', 'm1');
        $this->assertSame($score, $stored['dataQualityScore']);
        $this->assertSame('2026-06-03T00:00:00Z', $stored['lastReviewedAt']);
    }//end testScoreEntityPersists()
}//end class
