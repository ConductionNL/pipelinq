<?php

/**
 * Contract tests for PortalDataController.
 *
 * Invoices, contracts and orders are the portal's customer-scoped read surface
 * and every action is reachable unauthenticated over the internet, so these
 * tests deliberately wire the REAL read facades and the REAL scope resolver
 * behind the controller (only the register reader, the account store and the
 * tenant feature gate are mocked). A test that mocked the facade would prove
 * nothing about the per-customer boundary; wired this way, an id belonging to
 * another customer really does travel through the production filter.
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

use OCA\Pipelinq\Controller\PortalDataController;
use OCA\Pipelinq\Service\Portal\MainRegisterReader;
use OCA\Pipelinq\Service\Portal\PortalContractService;
use OCA\Pipelinq\Service\Portal\PortalDelegationService;
use OCA\Pipelinq\Service\Portal\PortalException;
use OCA\Pipelinq\Service\Portal\PortalInvoiceService;
use OCA\Pipelinq\Service\Portal\PortalObjectRepository;
use OCA\Pipelinq\Service\Portal\PortalOrderService;
use OCA\Pipelinq\Service\Portal\PortalRequestGuard;
use OCA\Pipelinq\Service\Portal\PortalScopeResolver;
use OCA\Pipelinq\Service\Portal\PortalTenantService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for PortalDataController.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Wiring the real read facades
 *  plus their real scope resolver is the point of these tests.
 */
class PortalDataControllerTest extends TestCase {

	/**
	 * The account authenticated by the guard in each test.
	 *
	 * @var array<string, mixed>
	 */
	private const OWN_ACCOUNT = [
		'@self' => ['id' => 'acct-own'],
		'email' => 'own@example.com',
		'status' => 'active',
		'linkedContactId' => 'contact-own',
		'linkedOrganisationId' => 'client-own',
	];

	/**
	 * A posTransaction owned by the authenticated account's client.
	 *
	 * @var array<string, mixed>
	 */
	private const OWN_TRANSACTION = [
		'@self' => ['id' => 'inv-own'],
		'client' => 'client-own',
		'invoiceNumber' => 'F-2026-0001',
		'reference' => 'ORD-0001',
		'confirmedAt' => '2026-03-01T10:00:00+00:00',
		'total' => 121.00,
		'status' => 'confirmed',
	];

	/**
	 * A posTransaction owned by a DIFFERENT customer's client.
	 *
	 * @var array<string, mixed>
	 */
	private const FOREIGN_TRANSACTION = [
		'@self' => ['id' => 'inv-foreign'],
		'client' => 'client-someone-else',
		'invoiceNumber' => 'F-2026-9999',
		'reference' => 'ORD-9999',
		'confirmedAt' => '2026-03-02T10:00:00+00:00',
		'total' => 9999.00,
		'status' => 'confirmed',
	];

	/**
	 * The main-register reader mock.
	 *
	 * @var MainRegisterReader&MockObject
	 */
	private $reader;

	/**
	 * The portal object repository mock (backs the scope resolver).
	 *
	 * @var PortalObjectRepository&MockObject
	 */
	private $repository;

	/**
	 * The delegation service mock.
	 *
	 * @var PortalDelegationService&MockObject
	 */
	private $delegations;

	/**
	 * The tenant service mock (feature gate).
	 *
	 * @var PortalTenantService&MockObject
	 */
	private $tenant;

	/**
	 * The guard mock.
	 *
	 * @var PortalRequestGuard&MockObject
	 */
	private $guard;

	/**
	 * The request mock.
	 *
	 * @var IRequest&MockObject
	 */
	private $request;

	/**
	 * The controller under test.
	 *
	 * @var PortalDataController
	 */
	private PortalDataController $controller;

