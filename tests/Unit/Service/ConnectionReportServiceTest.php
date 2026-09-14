<?php

/**
 * ConnectionReportService unit tests.
 *
 * The service tells integriq's connection registry what a pipelinq check
 * observed. Every test here guards one way it could quietly stop doing that:
 * sending the wrong app or key, sending a status integriq would drop, turning
 * a working check into a 500 because a listener threw, logging a fault when
 * integriq is simply not installed, or reading a passing CTI check as more than
 * it proves.
 *
 * @category Tests
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md#requirement-req-as-132-pipelinq-reports-what-its-own-checks-observe
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Integriq\Event\ConnectionStatusReportedEvent;
use OCA\Pipelinq\Service\ConnectionReportService;
use OCA\Pipelinq\Service\Social\SocialBrokerGateway;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

require_once __DIR__ . '/../../Stubs/Integriq/Event/ConnectionStatusReportedEvent.php';

/**
 * Unit tests for ConnectionReportService.
 *
 * @covers \OCA\Pipelinq\Service\ConnectionReportService
 */
class ConnectionReportServiceTest extends TestCase {

	/**
	 * Mocked event dispatcher.
	 *
	 * @var IEventDispatcher&MockObject
	 */
	private IEventDispatcher $dispatcher;

	/**
	 * Mocked logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Every event handed to the dispatcher.
	 *
	 * @var array<int, Event>
	 */
	private array $sent = [];

	/**
	 * Set up the fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$this->logger     = $this->createMock(originalClassName: LoggerInterface::class);
		$this->sent       = [];
		$this->dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				$this->sent[] = $event;
			}
		);
	}//end setUp()

	/**
	 * The service as production builds it.
	 *
	 * @return ConnectionReportService
	 */
	private function service(): ConnectionReportService {
		return new ConnectionReportService(eventDispatcher: $this->dispatcher, logger: $this->logger);
	}//end service()

	/**
	 * The service as it behaves on an instance without integriq.
	 *
	 * Only the class lookup is replaced. The stub makes the event class
	 * resolvable in this process, so absence is simulated at the one seam that
	 * asks.
	 *
	 * @return ConnectionReportService
	 */
	private function serviceWithoutIntegriq(): ConnectionReportService {
		return new class($this->dispatcher, $this->logger) extends ConnectionReportService {

			/**
			 * Integriq is not installed, so no class resolves.
			 *
			 * @param string $eventClass The class name asked for.
			 *
			 * @return string|null Always null.
			 */
			protected function resolveEventClass(string $eventClass): ?string {
				return null;
			}//end resolveEventClass()
		};
	}//end serviceWithoutIntegriq()

	/**
	 * The one event sent, typed.
	 *
	 * @return ConnectionStatusReportedEvent
	 */
	private function onlyEvent(): ConnectionStatusReportedEvent {
		$this->assertCount(expectedCount: 1, haystack: $this->sent);
		$event = $this->sent[0];
		$this->assertInstanceOf(expected: ConnectionStatusReportedEvent::class, actual: $event);

		return $event;
	}//end onlyEvent()

	/**
	 * A report reaches integriq as one event with pipelinq's id and the words given.
	 *
	 * @return void
	 */
	public function testAReportIsSentWithTheAppKeyStatusAndMessage(): void {
		$this->assertTrue(condition: $this->service()->report(key: 'berichtenbox', status: 'error', message: 'Refused'));

		$event = $this->onlyEvent();
		$this->assertSame(expected: 'pipelinq', actual: $event->app);
		$this->assertSame(expected: 'berichtenbox', actual: $event->key);
		$this->assertSame(expected: 'error', actual: $event->status);
		$this->assertSame(expected: 'Refused', actual: $event->message);
	}//end testAReportIsSentWithTheAppKeyStatusAndMessage()

	/**
	 * The event name is the one the contract fixes, and it resolves here.
	 *
	 * A string class name is exactly the reference that rots into a silent
	 * no-op after a rename, so it is compared to the stub's real name.
	 *
	 * @return void
	 */
	public function testTheEventNameIsTheContractName(): void {
		$this->assertSame(expected: ConnectionStatusReportedEvent::class, actual: ConnectionReportService::STATUS_EVENT);
	}//end testTheEventNameIsTheContractName()

