<?php

/**
 * Unit tests for ContactMomentLeafProvider.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Integration;

use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Integration\ContactMomentLeafProvider;
use OCA\Pipelinq\Service\ContactMomentFilingService;
use OCA\Pipelinq\Service\TicketService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the contact moments leaf.
 */
class ContactMomentLeafProviderTest extends TestCase {
	/**
	 * The ticket service double.
	 *
	 * @var TicketService&MockObject
	 */
	private $ticketService;

	/**
	 * The object service double, standing in for OpenRegister.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private $objectService;

	/**
	 * Build the doubles shared by every test.
	 *
	 * `onlyMethods` throughout: a double that adds a method the real class
	 * lacks produces a green test over a call that 500s in production.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = $this->createMock(ObjectServiceInterface::class);

		$this->ticketService = $this->getMockBuilder(TicketService::class)
			->disableOriginalConstructor()
			->onlyMethods(['getObjectService', 'findByType', 'save'])
			->getMock();
		$this->ticketService->method('getObjectService')->willReturn($this->objectService);
	}//end setUp()

	/**
	 * Build the provider over the shared doubles.
	 *
	 * @param string $userId The acting user, or '' for no session.
	 *
	 * @return ContactMomentLeafProvider The provider.
	 */
	private function provider(string $userId = 'maria'): ContactMomentLeafProvider {
		$session = $this->createMock(IUserSession::class);

		if ($userId === '') {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($userId);
			$session->method('getUser')->willReturn($user);
		}

		// The filing service is REAL, not a double: the leaf's rows carry the
		// shared marker it computes, and a double would let the provider claim
		// a marker the real service never produces.
		$filingService = new ContactMomentFilingService(
			$this->ticketService,
			$session,
			$this->createMock(LoggerInterface::class),
		);

		return new ContactMomentLeafProvider(
			$this->ticketService,
			$filingService,
			$session,
			$this->createMock(LoggerInterface::class),
		);
	}//end provider()

	/**
	 * Make the host resolve, or not.
	 *
	 * @param bool $readable Whether the acting user may read the host.
	 *
	 * @return void
	 */
	private function hostIsReadable(bool $readable): void {
		$this->objectService->method('find')->willReturn(
			$readable === true ? $this->createMock(ObjectEntityInterface::class) : null
		);
	}//end hostIsReadable()

	/**
	 * A handler logs a call on a case, and the leaf lists it back.
	 *
	 * @return void
	 */
	public function testAHandlerLogsACallOnACase(): void {
		$this->hostIsReadable(true);

		$written = [];
		$this->ticketService->method('save')->willReturnCallback(
			function (string $ticketType, array $payload) use (&$written): object {
				$written = $payload + ['ticketType' => $ticketType, 'id' => 'cm-1'];

				return new class ($written) implements \JsonSerializable {
					/**
					 * @param array<string, mixed> $data The saved payload.
					 */
					public function __construct(private array $data) {
					}

					/**
					 * @return array<string, mixed> The saved payload.
					 */
					public function jsonSerialize(): array {
						return $this->data;
					}
				};
			}
		);

		$result = $this->provider()->create(
			hostId: 'case-42',
			payload: ['title' => 'Gebeld over de aanvraag', 'channel' => 'telefoon', 'direction' => 'inbound'],
		);

		$this->assertSame(201, $result['status']);
		$this->assertSame('case-42', $written['caseReference'], 'The host is written as the case reference.');
		$this->assertSame(['case-42'], $written['caseReferences'], 'A new contact moment starts as a one-element set.');
		$this->assertSame('case-42', $written['primaryCaseReference']);
		$this->assertSame('maria', $written['assignee'], 'The caller is stamped as the agent.');
		$this->assertSame('inbound', $written['direction']);
		$this->assertSame(TicketService::TYPE_CONTACTMOMENT, $written['ticketType']);
	}//end testAHandlerLogsACallOnACase()

	/**
	 * A caller who may not read the case is refused, and nothing is written.
	 *
	 * The least privileged principal that should be refused: a signed-in user
	 * for whom the host object does not resolve.
	 *
	 * @return void
	 */
	public function testACallerWithoutReadOnTheCaseIsRefused(): void {
		$this->hostIsReadable(false);
		$this->ticketService->expects($this->never())->method('save');

		$result = $this->provider(userId: 'nobody')->create(
			hostId: 'case-42',
			payload: ['title' => 'Gebeld', 'channel' => 'telefoon', 'direction' => 'inbound'],
		);

		$this->assertSame(403, $result['status']);
		$this->assertArrayNotHasKey('contactMoment', $result);
	}//end testACallerWithoutReadOnTheCaseIsRefused()

