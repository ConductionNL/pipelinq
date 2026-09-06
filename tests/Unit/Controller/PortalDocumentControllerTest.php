<?php

/**
 * Contract tests for PortalDocumentController.
 *
 * The download endpoint is the portal's only unauthenticated data-bearing route:
 * it carries no bearer token at all, only a signed link, so the signature, the
 * TTL and the re-check of the bound account's access are the whole security
 * story. These tests therefore wire the REAL DocumentSigningService (genuine
 * HMAC over a fixed key) and the REAL invoice facade + scope resolver, so a
 * forged, expired, or no-longer-authorised link travels the production path.
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

use OCA\Pipelinq\Controller\PortalDocumentController;
use OCA\Pipelinq\Service\Portal\DocumentSigningService;
use OCA\Pipelinq\Service\Portal\MainRegisterReader;
use OCA\Pipelinq\Service\Portal\PortalAuditService;
use OCA\Pipelinq\Service\Portal\PortalDelegationService;
use OCA\Pipelinq\Service\Portal\PortalException;
use OCA\Pipelinq\Service\Portal\PortalProfileService;
use OCA\Pipelinq\Service\Portal\PortalTokenService;
use OCA\Pipelinq\Service\Portal\PortalMailService;
use OCP\IL10N;
use OCA\Pipelinq\Service\Portal\PortalExportService;
use OCA\Pipelinq\Service\Portal\PortalInvoiceService;
use OCA\Pipelinq\Service\Portal\PortalObjectRepository;
use OCA\Pipelinq\Service\Portal\PortalRequestGuard;
use OCA\Pipelinq\Service\Portal\PortalScopeResolver;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for PortalDocumentController.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A signed-download proxy fronts
 *  signing, access re-check, account store and audit; all four must be wired.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) One contract per token state.
 */
class PortalDocumentControllerTest extends TestCase {

	/**
	 * Fixed clock.
	 *
	 * @var int
	 */
	private const NOW = 1800000000;

	/**
	 * The account the signed links are bound to.
	 *
	 * @var array<string, mixed>
	 */
	private const ACCOUNT = [
		'@self' => ['id' => 'acct-1'],
		'email' => 'own@example.com',
		'status' => 'active',
		'tenantId' => 'tenant-a',
		'linkedOrganisationId' => 'client-own',
	];

	/**
	 * An invoice the account owns.
	 *
	 * @var array<string, mixed>
	 */
	private const OWN_INVOICE = [
		'@self' => ['id' => 'inv-own'],
		'client' => 'client-own',
		'invoiceNumber' => 'F-2026-0001',
		'confirmedAt' => '2026-03-01T10:00:00+00:00',
		'total' => 121.00,
	];

	/**
	 * An invoice owned by another customer.
	 *
	 * @var array<string, mixed>
	 */
	private const FOREIGN_INVOICE = [
		'@self' => ['id' => 'inv-foreign'],
		'client' => 'client-someone-else',
		'invoiceNumber' => 'F-2026-9999',
	];

	/**
	 * Request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * The main-register reader mock.
	 *
	 * @var MainRegisterReader&MockObject
	 */
	private $reader;

	/**
	 * The portal object repository mock (account store + scope resolver).
	 *
	 * @var PortalObjectRepository&MockObject
	 */
	private $repository;

	/**
	 * The guard mock.
	 *
	 * @var PortalRequestGuard&MockObject
	 */
	private $guard;

	/**
	 * The audit service mock.
	 *
	 * @var PortalAuditService&MockObject
	 */
	private $audit;

	/**
	 * Reset per-test state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->params = [];
		$this->reader = $this->createMock(MainRegisterReader::class);
		$this->repository = $this->createMock(PortalObjectRepository::class);
		$this->guard = $this->createMock(PortalRequestGuard::class);
		$this->audit = $this->createMock(PortalAuditService::class);

		$this->repository->method('idOf')->willReturnCallback(
			static fn (array $object): ?string => ($object['@self']['id'] ?? $object['id'] ?? null)
		);
	}//end setUp()

	/**
	 * A time factory pinned to a given instant.
	 *
	 * @param int $now The instant.
	 *
	 * @return ITimeFactory&MockObject The time factory.
	 */
	private function timeFactory(int $now) {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn($now);
		$time->method('getDateTime')->willReturnCallback(
			static fn (): \DateTime => new \DateTime('@' . $now)
		);
		return $time;
	}//end timeFactory()

