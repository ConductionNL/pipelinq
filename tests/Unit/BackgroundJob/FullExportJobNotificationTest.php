<?php

/**
 * Unit test for FullExportJob: an administrator who cannot be notified is logged, after the archive was stored.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use OCA\Pipelinq\BackgroundJob\FullExportJob;
use OCA\Pipelinq\Kernel\Contracts\FullExportServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Notification\IManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the notification failure of FullExportJob.
 *
 * @covers \OCA\Pipelinq\BackgroundJob\FullExportJob
 * @uses   \OCA\Pipelinq\Service\UserNotice
 */
class FullExportJobNotificationTest extends TestCase {

	/**
	 * A notification that cannot be created is logged as a warning, and the
	 * export it was about still ran.
	 *
	 * @return void
	 */
	public function testANotificationThatCannotBeSentIsLogged(): void {
		$export = $this->createMock(FullExportServiceInterface::class);
		$export->expects($this->once())->method('exportFor')->with('admin')->willReturn(['name' => 'export.zip', 'fileId' => 7]);
		$notifications = $this->createMock(IManager::class);
		$notifications->method('createNotification')->willThrowException(new RuntimeException('notifications app disabled'));
		$notifications->expects($this->never())->method('notify');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'FullExportJob: the administrator could not be notified',
			['exception' => 'notifications app disabled']
		);
		$logger->expects($this->never())->method('error');

		$job = new FullExportJob($this->createMock(ITimeFactory::class), $export, $notifications, $logger);
		$method = new \ReflectionMethod($job, 'run');
		$method->invoke($job, ['userId' => 'admin']);
	}//end testANotificationThatCannotBeSentIsLogged()
}//end class
