<?php

/**
 * Unit tests for DpiaDetectionService (pattern threshold flagging).
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Avg
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Avg;

use DateTimeImmutable;
use OCA\Pipelinq\Service\Avg\AvgRepository;
use OCA\Pipelinq\Service\Avg\DpiaDetectionService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__.'/AvgTestSupport.php';

/**
 * Tests for DpiaDetectionService.
 */
class DpiaDetectionServiceTest extends TestCase
{
    /**
     * The fake OR ObjectService.
     *
     * @var FakeAvgObjectService
     */
    private FakeAvgObjectService $objectService;

    /**
     * The repository.
     *
     * @var AvgRepository
     */
    private AvgRepository $repository;

    /**
     * The service under test.
     *
     * @var DpiaDetectionService
     */
    private DpiaDetectionService $service;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->objectService = new FakeAvgObjectService();
        $appConfig           = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            static fn (string $app, string $key, string $default = '', bool $lazy = false): string
                => ($key === 'register' ? 'reg' : $key)
        );
        $appConfig->method('getValueInt')->willReturnCallback(
            static fn (string $app, string $key, int $default = 0, bool $lazy = false): int => $default
        );

        $this->repository = AvgRepositoryFactory::build($this->objectService, $appConfig);
        $this->service    = new DpiaDetectionService(
            repository: $this->repository,
            container: AvgRepositoryFactory::container($this->objectService),
            appConfig: $appConfig,
            logger: new NullLogger()
        );
    }//end setUp()

    /**
     * Seed N recent identical art-17/marketing requests.
     *
     * @param int $count The number of requests.
     *
     * @return void
     */
    private function seedRequests(int $count): void
    {
        $today = (new DateTimeImmutable())->format('c');
        for ($i = 0; $i < $count; $i++) {
            $this->objectService->saveObject(
                object: [
                    'artikel'     => 'art-17-wissing',
                    'scope'       => ['marketingdata'],
                    'ingediendOp' => $today,
                    'dpiaFlag'    => false,
                ],
                extend: [],
                register: 'reg',
                schema: AvgRepository::SCHEMA_VERZOEK
            );
        }
    }//end seedRequests()

    /**
     * Below threshold yields no pattern; at/above threshold flags all.
     *
     * @return void
     */
    public function testThresholdFlagging(): void
    {
        $now = new DateTimeImmutable();

        $this->seedRequests(9);
        $this->assertCount(0, $this->service->detectPatterns(now: $now));
        $this->assertSame(0, $this->service->analyzeAndFlag(now: $now));

        $this->seedRequests(1);
        $patterns = $this->service->detectPatterns(now: $now);
        $this->assertCount(1, $patterns);
        $this->assertSame(10, $patterns[0]['count']);

        $flagged = $this->service->analyzeAndFlag(now: $now);
        $this->assertSame(10, $flagged);

        // All matching requests are now flagged; a re-run flags none.
        $this->assertSame(0, $this->service->analyzeAndFlag(now: $now));
    }//end testThresholdFlagging()

    /**
     * Requests older than the 30-day window are excluded from the count.
     *
     * @return void
     */
    public function testOldRequestsExcludedFromWindow(): void
    {
        $old = (new DateTimeImmutable('-60 days'))->format('c');
        for ($i = 0; $i < 12; $i++) {
            $this->objectService->saveObject(
                object: ['artikel' => 'art-17-wissing', 'scope' => ['marketingdata'], 'ingediendOp' => $old],
                extend: [],
                register: 'reg',
                schema: AvgRepository::SCHEMA_VERZOEK
            );
        }

        $this->assertCount(0, $this->service->detectPatterns(now: new DateTimeImmutable()));
    }//end testOldRequestsExcludedFromWindow()
}//end class
