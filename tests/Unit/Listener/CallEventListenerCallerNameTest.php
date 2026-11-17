<?php

/**
 * How CallEventListener names a caller from the integriq call event.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\Integriq\Event\CallEvent;
use OCA\Pipelinq\Listener\CallEventListener;
use OCA\Pipelinq\Service\Calls\CallMirror;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/../../Stubs/Integriq/Event/CallEvent.php';

/**
 * A known caller whose name fields are all blank is mirrored without a name,
 * and a later name key is used when the earlier ones are blank or not strings.
 *
 * @covers \OCA\Pipelinq\Listener\CallEventListener
 */
class CallEventListenerCallerNameTest extends TestCase {

	/**
	 * The caller name the mirror receives for a caller block.
	 *
	 * @param array<string, mixed> $caller The caller block.
	 *
	 * @return string The mirrored caller name.
	 */
	private function mirroredName(array $caller): string {
		$name   = null;
		$mirror = $this->createMock(CallMirror::class);
		$mirror->expects($this->once())->method('mirror')->willReturnCallback(
			function (array $call) use (&$name): string {
				$name = $call['callerName'];
				return 'row-1';
			}
		);

		(new CallEventListener($mirror, $this->createMock(LoggerInterface::class)))->handle(
			new CallEvent(kind: 'ringing', callId: '7', callerNumber: '+31201234567', caller: $caller, openCases: [], agentId: '', sourceId: 'source-1', at: '', durationSeconds: 0)
		);

		return $name;
	}//end mirroredName()

	/**
	 * A caller whose name fields are blank or missing has no name.
	 *
	 * @return void
	 */
	public function testACallerWithOnlyBlankNamesHasNoName(): void {
		$this->assertSame('', $this->mirroredName(caller: ['naam' => '  ', 'name' => '', 'partijIdentificatoren' => []]));
	}//end testACallerWithOnlyBlankNamesHasNoName()

	/**
	 * A non-string or blank earlier key falls through to displayName, trimmed.
	 *
	 * @return void
	 */
	public function testTheFirstNonBlankNameKeyWins(): void {
		$this->assertSame('Team Noord', $this->mirroredName(caller: ['naam' => 42, 'volledigeNaam' => ' ', 'displayName' => ' Team Noord ']));
	}//end testTheFirstNonBlankNameKeyWins()
}//end class
