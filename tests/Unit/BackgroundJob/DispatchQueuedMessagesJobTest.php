<?php

/**
 * Unit tests for DispatchQueuedMessagesJob.
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

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\BackgroundJob\DispatchQueuedMessagesJob;
use OCA\Pipelinq\Service\BerichtenboxService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Tests for DispatchQueuedMessagesJob.
 */
class DispatchQueuedMessagesJobTest extends TestCase
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
     * @return DispatchQueuedMessagesJob The job.
     */
    private function buildJob(): DispatchQueuedMessagesJob
    {
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(time());

        return new DispatchQueuedMessagesJob(
            $time,
            $this->service,
            $this->appConfig,
            $this->createMock(LoggerInterface::class)
        );
    }//end buildJob()

    /**
     * The job dispatches when configured.
     *
     * @return void
     */
    public function testRunDispatchesWhenConfigured(): void
    {
        $this->appConfig->method('getValueString')->willReturnCallback(
            static function (string $app, string $key, string $default=''): string {
                return ($key === 'default') ? $default : '2';
            }
        );
        $this->service->expects($this->once())->method('dispatchQueuedMessages')->willReturn(3);

        $job = $this->buildJob();
        $ref = new ReflectionMethod($job, 'run');
        $ref->setAccessible(true);
        $ref->invoke($job, null);
    }//end testRunDispatchesWhenConfigured()

    /**
     * The job skips when not configured.
     *
     * @return void
     */
    public function testRunSkipsWhenNotConfigured(): void
    {
        $this->appConfig->method('getValueString')->willReturn('');
        $this->service->expects($this->never())->method('dispatchQueuedMessages');

        $job = $this->buildJob();
        $ref = new ReflectionMethod($job, 'run');
        $ref->setAccessible(true);
        $ref->invoke($job, null);
    }//end testRunSkipsWhenNotConfigured()
}//end class
