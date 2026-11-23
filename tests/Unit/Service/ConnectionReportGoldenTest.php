<?php

/**
 * Pins every answer of one connection report: refusals, the event sent, and a failing send.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md#requirement-req-as-132-pipelinq-reports-what-its-own-checks-observe
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Integriq\Event\ConnectionStatusReportedEvent;
use OCA\Pipelinq\Service\ConnectionReportService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The answer, the events dispatched and the warnings, in order.
 */
class ConnectionReportGoldenTest extends TestCase {

	/**
	 * Every outcome is exact.
	 *
	 * @return void
	 */
	public function testEveryOutcome(): void {
		$calls      = [];
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event) use (&$calls): void {
				if ($event instanceof ConnectionStatusReportedEvent) {
					$calls[] = ['sent', $event->app, $event->key, $event->status, $event->message];
					if ($event->message === 'boom') {
						throw new RuntimeException('listener broke');
					}
				}
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []) use (&$calls): void {
				$calls[] = ['warning', $message, $context];
			}
		);
		$service = new ConnectionReportService(eventDispatcher: $dispatcher, logger: $logger);

		$this->assertSame(
			[false, false, false, true, true, false],
			[
				$service->report(key: 'kvk', status: 'nope'),
				$service->report(key: 'cti', status: 'ok'),
				$service->report(key: 'nope', status: 'configured'),
				$service->report(key: 'cti', status: 'configured', message: 'Asterisk'),
				$service->report(key: 'cti', status: 'error'),
				$service->report(key: 'cti', status: 'limited', message: 'boom'),
			]
		);
		$this->assertSame(
			[
				['warning', 'Pipelinq: refusing to report an undeclared connection key', ['key' => 'kvk']],
				['warning', 'Pipelinq: refusing to report an unknown connection status', ['key' => 'cti', 'status' => 'ok']],
				['warning', 'Pipelinq: refusing to report an undeclared connection key', ['key' => 'nope']],
				['sent', 'pipelinq', 'cti', 'configured', 'Asterisk'],
				['sent', 'pipelinq', 'cti', 'error', ''],
				['sent', 'pipelinq', 'cti', 'limited', 'boom'],
				['warning', 'Pipelinq: could not send a connection report to integriq', ['key' => 'cti', 'exception' => 'listener broke']],
			],
			$calls
		);
	}//end testEveryOutcome()
}//end class
