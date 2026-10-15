<?php

/**
 * Tests for CallEventListener, from the registration to the mirror.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\Integriq\Event\CallEvent;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Listener\CallEventListener;
use OCA\Pipelinq\Service\Calls\CallMirror;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

// Declares nothing when integriq's real class is there; needed under the bare bootstrap.
require_once __DIR__ . '/../../Stubs/Integriq/Event/CallEvent.php';

/**
 * Integriq's call event reaches the call mirror.
 *
 * The event here has the constructor integriq development declares and is
 * built the way `CtiEventIntake::dispatchFor()` builds it. The first test is
 * the wiring: a listener nobody registers has a green suite and never runs.
 *
 * @covers \OCA\Pipelinq\Listener\CallEventListener
 * @uses   \OCA\Pipelinq\AppInfo\Application
 * @uses   \OCA\Pipelinq\AppInfo\OpenRegisterAutoloader
 *
 * @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-a-call-event-is-mirrored-and-resolved-once
 */
class CallEventListenerTest extends TestCase {
	/**
	 * The application registers the listener for integriq's event class.
	 *
	 * @return void
	 */
	public function testApplicationRegistersTheListenerForIntegriqsEvent(): void {
		$this->assertSame('OCA\Integriq\Event\CallEvent', CallEventListener::EVENT);
		$this->assertSame(CallEvent::class, CallEventListener::EVENT);

		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			function (string $event, string $listener, int $priority = 0) use (&$registered): void {
				$registered[] = [$event, $listener];
			}
		);

		$app = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
		$app->register($context);

		$this->assertContains([CallEventListener::EVENT, CallEventListener::class], $registered);
	}//end testApplicationRegistersTheListenerForIntegriqsEvent()

	/**
	 * A ringing event is handed to the mirror field for field, with the party's name and not the party.
	 *
	 * @return void
	 */
	public function testARingingEventIsHandedToTheMirror(): void {
		$handed = null;
		$mirror = $this->createMock(CallMirror::class);
		$mirror->expects($this->once())->method('mirror')->willReturnCallback(
			function (array $call) use (&$handed): string {
				$handed = $call;
				return 'row-1';
			}
		);

		$event = new CallEvent(
			kind: 'ringing',
			callId: '42',
			callerNumber: '+31612345678',
			caller: ['naam' => 'Sanne de Vries', 'partijIdentificatoren' => [['objectId' => '123456782']]],
			openCases: [['reference' => 'ZAAK-2026-0001']],
			agentId: 'noor',
			sourceId: 'source-1',
			at: '2026-10-12T14:00:00+02:00',
			durationSeconds: 0
		);
		(new CallEventListener($mirror, $this->createMock(LoggerInterface::class)))->handle($event);

		$this->assertSame(
			[
				'callId' => '42',
				'kind' => 'ringing',
				'sourceId' => 'source-1',
				'callerNumber' => '+31612345678',
				'callerName' => 'Sanne de Vries',
				'openCases' => [['reference' => 'ZAAK-2026-0001']],
				'agentId' => 'noor',
				'at' => '2026-10-12T14:00:00+02:00',
				'durationSeconds' => 0,
			],
			$handed
		);
		$this->assertStringNotContainsString('123456782', (string)json_encode($handed), 'An identifier of the party is not handed on.');
	}//end testARingingEventIsHandedToTheMirror()

	/**
	 * An unknown caller is mirrored with an empty name.
	 *
	 * @return void
	 */
	public function testAnUnknownCallerHasNoName(): void {
		$mirror = $this->createMock(CallMirror::class);
		$mirror->expects($this->once())->method('mirror')->with(
			$this->callback(static fn (array $call): bool => $call['callerName'] === '' && $call['kind'] === 'ended' && $call['durationSeconds'] === 95)
		);

		(new CallEventListener($mirror, $this->createMock(LoggerInterface::class)))->handle(
			new CallEvent(kind: 'ended', callId: '42', callerNumber: '', caller: null, openCases: [], agentId: '', sourceId: 'source-1', at: '', durationSeconds: 95)
		);
	}//end testAnUnknownCallerHasNoName()

	/**
	 * Another event is ignored, and a mirror that throws is logged, not passed on.
	 *
	 * @return void
	 */
	public function testAnotherEventIsIgnoredAndAFailureIsNotPassedOn(): void {
		$mirror = $this->createMock(CallMirror::class);
		$mirror->expects($this->once())->method('mirror')->willThrowException(new \RuntimeException('store down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');
		$listener = new CallEventListener($mirror, $logger);

		$listener->handle(new Event());
		$listener->handle(
			new CallEvent(kind: 'ringing', callId: '42', callerNumber: '', caller: null, openCases: [], agentId: '', sourceId: 'source-1', at: '')
		);
	}//end testAnotherEventIsIgnoredAndAFailureIsNotPassedOn()
}//end class
