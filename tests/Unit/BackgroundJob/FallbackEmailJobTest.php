<?php

/**
 * Unit tests for FallbackEmailJob.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://codeberg.org/Conduction/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use OCA\Pipelinq\BackgroundJob\FallbackEmailJob;
use OCA\Pipelinq\Service\BerichtenboxService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Tests for FallbackEmailJob.
 */
class FallbackEmailJobTest extends TestCase
{
    /**
     * The core service mock.
     *
     * @var BerichtenboxService
     */
    private BerichtenboxService $service;

    /**
     * The app config mock.
     *
     * @var IAppConfig
     */
    private IAppConfig $appConfig;

    /**
     * Set up shared mocks.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->service   = $this->createMock(BerichtenboxService::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
    }//end setUp()

    /**
     * Build the job.
     *
     * @return FallbackEmailJob The job.
     */
    private function buildJob(): FallbackEmailJob
    {
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(time());

        return new FallbackEmailJob(
            $time,
            $this->service,
            $this->appConfig,
            $this->createMock(LoggerInterface::class)
        );
    }//end buildJob()

    /**
     * The job processes the fallback queue when configured.
     *
     * @return void
     */
    public function testRunProcessesWhenConfigured(): void
    {
        $this->appConfig->method('getValueString')->willReturn('2');
        $this->service->expects($this->once())->method('processFallbackQueue')->willReturn(1);

        $job = $this->buildJob();
        $ref = new ReflectionMethod($job, 'run');
        $ref->setAccessible(true);
        $ref->invoke($job, null);
    }//end testRunProcessesWhenConfigured()

    /**
     * The job skips when not configured.
     *
     * @return void
     */
    public function testRunSkipsWhenNotConfigured(): void
    {
        $this->appConfig->method('getValueString')->willReturn('');
        $this->service->expects($this->never())->method('processFallbackQueue');

        $job = $this->buildJob();
        $ref = new ReflectionMethod($job, 'run');
        $ref->setAccessible(true);
        $ref->invoke($job, null);
    }//end testRunSkipsWhenNotConfigured()
}//end class
