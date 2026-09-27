<?php

/**
 * Unit tests for PortalRequestService: scoping, what a resident reads, replies, rate limit.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Portal;

use OCA\Pipelinq\Service\Portal\PortalDelegationService;
use OCA\Pipelinq\Service\Portal\PortalException;
use OCA\Pipelinq\Service\Portal\PortalRequestService;
use OCA\Pipelinq\Service\Portal\PortalScopeResolver;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCP\IAppConfig;

/**
 * Tests for the portal request surface.
 */
class PortalRequestServiceTest extends TestCase {
	/**
	 * The fake portal repository.
	 *
	 * @var FakePortalObjectRepository
	 */
	private FakePortalObjectRepository $portalRepo;

	/**
	 * The fake main-register reader.
	 *
	 * @var FakeMainRegisterReader
	 */
	private FakeMainRegisterReader $reader;

	/**
	 * The service under test.
	 *
	 * @var PortalRequestService
	 */
	private PortalRequestService $service;

	/**
	 * "now" timestamp.
	 *
	 * @var int
	 */
	private int $now = 1000000;

	/**
	 * Set up the request service over fakes.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->portalRepo = new FakePortalObjectRepository(InstalledAppConfig::wire($this->createMock(IAppConfig::class)));
		$this->reader = new FakeMainRegisterReader(InstalledAppConfig::wire($this->createMock(IAppConfig::class)));
		$this->reader->markConfigured('ticket');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);
		$time->method('getDateTime')->willReturnCallback(
			fn (): \DateTime => (new \DateTime())->setTimestamp($this->now)
		);

		$audit = $this->createMock(\OCA\Pipelinq\Service\Portal\PortalAuditService::class);
		$delegations = new PortalDelegationService($this->portalRepo, $audit, $time);
		$scope = new PortalScopeResolver($this->portalRepo, $delegations);
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$logger = $this->createMock(LoggerInterface::class);

		$this->service = new PortalRequestService($this->reader,
			$scope,
			$audit,
			$dispatcher,
			$time,
			$logger
		);
	}//end setUp()

	/**
	 * Only the customer's own request tickets are listed; complaints and
	 * logged interactions on the same contact are not requests.
	 *
	 * @return void
	 */
	public function testOnlyOwnRequestTicketsListed(): void {
		$account = $this->portalRepo->seed('crmPortalAccount', 'acc-a', ['linkedContactId' => 'contact-a']);
		$this->reader->seed('ticket', 'r1', ['ticketType' => 'request', 'contact' => 'contact-a', 'title' => 'Mine', 'occurredAt' => '2026-05-01T00:00:00Z']);
		$this->reader->seed('ticket', 'r2', ['ticketType' => 'request', 'contact' => 'contact-x', 'title' => 'Theirs', 'occurredAt' => '2026-05-02T00:00:00Z']);
		$this->reader->seed('ticket', 'c1', ['ticketType' => 'complaint', 'contact' => 'contact-a', 'title' => 'A complaint']);
		$this->reader->seed('ticket', 'i1', ['ticketType' => 'interaction', 'contact' => 'contact-a', 'title' => 'A phone call']);

		$page = $this->service->getForAccount($account);
		$this->assertSame(1, $page['total']);
		$this->assertSame('Mine', $page['items'][0]['subject']);
		$this->assertSame('2026-05-01T00:00:00Z', $page['items'][0]['date']);
	}//end testOnlyOwnRequestTicketsListed()

	/**
	 * A request belonging to another contact returns null detail (IDOR, 404).
	 *
	 * @return void
	 */
	public function testCrossCustomerRequestDetailNull(): void {
		$account = $this->portalRepo->seed('crmPortalAccount', 'acc-a', ['linkedContactId' => 'contact-a']);
		$this->reader->seed('ticket', 'r-x', ['ticketType' => 'request', 'contact' => 'contact-x', 'title' => 'Theirs']);

		$this->assertNull($this->service->getDetailForAccount($account, 'r-x', false));
	}//end testCrossCustomerRequestDetailNull()

	/**
	 * A ticket of another type is not a request, even on the resident's own contact.
	 *
	 * @return void
	 */
	public function testOwnComplaintIsNotARequestDetail(): void {
		$account = $this->portalRepo->seed('crmPortalAccount', 'acc-a', ['linkedContactId' => 'contact-a']);
		$this->reader->seed('ticket', 'c1', ['ticketType' => 'complaint', 'contact' => 'contact-a', 'title' => 'A complaint']);

		$this->assertNull($this->service->getDetailForAccount($account, 'c1', false));
	}//end testOwnComplaintIsNotARequestDetail()