	/**
	 * The class lookup answers null for a class nobody ships.
	 *
	 * This is the real guard, not the test double: an instance without
	 * integriq has no class, and the lookup must say so instead of throwing.
	 *
	 * @return void
	 */
	public function testTheLookupAnswersNullForAnAbsentClass(): void {
		$method = new ReflectionMethod(ConnectionReportService::class, 'resolveEventClass');

		$this->assertNull(actual: $method->invoke($this->service(), 'OCA\\Nobody\\Event\\ShipsThisEvent'));
		$this->assertSame(
			expected: '\\' . ConnectionReportService::STATUS_EVENT,
			actual: $method->invoke($this->service(), ConnectionReportService::STATUS_EVENT)
		);
	}//end testTheLookupAnswersNullForAnAbsentClass()

	/**
	 * Without integriq nothing is sent, nothing is logged and nothing throws.
	 *
	 * @return void
	 */
	public function testWithoutIntegriqNothingIsSentOrLogged(): void {
		$this->dispatcher->expects($this->never())->method('dispatchTyped');
		$this->logger->expects($this->never())->method('warning');

		$service = $this->serviceWithoutIntegriq();
		$this->assertFalse(condition: $service->report(key: 'cti', status: 'configured'));
		$this->assertFalse(condition: $service->reportCtiCheck(outcome: ['ok' => true, 'platform' => 'asterisk']));
		$this->assertSame(
			expected: [],
			actual: $service->reportSocialReadiness(readiness: ['mastodon' => ['state' => 'ready', 'reason' => '']])
		);
	}//end testWithoutIntegriqNothingIsSentOrLogged()

	/**
	 * An undeclared key is refused with a warning and sends nothing.
	 *
	 * @return void
	 */
	public function testAnUndeclaredKeyIsRefused(): void {
		$this->logger->expects($this->once())->method('warning');

		$this->assertFalse(condition: $this->service()->report(key: 'kvk', status: 'configured'));
		$this->assertSame(expected: [], actual: $this->sent);
	}//end testAnUndeclaredKeyIsRefused()

	/**
	 * A status outside the five is refused with a warning and sends nothing.
	 *
	 * @return void
	 */
	public function testAnUnknownStatusIsRefused(): void {
		$this->logger->expects($this->once())->method('warning');

		$this->assertFalse(condition: $this->service()->report(key: 'cti', status: 'ok'));
		$this->assertSame(expected: [], actual: $this->sent);
	}//end testAnUnknownStatusIsRefused()

	/**
	 * A listener that throws is caught and logged, never passed to the caller.
	 *
	 * @return void
	 */
	public function testAThrowingListenerIsCaughtAndLogged(): void {
		$dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willThrowException(new RuntimeException('listener broke'));
		$this->logger->expects($this->once())->method('warning')->with(
			$this->stringContains(string: 'could not send'),
			$this->callback(callback: static fn (array $context): bool => ($context['key'] ?? '') === 'social-x')
		);

		$service = new ConnectionReportService(eventDispatcher: $dispatcher, logger: $this->logger);

		$this->assertFalse(condition: $service->report(key: 'social-x', status: 'configured'));
	}//end testAThrowingListenerIsCaughtAndLogged()

	/**
	 * A passing CTI check reads configured, and says it made no call.
	 *
	 * @return void
	 */
	public function testAPassingCtiCheckReadsConfiguredAndSaysItMadeNoCall(): void {
		$this->service()->reportCtiCheck(outcome: ['ok' => true, 'platform' => 'asterisk', 'message' => 'Adapter resolved']);

		$event = $this->onlyEvent();
		$this->assertSame(expected: 'cti', actual: $event->key);
		$this->assertSame(expected: 'configured', actual: $event->status);
		$this->assertStringContainsString(needle: 'asterisk', haystack: $event->message);
		$this->assertStringContainsString(needle: 'no call', haystack: $event->message);
	}//end testAPassingCtiCheckReadsConfiguredAndSaysItMadeNoCall()

