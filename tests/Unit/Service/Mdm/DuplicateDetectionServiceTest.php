<?php

/**
 * Unit tests for DuplicateDetectionService.
 *
 * Detection itself is delegated to OpenRegister's findDuplicates(); these tests
 * drive the pipelinq adapter through a fake OR service (via a fake container)
 * and cover: mapping OR's {objectA, objectB, score, matchedOn} into the
 * pipelinq candidate DTO, deterministic-vs-probabilistic classification from
 * matchedOn, the app-side auto-merge eligibility gate (high confidence +
 * non-overridable trust rule), candidate de-dup, exclusion of non-active
 * entities, and the in-process fallback when OpenRegister is unavailable.
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
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

require_once __DIR__.'/InMemoryMdmObjectRepository.php';

/**
 * Fake OpenRegister duplicate-detection service returning canned pairs.
 */
final class FakeOrDuplicateService
{
    /**
     * Canned pairs to return from findDuplicates().
     *
     * @var array<int, array<string, mixed>>
     */
    public array $pairs = [];

    /**
     * Return the canned pairs regardless of arguments.
     *
     * @param mixed                  $register   The register (ignored).
     * @param mixed                  $schema     The schema (ignored).
     * @param array<int, mixed>|null $matchRules The match rules (ignored).
     * @param float|null             $threshold  The threshold (ignored).
     *
     * @return array<int, array<string, mixed>> The canned pairs.
     */
    public function findDuplicates($register, $schema, ?array $matchRules=null, ?float $threshold=null): array
    {
        return $this->pairs;
    }//end findDuplicates()
}//end class

/**
 * Fake DI container exposing only the OR duplicate-detection service.
 */
final class FakeContainer implements ContainerInterface
{
    /**
     * Construct with the OR service (or null to simulate unavailability).
     *
     * @param FakeOrDuplicateService|null $orService The OR service, or null.
     */
    public function __construct(private ?FakeOrDuplicateService $orService)
    {
    }//end __construct()

    /**
     * {@inheritDoc}
     *
     * @param string $id The service id.
     *
     * @return mixed The resolved service.
     */
    public function get(string $id): mixed
    {
        if ($id === 'OCA\OpenRegister\Service\Quality\DuplicateDetectionService' && $this->orService !== null) {
            return $this->orService;
        }

        throw new class extends \Exception implements \Psr\Container\NotFoundExceptionInterface {
        };
    }//end get()