	/**
	 * Wire the controller to the real facades over mocked storage.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->reader = $this->createMock(MainRegisterReader::class);
		$this->repository = $this->createMock(PortalObjectRepository::class);
		$this->delegations = $this->createMock(PortalDelegationService::class);
		$this->tenant = $this->createMock(PortalTenantService::class);
		$this->guard = $this->createMock(PortalRequestGuard::class);
		$this->request = $this->createMock(IRequest::class);

		$this->repository->method('idOf')->willReturnCallback(
			static fn (array $object): ?string => ($object['@self']['id'] ?? $object['id'] ?? null)
		);
		$this->delegations->method('getActiveScopes')->willReturn([]);
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => $default
		);

		$scope = new PortalScopeResolver($this->repository, $this->delegations);

		$this->controller = new PortalDataController(
			$this->request,
			$this->guard,
			$this->createMock(LoggerInterface::class),
			new PortalInvoiceService($this->reader, $scope),
			new PortalContractService($this->reader, $scope),
			new PortalOrderService($this->reader, $scope),
			$this->tenant
		);
	}//end setUp()

	/**
	 * Authenticate the guard as the own account and enable every feature.
	 *
	 * @return void
	 */
	private function authenticateAsOwnAccount(): void {
		$this->guard->method('authenticate')->willReturn(
			[
				'account' => self::OWN_ACCOUNT,
				'accountId' => 'acct-own',
				'session' => ['@self' => ['id' => 'sess-1'], 'accountId' => 'acct-own'],
				'tenantId' => 'tenant-a',
			]
		);
	}//end authenticateAsOwnAccount()

