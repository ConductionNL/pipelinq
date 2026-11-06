<?php

/**
 * Tests for TicketPartyController: a host app's link to one party's tickets.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://pipelinq.nl
 * @spec openspec/changes/tickets-list-by-party/specs/ticket-leaf/spec.md#requirement-a-host-app-can-link-to-one-partys-ticket-list
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\TicketPartyController;
use OCA\Pipelinq\Integration\TicketLeafProvider;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * The party ticket link redirects to the ticket list filtered on the party's clients.
 */
class TicketPartyControllerTest extends TestCase {
	/**
	 * The controller with a provider that knows the given clients.
	 *
	 * @param array<int, string> $clients The client ids of the asked party.
	 *
	 * @return TicketPartyController The controller.
	 */
	private function controller(array $clients): TicketPartyController {
		$provider = $this->createMock(originalClassName: TicketLeafProvider::class);
		$provider->method('clientIdsOf')->willReturn($clients);
		$urls = $this->createMock(originalClassName: IURLGenerator::class);
		$urls->method('linkToRoute')->willReturnCallback(
			static fn (string $route, array $params = []): string => '/index.php/apps/pipelinq/'.($params['path'] ?? '')
		);

		return new TicketPartyController(
			request: $this->createMock(originalClassName: IRequest::class),
			provider: $provider,
			urlGenerator: $urls
		);
	}//end controller()

	/**
	 * One client: the ticket list on that client.
	 *
	 * @return void
	 */
	public function testOneClientOpensTheListOnThatClient(): void {
		$response = $this->controller(clients: ['c-1'])->tickets(ref: 'm-1');

		$this->assertInstanceOf(expected: RedirectResponse::class, actual: $response);
		$this->assertSame(expected: '/index.php/apps/pipelinq/tickets?client=c-1', actual: $response->getRedirectURL());
	}//end testOneClientOpensTheListOnThatClient()

	/**
	 * Several clients of one party: the list on all of them.
	 *
	 * @return void
	 */
	public function testSeveralClientsOpenTheListOnAllOfThem(): void {
		$response = $this->controller(clients: ['c-1', 'c-2'])->tickets(ref: 'm-1');

		$this->assertSame(expected: '/index.php/apps/pipelinq/tickets?client%5B0%5D=c-1&client%5B1%5D=c-2', actual: $response->getRedirectURL());
	}//end testSeveralClientsOpenTheListOnAllOfThem()

	/**
	 * A party pipelinq does not know (or may not show): the list without a filter.
	 *
	 * @return void
	 */
	public function testAnUnknownPartyOpensTheWholeList(): void {
		$response = $this->controller(clients: [])->tickets(ref: 'm-unknown');

		$this->assertSame(expected: '/index.php/apps/pipelinq/tickets', actual: $response->getRedirectURL());
	}//end testAnUnknownPartyOpensTheWholeList()
}//end class