	/**
	 * The handler's message to the customer reaches the resident, and the
	 * ticket's internal notes (a string on the ticket schema) never do.
	 *
	 * @return void
	 */
	public function testHandlerMessageShownAndInternalNotesHidden(): void {
		$account = $this->portalRepo->seed('crmPortalAccount', 'acc-a', ['linkedContactId' => 'contact-a']);
		$this->reader->seed('ticket', 'r1', [
			'ticketType' => 'request',
			'contact' => 'contact-a',
			'title' => 'Mine',
			'notes' => 'internal: caller sounded upset',
			'customerMessage' => 'Could you send us a copy of your ID?',
			'portalReplies' => [
				['message' => 'earlier reply', 'createdAt' => '2026-05-01T00:00:00Z'],
			],
		]);

		$detail = $this->service->getDetailForAccount($account, 'r1', false);
		$messages = array_column($detail['notes'], 'message');
		$this->assertContains('Could you send us a copy of your ID?', $messages);
		$this->assertContains('earlier reply', $messages);
		$this->assertNotContains('internal: caller sounded upset', $messages);
		$authors = array_column($detail['notes'], 'author', 'message');
		$this->assertSame('handler', $authors['Could you send us a copy of your ID?']);
		$this->assertSame('customer', $authors['earlier reply']);
	}//end testHandlerMessageShownAndInternalNotesHidden()

	/**
	 * The assignee is hidden unless the tenant exposes it.
	 *
	 * @return void
	 */
	public function testAssigneeHiddenByDefault(): void {
		$account = $this->portalRepo->seed('crmPortalAccount', 'acc-a', ['linkedContactId' => 'contact-a']);
		$this->reader->seed('ticket', 'r1', ['ticketType' => 'request', 'contact' => 'contact-a', 'assignee' => 'm.bakker']);

		$detail = $this->service->getDetailForAccount($account, 'r1', false);
		$this->assertArrayNotHasKey('assignee', $detail);
		$this->assertTrue($detail['assigneeHidden']);
	}//end testAssigneeHiddenByDefault()

	/**
	 * Submitting persists a request ticket from the portal channel.
	 *
	 * @return void
	 */
	public function testSubmitCreatesRequestTicket(): void {
		$account = $this->portalRepo->seed('crmPortalAccount', 'acc-a', ['linkedContactId' => 'contact-a']);
		$result = $this->service->submit($account, 'tenant-a', 'Subject', 'Body', [['id' => 'f1', 'size' => 10]], 'cat-1');

		$this->assertArrayHasKey('requestId', $result);
		$stored = $this->reader->find('ticket', $result['requestId']);
		$this->assertSame('request', $stored['ticketType']);
		$this->assertSame('portal', $stored['channel']);
		$this->assertSame('new', $stored['status']);
		$this->assertSame('contact-a', $stored['contact']);
		$this->assertSame(['f1'], $stored['attachmentIds']);
	}//end testSubmitCreatesRequestTicket()

	/**
	 * The 6th submission within the hour is rate-limited (429).
	 *
	 * @return void
	 */
	public function testSubmitRateLimited(): void {
		$account = $this->portalRepo->seed('crmPortalAccount', 'acc-a', ['linkedContactId' => 'contact-a']);
		for ($i = 0; $i < 5; $i++) {
			$this->service->submit($account, 'tenant-a', 'S' . $i, 'B', [], 'cat-1');
		}

		try {
			$this->service->submit($account, 'tenant-a', 'S6', 'B', [], 'cat-1');
			$this->fail('Expected rate limit');
		} catch (PortalException $e) {
			$this->assertSame(Http::STATUS_TOO_MANY_REQUESTS, $e->getStatus());
			$this->assertSame('rateLimited', $e->getErrorCode());
		}
	}//end testSubmitRateLimited()

	/**
	 * An attachment over 25 MB is rejected with 413.
	 *
	 * @return void
	 */
	public function testOversizeAttachmentRejected(): void {
		$account = $this->portalRepo->seed('crmPortalAccount', 'acc-a', ['linkedContactId' => 'contact-a']);
		try {
			$this->service->submit($account, 'tenant-a', 'S', 'B', [['id' => 'f1', 'size' => (30 * 1024 * 1024)]], 'cat-1');
			$this->fail('Expected file-too-large');
		} catch (PortalException $e) {
			$this->assertSame(Http::STATUS_REQUEST_ENTITY_TOO_LARGE, $e->getStatus());
			$this->assertSame('fileTooLarge', $e->getErrorCode());
		}
	}//end testOversizeAttachmentRejected()

	/**
	 * The reply box shows only while the ticket waits for the customer.
	 *
	 * @return void
	 */
	public function testCanReplyOnlyWhileAwaitingCustomer(): void {
		$account = $this->portalRepo->seed('crmPortalAccount', 'acc-a', ['linkedContactId' => 'contact-a']);
		$this->reader->seed('ticket', 'r1', ['ticketType' => 'request', 'contact' => 'contact-a', 'status' => 'awaiting_customer']);
		$this->reader->seed('ticket', 'r2', ['ticketType' => 'request', 'contact' => 'contact-a', 'status' => 'in_progress']);

		$this->assertTrue($this->service->getDetailForAccount($account, 'r1', false)['canReply']);
		$this->assertFalse($this->service->getDetailForAccount($account, 'r2', false)['canReply']);
	}//end testCanReplyOnlyWhileAwaitingCustomer()

