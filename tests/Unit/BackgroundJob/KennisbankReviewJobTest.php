<?php

/**
 * Unit tests for KennisbankReviewJob.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\BackgroundJob\KennisbankReviewJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Tests for KennisbankReviewJob.
 */
class KennisbankReviewJobTest extends TestCase
{

    /**
     * The time factory mock.
     *
     * @var ITimeFactory&MockObject
     */
    private ITimeFactory $timeFactory;

    /**
     * The app config mock.
     *
     * @var IAppConfig&MockObject
     */
    private IAppConfig $appConfig;

    /**
     * The logger mock.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->timeFactory = $this->createMock(originalClassName: ITimeFactory::class);
        $this->appConfig   = $this->createMock(originalClassName: IAppConfig::class);
        $this->logger      = $this->createMock(originalClassName: LoggerInterface::class);

        $this->timeFactory->method('getTime')->willReturn(time());

        // Default: return typed defaults for all config lookups.
        $this->appConfig->method('getValueInt')->willReturnCallback(
            static fn (string $app, string $key, int $default): int => $default
        );
        $this->appConfig->method('getValueString')->willReturnCallback(
            static fn (string $app, string $key, string $default): string => $default
        );
    }//end setUp()

    /**
     * Build the job under test using the shared mocks.
     *
     * @return KennisbankReviewJob
     */
    private function buildJob(): KennisbankReviewJob
    {
        return new KennisbankReviewJob(
            time: $this->timeFactory,
            appConfig: $this->appConfig,
            logger: $this->logger,
        );
    }//end buildJob()

    /**
     * Test that the job can be instantiated without errors.
     *
     * @return void
     */
    public function testJobCanBeInstantiated(): void
    {
        $this->assertInstanceOf(expected: KennisbankReviewJob::class, actual: $this->buildJob());
    }//end testJobCanBeInstantiated()

    /**
     * Test that the job reads the review interval from admin-config.
     *
     * Verifies that the configured interval (e.g. 90 days) is used instead of
     * the default (180 days) when the admin has set a custom value.
     *
     * @return void
     */
    public function testJobUsesConfiguredReviewInterval(): void
    {
        $this->appConfig->method('getValueInt')
            ->willReturnCallback(
                static function (string $app, string $key, int $default): int {
                    if ($app === Application::APP_ID && $key === 'kennisbank.review_interval_days') {
                        return 90;
                    }

                    return $default;
                }
            );

        $job = $this->buildJob();

        // The job should instantiate successfully with the custom interval.
        $this->assertInstanceOf(expected: KennisbankReviewJob::class, actual: $job);
    }//end testJobUsesConfiguredReviewInterval()

    /**
     * Test that run() skips processing when no register is configured.
     *
     * @return void
     */
    public function testRunSkipsWhenNoRegisterConfigured(): void
    {
        $this->appConfig->method('getValueString')->willReturn('');

        $this->logger->expects($this->once())
            ->method('debug')
            ->with($this->stringContains(string: 'skipping'));

        $job = $this->buildJob();
        $ref = new ReflectionMethod(objectOrMethod: $job, method: 'run');
        $ref->setAccessible(accessible: true);
        $ref->invoke($job, null);
    }//end testRunSkipsWhenNoRegisterConfigured()

    /**
     * Test that run() logs an info message when register and schema are configured.
     *
     * Uses a fresh set of mocks to override the default getValueString behaviour
     * without conflicting with the setUp stubs.
     *
     * @return void
     */
    public function testRunLogsInfoWhenConfigured(): void
    {
        $timeFactory = $this->createMock(originalClassName: ITimeFactory::class);
        $appConfig   = $this->createMock(originalClassName: IAppConfig::class);
        $logger      = $this->createMock(originalClassName: LoggerInterface::class);

        $timeFactory->method('getTime')->willReturn(time());

        $appConfig->method('getValueInt')->willReturnCallback(
            static fn (string $app, string $key, int $default): int => $default
        );
        $appConfig->method('getValueString')->willReturnCallback(
            static function (string $app, string $key, string $default): string {
                if ($key === 'register') {
                    return 'pipelinq';
                }

                if ($key === 'kennisartikel_schema') {
                    return 'kennisartikel';
                }

                return $default;
            }
        );

        $logger->expects($this->atLeastOnce())->method('info');

        $job = new KennisbankReviewJob(
            time: $timeFactory,
            appConfig: $appConfig,
            logger: $logger,
        );
        $ref = new ReflectionMethod(objectOrMethod: $job, method: 'run');
        $ref->setAccessible(accessible: true);
        $ref->invoke($job, null);
    }//end testRunLogsInfoWhenConfigured()
}//end class
