<?php

/**
 * Unit tests for KennisbankReviewJob.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use OCA\Pipelinq\BackgroundJob\KennisbankReviewJob;
use OCA\Pipelinq\Service\NotificationService;
use OCA\Pipelinq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

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
     * The settings service mock.
     *
     * @var SettingsService&MockObject
     */
    private SettingsService $settingsService;

    /**
     * The notification service mock.
     *
     * @var NotificationService&MockObject
     */
    private NotificationService $notificationService;

    /**
     * The app manager mock.
     *
     * @var IAppManager&MockObject
     */
    private IAppManager $appManager;

    /**
     * The container mock.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface $container;

    /**
     * The logger mock.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->timeFactory         = $this->createMock(ITimeFactory::class);
        $this->settingsService     = $this->createMock(SettingsService::class);
        $this->notificationService = $this->createMock(NotificationService::class);
        $this->appManager          = $this->createMock(IAppManager::class);
        $this->container           = $this->createMock(ContainerInterface::class);
        $this->logger              = $this->createMock(LoggerInterface::class);

        $this->timeFactory->method('getTime')->willReturn(time());
    }//end setUp()

    /**
     * Build the job under test.
     *
     * @return KennisbankReviewJob
     */
    private function buildJob(): KennisbankReviewJob
    {
        return new KennisbankReviewJob(
            time: $this->timeFactory,
            settingsService: $this->settingsService,
            notificationService: $this->notificationService,
            appManager: $this->appManager,
            container: $this->container,
            logger: $this->logger,
        );
    }//end buildJob()

    /**
     * Test that the job can be instantiated.
     *
     * @return void
     */
    public function testJobCanBeInstantiated(): void
    {
        $this->assertInstanceOf(KennisbankReviewJob::class, $this->buildJob());
    }//end testJobCanBeInstantiated()

    /**
     * Test that the job skips when OpenRegister is not installed.
     *
     * @return void
     */
    public function testJobSkipsWhenOpenRegisterNotInstalled(): void
    {
        $this->appManager
            ->method('getInstalledApps')
            ->willReturn(['pipelinq', 'contacts']);

        // No notification should be sent.
        $this->notificationService->expects($this->never())->method($this->anything());

        $job = $this->buildJob();
        $ref = new \ReflectionMethod($job, 'run');
        $ref->setAccessible(true);
        $ref->invoke($job, null);
    }//end testJobSkipsWhenOpenRegisterNotInstalled()

    /**
     * Test that the job skips when register or schema is not configured.
     *
     * @return void
     */
    public function testJobSkipsWhenNotConfigured(): void
    {
        $this->appManager->method('getInstalledApps')->willReturn(['openregister']);
        $this->settingsService->method('getSettings')->willReturn([]);

        $this->notificationService->expects($this->never())->method($this->anything());

        $job = $this->buildJob();
        $ref = new \ReflectionMethod($job, 'run');
        $ref->setAccessible(true);
        $ref->invoke($job, null);
    }//end testJobSkipsWhenNotConfigured()

    /**
     * Test that the job sends a notification for stale articles.
     *
     * @return void
     */
    public function testJobSendsNotificationForStaleArticle(): void
    {
        $this->appManager->method('getInstalledApps')->willReturn(['openregister']);
        $this->settingsService->method('getSettings')->willReturn([
            'register'                  => 'reg-uuid',
            'kennisartikel_schema'       => 'schema-uuid',
            'kennisbank_review_interval' => 180,
        ]);

        $staleDate = (new \DateTime())->modify('-200 days')->format('c');

        $objectServiceMock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['findAll'])
            ->getMock();
        $objectServiceMock->method('findAll')->willReturn([
            'results' => [
                [
                    'id'           => 'article-1',
                    'title'        => 'Stale Article',
                    'status'       => 'gepubliceerd',
                    'author'       => 'user1',
                    'dateModified' => $staleDate,
                ],
            ],
        ]);

        $this->container->method('get')->willReturn($objectServiceMock);

        $this->notificationService
            ->expects($this->once())
            ->method('sendNotification')
            ->with('user1', 'kennisbank_review_needed', $this->arrayHasKey('articleTitle'));

        $job = $this->buildJob();
        $ref = new \ReflectionMethod($job, 'run');
        $ref->setAccessible(true);
        $ref->invoke($job, null);
    }//end testJobSendsNotificationForStaleArticle()

    /**
     * Test that the job does not send notifications for recently updated articles.
     *
     * @return void
     */
    public function testJobSkipsRecentlyUpdatedArticle(): void
    {
        $this->appManager->method('getInstalledApps')->willReturn(['openregister']);
        $this->settingsService->method('getSettings')->willReturn([
            'register'            => 'reg-uuid',
            'kennisartikel_schema' => 'schema-uuid',
        ]);

        $recentDate        = (new \DateTime())->modify('-10 days')->format('c');
        $objectServiceMock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['findAll'])
            ->getMock();
        $objectServiceMock->method('findAll')->willReturn([
            'results' => [
                [
                    'id'           => 'article-2',
                    'title'        => 'Fresh Article',
                    'status'       => 'gepubliceerd',
                    'author'       => 'user1',
                    'dateModified' => $recentDate,
                ],
            ],
        ]);

        $this->container->method('get')->willReturn($objectServiceMock);

        $this->notificationService->expects($this->never())->method($this->anything());

        $job = $this->buildJob();
        $ref = new \ReflectionMethod($job, 'run');
        $ref->setAccessible(true);
        $ref->invoke($job, null);
    }//end testJobSkipsRecentlyUpdatedArticle()

    /**
     * Test that the job logs an error on exception.
     *
     * @return void
     */
    public function testJobLogsErrorOnException(): void
    {
        $this->appManager->method('getInstalledApps')->willReturn(['openregister']);
        $this->settingsService->method('getSettings')->willThrowException(new \RuntimeException('Test error'));

        $this->logger->expects($this->once())->method('error');

        $job = $this->buildJob();
        $ref = new \ReflectionMethod($job, 'run');
        $ref->setAccessible(true);
        $ref->invoke($job, null);
    }//end testJobLogsErrorOnException()
}//end class