	/**
	 * The same refusal guards the read.
	 *
	 * @return void
	 */
	public function testAListForAnUnreadableHostIsRefused(): void {
		$this->hostIsReadable(false);
		$this->ticketService->expects($this->never())->method('findByType');

		$result = $this->provider(userId: 'nobody')->list(hostId: 'case-42');

		$this->assertSame(403, $result['status']);
	}//end testAListForAnUnreadableHostIsRefused()

	/**
	 * A create without a direction is refused, naming the field.
	 *
	 * The refusal is the TicketService facet guard, surfaced as a 400 rather
	 * than an exception the host app has to interpret.
	 *
	 * @return void
	 */
	public function testACreateWithoutADirectionIsRefused(): void {
		$this->hostIsReadable(true);
		$this->ticketService->method('save')->willThrowException(
			new InvalidArgumentException('A interaction ticket requires direction.')
		);

		$result = $this->provider()->create(
			hostId: 'case-42',
			payload: ['title' => 'Gebeld', 'channel' => 'telefoon'],
		);

		$this->assertSame(400, $result['status']);
		$this->assertStringContainsString('direction', $result['error']);
	}//end testACreateWithoutADirectionIsRefused()

	/**
	 * The list answers the host's moments, newest first.
	 *
	 * @return void
	 */
	public function testTheListIsNewestFirst(): void {
		$this->hostIsReadable(true);

		$this->ticketService->method('findByType')->willReturn(
			[
				['id' => 'old', 'title' => 'Eerste', 'occurredAt' => '2026-01-01T10:00:00+00:00', 'direction' => 'inbound'],
				['id' => 'new', 'title' => 'Tweede', 'occurredAt' => '2026-09-01T10:00:00+00:00', 'direction' => 'outbound'],
			]
		);

		$result = $this->provider()->list(hostId: 'case-42');

		$this->assertSame(200, $result['status']);
		$this->assertSame(['new', 'old'], array_column($result['contactMoments'], 'id'));
		$this->assertSame('outbound', $result['contactMoments'][0]['direction']);
		$this->assertSame('Tweede', $result['contactMoments'][0]['subject']);
	}//end testTheListIsNewestFirst()

	/**
	 * The page size is bounded, per ADR-058.
	 *
	 * @return void
	 */
	public function testThePageSizeIsBounded(): void {
		$this->hostIsReadable(true);

		$asked = null;
		$this->ticketService->method('findByType')->willReturnCallback(
			static function (string $type, array $filters, int $limit) use (&$asked): array {
				$asked = $limit;

				return [];
			}
		);

		$this->provider()->list(hostId: 'case-42', limit: 100000);

		$this->assertSame(ContactMomentLeafProvider::MAX_LIMIT, $asked);
	}//end testThePageSizeIsBounded()

	/**
	 * The host filter is membership, not equality.
	 *
	 * One contact moment filed on three cases has to appear on each of the
	 * three. Filtering on the single `caseReference` shows it only on the
	 * primary, and an absent row on the other two looks exactly like a case
	 * with no contact moments.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-contact-moment-on-several-cases/specs/contactmomenten/spec.md#requirement-a-case-lists-the-contact-moments-it-is-a-member-of-req-cms-003
	 */
	public function testTheHostFilterIsMembership(): void {
		$this->hostIsReadable(true);

		$filters = [];
		$this->ticketService->method('findByType')->willReturnCallback(
			static function (string $type, array $given) use (&$filters): array {
				$filters = $given;

				return [];
			}
		);

		$this->provider()->list(hostId: 'case-b');

		$this->assertSame(['caseReferences' => 'case-b'], $filters);
		$this->assertArrayNotHasKey('caseReference', $filters);
	}//end testTheHostFilterIsMembership()

	/**
	 * A row on three cases is listed once per case, with the others named.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-contact-moment-on-several-cases/specs/contactmomenten/spec.md#requirement-a-shared-contact-moment-says-so-before-it-is-edited-req-cms-005
	 */
	public function testASharedRowNamesTheOtherCases(): void {
		$this->hostIsReadable(true);
		$this->ticketService->method('findByType')->willReturn(
			[
				[
					'id' => 'cm-1',
					'title' => 'Een telefoontje over drie zaken',
					'occurredAt' => '2026-09-01T10:00:00+00:00',
					'caseReferences' => ['case-a', 'case-b', 'case-c'],
					'primaryCaseReference' => 'case-a',
				],
			]
		);

		$result = $this->provider()->list(hostId: 'case-b');

		$row = $result['contactMoments'][0];
		$this->assertCount(1, $result['contactMoments'], 'One record, not one per case.');
		$this->assertTrue($row['shared']);
		$this->assertSame(['case-a', 'case-c'], $row['alsoOnCases'], 'The host itself is not one of the others.');
		$this->assertSame('case-a', $row['primaryCase']);
	}//end testASharedRowNamesTheOtherCases()
}//end class
