<?php

/**
 * Unit tests for MarketingSequenceService.
 *
 * Covers case-insensitive AND-logic segment evaluation and the 24-hour
 * per (contact + automation) deduplication guard derived from the append-only
 * automationLog. The OpenRegister ObjectService is supplied as an in-memory
 * double resolved through the container; the real AutomationService is wired in
 * so segment + trigger semantics share a single evaluator.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\Service\AutomationService;
use OCA\Pipelinq\Service\DmnDecisionService;
use OCA\Pipelinq\Service\MarketingSequenceService;
use OCA\Pipelinq\Service\NotificationService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for MarketingSequenceService.
 */
class MarketingSequenceServiceTest extends TestCase
{
    /**
     * The service under test.
     *
     * @var MarketingSequenceService
     */
    private MarketingSequenceService $service;

    /**
     * The in-memory object service double.
     *
     * @var object
     */
    private object $objectService;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->objectService = $this->makeObjectServiceDouble();

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            fn (string $id): object => $this->objectService
        );

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            static function (string $app, string $key): string {
                return match ($key) {
                    'register'             => 'reg1',
                    'automation_schema'    => 'autoSchema',
                    'automationLog_schema' => 'logSchema',
                    default                => '',
                };
            }
        );

        $logger       = $this->createMock(LoggerInterface::class);
        $notification = $this->createMock(NotificationService::class);
        $dmn          = $this->createMock(DmnDecisionService::class);

        $automationService = new AutomationService(
            $container,
            $appConfig,
            $dmn,
            $notification,
            $logger,
        );

        $this->service = new MarketingSequenceService(
            $container,
            $appConfig,
            $automationService,
            $logger,
        );
    }//end setUp()

    /**
     * Segment evaluation matches case-insensitively under AND logic.
     *
     * @return void
     */
    public function testEvaluateSegmentMatchesCaseInsensitive(): void
    {
        $conditions = ['industry' => 'Gemeente'];
        $entity     = ['industry' => 'gemeente'];

        $this->assertTrue($this->service->evaluateSegment($conditions, $entity));
    }//end testEvaluateSegmentMatchesCaseInsensitive()

    /**
     * A partial match does not qualify (AND logic).
     *
     * @return void
     */
    public function testEvaluateSegmentPartialMatchFails(): void
    {
        $conditions = ['industry' => 'Gemeente', 'source' => 'website'];
        $entity     = ['industry' => 'Gemeente', 'source' => 'referral'];

        $this->assertFalse($this->service->evaluateSegment($conditions, $entity));
    }//end testEvaluateSegmentPartialMatchFails()

    /**
     * A first-time enqueue runs the sequence and writes a log.
     *
     * @return void
     */
    public function testEnqueueSequenceFiresWhenNotRecentlyTriggered(): void
    {
        $this->objectService->seed = [
            ['id' => 'a1', 'isActive' => true, 'trigger' => 'marketing_segment_match', 'actions' => [['type' => 'add_note']]],
        ];

        $started = $this->service->enqueueSequence('a1', 'contact1', ['industry' => 'Gemeente']);

        $this->assertTrue($started);
        $this->assertNotEmpty($this->objectService->saved);
    }//end testEnqueueSequenceFiresWhenNotRecentlyTriggered()

    /**
     * A repeat within 24 hours is deduplicated (no run).
     *
     * @return void
     */
    public function testEnqueueSequenceSkipsWhenRecentlyTriggered(): void
    {
        $recent = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

        $this->objectService->seed = [
            ['id' => 'a1', 'isActive' => true, 'trigger' => 'marketing_segment_match', 'actions' => [['type' => 'add_note']]],
            ['automation' => 'a1', 'triggerEntity' => 'contact1', 'triggeredAt' => $recent, 'status' => 'success'],
        ];

        $started = $this->service->enqueueSequence('a1', 'contact1', ['industry' => 'Gemeente']);

        $this->assertFalse($started);
    }//end testEnqueueSequenceSkipsWhenRecentlyTriggered()

    /**
     * A trigger older than 24 hours does not deduplicate.
     *
     * @return void
     */
    public function testEnqueueSequenceFiresWhenLastTriggerIsOld(): void
    {
        $old = (new DateTimeImmutable('-2 days'))->format(DateTimeInterface::ATOM);

        $this->objectService->seed = [
            ['id' => 'a1', 'isActive' => true, 'trigger' => 'marketing_segment_match', 'actions' => [['type' => 'add_note']]],
            ['automation' => 'a1', 'triggerEntity' => 'contact1', 'triggeredAt' => $old, 'status' => 'success'],
        ];

        $started = $this->service->enqueueSequence('a1', 'contact1', ['industry' => 'Gemeente']);

        $this->assertTrue($started);
    }//end testEnqueueSequenceFiresWhenLastTriggerIsOld()

    /**
     * Build an in-memory ObjectService test double.
     *
     * @return object The double.
     */
    private function makeObjectServiceDouble(): object
    {
        return new class {
            /**
             * Seed objects returned by find / findAll.
             *
             * @var array<int, array<string, mixed>>
             */
            public array $seed = [];

            /**
             * Every object passed to saveObject.
             *
             * @var array<int, array<string, mixed>>
             */
            public array $saved = [];

            /**
             * Return seeded objects, applying the automation/triggerEntity filter
             * used by the dedup lookup and the trigger filter used by listing.
             *
             * @param array $config The query config.
             *
             * @return array The matching objects.
             */
            public function findAll(array $config=[]): array
            {
                $filters = ($config['filters'] ?? []);
                $result  = [];
                foreach ($this->seed as $object) {
                    if (isset($filters['automation']) === true
                        && ($object['automation'] ?? null) !== $filters['automation']
                    ) {
                        continue;
                    }

                    if (isset($filters['triggerEntity']) === true
                        && ($object['triggerEntity'] ?? null) !== $filters['triggerEntity']
                    ) {
                        continue;
                    }

                    if (isset($filters['trigger']) === true
                        && ($object['trigger'] ?? null) !== $filters['trigger']
                    ) {
                        continue;
                    }

                    $result[] = $object;
                }

                return $result;
            }

            /**
             * Find a seeded object by id.
             *
             * @param string $id       The object id.
             * @param mixed  $register The register (ignored).
             * @param mixed  $schema   The schema (ignored).
             *
             * @return array|null The matching object or null.
             */
            public function find(string $id, mixed $register=null, mixed $schema=null): ?array
            {
                foreach ($this->seed as $object) {
                    if (($object['id'] ?? null) === $id) {
                        return $object;
                    }
                }

                return null;
            }

            /**
             * Capture a saved object.
             *
             * @param array $object   The object to save.
             * @param array $extend   Extend config (ignored).
             * @param mixed $register The register (ignored).
             * @param mixed $schema   The schema (ignored).
             * @param mixed $uuid     The uuid (ignored).
             *
             * @return array The saved object.
             */
            public function saveObject(array $object, array $extend=[], mixed $register=null, mixed $schema=null, mixed $uuid=null): array
            {
                $this->saved[] = $object;

                return $object;
            }
        };
    }//end makeObjectServiceDouble()
}//end class
