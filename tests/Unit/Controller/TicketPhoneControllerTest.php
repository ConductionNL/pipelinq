<?php

/**
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\TicketPhoneController;
use OCA\Pipelinq\Service\PhoneHistoryService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * GET /api/tickets/by-phone answers the tickets about a number.
 *
 * @covers \OCA\Pipelinq\Controller\TicketPhoneController
 *
 * @spec openspec/changes/contact-moments-phone-lookup/tasks.md#task-1.4
 */
class TicketPhoneControllerTest extends TestCase {

	/**
	 * The controller over a phone search double and an optional signed-in user.
	 *
	 * @param PhoneHistoryService $history  The phone search.
	 * @param bool                $signedIn Whether a user is signed in.
	 *
	 * @return TicketPhoneController
	 */
	private function controller(PhoneHistoryService $history, bool $signedIn = true): TicketPhoneController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $this->createMock(IUser::class) : null);

		return new TicketPhoneController($this->createMock(IRequest::class), $history, $session);
	}//end controller()

	/**
	 * The number is handed to the search as typed, and its answer comes back with a count.
	 *
	 * @return void
	 */
	public function testTheTicketsAboutTheNumberAreAnswered(): void {
		$history = $this->createMock(PhoneHistoryService::class);
		$history->expects($this->once())->method('findTickets')->with('06 1234 5678')->willReturn(
			['number' => '+31612345678', 'tickets' => [['id' => 't-1', '_phoneMatch' => 'exact']]]
		);

		$response = $this->controller($history)->byPhone('06 1234 5678');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['number' => '+31612345678', 'results' => [['id' => 't-1', '_phoneMatch' => 'exact']], 'total' => 1],
			$response->getData()
		);
	}//end testTheTicketsAboutTheNumberAreAnswered()

	/**
	 * Without a session the answer is 401 and nothing is searched.
	 *
	 * @return void
	 */
	public function testWithoutASessionNothingIsSearched(): void {
		$history = $this->createMock(PhoneHistoryService::class);
		$history->expects($this->never())->method('findTickets');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller($history, signedIn: false)->byPhone('0612345678')->getStatus());
	}//end testWithoutASessionNothingIsSearched()

	/**
	 * A blank number is a bad request.
	 *
	 * @return void
	 */
	public function testABlankNumberIsABadRequest(): void {
		$history = $this->createMock(PhoneHistoryService::class);
		$history->expects($this->never())->method('findTickets');

		$response = $this->controller($history)->byPhone('   ');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'A phone number is required'], $response->getData());
	}//end testABlankNumberIsABadRequest()
}//end class
