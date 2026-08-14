<?php

/**
 * Unit tests for SemanticHandoffController.
 *
 * Covers the auth guard, per-object guard, status guards, hidden-without
 * -implementer refusal and success atomicity of the request→case and
 * contract→invoice emit endpoints.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/semantic-handoff-emit/specs/request-management/spec.md#requirement-request-to-case-conversion-v1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\SemanticHandoffController;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\SemanticHandoffService;
use OCA\Pipelinq\Service\TicketService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * SemanticHandoffController unit coverage.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class SemanticHandoffControllerTest extends TestCase {
	private SemanticHandoffService $handoffService;
	private IUserSession $userSession;
	private object $objectService;
	private IGroupManager $groupManager;
	private ContainerInterface $container;
	private IAppConfig $appConfig;
	private TicketService $ticketService;
	private SemanticHandoffController $controller;

	/**
	 * Build the controller with an in-memory OR object stub.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->handoffService = $this->createMock(SemanticHandoffService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		// Default: the caller holds no privileged group, so authorization has to
		// come from ownership. A test that wants the privileged path says so.
		$this->groupManager->method('isInGroup')->willReturn(false);

		$this->objectService = new class {
			/** @var array<string, array<string, mixed>> */
			public array $store = [];

			/** @var array<int, array<string, mixed>> */
			public array $saves = [];

			/**
			 * @param string $id Id.
			 * @param mixed $register Register.
			 * @param mixed $schema Schema.
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(string $id, $register = null, $schema = null): ?array {
				return ($this->store[$id] ?? null);
			}

			/**
			 * @param array<string, mixed> $object Payload.
			 * @param mixed $register Register.
			 * @param mixed $schema Schema.
			 * @param string|null $uuid Id.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, $register = null, $schema = null, ?string $uuid = null): array {
				$object['uuid'] = ($uuid ?? 'new');
				$this->store[$object['uuid']] = $object;
				$this->saves[] = $object;
				return $object;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $this->objectService;
				}

				throw new \RuntimeException('not registered: ' . $id);
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				if ($key === 'register') {
					return 'pipelinq';
				}

				return $default;
			}
		);

		// Unconfigured ticket schema id → the controller falls back to the
		// built-in `ticket` slug, exactly as it does on a fresh install.
		$ticketService = $this->createMock(TicketService::class);
		$ticketService->method('getSchemaId')->willReturn('');

		$this->container = $container;
		$this->appConfig = $appConfig;
		$this->ticketService = $ticketService;

		$this->rebuildControllerWithGroupManager($this->groupManager);
	}//end setUp()

	/**
	 * Rebuild the controller behind a specific group manager.
	 *
	 * Lets one test exercise the privileged-group branch without loosening the
	 * default (no privileged membership) that every other test relies on.
	 *
	 * @param IGroupManager $groupManager The group manager to authorize against.
	 *
	 * @return void
	 */
	private function rebuildControllerWithGroupManager(IGroupManager $groupManager): void {
		$this->controller = new SemanticHandoffController(
			$this->createMock(IRequest::class),
			$this->handoffService,
			$this->container,
			$this->appConfig,
			$this->ticketService,
			$this->userSession,
			new ObjectOwnerAccessPolicy(
				groupManager: $groupManager,
				appConfig: $this->createMock(IAppConfig::class)
			),
			$this->createMock(LoggerInterface::class),
		);
	}//end rebuildControllerWithGroupManager()

	/**
	 * Sign a user in.
	 *
	 * @return void
	 */
	private function signIn(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('agent-1');
		$this->userSession->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * Unauthenticated conversion is rejected with 401.
	 *
	 * @return void
	 */
	public function testConvertUnauthorized(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$response = $this->controller->convertRequestToCase(id: 'req-1');
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testConvertUnauthorized()

	/**
	 * A missing request is 404 and no handoff is attempted.
	 *
	 * @return void
	 */
	public function testConvertNotFound(): void {
		$this->signIn();
		$this->handoffService->expects($this->never())->method('handoff');
		$response = $this->controller->convertRequestToCase(id: 'nope');
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testConvertNotFound()

	/**
	 * Converting a non-in_progress request is refused (409) without a handoff.
	 *
	 * @return void
	 */
	public function testConvertInvalidStatus(): void {
		$this->signIn();
		$this->objectService->store['req-1'] = ['uuid' => 'req-1', 'ticketType' => 'request', 'status' => 'new'];
		$this->handoffService->expects($this->never())->method('handoff');

		$response = $this->controller->convertRequestToCase(id: 'req-1');
		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('invalid-status', $response->getData()['status']);
	}//end testConvertInvalidStatus()

	/**
	 * With no ns#Case implementer, conversion is refused cleanly (409).
	 *
	 * @return void
	 */
	public function testConvertNotAvailable(): void {
		$this->signIn();
		$this->objectService->store['req-1'] = ['uuid' => 'req-1', 'ticketType' => 'request', 'status' => 'in_progress'];
		$this->handoffService->method('hasImplementer')->willReturn(false);
		$this->handoffService->expects($this->never())->method('handoff');

		$response = $this->controller->convertRequestToCase(id: 'req-1');
		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('not-available', $response->getData()['status']);
	}//end testConvertNotAvailable()

	/**
	 * A failed handoff leaves the request status unchanged (502).
	 *
	 * @return void
	 */
	public function testConvertHandoffFailedLeavesRequestUntouched(): void {
		$this->signIn();
		$this->objectService->store['req-1'] = ['uuid' => 'req-1', 'ticketType' => 'request', 'status' => 'in_progress'];
		$this->handoffService->method('hasImplementer')->willReturn(true);
		$this->handoffService->method('handoff')->willReturn(['ok' => false, 'reason' => 'handoff-failed']);

		$response = $this->controller->convertRequestToCase(id: 'req-1');
		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		// No save happened → status still in_progress.
		$this->assertSame('in_progress', $this->objectService->store['req-1']['status']);
		$this->assertCount(0, $this->objectService->saves);
	}//end testConvertHandoffFailedLeavesRequestUntouched()

	/**
	 * A successful conversion sets status=converted + caseReference.
	 *
	 * @return void
	 */
	public function testConvertSuccess(): void {
		$this->signIn();
		$this->objectService->store['req-1'] = [
			'uuid' => 'req-1',
			'ticketType' => 'request',
			'status' => 'in_progress',
			'title' => 'Broken bin',
		];
		$this->handoffService->method('hasImplementer')->willReturn(true);
		$this->handoffService->method('handoff')->willReturn([
			'ok' => true,
			'targetUuid' => 'case-42',
			'correlationId' => 'corr-1',
			'reason' => '',
		]);

		$response = $this->controller->convertRequestToCase(id: 'req-1');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('converted', $response->getData()['status']);
		$this->assertSame('case-42', $response->getData()['caseReference']);
		// Persisted, with the discriminator kept pinned on the round-tripped payload.
		$this->assertSame('converted', $this->objectService->store['req-1']['status']);
		$this->assertSame('case-42', $this->objectService->store['req-1']['caseReference']);
		$this->assertSame('request', $this->objectService->store['req-1']['ticketType']);
	}//end testConvertSuccess()

	/**
	 * A ticket of another subtype is not reachable through the request
	 * endpoints — it is reported as absent (404) and never converted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets
	 */
	public function testConvertRefusesNonRequestTicket(): void {
		$this->signIn();
		$this->objectService->store['cm-1'] = [
			'uuid' => 'cm-1',
			'ticketType' => 'contactmoment',
			'status' => 'in_progress',
		];
		$this->handoffService->expects($this->never())->method('handoff');

		$response = $this->controller->convertRequestToCase(id: 'cm-1');
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertCount(0, $this->objectService->saves);
	}//end testConvertRefusesNonRequestTicket()

	/**
	 * requestAvailability reports canConvert only for in_progress + implementer.
	 *
	 * @return void
	 */
	public function testRequestAvailability(): void {
		$this->signIn();
		$this->objectService->store['req-1'] = ['uuid' => 'req-1', 'ticketType' => 'request', 'status' => 'in_progress'];
		$this->handoffService->method('hasImplementer')->willReturn(true);

		$response = $this->controller->requestAvailability(id: 'req-1');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['available']);
		$this->assertTrue($response->getData()['canConvert']);
	}//end testRequestAvailability()

	/**
	 * Sending a non-active contract to invoicing is refused (409).
	 *
	 * @return void
	 */
	public function testSendContractInvalidStatus(): void {
		$this->signIn();
		$this->objectService->store['c-1'] = ['uuid' => 'c-1', 'status' => 'draft', 'ownerId' => 'agent-1'];
		$this->handoffService->expects($this->never())->method('handoff');

		$response = $this->controller->sendContractToInvoicing(id: 'c-1');
		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('invalid-status', $response->getData()['status']);
	}//end testSendContractInvalidStatus()

	/**
	 * A successful contract handoff records the invoice provenance link.
	 *
	 * @return void
	 */
	public function testSendContractSuccess(): void {
		$this->signIn();
		$this->objectService->store['c-1'] = ['uuid' => 'c-1', 'status' => 'active', 'contractNumber' => 'C-2026-1', 'ownerId' => 'agent-1'];
		$this->handoffService->method('hasImplementer')->willReturn(true);
		$this->handoffService->method('handoff')->willReturn([
			'ok' => true,
			'targetUuid' => 'inv-7',
			'correlationId' => 'corr-2',
			'reason' => '',
		]);

		$response = $this->controller->sendContractToInvoicing(id: 'c-1');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('sent', $response->getData()['status']);
		$this->assertSame('inv-7', $response->getData()['invoiceReference']);
		$this->assertSame('inv-7', $this->objectService->store['c-1']['invoiceReference']);
	}//end testSendContractSuccess()

	/**
	 * Unauthenticated contract-availability probe is refused with 401 and no
	 * implementer lookup is attempted.
	 *
	 * @return void
	 */
	public function testContractAvailabilityUnauthorized(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->handoffService->expects($this->never())->method('hasImplementer');

		$response = $this->controller->contractAvailability(id: 'c-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['status' => 'unauthorized'], $response->getData());
	}//end testContractAvailabilityUnauthorized()

	/**
	 * An unknown contract is reported as 404 without consulting the handoff
	 * registry.
	 *
	 * @return void
	 */
	public function testContractAvailabilityNotFound(): void {
		$this->signIn();
		$this->handoffService->expects($this->never())->method('hasImplementer');

		$response = $this->controller->contractAvailability(id: 'nope');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['status' => 'not-found'], $response->getData());
	}//end testContractAvailabilityNotFound()

	/**
	 * A stranger may not read another user's contract through the availability
	 * endpoint: 403, and the handoff service is never consulted.
	 *
	 * Regression for #801. Before the guard this returned **200** with the
	 * victim's `status`, measured live on a two-account rig.
	 *
	 * @return void
	 */
	public function testContractAvailabilityRefusesAContractTheCallerDoesNotOwn(): void {
		$this->signIn();
		$this->objectService->store['c-9'] = ['uuid' => 'c-9', 'status' => 'active', 'ownerId' => 'someone-else'];
		$this->handoffService->expects($this->never())->method('hasImplementer');

		$response = $this->controller->contractAvailability(id: 'c-9');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['status' => 'forbidden'], $response->getData());
	}//end testContractAvailabilityRefusesAContractTheCallerDoesNotOwn()

	/**
	 * A stranger may not mint an invoice against another user's contract: 403,
	 * nothing is handed off and nothing is written back.
	 *
	 * Regression for #801 — the worst endpoint in that issue. Live, the
	 * attacker passed the object load AND the `status === 'active'` gate on the
	 * victim's contract and was stopped only because no app implemented
	 * `ns#Invoice` on that rig (409 `not-available`). On an instance with
	 * shillinq installed the next statement mints a real invoice.
	 *
	 * @return void
	 */
	public function testSendToInvoicingRefusesAContractTheCallerDoesNotOwn(): void {
		$this->signIn();
		$this->objectService->store['c-9'] = ['uuid' => 'c-9', 'status' => 'active', 'ownerId' => 'someone-else'];
		$this->handoffService->expects($this->never())->method('handoff');

		$response = $this->controller->sendContractToInvoicing(id: 'c-9');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['status' => 'forbidden'], $response->getData());
		$this->assertSame([], $this->objectService->saves);
	}//end testSendToInvoicingRefusesAContractTheCallerDoesNotOwn()

	/**
	 * Authorization is decided BEFORE the status gate, so a stranger cannot use
	 * the response to learn whether someone else's contract is active.
	 *
	 * @return void
	 */
	public function testSendToInvoicingDoesNotLeakStatusOfAForeignContract(): void {
		$this->signIn();
		$this->objectService->store['c-10'] = ['uuid' => 'c-10', 'status' => 'draft', 'ownerId' => 'someone-else'];

		$response = $this->controller->sendContractToInvoicing(id: 'c-10');

		// Not `invalid-status`: that answer would confirm the contract exists
		// and is not active — a working oracle over another user's data.
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['status' => 'forbidden'], $response->getData());
	}//end testSendToInvoicingDoesNotLeakStatusOfAForeignContract()

	/**
	 * A privileged-group member (sales) may act on a contract they do not own.
	 *
	 * This is the other half of the guard: without it a "deny everything" fix
	 * would pass every test above while breaking the app.
	 *
	 * @return void
	 */
	public function testSendToInvoicingAllowsAPrivilegedGroupMember(): void {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $group): bool => ($group === 'sales')
		);
		$this->rebuildControllerWithGroupManager($groupManager);

		$this->signIn();
		$this->objectService->store['c-11'] = ['uuid' => 'c-11', 'status' => 'active', 'ownerId' => 'someone-else'];
		$this->handoffService->method('hasImplementer')->willReturn(true);
		$this->handoffService->method('handoff')->willReturn(
			['ok' => true, 'targetUuid' => 'inv-9', 'correlationId' => 'corr-9', 'reason' => '']
		);

		$response = $this->controller->sendContractToInvoicing(id: 'c-11');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('sent', $response->getData()['status']);
	}//end testSendToInvoicingAllowsAPrivilegedGroupMember()

	/**
	 * An active contract with an invoice implementer present reports the full
	 * triple with `canSend: true`.
	 *
	 * @return void
	 */
	public function testContractAvailabilityAllowsSendingAnActiveContract(): void {
		$this->signIn();
		$this->objectService->store['c-1'] = ['uuid' => 'c-1', 'status' => 'active', 'ownerId' => 'agent-1'];
		$this->handoffService->method('hasImplementer')->willReturn(true);

		$response = $this->controller->contractAvailability(id: 'c-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['available' => true, 'status' => 'active', 'canSend' => true],
			$response->getData()
		);
	}//end testContractAvailabilityAllowsSendingAnActiveContract()

	/**
	 * A non-active contract reports its real status and refuses sending, even
	 * when an implementer is present.
	 *
	 * @return void
	 */
	public function testContractAvailabilityRefusesANonActiveContract(): void {
		$this->signIn();
		$this->objectService->store['c-2'] = ['uuid' => 'c-2', 'status' => 'draft', 'ownerId' => 'agent-1'];
		$this->handoffService->method('hasImplementer')->willReturn(true);

		$response = $this->controller->contractAvailability(id: 'c-2');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['available' => true, 'status' => 'draft', 'canSend' => false],
			$response->getData()
		);
	}//end testContractAvailabilityRefusesANonActiveContract()

	/**
	 * With no invoice implementer registered, an active contract still reports
	 * its status but `available` and `canSend` are both false.
	 *
	 * @return void
	 */
	public function testContractAvailabilityReportsNoImplementer(): void {
		$this->signIn();
		$this->objectService->store['c-3'] = ['uuid' => 'c-3', 'status' => 'active', 'ownerId' => 'agent-1'];
		$this->handoffService->method('hasImplementer')->willReturn(false);

		$response = $this->controller->contractAvailability(id: 'c-3');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['available' => false, 'status' => 'active', 'canSend' => false],
			$response->getData()
		);
	}//end testContractAvailabilityReportsNoImplementer()

	/**
	 * The probe is read-only — it must never write to the contract.
	 *
	 * @return void
	 */
	public function testContractAvailabilityWritesNothing(): void {
		$this->signIn();
		$this->objectService->store['c-4'] = ['uuid' => 'c-4', 'status' => 'active', 'ownerId' => 'agent-1'];
		$this->handoffService->method('hasImplementer')->willReturn(true);

		$this->controller->contractAvailability(id: 'c-4');

		$this->assertSame([], $this->objectService->saves);
	}//end testContractAvailabilityWritesNothing()
}//end class
