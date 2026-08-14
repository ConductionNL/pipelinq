<?php

/**
 * Contract tests for PortalRequestController.
 *
 * The reply endpoint takes a request id straight from the URL on a route that
 * carries no Nextcloud session, so the per-customer scoping is the only thing
 * between a guessed id and another customer's case history. The tests wire the
 * REAL PortalRequestService and the REAL scope resolver so that guessed id
 * really travels the production filter, and they pin the customer-safe
 * projection: internal notes must never appear in a reply response.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\PortalRequestController;
use OCA\Pipelinq\Service\Portal\MainRegisterReader;
use OCA\Pipelinq\Service\Portal\PortalAuditService;
use OCA\Pipelinq\Service\Portal\PortalDelegationService;
use OCA\Pipelinq\Service\Portal\PortalException;
use OCA\Pipelinq\Service\Portal\PortalObjectRepository;
use OCA\Pipelinq\Service\Portal\PortalRequestGuard;
use OCA\Pipelinq\Service\Portal\PortalRequestService;
use OCA\Pipelinq\Service\Portal\PortalScopeResolver;
use OCA\Pipelinq\Service\Portal\PortalTenantService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for PortalRequestController.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The real request service and
 *  scope resolver bring their own collaborators; wiring them is the point.
 */
class PortalRequestControllerTest extends TestCase {

	/**
	 * Fixed clock.
	 *
	 * @var int
	 */
	private const NOW = 1800000000;

	/**
	 * The authenticated account.
	 *
	 * @var array<string, mixed>
	 */
	private const ACCOUNT = [
		'@self' => ['id' => 'acct-1'],
		'status' => 'active',
		'linkedContactId' => 'contact-own',
		'linkedOrganisationId' => 'client-own',
	];

	/**
	 * A request the account owns, paused awaiting the customer, carrying one
	 * internal and one customer-visible note.
	 *
	 * @var array<string, mixed>
	 */
	private const OWN_REQUEST = [
		'@self' => ['id' => 'req-own'],
		'contact' => 'contact-own',
		'title' => 'Broken scanner',
		'description' => 'The scanner stopped reading barcodes.',
		'caseReference' => 'REQ-0001',
		'category' => 'cat-hardware',
		'status' => 'awaiting-customer',
		'requestedAt' => '2026-03-01T09:00:00+00:00',
		'assignee' => 'agent-nine',
		'notes' => [
			[
				'visibility' => 'internal',
				'author' => 'agent-nine',
				'message' => 'Customer is on the churn watchlist, handle gently.',
				'createdAt' => '2026-03-01T09:05:00+00:00',
			],
			[
				'visibility' => 'customer',
				'author' => 'agent',
				'message' => 'Could you confirm the serial number?',
				'createdAt' => '2026-03-01T09:10:00+00:00',
			],
		],
	];

	/**
	 * A request owned by another customer.
	 *
	 * @var array<string, mixed>
	 */
	private const FOREIGN_REQUEST = [
		'@self' => ['id' => 'req-foreign'],
		'contact' => 'contact-someone-else',
		'title' => 'Confidential complaint',
		'description' => 'Secret complaint body.',
		'status' => 'awaiting-customer',
		'notes' => [
			[
				'visibility' => 'customer',
				'author' => 'agent',
				'message' => 'Another customer conversation.',
				'createdAt' => '2026-03-01T09:10:00+00:00',
			],
		],
	];

	/**
	 * Request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * Captured save payloads as [id, data].
	 *
	 * @var array<int, array{0: string|null, 1: array<string, mixed>}>
	 */
	private array $saved = [];

	/**
	 * The main-register reader mock.
	 *
	 * @var MainRegisterReader&MockObject
	 */
	private $reader;

	/**
	 * The guard mock.
	 *
	 * @var PortalRequestGuard&MockObject
	 */
	private $guard;

	/**
	 * Reset per-test state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->params = [];
		$this->saved = [];
		$this->reader = $this->createMock(MainRegisterReader::class);
		$this->guard = $this->createMock(PortalRequestGuard::class);

		$this->reader->method('save')->willReturnCallback(
			function (string $schemaKey, array $data, ?string $id = null): array {
				$this->saved[] = [$id, $data];
				return $data;
			}
		);
	}//end setUp()

	/**
	 * Build the controller over the real request service.
	 *
	 * @return PortalRequestController The controller.
	 */
	private function build(): PortalRequestController {
		$repository = $this->createMock(PortalObjectRepository::class);
		$repository->method('idOf')->willReturnCallback(
			static fn (array $object): ?string => ($object['@self']['id'] ?? $object['id'] ?? null)
		);

		$delegations = $this->createMock(PortalDelegationService::class);
		$delegations->method('getActiveScopes')->willReturn([]);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$time->method('getDateTime')->willReturnCallback(
			static fn (): \DateTime => new \DateTime('@' . self::NOW)
		);

		$requests = new PortalRequestService(
			$this->reader,
			new PortalScopeResolver($repository, $delegations),
			$this->createMock(PortalAuditService::class),
			$this->createMock(IEventDispatcher::class),
			$time,
			$this->createMock(LoggerInterface::class)
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null): mixed => ($this->params[$key] ?? $default)
		);

