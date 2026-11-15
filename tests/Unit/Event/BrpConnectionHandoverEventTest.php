<?php

/**
 * Unit tests for BrpConnectionHandoverEvent.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Event
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/brp-monitor-moves-to-integriq/specs/brp-lookup/spec.md#requirement-the-brp-connection-settings-move-to-integriq-once
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Event;

use InvalidArgumentException;
use OCA\Pipelinq\Event\BrpConnectionHandoverEvent;
use PHPUnit\Framework\TestCase;

/**
 * The handover carries the parts, takes integriq's answers and says when pipelinq may clear its keys.
 */
class BrpConnectionHandoverEventTest extends TestCase {

	/**
	 * A handover of two settings and one secret.
	 *
	 * @return BrpConnectionHandoverEvent The event.
	 */
	private function handover(): BrpConnectionHandoverEvent {
		return new BrpConnectionHandoverEvent(
			settings: ['baseUrl' => 'https://brp.example/haalcentraal/api/brp', 'clientId' => 'pipelinq'],
			secrets: ['certificate' => "-----BEGIN CERTIFICATE-----\nAAA\n-----END CERTIFICATE-----\n"]
		);
	}//end handover()

	/**
	 * The settings, secrets and part names come back as handed over, settings first.
	 *
	 * @return void
	 */
	public function testCarriesTheHandedParts(): void {
		$event = $this->handover();

		self::assertSame(['baseUrl' => 'https://brp.example/haalcentraal/api/brp', 'clientId' => 'pipelinq'], $event->getSettings());
		self::assertSame(['certificate'], array_keys($event->getSecrets()));
		self::assertSame(['baseUrl', 'clientId', 'certificate'], $event->getParts());
		self::assertNull($event->getAnswer('baseUrl'));
	}//end testCarriesTheHandedParts()

	/**
	 * An unknown setting or secret is refused at construction.
	 *
	 * @return void
	 */
	public function testRefusesAnUnknownPart(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown BRP connection part password');

		new BrpConnectionHandoverEvent(settings: [], secrets: ['password' => 'x']);
	}//end testRefusesAnUnknownPart()

	/**
	 * A secret name in the settings is refused too: the two lists do not mix.
	 *
	 * @return void
	 */
	public function testRefusesASecretAmongTheSettings(): void {
		$this->expectException(InvalidArgumentException::class);

		new BrpConnectionHandoverEvent(settings: ['clientSecret' => 'x'], secrets: []);
	}//end testRefusesASecretAmongTheSettings()

	/**
	 * Every part taken or kept confirms the handover; a later answer replaces an earlier one.
	 *
	 * @return void
	 */
	public function testConfirmsWhenEveryPartIsTakenOrKept(): void {
		$event = $this->handover();
		$event->answer('baseUrl', BrpConnectionHandoverEvent::KEPT, 'integriq has another base URL');
		$event->answer('clientId', BrpConnectionHandoverEvent::FAILED);
		$event->answer('certificate', BrpConnectionHandoverEvent::TAKEN);
		self::assertFalse($event->isConfirmed());

		$event->answer('clientId', BrpConnectionHandoverEvent::TAKEN);

		self::assertTrue($event->isConfirmed());
		self::assertSame(['outcome' => 'kept', 'detail' => 'integriq has another base URL'], $event->getAnswer('baseUrl'));
		self::assertSame(['outcome' => 'taken', 'detail' => ''], $event->getAnswer('clientId'));
	}//end testConfirmsWhenEveryPartIsTakenOrKept()

	/**
	 * A part without an answer, or a handover of nothing, is not confirmed.
	 *
	 * @return void
	 */
	public function testIsNotConfirmedWithoutEveryAnswer(): void {
		$event = $this->handover();
		$event->answer('baseUrl', BrpConnectionHandoverEvent::TAKEN);
		$event->answer('clientId', BrpConnectionHandoverEvent::TAKEN);

		self::assertFalse($event->isConfirmed());
		self::assertFalse((new BrpConnectionHandoverEvent(settings: [], secrets: []))->isConfirmed());
	}//end testIsNotConfirmedWithoutEveryAnswer()

	/**
	 * An answer for a part that was not handed over is refused.
	 *
	 * @return void
	 */
	public function testRefusesAnAnswerForAPartNotHandedOver(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Part privateKey was not handed over');

		$this->handover()->answer('privateKey', BrpConnectionHandoverEvent::TAKEN);
	}//end testRefusesAnAnswerForAPartNotHandedOver()

	/**
	 * An unknown outcome is refused.
	 *
	 * @return void
	 */
	public function testRefusesAnUnknownOutcome(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown outcome maybe');

		$this->handover()->answer('baseUrl', 'maybe');
	}//end testRefusesAnUnknownOutcome()

	/**
	 * A dump of the event shows parts and answers, never the secret material.
	 *
	 * @return void
	 */
	public function testADumpNeverShowsTheSecrets(): void {
		$event = $this->handover();
		$event->answer('certificate', BrpConnectionHandoverEvent::TAKEN);

		$dump = print_r($event, true);

		self::assertStringNotContainsString('BEGIN CERTIFICATE', $dump);
		self::assertStringNotContainsString('brp.example', $dump);
		self::assertSame(
			['parts' => ['baseUrl', 'clientId', 'certificate'], 'answers' => ['certificate' => ['outcome' => 'taken', 'detail' => '']]],
			$event->__debugInfo()
		);
	}//end testADumpNeverShowsTheSecrets()
}//end class