	/**
	 * A reply unpauses the ticket into the schema's own in_progress, is kept
	 * with the earlier replies, and leaves the internal notes string alone.
	 *
	 * @return void
	 */
	public function testReplyUnpausesAndKeepsInternalNotes(): void {
		$account = $this->portalRepo->seed('crmPortalAccount', 'acc-a', ['linkedContactId' => 'contact-a']);
		$this->reader->seed('ticket', 'r1', [
			'ticketType' => 'request',
			'contact' => 'contact-a',
			'status' => 'awaiting_customer',
			'notes' => 'internal note',
			'portalReplies' => [['message' => 'first', 'createdAt' => '2026-05-01T00:00:00Z']],
		]);

		$detail = $this->service->addReply($account, 'tenant-a', 'r1', 'Here is my reply');
		$stored = $this->reader->find('ticket', 'r1');
		$this->assertSame('in_progress', $stored['status']);
		$this->assertSame('internal note', $stored['notes']);
		$this->assertSame(['first', 'Here is my reply'], array_column($stored['portalReplies'], 'message'));
		$this->assertFalse($detail['canReply']);
	}//end testReplyUnpausesAndKeepsInternalNotes()

	/**
	 * Every field the portal writes, and every status it writes or waits on,
	 * is declared on the ticket schema the install imports. A write outside
	 * the schema is dropped or refused by OpenRegister (pipelinq#2038).
	 *
	 * @return void
	 */
	public function testPortalWritesOnlyWhatTheTicketSchemaDeclares(): void {
		$ticket = self::mergedTicketSchema();
		$properties = ($ticket['properties'] ?? []);
		$this->assertArrayHasKey('ticketType', $properties, 'positive control: the merged schema was read');

		$account = $this->portalRepo->seed('crmPortalAccount', 'acc-a', ['linkedContactId' => 'contact-a']);
		$result = $this->service->submit($account, 'tenant-a', 'Subject', 'Body', [['id' => 'f1', 'size' => 10]], 'cat-1');
		$stored = $this->reader->find('ticket', $result['requestId']);
		$stored['status'] = 'awaiting_customer';
		$this->reader->save('ticket', $stored, $result['requestId']);
		$this->service->addReply($account, 'tenant-a', $result['requestId'], 'reply');
		$stored = $this->reader->find('ticket', $result['requestId']);
		unset($stored['@self']);

		foreach (array_keys($stored) as $field) {
			$this->assertArrayHasKey($field, $properties, "the portal writes '{$field}', which the ticket schema does not declare");
		}

		foreach (($ticket['required'] ?? []) as $required) {
			$this->assertArrayHasKey($required, $stored, "a portal request lacks the required '{$required}'");
		}

		$this->assertContains($stored['ticketType'], $properties['ticketType']['enum']);
		$this->assertContains('awaiting_customer', $properties['status']['enum']);
		$this->assertContains($stored['status'], $properties['status']['enum']);
		$this->assertSame('string', $properties['notes']['type']);
		$this->assertSame('string', $properties['customerMessage']['type']);
	}//end testPortalWritesOnlyWhatTheTicketSchemaDeclares()

	/**
	 * The ticket schema as the install imports it: the monolith with every
	 * register.d fragment deep-merged on top, in filename order.
	 *
	 * @return array<string, mixed> The merged ticket schema.
	 */
	private static function mergedTicketSchema(): array {
		$settings = dirname(__DIR__, 4) . '/lib/Settings';
		$files = array_merge([$settings . '/pipelinq_register.json'], glob($settings . '/register.d/*.json'));
		$merged = [];
		foreach ($files as $file) {
			$data = json_decode((string)file_get_contents($file), true);
			$fragment = ($data['components']['schemas']['ticket'] ?? null);
			if (is_array($fragment) === true) {
				$merged = self::deepMerge(base: $merged, overlay: $fragment);
			}
		}

		return $merged;
	}//end mergedTicketSchema()

	/**
	 * Deep-merge like ConfigFileLoaderService: objects merge, lists and scalars replace.
	 *
	 * @param array<string, mixed> $base The base.
	 * @param array<string, mixed> $overlay The overlay.
	 *
	 * @return array<string, mixed> The merged array.
	 */
	private static function deepMerge(array $base, array $overlay): array {
		foreach ($overlay as $key => $value) {
			if (is_array($value) === true && array_is_list($value) === false
				&& is_array($base[$key] ?? null) === true && array_is_list($base[$key]) === false
			) {
				$base[$key] = self::deepMerge(base: $base[$key], overlay: $value);
				continue;
			}

			$base[$key] = $value;
		}

		return $base;
	}//end deepMerge()
}//end class
