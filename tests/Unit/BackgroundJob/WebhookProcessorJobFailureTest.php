<?php

/**
 * Unit test for WebhookProcessorJob: a row that cannot be read stops the drain and is logged as a failed run.
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

use OCA\Pipelinq\BackgroundJob\WebhookProcessorJob;
use OCA\Pipelinq\Kernel\Contracts\SmsAdapterInterface;
use OCA\Pipelinq\Kernel\Contracts\WhatsAppAdapterInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the failed-run path of WebhookProcessorJob.
 *
 * @covers \OCA\Pipelinq\BackgroundJob\WebhookProcessorJob
 * @uses   \OCA\Pipelinq\Util\PayloadToArray
 * @uses   \OCA\Pipelinq\Util\EntityPayload
 * @uses   \OCA\Pipelinq\Util\SerialisedPayload
 * @uses   \OCA\Pipelinq\Util\ContainerObjectServiceLookup
 */
class WebhookProcessorJobFailureTest extends TestCase {

	/**
	 * A queued row whose payload throws ends the run with one error log and
	 * nothing dispatched or saved.
	 *
	 * @return void
	 */
	public function testARowWhosePayloadThrowsEndsTheRunWithOneErrorLog(): void {
		$saved = 0;
		$objectService = new class($saved) {
			/**
			 * @param int $saved Save counter, by reference.
			 */
			public function __construct(
				private int &$saved,
			) {
			}

			/**
			 * One queued row whose payload cannot be read.
			 *
			 * @param array<string, mixed> $config The query.
			 *
			 * @return array<int, object>
			 */
			public function findAll(array $config = []): array {
				return [
					new class {
						/**
						 * The payload read fails.
						 *
						 * @return array<string, mixed>
						 */
						public function getObject(): array {
							throw new RuntimeException('row is corrupt');
						}
					},
				];
			}

			/**
			 * Counts saves.
			 *
			 * @param mixed ...$args The save arguments.
			 *
			 * @return void
			 */
			public function saveObject(mixed ...$args): void {
				$this->saved++;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);
		$whatsApp = $this->createMock(WhatsAppAdapterInterface::class);
		$whatsApp->expects($this->never())->method('handleInboundWebhook');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('WebhookProcessorJob failed', ['exception' => 'row is corrupt']);

		$job = new WebhookProcessorJob(
			$this->createMock(ITimeFactory::class),
			$container,
			$this->createMock(IAppConfig::class),
			$whatsApp,
			$this->createMock(SmsAdapterInterface::class),
			$logger,
		);
		$method = new \ReflectionMethod($job, 'run');
		$method->invoke($job, null);

		$this->assertSame(0, $saved);
	}//end testARowWhosePayloadThrowsEndsTheRunWithOneErrorLog()
}//end class