	/**
	 * No platform chosen reads unconfigured, not error.
	 *
	 * @return void
	 */
	public function testNoCtiPlatformReadsUnconfigured(): void {
		$this->service()->reportCtiCheck(outcome: ['ok' => false, 'platform' => '', 'message' => 'No CTI platform configured.']);

		$this->assertSame(expected: 'unconfigured', actual: $this->onlyEvent()->status);
	}//end testNoCtiPlatformReadsUnconfigured()

	/**
	 * An adapter that does not load reads error, with the reason.
	 *
	 * @return void
	 */
	public function testAnAdapterThatDoesNotLoadReadsErrorWithTheReason(): void {
		$this->service()->reportCtiCheck(
			outcome: ['ok' => false, 'platform' => 'teams', 'message' => 'CTI adapter not registered for platform: teams']
		);

		$event = $this->onlyEvent();
		$this->assertSame(expected: 'error', actual: $event->status);
		$this->assertStringContainsString(needle: 'not registered for platform: teams', haystack: $event->message);
	}//end testAnAdapterThatDoesNotLoadReadsErrorWithTheReason()

	/**
	 * Each readiness state maps onto its registry status, one report per network.
	 *
	 * @return void
	 */
	public function testSocialReadinessMapsEachStateAndSendsOneReportPerNetwork(): void {
		$sent = $this->service()->reportSocialReadiness(
			readiness: [
				'mastodon' => ['state' => SocialBrokerGateway::READY, 'reason' => ''],
				'bluesky' => ['state' => SocialBrokerGateway::PREVIEW, 'reason' => 'Still a preview.'],
				'threads' => ['state' => SocialBrokerGateway::NOT_CONFIGURED, 'reason' => 'No application filed.'],
			]
		);

		$this->assertSame(expected: ['social-mastodon', 'social-bluesky', 'social-threads'], actual: $sent);
		$byKey = [];
		foreach ($this->sent as $event) {
			$this->assertInstanceOf(expected: ConnectionStatusReportedEvent::class, actual: $event);
			$byKey[$event->key] = $event;
		}

		$this->assertSame(expected: 'configured', actual: $byKey['social-mastodon']->status);
		$this->assertNotSame(expected: '', actual: $byKey['social-mastodon']->message);
		$this->assertSame(expected: 'unavailable', actual: $byKey['social-bluesky']->status);
		$this->assertSame(expected: 'Still a preview.', actual: $byKey['social-bluesky']->message);
		$this->assertSame(expected: 'unconfigured', actual: $byKey['social-threads']->status);
		$this->assertSame(expected: 'No application filed.', actual: $byKey['social-threads']->message);
	}//end testSocialReadinessMapsEachStateAndSendsOneReportPerNetwork()

	/**
	 * A network nobody declared, or a state nobody maps, sends nothing.
	 *
	 * @return void
	 */
	public function testAnUndeclaredNetworkOrUnknownStateIsSkipped(): void {
		$sent = $this->service()->reportSocialReadiness(
			readiness: [
				'myspace' => ['state' => SocialBrokerGateway::READY, 'reason' => ''],
				'x' => ['state' => 'degraded', 'reason' => ''],
				'linkedin' => 'ready',
			]
		);

		$this->assertSame(expected: [], actual: $sent);
		$this->assertSame(expected: [], actual: $this->sent);
	}//end testAnUndeclaredNetworkOrUnknownStateIsSkipped()

	/**
	 * Every network the social registry knows has a declared key.
	 *
	 * A network added to the registry without a row would be checked and
	 * silently never reported.
	 *
	 * @return void
	 */
	public function testEverySocialNetworkHasADeclaredKey(): void {
		foreach (\OCA\Pipelinq\Service\Social\SocialAdapterRegistry::NETWORKS as $network) {
			$this->assertContains(needle: 'social-' . $network, haystack: ConnectionReportService::KEYS);
		}
	}//end testEverySocialNetworkHasADeclaredKey()
}//end class