    /**
     * {@inheritDoc}
     *
     * @param string $id The service id.
     *
     * @return bool Whether the service is known.
     */
    public function has(string $id): bool
    {
        return ($id === 'OCA\OpenRegister\Service\Quality\DuplicateDetectionService' && $this->orService !== null);
    }//end has()
}//end class

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
     * The trust service.
     *
     * @var TrustConfigurationService
     */
    private TrustConfigurationService $trust;

    /**
     * The fake OR service.
     *
     * @var FakeOrDuplicateService
     */
    private FakeOrDuplicateService $orService;

    /**
     * Build the service with the OR service wired through the fake container.
     *
     * @param bool $orAvailable Whether the OR service resolves.
     *
     * @return DuplicateDetectionService
     */
    private function makeService(bool $orAvailable=true): DuplicateDetectionService
    {
        $master    = new MasterEntityService($this->repo, $this->trust, new NullLogger());
        $container = new FakeContainer($orAvailable === true ? $this->orService : null);

        return new DuplicateDetectionService($master, $this->trust, $this->repo, $container, new NullLogger());
    }//end makeService()

    /**
     * Set up the shared fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->repo      = new InMemoryMdmObjectRepository();
        $this->trust     = new TrustConfigurationService($this->repo);
        $this->orService = new FakeOrDuplicateService();
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
     * An OR pair matched on a natural key maps to a deterministic candidate.
     *
     * @return void
     */
    public function testDeterministicMappingFromMatchedOn(): void
    {
        $this->entity('a', 'account', ['name' => 'Voorbeeld B.V.', 'kvkNumber' => '12345678']);
        $this->entity('b', 'account', ['name' => 'Voorbeeld BV', 'kvkNumber' => '12345678']);
        $this->orService->pairs = [
            ['objectA' => 'a', 'objectB' => 'b', 'score' => 0.92, 'matchedOn' => ['matchKvkNumber']],
        ];

        $candidates = $this->makeService()->detectDuplicates('account');

        $this->assertCount(1, $candidates);
        $this->assertSame('deterministic-key', $candidates[0]['linkageMethod']);
        $this->assertSame(1.0, $candidates[0]['linkageConfidence']);
        $this->assertSame('matchKvkNumber', $candidates[0]['matchedOn']);
    }//end testDeterministicMappingFromMatchedOn()

    /**
     * An OR pair matched only on name maps to a probabilistic candidate.
     *
     * @return void
     */
    public function testProbabilisticMappingFromMatchedOn(): void
    {
        $this->entity('p1', 'account', ['name' => 'Jansens Bouw BV']);
        $this->entity('p2', 'account', ['name' => "Jansen's Bouw B.V."]);
        $this->orService->pairs = [
            ['objectA' => 'p1', 'objectB' => 'p2', 'score' => 0.88, 'matchedOn' => ['matchName']],
        ];

        $candidates = $this->makeService()->detectDuplicates('account');

        $this->assertCount(1, $candidates);
        $this->assertSame('probabilistic-match', $candidates[0]['linkageMethod']);
        $this->assertEqualsWithDelta(0.88, $candidates[0]['linkageConfidence'], 0.001);
    }//end testProbabilisticMappingFromMatchedOn()

    /**
     * Pairs referencing a non-active / unknown entity are dropped.
     *
     * @return void
     */
    public function testNonActiveEntitiesExcluded(): void
    {
        $this->entity('a', 'account', ['name' => 'Voorbeeld', 'kvkNumber' => '12345678']);
        $this->repo->seed('masterEntity', 'b', ['masterId' => 'b', 'entityType' => 'account', 'status' => 'merged-into-other', 'goldenRecord' => ['kvkNumber' => '12345678'], 'attributeProvenance' => []]);
        $this->orService->pairs = [
            ['objectA' => 'a', 'objectB' => 'b', 'score' => 1.0, 'matchedOn' => ['matchKvkNumber']],
        ];

        $this->assertCount(0, $this->makeService()->detectDuplicates('account'));
    }//end testNonActiveEntitiesExcluded()

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

        $service  = $this->makeService();
        $eligible = $service->isAutoMergeEligible(
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
        $notEligible = $service->isAutoMergeEligible(
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
     * The flattened matchedOn field resolves to the canonical trust attribute.
     *
     * @return void
     */
    public function testAutoMergeGateMapsFlattenedMatchField(): void
    {
        $this->repo->seed(
            'trustConfiguration',
            'kvk-rule',
            ['entityType' => 'account', 'attribute' => 'kvkNumber', 'sourceSystem' => 'kvk-api', 'trustTier' => 'gold', 'manualOverrideAllowed' => false, 'effectiveFrom' => '2026-01-01']
        );

        $eligible = $this->makeService()->isAutoMergeEligible(
            [
                'entityType'        => 'account',
                'linkageConfidence' => 1.0,
                'matchedOn'         => 'matchKvkNumber',
                'intoEntity'        => ['attributeProvenance' => ['kvkNumber' => ['sourceSystem' => 'kvk-api']]],
            ]
        );
        $this->assertTrue($eligible);
    }//end testAutoMergeGateMapsFlattenedMatchField()

    /**
     * Below the auto-merge threshold is never eligible regardless of rule.
     *
     * @return void
     */
    public function testBelowAutoMergeThresholdNotEligible(): void
    {
        $this->assertFalse(
            $this->makeService()->isAutoMergeEligible(
                ['entityType' => 'account', 'linkageConfidence' => 0.9, 'matchedOn' => 'matchKvkNumber', 'intoEntity' => []]
            )
        );
    }//end testBelowAutoMergeThresholdNotEligible()

    /**
     * When OpenRegister is unavailable the in-process fallback still detects.
     *
     * @return void
     */
    public function testFallbackWhenOrUnavailable(): void
    {
        $this->entity('p1', 'account', ['name' => 'Jansens Bouw BV', 'billingAddress' => '1234AB Amsterdam', 'phone' => '020-1234567']);
        $this->entity('p2', 'account', ['name' => "Jansen's Bouw B.V.", 'billingAddress' => '1234AB Amsterdam', 'phone' => '020-1234567']);

        $candidates = $this->makeService(orAvailable: false)->detectDuplicates('account');

        $this->assertCount(1, $candidates);
        $this->assertSame('probabilistic-match', $candidates[0]['linkageMethod']);
        $this->assertGreaterThanOrEqual(0.85, $candidates[0]['linkageConfidence']);
    }//end testFallbackWhenOrUnavailable()
}//end class