	/**
	 * A real signing service over a fixed per-instance key, pinned to $now.
	 *
	 * @param int $now The instant.
	 *
	 * @return DocumentSigningService The signing service.
	 */
	private function signingService(int $now = self::NOW): DocumentSigningService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('fixed-per-instance-signing-key');

		return new DocumentSigningService($appConfig,
			$this->createMock(ISecureRandom::class),
			$this->timeFactory($now)
		);
	}//end signingService()

	/**
	 * Build the controller over the real signing service and the real invoice
	 * facade.
	 *
	 * @param DocumentSigningService|null $signing A signing service override.
	 * @param PortalDelegationService|null $delegations A delegation override.
	 *
	 * @return PortalDocumentController The controller.
	 */
	private function build(?DocumentSigningService $signing = null, ?PortalDelegationService $delegations = null): PortalDocumentController {
		if ($delegations === null) {
			$delegations = $this->createMock(PortalDelegationService::class);
			$delegations->method('getActiveScopes')->willReturn([]);
		}

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null): mixed => ($this->params[$key] ?? $default)
		);

		$scope = new PortalScopeResolver($this->repository, $delegations);

		return new PortalDocumentController($request,
			$this->guard,
			$this->createMock(LoggerInterface::class),
			($signing ?? $this->signingService()),
			new PortalInvoiceService($this->reader, $scope),
			$this->repository,
			$this->audit,
			// The REAL export service, not a mock: the data-export test asserts
			// the shape of the export it produces (account, auditEvents,
			// documents), which is the whole point of wiring buildExport() into
			// the download path. A mock would answer [] and the test would then
			// pass against an empty export -- the bug it was written for.
			new PortalExportService(
				// present() reads only the repository; the rest of this
				// service's collaborators are never reached from buildExport().
				new PortalProfileService(
					$this->repository,
					$this->createMock(PortalTokenService::class),
					$this->createMock(PortalMailService::class),
					$this->audit,
					$this->l10nStub()
				),
				$this->audit,
				new PortalInvoiceService($this->reader, $scope),
				($signing ?? $this->signingService()),
				$this->createMock(PortalMailService::class),
				$this->timeFactory(self::NOW),
				$this->l10nStub()
			)
		);
	}//end build()

	/**
	 * An IL10N that answers its input, so assertions read the English source.
	 *
	 * @return IL10N&MockObject The stub.
	 */
	private function l10nStub(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return $l10n;
	}//end l10nStub()

	/**
	 * The headers the response itself set.
	 *
	 * Response::getHeaders() merges in server-derived headers via the global
	 * Nextcloud service container, which does not exist in a bare unit run; the
	 * response's own header bag is read directly so the download's Content-Type
	 * and Content-Disposition can still be asserted.
	 *
	 * @param object $response The response.
	 *
	 * @return array<string, string> The headers set on the response.
	 */
	private function ownHeaders(object $response): array {
		$property = new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers');
		$property->setAccessible(true);
		return $property->getValue($response);
	}//end ownHeaders()

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
	 * Signing a document the account can see answers 200 with the link
	 * descriptor and a future expiry.
	 *
	 * @return void
	 */
	public function testSignIssuesALinkForAnAccessibleDocument(): void {
		$this->authenticate();
		$this->reader->method('find')->willReturn(self::OWN_INVOICE);
		$this->params = ['objectId' => 'inv-own', 'objectType' => 'invoice'];

		$response = $this->build()->sign();
		$body = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['downloadUrl', 'expiresAt'], array_keys($body));
		$this->assertStringStartsWith('/portal/api/documents/', $body['downloadUrl']);
		$this->assertStringEndsWith('/download', $body['downloadUrl']);
		$this->assertGreaterThan(self::NOW, $body['expiresAt']);
	}//end testSignIssuesALinkForAnAccessibleDocument()

	/**
	 * IDOR guard on link issuance: another customer's document id must not be
	 * signable. A link for an object you cannot read would defeat the whole
	 * signed-URL design.
	 *
	 * @return void
	 */
	public function testSignRefusesAnotherCustomersDocument(): void {
		$this->authenticate();
		$this->reader->method('find')->willReturn(self::FOREIGN_INVOICE);
		$this->params = ['objectId' => 'inv-foreign', 'objectType' => 'invoice'];

		$response = $this->build()->sign();
		$body = $response->getData();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('notFound', $body['errorCode']);
		$this->assertArrayNotHasKey('downloadUrl', $body);
	}//end testSignRefusesAnotherCustomersDocument()

	/**
	 * An omitted object id must fail closed rather than mint a link over an
	 * empty id.
	 *
	 * @return void
	 */
	public function testSignRefusesAnEmptyObjectId(): void {
		$this->authenticate();
		$this->reader->expects($this->never())->method('find');

		$response = $this->build()->sign();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('notFound', $response->getData()['errorCode']);
	}//end testSignRefusesAnEmptyObjectId()

	/**
	 * Link issuance requires a live portal session.
	 *
	 * @return void
	 */
	public function testSignReturnsUnauthorizedWithoutASession(): void {
		$this->guard->method('authenticate')->willThrowException(
			new PortalException(Http::STATUS_UNAUTHORIZED, 'unauthenticated', 'Niet ingelogd.')
		);
		$this->params = ['objectId' => 'inv-own'];

		$response = $this->build()->sign();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('unauthenticated', $response->getData()['errorCode']);
	}//end testSignReturnsUnauthorizedWithoutASession()

	/**
	 * Issue a signed token through the real signing service.
	 *
	 * @param string $objectId The object id.
	 * @param string $objectType The object type.
	 * @param string $accountId The bound account id.
	 * @param int $issuedAt The issuing instant.
	 *
	 * @return string The token.
	 */
	private function issueToken(string $objectId, string $objectType = 'invoice', string $accountId = 'acct-1', int $issuedAt = self::NOW): string {
		return $this->signingService($issuedAt)->generateUrl(
			objectId: $objectId,
			objectType: $objectType,
			accountId: $accountId
		)['token'];
	}//end issueToken()

	/**
	 * A valid, unexpired link whose bound account still has access streams the
	 * document as an attachment with the JSON content type.
	 *
	 * @return void
	 */
	public function testDownloadStreamsTheDocumentForAValidLink(): void {
		$this->repository->method('find')->willReturn(self::ACCOUNT);
		$this->reader->method('find')->willReturn(self::OWN_INVOICE);

		$response = $this->build()->download($this->issueToken('inv-own'));

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('application/json', $this->ownHeaders($response)['Content-Type']);

		// Assert the CONTRACT — an attachment, named for the document — not the
		// framework's quoting of it. `OCP\AppFramework\Http\DownloadResponse`
		// builds this header, and the bundled `vendor/nextcloud/ocp` stub quotes
		// the filename while the real server this suite runs against in CI does
		// not. Pinning the exact string asserts which OCP is on the include path,
		// not what this controller returns.
		$disposition = $this->ownHeaders($response)['Content-Disposition'];
		$this->assertStringStartsWith('attachment;', $disposition);
		$this->assertStringContainsString('invoice-inv-own.json', $disposition);

		$payload = json_decode($response->render(), true);
		$this->assertSame('invoice', $payload['objectType']);
		$this->assertSame('inv-own', $payload['objectId']);
		$this->assertArrayHasKey('generatedAt', $payload);
	}//end testDownloadStreamsTheDocumentForAValidLink()

	/**
	 * Every served download is audited against the bound account.
	 *
	 * @return void
	 */
	public function testDownloadAuditsTheAccess(): void {
		$this->repository->method('find')->willReturn(self::ACCOUNT);
		$this->reader->method('find')->willReturn(self::OWN_INVOICE);
		$this->audit->expects($this->once())->method('log')->with(
			'acct-1',
			'tenant-a',
			'document-download',
			'success',
			['targetObjectType' => 'invoice', 'targetObjectId' => 'inv-own']
		);

		$response = $this->build()->download($this->issueToken('inv-own'));

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testDownloadAuditsTheAccess()

	/**
	 * A legitimately-issued but stale link answers 410 Gone with the
	 * re-download hint, distinguishable from a forged link.
	 *
	 * @return void
	 */
	public function testDownloadReturnsGoneForAnExpiredLink(): void {
		$this->repository->method('find')->willReturn(self::ACCOUNT);
		$this->reader->method('find')->willReturn(self::OWN_INVOICE);

		$stale = $this->issueToken('inv-own', issuedAt: (self::NOW - 3600));
		$response = $this->build()->download($stale);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_GONE, $response->getStatus());
		$this->assertSame('linkExpired', $response->getData()['errorCode']);
	}//end testDownloadReturnsGoneForAnExpiredLink()

	/**
	 * A tampered payload invalidates the signature: 404, and the tampered
	 * object is never read.
	 *
	 * @return void
	 */
	public function testDownloadReturnsNotFoundForATamperedToken(): void {
		$this->repository->method('find')->willReturn(self::ACCOUNT);
		$this->reader->expects($this->never())->method('find');

		$token = $this->issueToken('inv-own');
		[, $signature] = explode('.', $token);
		$forgedPayload = rtrim(
			strtr(
				base64_encode(
					(string)json_encode(
						[
							'objectId' => 'inv-foreign',
							'objectType' => 'invoice',
							'accountId' => 'acct-1',
							'issuedAt' => self::NOW,
							'expiresAt' => (self::NOW + 300),
						]
					)
				),
				'+/',
				'-_'
			),
			'='
		);

		$response = $this->build()->download($forgedPayload . '.' . $signature);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('notFound', $response->getData()['errorCode']);
	}//end testDownloadReturnsNotFoundForATamperedToken()

	/**
	 * A structurally invalid token answers 404 rather than a framework error.
	 *
	 * @return void
	 */
	public function testDownloadReturnsNotFoundForAMalformedToken(): void {
		$response = $this->build()->download('not-a-token');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('notFound', $response->getData()['errorCode']);
	}//end testDownloadReturnsNotFoundForAMalformedToken()

	/**
	 * Defence in depth: a link stays valid only while its bound account still
	 * has access. Once the document is no longer in that account's scope the
	 * link answers 404 even though the signature and TTL are fine.
	 *
	 * @return void
	 */
	public function testDownloadRefusesWhenTheBoundAccountLostAccess(): void {
		$this->repository->method('find')->willReturn(self::ACCOUNT);
		$this->reader->method('find')->willReturn(self::FOREIGN_INVOICE);

		$response = $this->build()->download($this->issueToken('inv-foreign'));

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('notFound', $response->getData()['errorCode']);
		$this->assertStringNotContainsString('F-2026-9999', (string)json_encode($response->getData()));
	}//end testDownloadRefusesWhenTheBoundAccountLostAccess()

	/**
	 * A link issued before the account was closed must stop working: closure
	 * revokes access, and an outstanding signed URL must not outlive it.
	 *
	 * @return void
	 */
	public function testDownloadRefusesALinkBoundToAClosedAccount(): void {
		$this->repository->method('find')->willReturn(array_merge(self::ACCOUNT, ['status' => 'closed']));
		$this->reader->method('find')->willReturn(self::OWN_INVOICE);

		$response = $this->build()->download($this->issueToken('inv-own'));

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('notFound', $response->getData()['errorCode']);
	}//end testDownloadRefusesALinkBoundToAClosedAccount()

	/**
	 * A link whose bound account no longer exists answers 404.
	 *
	 * @return void
	 */
	public function testDownloadRefusesALinkBoundToAnUnknownAccount(): void {
		$this->repository->method('find')->willReturn(null);
		$this->reader->method('find')->willReturn(self::OWN_INVOICE);

		$response = $this->build()->download($this->issueToken('inv-own', accountId: 'acct-deleted'));

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('notFound', $response->getData()['errorCode']);
	}//end testDownloadRefusesALinkBoundToAnUnknownAccount()

	/**
	 * A storage fault while resolving the bound account must be indistinguishable
	 * from an unknown token: same 404, same body, no internal detail. This
	 * endpoint is anonymous, so a differing error would be a probing oracle.
	 *
	 * @return void
	 */
	public function testDownloadMasksAStorageFaultAsNotFound(): void {
		$this->repository->method('find')->willThrowException(
			new \RuntimeException('SQLSTATE[08006] could not connect to oc_pipelinq')
		);

		$faulted = $this->build()->download($this->issueToken('inv-own'));
		$unknown = $this->build()->download('not-a-token');

		$this->assertSame(Http::STATUS_NOT_FOUND, $faulted->getStatus());
		$this->assertSame($unknown->getStatus(), $faulted->getStatus());
		$this->assertSame($unknown->getData(), $faulted->getData());
		$this->assertStringNotContainsString('SQLSTATE', (string)json_encode($faulted->getData()));
	}//end testDownloadMasksAStorageFaultAsNotFound()

	/**
	 * A delegated grant is honoured on download too: an invoice visible only via
	 * an active `view-invoices` delegation still streams.
	 *
	 * @return void
	 */
	public function testDownloadHonoursAnActiveDelegation(): void {
		$delegations = $this->createMock(PortalDelegationService::class);
		$delegations->method('getActiveScopes')->willReturn(
			[['grantorAccountId' => 'acct-grantor', 'scopes' => ['view-invoices']]]
		);
		$this->repository->method('find')->willReturnCallback(
			static fn (string $schema, string $id): ?array => ($id === 'acct-grantor'
				? ['@self' => ['id' => 'acct-grantor'], 'linkedOrganisationId' => 'client-someone-else']
				: self::ACCOUNT)
		);
		$this->reader->method('find')->willReturn(self::FOREIGN_INVOICE);

		$response = $this->build(delegations: $delegations)->download($this->issueToken('inv-foreign'));

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testDownloadHonoursAnActiveDelegation()

	/**
	 * The AVG Art. 15 export link must deliver the account's exported record —
	 * profile, audit trail and accessible documents — not just a descriptor of
	 * the request. This is the contract PortalExportService::buildExport()
	 * declares and the only reason the export endpoint exists.
	 *
	 * @return void
	 */
	public function testDownloadOfADataExportLinkDeliversTheExportedRecord(): void {
		$this->repository->method('find')->willReturn(self::ACCOUNT);
		$this->reader->method('find')->willReturn(self::OWN_INVOICE);

		$response = $this->build()->download($this->issueToken('acct-1', objectType: 'data-export'));
		$payload = json_decode($response->render(), true);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertArrayHasKey('account', $payload);
		$this->assertArrayHasKey('auditEvents', $payload);
		$this->assertArrayHasKey('documents', $payload);
		$this->assertSame('own@example.com', $payload['account']['email']);
	}//end testDownloadOfADataExportLinkDeliversTheExportedRecord()

	/**
	 * A data-export link deliberately skips the per-document access re-check
	 * (the exported object IS the account), so the account gate is the only
	 * thing standing between a leaked 30-day link and the data: a closed
	 * account must still be refused.
	 *
	 * @return void
	 */
	public function testDownloadOfADataExportLinkStillRequiresALiveAccount(): void {
		$this->repository->method('find')->willReturn(array_merge(self::ACCOUNT, ['status' => 'closed']));

		$response = $this->build()->download($this->issueToken('acct-1', objectType: 'data-export'));

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('notFound', $response->getData()['errorCode']);
	}//end testDownloadOfADataExportLinkStillRequiresALiveAccount()
}//end class
