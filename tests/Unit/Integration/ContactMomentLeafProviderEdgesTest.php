<?php

/**
 * ContactMomentLeafProvider: indicators, blocks and the ticket fields.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Integration
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Integration;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Integration\ContactMomentLeafProvider;
use OCA\Pipelinq\Service\ContactMomentFilingService;
use OCA\Pipelinq\Service\PartyIndicatorService;
use OCA\Pipelinq\Service\TicketService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The listing carries the party's indicators; an outbound moment to an
 * unblocked party is filed with its outcome, summary and given time.
 *
 * @covers \OCA\Pipelinq\Integration\ContactMomentLeafProvider
 */
class ContactMomentLeafProviderEdgesTest extends TestCase {

	/**
	 * Ticket service mock.
	 *
	 * @var TicketService&MockObject
	 */
	private TicketService&MockObject $tickets;

	/**
	 * Indicator service mock.
	 *
	 * @var PartyIndicatorService&MockObject
	 */
	private PartyIndicatorService&MockObject $indicators;

	/**
	 * Build the mocks; every host is readable.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturn($this->createMock(ObjectEntityInterface::class));
		$this->tickets = $this->createMock(TicketService::class);
		$this->tickets->method('getObjectService')->willReturn($objects);
		$this->indicators = $this->createMock(PartyIndicatorService::class);
	}//end setUp()

	/**
	 * The provider under test.
	 *
	 * @return ContactMomentLeafProvider
	 */
	private function provider(): ContactMomentLeafProvider {
		$filing = $this->createMock(ContactMomentFilingService::class);
		$filing->method('sharedMarker')->willReturn(['shared' => false, 'alsoOnCases' => [], 'hiddenCount' => 0]);
		$filing->method('primaryOf')->willReturnCallback(static fn (array $moment): string => (string)($moment['primaryCaseReference'] ?? ''));
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('agent-1');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new ContactMomentLeafProvider($this->tickets, $filing, $this->indicators, $session, $this->createMock(LoggerInterface::class));
	}//end provider()

	/**
	 * A listing for a party carries that party's resolved indicators.
	 *
	 * @return void
	 */
	public function testAListingCarriesThePartysIndicators(): void {
		$this->tickets->method('findByType')->willReturn([]);
		$this->indicators->expects($this->once())->method('resolve')->with('p-1')->willReturn([['label' => 'Agressie']]);

		$answer = $this->provider()->list(hostId: 'case-1', partyId: ' p-1 ');

		$this->assertSame(['status' => 200, 'contactMoments' => [], 'indicators' => [['label' => 'Agressie']]], $answer);
	}//end testAListingCarriesThePartysIndicators()

	/**
	 * An outbound moment to an unblocked party is saved with the given
	 * time, outcome and summary as description, and the party as client.
	 *
	 * @return void
	 */
	public function testAnOutboundMomentToAnUnblockedPartyIsFiled(): void {
		$this->indicators->expects($this->once())->method('isBlocked')->with('p-1', 'send')->willReturn(['blocked' => false, 'indicators' => []]);
		$saved = null;
		$this->tickets->expects($this->once())->method('save')->willReturnCallback(
			function (string $ticketType, array $payload) use (&$saved): object {
				$this->assertSame(TicketService::TYPE_CONTACTMOMENT, $ticketType);
				$saved = $payload;
				return (object)['ok' => true];
			}
		);

		$answer = $this->provider()->create(
			hostId: 'case-1',
			payload: ['partyId' => 'p-1', 'direction' => 'outbound', 'subject' => 'Call back', 'channel' => 'phone', 'outcome' => ' reached ', 'summary' => 'Agreed a visit', 'occurredAt' => ' 2026-03-01T10:00:00+00:00 ']
		);

		$this->assertSame(201, $answer['status']);
		$this->assertSame(
			[
				'title' => 'Call back',
				'channel' => 'phone',
				'direction' => 'outbound',
				'caseReference' => 'case-1',
				'caseReferences' => ['case-1'],
				'primaryCaseReference' => 'case-1',
				'assignee' => 'agent-1',
				'occurredAt' => '2026-03-01T10:00:00+00:00',
				'outcome' => 'reached',
				'description' => 'Agreed a visit',
				'client' => 'p-1',
			],
			$saved
		);
	}//end testAnOutboundMomentToAnUnblockedPartyIsFiled()
}//end class