	/**
	 * The invoice list answers 200 with the paginated envelope and only rows the
	 * account owns — a foreign row present in the register is dropped.
	 *
	 * @return void
	 */
	public function testInvoicesReturnsOnlyTheAccountsOwnRows(): void {
		$this->authenticateAsOwnAccount();
		$this->reader->method('findAll')->willReturn([self::OWN_TRANSACTION, self::FOREIGN_TRANSACTION]);

		$response = $this->controller->invoices();
		$body = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['total', 'page', 'perPage', 'items'], array_keys($body));
		$this->assertSame(1, $body['total']);
		$this->assertCount(1, $body['items']);
		$this->assertSame('inv-own', $body['items'][0]['id']);
		$this->assertSame('F-2026-0001', $body['items'][0]['invoiceNumber']);
		$this->assertSame(121.00, $body['items'][0]['amount']);
		$this->assertNull($body['items'][0]['delegatedFrom']);
		$this->assertNotContains('inv-foreign', array_column($body['items'], 'id'));
	}//end testInvoicesReturnsOnlyTheAccountsOwnRows()

	/**
	 * A tenant that has not enabled the invoices feature gets 404
	 * featureNotEnabled — the same status an unknown resource gets, so a
	 * disabled feature is not a probe signal.
	 *
	 * @return void
	 */
	public function testInvoicesReturnsNotFoundWhenFeatureIsDisabled(): void {
		$this->authenticateAsOwnAccount();
		$this->tenant->method('requireFeature')->willThrowException(
			new PortalException(Http::STATUS_NOT_FOUND, 'featureNotEnabled', 'Deze functie is niet beschikbaar.')
		);

		$response = $this->controller->invoices();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('featureNotEnabled', $response->getData()['errorCode']);
	}//end testInvoicesReturnsNotFoundWhenFeatureIsDisabled()

	/**
	 * With no bearer session the list endpoint answers 401 and no register read
	 * is attempted.
	 *
	 * @return void
	 */
	public function testInvoicesReturnsUnauthorizedWithoutASession(): void {
		$this->guard->method('authenticate')->willThrowException(
			new PortalException(Http::STATUS_UNAUTHORIZED, 'unauthenticated', 'Niet ingelogd.')
		);
		$this->reader->expects($this->never())->method('findAll');

		$response = $this->controller->invoices();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('unauthenticated', $response->getData()['errorCode']);
	}//end testInvoicesReturnsUnauthorizedWithoutASession()

	/**
	 * The detail endpoint answers 200 with the safe invoice projection for an id
	 * the account owns.
	 *
	 * @return void
	 */
	public function testInvoiceReturnsTheSafeProjectionForAnOwnedId(): void {
		$this->authenticateAsOwnAccount();
		$this->reader->method('find')->willReturn(self::OWN_TRANSACTION);

		$response = $this->controller->invoice('inv-own');
		$body = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['id', 'invoiceNumber', 'date', 'amount', 'status', 'delegatedFrom'],
			array_keys($body)
		);
		$this->assertSame('inv-own', $body['id']);
		$this->assertSame('2026-03-01T10:00:00+00:00', $body['date']);
	}//end testInvoiceReturnsTheSafeProjectionForAnOwnedId()

	/**
	 * IDOR guard: an invoice id belonging to another customer must answer 404
	 * and leak none of that customer's fields.
	 *
	 * @return void
	 */
	public function testInvoiceReturnsNotFoundForAnotherCustomersId(): void {
		$this->authenticateAsOwnAccount();
		$this->reader->method('find')->willReturn(self::FOREIGN_TRANSACTION);

		$response = $this->controller->invoice('inv-foreign');
		$body = $response->getData();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('notFound', $body['errorCode']);
		$this->assertArrayNotHasKey('invoiceNumber', $body);
		$this->assertArrayNotHasKey('amount', $body);
		$this->assertStringNotContainsString('9999', json_encode($body));
	}//end testInvoiceReturnsNotFoundForAnotherCustomersId()

	/**
	 * A non-existent id and another customer's id must be indistinguishable —
	 * same status, byte-identical body — so ids cannot be enumerated.
	 *
	 * @return void
	 */
	public function testInvoiceDoesNotLeakExistenceOfForeignIds(): void {
		$this->authenticateAsOwnAccount();
		$this->reader->method('find')->willReturnCallback(
			static fn (string $schemaKey, string $id): ?array => ($id === 'inv-foreign' ? self::FOREIGN_TRANSACTION : null)
		);

		$foreign = $this->controller->invoice('inv-foreign');
		$unknown = $this->controller->invoice('does-not-exist');

		$this->assertSame($unknown->getStatus(), $foreign->getStatus());
		$this->assertSame($unknown->getData(), $foreign->getData());
	}//end testInvoiceDoesNotLeakExistenceOfForeignIds()

	/**
	 * The contract list answers 200 with the paginated envelope and the safe
	 * contract projection for an owned row.
	 *
	 * @return void
	 */
	public function testContractsReturnsTheSafeContractProjection(): void {
		$this->authenticateAsOwnAccount();
		$this->reader->method('findAll')->willReturn(
			[
				[
					'@self' => ['id' => 'con-own'],
					'contact' => 'contact-own',
					'contractNumber' => 'C-001',
					'startDate' => '2026-01-01',
					'endDate' => '2026-12-31',
					'value' => 1200,
					'status' => 'active',
				],
				[
					'@self' => ['id' => 'con-foreign'],
					'contact' => 'contact-someone-else',
					'client' => 'client-someone-else',
				],
			]
		);

		$response = $this->controller->contracts();
		$body = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(1, $body['total']);
		$this->assertSame('con-own', $body['items'][0]['id']);
		$this->assertSame('C-001', $body['items'][0]['contractNumber']);
		$this->assertSame('2026-12-31', $body['items'][0]['endDate']);
		$this->assertNotContains('con-foreign', array_column($body['items'], 'id'));
	}//end testContractsReturnsTheSafeContractProjection()

	/**
	 * The contracts list is feature-gated on `contracts`, not on `invoices`.
	 *
	 * @return void
	 */
	public function testContractsIsGatedOnTheContractsFeature(): void {
		$this->authenticateAsOwnAccount();
		$this->tenant->expects($this->once())->method('requireFeature')
			->with(tenantId: 'tenant-a', feature: 'contracts');
		$this->reader->method('findAll')->willReturn([]);

		$response = $this->controller->contracts();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(0, $response->getData()['total']);
		$this->assertSame([], $response->getData()['items']);
	}//end testContractsIsGatedOnTheContractsFeature()

	/**
	 * The contract detail endpoint answers 200 with the safe contract
	 * projection for an id the account owns.
	 *
	 * @return void
	 */
	public function testContractReturnsTheSafeProjectionForAnOwnedId(): void {
		$this->authenticateAsOwnAccount();
		$this->reader->method('find')->willReturn(
			[
				'@self' => ['id' => 'con-own'],
				'contact' => 'contact-own',
				'contractNumber' => 'C-001',
				'startDate' => '2026-01-01',
				'endDate' => '2026-12-31',
				'value' => 1200,
				'status' => 'active',
				'internalMargin' => 0.42,
			]
		);

		$response = $this->controller->contract('con-own');
		$body = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['id', 'contractNumber', 'startDate', 'endDate', 'value', 'status', 'delegatedFrom'],
			array_keys($body)
		);
		$this->assertSame('con-own', $body['id']);
		$this->assertSame(1200, $body['value']);
		$this->assertArrayNotHasKey('internalMargin', $body);
	}//end testContractReturnsTheSafeProjectionForAnOwnedId()

	/**
	 * IDOR guard on the contract detail endpoint.
	 *
	 * @return void
	 */
	public function testContractReturnsNotFoundForAnotherCustomersId(): void {
		$this->authenticateAsOwnAccount();
		$this->reader->method('find')->willReturn(
			[
				'@self' => ['id' => 'con-foreign'],
				'contact' => 'contact-someone-else',
				'contractNumber' => 'C-SECRET',
			]
		);

		$response = $this->controller->contract('con-foreign');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('notFound', $response->getData()['errorCode']);
		$this->assertStringNotContainsString('C-SECRET', json_encode($response->getData()));
	}//end testContractReturnsNotFoundForAnotherCustomersId()

	/**
	 * The order list answers 200 with the order-shaped projection (orderNumber /
	 * total), which differs from the invoice projection over the same record.
	 *
	 * @return void
	 */
	public function testOrdersReturnsTheOrderShapedProjection(): void {
		$this->authenticateAsOwnAccount();
		$this->reader->method('findAll')->willReturn([self::OWN_TRANSACTION, self::FOREIGN_TRANSACTION]);

		$response = $this->controller->orders();
		$body = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(1, $body['total']);
		$this->assertSame(
			['id', 'orderNumber', 'date', 'total', 'status', 'delegatedFrom'],
			array_keys($body['items'][0])
		);
		$this->assertSame('ORD-0001', $body['items'][0]['orderNumber']);
		$this->assertSame(121.00, $body['items'][0]['total']);
	}//end testOrdersReturnsTheOrderShapedProjection()

	/**
	 * Pagination is clamped server-side: a caller asking for page 0 and 5000
	 * rows per page gets page 1 and the 100-row ceiling reported back.
	 *
	 * @return void
	 */
	public function testOrdersClampsClientSuppliedPagination(): void {
		$this->authenticateAsOwnAccount();
		$paged = $this->createMock(IRequest::class);
		$paged->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null): mixed {
				return ([
					'page' => 0,
					'perPage' => 5000,
				][$key] ?? $default);
			}
		);

		$scope = new PortalScopeResolver($this->repository, $this->delegations);
		$controller = new PortalDataController(
			$paged,
			$this->guard,
			$this->createMock(LoggerInterface::class),
			new PortalInvoiceService($this->reader, $scope),
			new PortalContractService($this->reader, $scope),
			new PortalOrderService($this->reader, $scope),
			$this->tenant
		);
		$this->reader->method('findAll')->willReturn([self::OWN_TRANSACTION]);

		$body = $controller->orders()->getData();

		$this->assertSame(1, $body['page']);
		$this->assertSame(100, $body['perPage']);
		$this->assertSame(1, $body['total']);
	}//end testOrdersClampsClientSuppliedPagination()

	/**
	 * IDOR guard on the order detail endpoint: another customer's order id is
	 * 404 with no amount disclosed.
	 *
	 * @return void
	 */
	public function testOrderReturnsNotFoundForAnotherCustomersId(): void {
		$this->authenticateAsOwnAccount();
		$this->reader->method('find')->willReturn(self::FOREIGN_TRANSACTION);

		$response = $this->controller->order('inv-foreign');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('notFound', $response->getData()['errorCode']);
		$this->assertStringNotContainsString('9999', json_encode($response->getData()));
	}//end testOrderReturnsNotFoundForAnotherCustomersId()

	/**
	 * An owned order id answers 200 with the order projection.
	 *
	 * @return void
	 */
	public function testOrderReturnsTheProjectionForAnOwnedId(): void {
		$this->authenticateAsOwnAccount();
		$this->reader->method('find')->willReturn(self::OWN_TRANSACTION);

		$response = $this->controller->order('inv-own');
		$body = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('inv-own', $body['id']);
		$this->assertSame('ORD-0001', $body['orderNumber']);
		$this->assertNull($body['delegatedFrom']);
	}//end testOrderReturnsTheProjectionForAnOwnedId()

	/**
	 * An account with no linked contact or organisation (a freshly registered,
	 * unlinked portal account) must see nothing rather than everything — the
	 * empty-scope case is the classic fail-open shape.
	 *
	 * @return void
	 */
	public function testUnlinkedAccountSeesNoRowsAtAll(): void {
		$this->guard->method('authenticate')->willReturn(
			[
				'account' => ['@self' => ['id' => 'acct-new'], 'status' => 'active'],
				'accountId' => 'acct-new',
				'session' => ['@self' => ['id' => 'sess-2']],
				'tenantId' => 'tenant-a',
			]
		);
		$this->reader->method('findAll')->willReturn([self::OWN_TRANSACTION, self::FOREIGN_TRANSACTION]);
		$this->reader->method('find')->willReturn(self::OWN_TRANSACTION);

		$list = $this->controller->invoices();
		$detail = $this->controller->invoice('inv-own');

		$this->assertSame(Http::STATUS_OK, $list->getStatus());
		$this->assertSame(0, $list->getData()['total']);
		$this->assertSame([], $list->getData()['items']);
		$this->assertSame(Http::STATUS_NOT_FOUND, $detail->getStatus());
	}//end testUnlinkedAccountSeesNoRowsAtAll()

	/**
	 * Data delegated under the matching scope is visible and tagged with the
	 * grantor account id, so the client can show whose data it is.
	 *
	 * @return void
	 */
	public function testDelegatedRowsAreVisibleAndTaggedWithTheGrantor(): void {
		$this->authenticateAsOwnAccount();
		$delegations = $this->createMock(PortalDelegationService::class);
		$delegations->method('getActiveScopes')->willReturn(
			[['grantorAccountId' => 'acct-grantor', 'scopes' => ['view-invoices']]]
		);
		$this->repository->method('find')->willReturn(
			['@self' => ['id' => 'acct-grantor'], 'linkedOrganisationId' => 'client-someone-else']
		);

		$scope = new PortalScopeResolver($this->repository, $delegations);
		$controller = new PortalDataController(
			$this->request,
			$this->guard,
			$this->createMock(LoggerInterface::class),
			new PortalInvoiceService($this->reader, $scope),
			new PortalContractService($this->reader, $scope),
			new PortalOrderService($this->reader, $scope),
			$this->tenant
		);
		$this->reader->method('findAll')->willReturn([self::FOREIGN_TRANSACTION]);

		$body = $controller->invoices()->getData();

		$this->assertSame(1, $body['total']);
		$this->assertSame('inv-foreign', $body['items'][0]['id']);
		$this->assertSame('acct-grantor', $body['items'][0]['delegatedFrom']);
	}//end testDelegatedRowsAreVisibleAndTaggedWithTheGrantor()

	/**
	 * A delegation that does not carry the facade's scope must not widen
	 * visibility: a grant of `view-contracts` alone does not expose invoices.
	 *
	 * @return void
	 */
	public function testDelegationOfAnotherScopeDoesNotWidenInvoiceVisibility(): void {
		$this->authenticateAsOwnAccount();
		$delegations = $this->createMock(PortalDelegationService::class);
		$delegations->method('getActiveScopes')->willReturn(
			[['grantorAccountId' => 'acct-grantor', 'scopes' => ['view-contracts']]]
		);
		$this->repository->method('find')->willReturn(
			['@self' => ['id' => 'acct-grantor'], 'linkedOrganisationId' => 'client-someone-else']
		);

		$scope = new PortalScopeResolver($this->repository, $delegations);
		$controller = new PortalDataController(
			$this->request,
			$this->guard,
			$this->createMock(LoggerInterface::class),
			new PortalInvoiceService($this->reader, $scope),
			new PortalContractService($this->reader, $scope),
			new PortalOrderService($this->reader, $scope),
			$this->tenant
		);
		$this->reader->method('find')->willReturn(self::FOREIGN_TRANSACTION);

		$response = $controller->invoice('inv-foreign');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('notFound', $response->getData()['errorCode']);
	}//end testDelegationOfAnotherScopeDoesNotWidenInvoiceVisibility()
}//end class
