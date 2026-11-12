<?php

/**
 * Unit tests for the mutable outcome of LandingPageFormSubmittedEvent.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Event;

use OCA\Pipelinq\Event\LandingPageFormSubmittedEvent;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Pipelinq\Event\LandingPageFormSubmittedEvent
 */
class LandingPageFormSubmittedEventTest extends TestCase {

	/**
	 * Build an event, optionally with a correlation id.
	 *
	 * @param string|null $correlationId The correlation id, or null to use the default.
	 *
	 * @return LandingPageFormSubmittedEvent The event.
	 */
	private function event(?string $correlationId = null): LandingPageFormSubmittedEvent {
		$args = [
			'sourceApp'         => 'portaliq',
			'formId'            => 'form-1',
			'pageId'            => 'page-1',
			'pageRoute'         => '/aanmelden',
			'portal'            => 'main',
			'externalReference' => 'ext-1',
			'values'            => ['email' => 'a@example.org'],
			'utmFirstTouch'     => ['utm_source' => 'news'],
			'utmLastTouch'      => ['utm_source' => 'ads'],
			'referrer'          => 'https://example.org',
			'submittedAt'       => '2026-11-12T10:00:00+00:00',
			'nonce'             => 'n-1',
		];
		if ($correlationId !== null) {
			$args['correlationId'] = $correlationId;
		}

		return new LandingPageFormSubmittedEvent(...$args);
	}//end event()

	/**
	 * A new event is unhandled, has no lead and an empty correlation id.
	 *
	 * @return void
	 */
	public function testStartsUnhandledWithoutLead(): void {
		$event = $this->event();

		$this->assertFalse($event->isHandled());
		$this->assertNull($event->getLeadId());
		$this->assertSame('', $event->getCorrelationId());
	}//end testStartsUnhandledWithoutLead()

	/**
	 * The listener's outcome is recorded and can be reset.
	 *
	 * @return void
	 */
	public function testRecordsTheListenerOutcome(): void {
		$event = $this->event(correlationId: 'corr-9');

		$event->setLeadId('lead-42');
		$event->setHandled(true);
		$this->assertSame('lead-42', $event->getLeadId());
		$this->assertTrue($event->isHandled());
		$this->assertSame('corr-9', $event->getCorrelationId());

		$event->setHandled(false);
		$this->assertFalse($event->isHandled());
		$this->assertSame('lead-42', $event->getLeadId());
	}//end testRecordsTheListenerOutcome()

	/**
	 * The submission itself is unchanged by recording an outcome.
	 *
	 * @return void
	 */
	public function testOutcomeDoesNotTouchTheSubmission(): void {
		$event = $this->event();
		$event->setLeadId('lead-1');
		$event->setHandled(true);

		$this->assertSame(['email' => 'a@example.org'], $event->getValues());
		$this->assertSame(['utm_source' => 'news'], $event->getUtmFirstTouch());
		$this->assertSame(['utm_source' => 'ads'], $event->getUtmLastTouch());
		$this->assertSame('n-1', $event->getNonce());
	}//end testOutcomeDoesNotTouchTheSubmission()
}//end class
