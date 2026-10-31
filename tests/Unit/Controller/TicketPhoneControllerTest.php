<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/contact-moments-phone-lookup/specs/contact-history-by-phone/spec.md#requirement-a-ticket-is-found-by-phone-number-however-it-was-typed-req-chp-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\TicketPhoneController;
use OCA\Pipelinq\Service\PhoneHistoryService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Pipelinq\Controller\TicketPhoneController
 */
class TicketPhoneControllerTest extends TestCase {
	/**
	 * The endpoint answers what the phone search found for the typed number.
	 *
	 * @return void
	 */
	public function testItAnswersThePhoneSearch(): void {
		$found = ['number' => '+31612345678', 'results' => [['id' => 't-1']]];
		$history = $this->createMock(PhoneHistoryService::class);
		$history->expects($this->once())->method('findTickets')->with('06 1234 5678')->willReturn($found);

		$response = (new TicketPhoneController($this->createMock(IRequest::class), $history))->byPhone(number: '06 1234 5678');

		$this->assertSame($found, $response->getData());
	}//end testItAnswersThePhoneSearch()
}//end class