		return new PortalRequestController(
			$request,
			$this->guard,
			$this->createMock(LoggerInterface::class),
			$requests,
			$this->createMock(PortalTenantService::class)
		);
	}//end build()

	/**
	 * Authenticate the guard as the fixture account.
	 *
	 * @return void
	 */
	private function authenticate(): void {
		$this->guard->method('authenticate')->willReturn(
			[
				'account' => self::ACCOUNT,
				'accountId' => 'acct-1',
				'session' => ['@self' => ['id' => 'sess-live'], 'accountId' => 'acct-1'],
				'tenantId' => 'tenant-a',
			]
		);
		$this->guard->method('resolveTenant')->willReturn('tenant-a');
	}//end authenticate()

	/**
	 * A reply on the customer's own request answers 200 with the customer-safe
	 * detail and appends the message.
	 *
	 * @return void
	 */
	public function testReplyAppendsTheMessageAndReturnsTheDetail(): void {
		$this->authenticate();
		$this->reader->method('find')->willReturn(self::OWN_REQUEST);
		$this->params = ['message' => '  Serial number is SN-4711.  '];

		$response = $this->build()->reply('req-own');
		$body = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('req-own', $body['id']);
		$this->assertSame('REQ-0001', $body['number']);
		$this->assertSame('Broken scanner', $body['subject']);
		$this->assertSame('The scanner stopped reading barcodes.', $body['body']);

		$this->assertCount(1, $this->saved);
		$this->assertSame('req-own', $this->saved[0][0]);
		$notes = $this->saved[0][1]['notes'];
		$this->assertCount(3, $notes);
		$this->assertSame('Serial number is SN-4711.', $notes[2]['message']);
		$this->assertSame('customer', $notes[2]['visibility']);
		$this->assertSame('customer', $notes[2]['author']);
	}//end testReplyAppendsTheMessageAndReturnsTheDetail()

	/**
	 * Internal notes must never reach the customer: the reply response carries
	 * only the customer-visible ones.
	 *
	 * @return void
	 */
	public function testReplyResponseStripsInternalNotes(): void {
		$this->authenticate();
		$this->reader->method('find')->willReturn(self::OWN_REQUEST);
		$this->params = ['message' => 'Serial number is SN-4711.'];

		$body = $this->build()->reply('req-own')->getData();

		$this->assertCount(2, $body['notes']);
		$this->assertSame(
			['Could you confirm the serial number?', 'Serial number is SN-4711.'],
			array_column($body['notes'], 'message')
		);
		$this->assertStringNotContainsString('churn watchlist', (string)json_encode($body));
		$this->assertArrayNotHasKey('visibility', $body['notes'][0]);
	}//end testReplyResponseStripsInternalNotes()

	/**
	 * Replying unpauses the case: an awaiting-customer request moves to
	 * in-progress and the reply affordance closes.
	 *
	 * @return void
	 */
	public function testReplyUnpausesAnAwaitingCustomerRequest(): void {
		$this->authenticate();
		$this->reader->method('find')->willReturn(self::OWN_REQUEST);
		$this->params = ['message' => 'Serial number is SN-4711.'];

		$body = $this->build()->reply('req-own')->getData();

		$this->assertSame('in-progress', $this->saved[0][1]['status']);
		$this->assertSame('in-progress', $body['status']);
		$this->assertFalse($body['canReply']);
	}//end testReplyUnpausesAnAwaitingCustomerRequest()

	/**
	 * The assignee is withheld unless the tenant exposes it.
	 *
	 * @return void
	 */
	public function testReplyResponseHidesTheAssignee(): void {
		$this->authenticate();
		$this->reader->method('find')->willReturn(self::OWN_REQUEST);
		$this->params = ['message' => 'Serial number is SN-4711.'];

		$body = $this->build()->reply('req-own')->getData();

		$this->assertArrayNotHasKey('assignee', $body);
		$this->assertTrue($body['assigneeHidden']);
		$this->assertStringNotContainsString('agent-nine', (string)json_encode($body));
	}//end testReplyResponseHidesTheAssignee()

	/**
	 * The persisted record must keep every field the reply did not touch — a
	 * read-modify-write that dropped fields would silently destroy case data.
	 *
	 * @return void
	 */
	public function testReplyPreservesFieldsItDoesNotTouch(): void {
		$this->authenticate();
		$this->reader->method('find')->willReturn(self::OWN_REQUEST);
		$this->params = ['message' => 'Serial number is SN-4711.'];

		$this->build()->reply('req-own');
		$written = $this->saved[0][1];

		$this->assertSame('Broken scanner', $written['title']);
		$this->assertSame('contact-own', $written['contact']);
		$this->assertSame('agent-nine', $written['assignee']);
		$this->assertSame('REQ-0001', $written['caseReference']);
		$this->assertSame('cat-hardware', $written['category']);
		$this->assertSame('2026-03-01T09:00:00+00:00', $written['requestedAt']);
	}//end testReplyPreservesFieldsItDoesNotTouch()

	/**
	 * IDOR guard: replying to another customer's request id must answer 404,
	 * write nothing, and disclose none of that request's content.
	 *
	 * @return void
	 */
	public function testReplyReturnsNotFoundForAnotherCustomersRequest(): void {
		$this->authenticate();
		$this->reader->method('find')->willReturn(self::FOREIGN_REQUEST);
		$this->params = ['message' => 'Injecting a reply into someone else s case.'];

		$response = $this->build()->reply('req-foreign');
		$body = $response->getData();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('notFound', $body['errorCode']);
		$this->assertSame([], $this->saved);
		$this->assertStringNotContainsString('Confidential complaint', (string)json_encode($body));
		$this->assertStringNotContainsString('Another customer conversation', (string)json_encode($body));
	}//end testReplyReturnsNotFoundForAnotherCustomersRequest()

	/**
	 * A foreign id and an unknown id must be indistinguishable, so request ids
	 * cannot be enumerated through this endpoint.
	 *
	 * @return void
	 */
	public function testReplyDoesNotLeakExistenceOfForeignRequests(): void {
		$this->authenticate();
		$this->reader->method('find')->willReturnCallback(
			static fn (string $schemaKey, string $id): ?array => ($id === 'req-foreign' ? self::FOREIGN_REQUEST : null)
		);
		$this->params = ['message' => 'Probe.'];

		$controller = $this->build();
		$foreign = $controller->reply('req-foreign');
		$unknown = $controller->reply('req-does-not-exist');

		$this->assertSame($unknown->getStatus(), $foreign->getStatus());
		$this->assertSame($unknown->getData(), $foreign->getData());
		$this->assertSame([], $this->saved);
	}//end testReplyDoesNotLeakExistenceOfForeignRequests()

	/**
	 * An empty message is a 400 missingFields and writes nothing.
	 *
	 * @return void
	 */
	public function testReplyRejectsAnEmptyMessage(): void {
		$this->authenticate();
		$this->reader->method('find')->willReturn(self::OWN_REQUEST);
		$this->params = ['message' => ''];

		$response = $this->build()->reply('req-own');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('missingFields', $response->getData()['errorCode']);
		$this->assertSame([], $this->saved);
	}//end testReplyRejectsAnEmptyMessage()

	/**
	 * A whitespace-only message is empty after trimming and is refused too.
	 *
	 * @return void
	 */
	public function testReplyRejectsAWhitespaceOnlyMessage(): void {
		$this->authenticate();
		$this->reader->method('find')->willReturn(self::OWN_REQUEST);
		$this->params = ['message' => "   \n\t  "];

		$response = $this->build()->reply('req-own');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('missingFields', $response->getData()['errorCode']);
		$this->assertSame([], $this->saved);
	}//end testReplyRejectsAWhitespaceOnlyMessage()

	/**
	 * The stored note's visibility and author are set by the server. A client
	 * that submits its own visibility/author must not be able to plant a note
	 * that the agent console renders as internal or as staff-authored.
	 *
	 * @return void
	 */
	public function testReplyIgnoresClientSuppliedNoteMetadata(): void {
		$this->authenticate();
		$this->reader->method('find')->willReturn(self::OWN_REQUEST);
		$this->params = [
			'message' => 'Legitimate looking text.',
			'visibility' => 'internal',
			'author' => 'agent-nine',
			'createdAt' => '2020-01-01T00:00:00+00:00',
		];

		$this->build()->reply('req-own');
		$note = $this->saved[0][1]['notes'][2];

		$this->assertSame('customer', $note['visibility']);
		$this->assertSame('customer', $note['author']);
		$this->assertNotSame('2020-01-01T00:00:00+00:00', $note['createdAt']);
	}//end testReplyIgnoresClientSuppliedNoteMetadata()

	/**
	 * Replying requires a live portal session; anonymous callers get 401 and
	 * nothing is read or written.
	 *
	 * @return void
	 */
	public function testReplyReturnsUnauthorizedWithoutASession(): void {
		$this->guard->method('authenticate')->willThrowException(
			new PortalException(Http::STATUS_UNAUTHORIZED, 'unauthenticated', 'Niet ingelogd.')
		);
		$this->reader->expects($this->never())->method('find');
		$this->params = ['message' => 'Serial number is SN-4711.'];

		$response = $this->build()->reply('req-own');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('unauthenticated', $response->getData()['errorCode']);
		$this->assertSame([], $this->saved);
	}//end testReplyReturnsUnauthorizedWithoutASession()
}//end class
