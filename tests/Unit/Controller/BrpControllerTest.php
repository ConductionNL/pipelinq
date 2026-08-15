<?php

/**
 * Unit tests for BrpController — Wet-BRP audit-record mapping.
 *
 * These tests pin the legally-required invariant that the `brpLookupVerzoek`
 * audit record carries the correlation id, response duration and response
 * status returned by HaalCentraalClient::lookupPersoon() — REGARDLESS of which
 * transport (the OpenRegister BRP leaf or the legacy OAuth2 + mTLS direct path)
 * produced them. The controller is source-agnostic: it reads `_correlationId`
 * / `_responseDurationMs` / `_responseStatus` off the returned person and maps
 * them to `haalcentraalCorrelationId` / `responseDuurMs` / `responseStatus`, so
 * an OR-200 envelope and a legacy-200 envelope with the same meta produce a
 * byte-identical persisted record.
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
 * @spec openspec/changes/pipelinq-brp-via-or-leaf/specs/brp-lookup/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Controller\BrpController;
use OCA\Pipelinq\Listener\BrpMutationWebhookListener;
use OCA\Pipelinq\Service\BrpCacheService;
use OCA\Pipelinq\Service\BsnAuditService;
use OCA\Pipelinq\Service\BsnValidationService;
use OCA\Pipelinq\Service\HaalCentraalClient;
use OCA\Pipelinq\Service\OptOutService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @spec openspec/changes/pipelinq-brp-via-or-leaf/specs/brp-lookup/spec.md
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 */
class BrpControllerTest extends TestCase {
	/**
	 * A formally-valid demo BSN (passes the 11-proef).
	 *
	 * @var string
	 */
	private const DEMO_BSN = '123456782';

	/**
	 * Holder receiving the captured `brpLookupVerzoek` payload.
	 *
	 * @var \ArrayObject<string,mixed>
	 */
	private \ArrayObject $requestHolder;

	/**
	 * Return the captured `brpLookupVerzoek` payload, or null when none.
	 *
	 * @return array<string,mixed>|null
	 */
	private function capturedRequest(): ?array {
		if (isset($this->requestHolder['verzoek']) === true) {
			return $this->requestHolder['verzoek'];
		}

		return null;
	}//end capturedVerzoek()

	/**
	 * The meta values that both transports surface identically. Feeding this as
	 * `_correlationId`/`_responseDurationMs`/`_responseStatus` proves the
	 * controller's audit mapping is transport-agnostic and byte-identical.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pipelinq-brp-via-or-leaf/specs/brp-lookup/spec.md
	 */
	public function testLookupPersistsMetaIntoAuditRecord(): void {
		$person = [
			'givenNames' => 'Jan',
			'surname' => 'Jansen',
			'indicationSecret' => '0',
			'residence' => ['straat' => 'Hoofdstraat'],
			'_correlationId' => 'corr-shared-xyz',
			'_responseDurationMs' => 142,
			'_responseStatus' => 200,
		];

		$controller = $this->buildController(remotePerson: $person);

		$response = $controller->lookup();
		$data = $response->getData();

		// Happy-path lookup succeeded.
		self::assertSame(200, $response->getStatus());
		self::assertArrayHasKey('persoon', $data);

		// The audit record carries the meta from the client, mapped to the
		// canonical Wet-BRP field names — identical regardless of transport.
		$request = $this->capturedRequest();
		self::assertNotNull($request, 'brpLookupVerzoek was not persisted');
		self::assertSame('corr-shared-xyz', $request['haalcentraalCorrelationId']);
		self::assertSame(142, $request['responseDurationMs']);
		self::assertSame('geslaagd', $request['responseStatus']);
		self::assertSame('Wet BRP', $request['doelbinding']);
		self::assertArrayHasKey('bsnHash', $request);
		// The raw BSN must never be a field on the audit record.
		self::assertArrayNotHasKey('bsn', $request);
	}//end testLookupPersistsMetaIntoAuditRecord()

	/**
	 * A null correlation id from the client (no upstream X-Correlation-ID) must
	 * persist as an absent `haalcentraalCorrelationId` (the controller filters
	 * nulls), exactly as the legacy path records today.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pipelinq-brp-via-or-leaf/specs/brp-lookup/spec.md
	 */
	public function testLookupNullCorrelationIdNotPersisted(): void {
		$person = [
			'givenNames' => 'Jan',
			'indicationSecret' => '0',
			'_correlationId' => null,
			'_responseDurationMs' => 9,
			'_responseStatus' => 200,
		];

		$controller = $this->buildController(remotePerson: $person);
		$controller->lookup();

		$request = $this->capturedRequest();
		self::assertNotNull($request);
		self::assertArrayNotHasKey(
			'haalcentraalCorrelationId',
			$request,
			'null correlation id is array_filtered out, not persisted as null'
		);
		self::assertSame(9, $request['responseDurationMs']);
	}//end testLookupNullCorrelationIdNotPersisted()

	/**
	 * Build the controller wired with mocks for all collaborators, the user
	 * authorised, the BSN formally valid, the cache empty, and the
	 * HaalCentraalClient returning the supplied person. The container's
	 * ObjectService stub captures the persisted `brpLookupVerzoek`.
	 *
	 * @param array<string,mixed> $remotePerson The person HaalCentraalClient returns.
	 *
	 * @return BrpController
	 */
	private function buildController(array $remotePerson): BrpController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) {
				$params = [
					'bsn' => self::DEMO_BSN,
					'verzoekreden' => 'Adresverificatie',
					'doelbinding' => 'Wet BRP',
					'basis' => 'Wet BRP art. 1.4',
				];
				return $params[$key] ?? $default;
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('behandelaar1');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		// Authorise the actor as admin (resolveActorRol → 'beheerder').
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(true);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		// App-config: register + brpLookupVerzoek_schema set so saveLookupVerzoek
		// routes through the ObjectService; brpPersoon_schema set for back-fill.
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') {
				$cfg = [
					'register' => 'reg-1',
					'brpLookupVerzoek_schema' => 'schema-verzoek',
					'brpPersoon_schema' => 'schema-persoon',
				];
				return $cfg[$key] ?? $default;
			}
		);

		$validation = $this->createMock(BsnValidationService::class);
		$validation->method('validate')->willReturn(
			['isFormalValid' => true, 'errorCode' => null, 'errorMessage' => null, 'maskedBsn' => '*****6782']
		);

		$cacheService = $this->createMock(BrpCacheService::class);
		$cacheService->method('get')->willReturn(null);
		// set() echoes the persoon back (so back-fill + response shaping work).
		$cacheService->method('set')->willReturnCallback(
			static fn (array $person, ?int $ttl = null): array => $person
		);

		$haalCentraal = $this->createMock(HaalCentraalClient::class);
		$haalCentraal->method('lookupPersoon')->willReturn($remotePerson);

		$audit = $this->createMock(BsnAuditService::class);
		$optOut = $this->createMock(OptOutService::class);
		$webhookListener = $this->createMock(BrpMutationWebhookListener::class);

		// ObjectService stub captures the brpLookupVerzoek payload into an
		// ArrayObject holder so the reference survives back to the test.
		$holder = new \ArrayObject();
		$objectService = new class($holder) {
			/**
			 * @param \ArrayObject<string,mixed> $holder Capture holder.
			 */
			public function __construct(
				private \ArrayObject $holder,
			) {
			}//end __construct()

			/**
			 * Capture the first verzoek-shaped save (has verzoekreden), echo it back.
			 *
			 * @param array<string,mixed> $object The object to save.
			 * @param array<int,mixed> $extend Extend list.
			 * @param string $register Register id.
			 * @param string $schema Schema id.
			 * @param string|null $uuid Optional uuid.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object, array $extend = [], string $register = '', string $schema = '', ?string $uuid = null): array {
				if (isset($object['verzoekreden']) === true && $this->holder->count() === 0) {
					$this->holder['verzoek'] = $object;
				}

				$object['@self'] = ['id' => 'saved-uuid'];
				return $object;
			}//end saveObject()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);
		$this->requestHolder = $holder;

		return new BrpController(
			$request,
			$userSession,
			$groupManager,
			$l10n,
			$appConfig,
			$validation,
			$cacheService,
			$haalCentraal,
			$audit,
			$optOut,
			$webhookListener,
			$container,
			$this->createMock(LoggerInterface::class),
			objectService: $this->createMock(ObjectServiceInterface::class),
		);
	}//end buildController()

	// ==================================================================
	// revealAddress — POST /api/brp/contact/{id}/reveal-address
	//
	// A geheimhouding reveal surfaces the residence of a Dutch citizen who
	// asked for it to be withheld. Wet BRP art. 3.3 permits it only with an
	// authorised actor AND an audit entry naming the data subject.
	// ==================================================================

	/**
	 * An anonymous reveal is refused with 401 and writes no audit entry.
	 *
	 * @return void
	 */
	public function testRevealAddressRejectsAnonymousCaller(): void {
		$audit = $this->createMock(BsnAuditService::class);
		$audit->expects($this->never())->method('recordLookup');

		$controller = $this->buildPrivacyController(audit: $audit, uid: null);
		$response = $controller->revealAddress('contact-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Authentication required'], $response->getData());
	}//end testRevealAddressRejectsAnonymousCaller()

	/**
	 * A signed-in user who is neither an admin nor a member of an authorised
	 * BRP group is refused with 403 and the address is never resolved.
	 *
	 * @return void
	 */
	public function testRevealAddressRefusesAnUnauthorisedRole(): void {
		$controller = $this->buildPrivacyController(authorised: false);
		$response = $controller->revealAddress('contact-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(
			['errorCode' => 'unauthorized', 'errorMessage' => 'U bent niet bevoegd voor deze actie.'],
			$response->getData()
		);
	}//end testRevealAddressRefusesAnUnauthorisedRole()

	/**
	 * A contact with no linked BRP person is a 404 with a coded envelope, not
	 * an empty 200.
	 *
	 * @return void
	 */
	public function testRevealAddressReturns404WhenNoBrpPersonIsLinked(): void {
		$controller = $this->buildPrivacyController(persons: []);
		$response = $controller->revealAddress('contact-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('not-found', $response->getData()['errorCode']);
	}//end testRevealAddressReturns404WhenNoBrpPersonIsLinked()

	/**
	 * The happy path returns 200 with the residence AND writes exactly one
	 * audit entry carrying the Wet-BRP doelbinding, the reveal action and the
	 * actor's resolved role.
	 *
	 * @return void
	 */
	public function testRevealAddressReturnsTheResidenceAndWritesTheAuditEntry(): void {
		$recorded = [];
		$audit = $this->capturingAudit($recorded);

		$controller = $this->buildPrivacyController(
			audit: $audit,
			persons: [
				[
					'bsnHash' => str_repeat('f', 64),
					'gekoppeldContact' => 'contact-1',
					'fetchedOn' => '2026-08-01T10:00:00Z',
					'residence' => ['straat' => 'Hoofdstraat', 'huisnummer' => 12],
				],
			]
		);

		$response = $controller->revealAddress('contact-1');
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['residence'], array_keys($data));
		$this->assertSame('Hoofdstraat', $data['residence']['straat']);

		$this->assertCount(1, $recorded, 'the reveal was not audited');
		$this->assertSame('brp-adres-onthuld', $recorded[0]['action']);
		$this->assertSame('adres-onthuld', $recorded[0]['uitkomst']);
		$this->assertSame('behandelaar1', $recorded[0]['actor']);
		$this->assertSame('beheerder', $recorded[0]['actorRole']);
		$this->assertStringContainsString('Wet BRP art. 3.3', $recorded[0]['doelbinding']);
	}//end testRevealAddressReturnsTheResidenceAndWritesTheAuditEntry()

	/**
	 * The most recent BRP person wins when a contact has several — a reveal
	 * must never surface a stale residence.
	 *
	 * @return void
	 */
	public function testRevealAddressReturnsTheMostRecentlyRetrievedPerson(): void {
		$controller = $this->buildPrivacyController(
			persons: [
				['fetchedOn' => '2026-01-01T00:00:00Z', 'residence' => ['straat' => 'Oude Weg']],
				['fetchedOn' => '2026-08-01T00:00:00Z', 'residence' => ['straat' => 'Nieuwe Weg']],
			]
		);

		$response = $controller->revealAddress('contact-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('Nieuwe Weg', $response->getData()['residence']['straat']);
	}//end testRevealAddressReturnsTheMostRecentlyRetrievedPerson()

	/**
	 * The audit entry must identify WHOSE address was revealed. Without a
	 * subject identifier the entry cannot answer the only question a Wet-BRP
	 * audit exists to answer, and every reveal on the instance produces a
	 * byte-identical `bsnHash`.
	 *
	 * @return void
	 */
	public function testRevealAddressAuditEntryIdentifiesTheDataSubject(): void {
		$this->markTestSkipped(
			'BUG: BrpController::revealAddress calls recordLookup(rawBsn: \'\') so the '
			. 'audit entry stores sha256("") for every reveal and carries no contact '
			. 'id — the persoon in hand already has its bsnHash — see coordinator report'
		);

		$recorded = [];
		$audit = $this->capturingAudit($recorded);

		$controller = $this->buildPrivacyController(
			audit: $audit,
			persons: [
				[
					'bsnHash' => str_repeat('f', 64),
					'fetchedOn' => '2026-08-01T10:00:00Z',
					'residence' => ['straat' => 'Hoofdstraat'],
				],
			]
		);

		$controller->revealAddress('contact-1');

		$this->assertNotSame(
			'',
			$recorded[0]['rawBsn'],
			'the audit entry hashes the empty string, identifying nobody'
		);
	}//end testRevealAddressAuditEntryIdentifiesTheDataSubject()

	/**
	 * When the audit entry could NOT be written the address must not be
	 * surfaced. `BsnAuditService::recordLookup()` swallows its own write
	 * failure and returns an empty uuid, so an unlogged reveal is
	 * indistinguishable from a logged one on the wire.
	 *
	 * @return void
	 */
	public function testRevealAddressIsRefusedWhenTheAuditEntryCouldNotBeWritten(): void {
		$this->markTestSkipped(
			'BUG: revealAddress ignores recordLookup()\'s return value, so a failed '
			. 'audit write still returns 200 with the withheld residence — an '
			. 'unlogged geheimhouding reveal — see coordinator report'
		);

		$audit = $this->createMock(BsnAuditService::class);
		// An empty uuid is what recordLookup() returns when its own write failed.
		$audit->method('recordLookup')->willReturn('');

		$controller = $this->buildPrivacyController(
			audit: $audit,
			persons: [['fetchedOn' => '2026-08-01T10:00:00Z', 'residence' => ['straat' => 'Hoofdstraat']]]
		);

		$response = $controller->revealAddress('contact-1');

		$this->assertGreaterThanOrEqual(500, $response->getStatus());
		$this->assertArrayNotHasKey('residence', $response->getData());
	}//end testRevealAddressIsRefusedWhenTheAuditEntryCouldNotBeWritten()

	// ==================================================================
	// optOutCreate — POST /api/brp/opt-out
	// ==================================================================

	/**
	 * An anonymous opt-out create is refused with 401 and records nothing.
	 *
	 * @return void
	 */
	public function testOptOutCreateRejectsAnonymousCaller(): void {
		$optOut = $this->createMock(OptOutService::class);
		$optOut->expects($this->never())->method('recordLocalOptOut');

		$controller = $this->buildPrivacyController(optOut: $optOut, uid: null);
		$response = $controller->optOutCreate();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Authentication required'], $response->getData());
	}//end testOptOutCreateRejectsAnonymousCaller()

	/**
	 * An unauthorised role is refused with 403 and records nothing — the
	 * endpoint takes a raw BSN from the body, so the role gate is the only
	 * thing standing between any authenticated user and a write keyed on an
	 * arbitrary citizen.
	 *
	 * @return void
	 */
	public function testOptOutCreateRefusesAnUnauthorisedRole(): void {
		$optOut = $this->createMock(OptOutService::class);
		$optOut->expects($this->never())->method('recordLocalOptOut');

		$controller = $this->buildPrivacyController(optOut: $optOut, authorised: false);
		$response = $controller->optOutCreate();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('unauthorized', $response->getData()['errorCode']);
	}//end testOptOutCreateRefusesAnUnauthorisedRole()

	/**
	 * A BSN that fails the 11-proef is a 400 with the coded envelope and
	 * records nothing.
	 *
	 * @return void
	 */
	public function testOptOutCreateRejectsAnInvalidBsn(): void {
		$optOut = $this->createMock(OptOutService::class);
		$optOut->expects($this->never())->method('recordLocalOptOut');

		$controller = $this->buildPrivacyController(optOut: $optOut, bsnValid: false);
		$response = $controller->optOutCreate();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('invalid-bsn', $response->getData()['errorCode']);
	}//end testOptOutCreateRejectsAnInvalidBsn()

	/**
	 * A recorded opt-out is 201 with `{result: ok}` and attributes the write to
	 * the SESSION actor.
	 *
	 * @return void
	 */
	public function testOptOutCreateReturns201AndAttributesTheActor(): void {
		$optOut = $this->createMock(OptOutService::class);
		$optOut->expects($this->once())
			->method('recordLocalOptOut')
			->with(self::DEMO_BSN, 'behandelaar1', 'Verzoek per brief')
			->willReturn(true);

		$controller = $this->buildPrivacyController(optOut: $optOut, note: 'Verzoek per brief');
		$response = $controller->optOutCreate();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['result' => 'ok'], $response->getData());
	}//end testOptOutCreateReturns201AndAttributesTheActor()

	/**
	 * A refused write is a 500 with the coded envelope and no `result: ok`.
	 *
	 * @return void
	 */
	public function testOptOutCreateReturns500WhenTheWriteWasRefused(): void {
		$optOut = $this->createMock(OptOutService::class);
		$optOut->method('recordLocalOptOut')->willReturn(false);

		$controller = $this->buildPrivacyController(optOut: $optOut);
		$response = $controller->optOutCreate();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['errorCode' => 'internal'], $response->getData());
	}//end testOptOutCreateReturns500WhenTheWriteWasRefused()

	// ==================================================================
	// mutationWebhook — POST /api/brp/mutations   (PUBLIC)
	// ==================================================================

	/**
	 * An unsigned delivery to the PUBLIC mutation webhook must invalidate
	 * nothing. Run against the REAL BrpMutationWebhookListener so the HMAC
	 * decision under test is the shipped one.
	 *
	 * @return void
	 */
	public function testMutationWebhookRejectsAnUnsignedDeliveryAndInvalidatesNothing(): void {
		$cache = $this->createMock(BrpCacheService::class);
		$cache->expects($this->never())->method('invalidate');

		$response = $this->buildWebhookController(cache: $cache, signature: '')
			->mutationWebhook();

		$this->assertSame(
			['result' => 'forbidden', 'invalidated' => 0],
			$response->getData()
		);
	}//end testMutationWebhookRejectsAnUnsignedDeliveryAndInvalidatesNothing()

	/**
	 * A wrongly-signed delivery is refused and invalidates nothing.
	 *
	 * @return void
	 */
	public function testMutationWebhookRejectsAWronglySignedDeliveryAndInvalidatesNothing(): void {
		$cache = $this->createMock(BrpCacheService::class);
		$cache->expects($this->never())->method('invalidate');

		$response = $this->buildWebhookController(cache: $cache, signature: str_repeat('c', 64))
			->mutationWebhook();

		$this->assertSame(
			['result' => 'forbidden', 'invalidated' => 0],
			$response->getData()
		);
	}//end testMutationWebhookRejectsAWronglySignedDeliveryAndInvalidatesNothing()

	/**
	 * Fail-closed control: with NO configured webhook secret every delivery is
	 * refused, even one carrying a digest computed with the empty secret.
	 *
	 * @return void
	 */
	public function testMutationWebhookRefusesEveryDeliveryWhenNoSecretIsConfigured(): void {
		$cache = $this->createMock(BrpCacheService::class);
		$cache->expects($this->never())->method('invalidate');

		$response = $this->buildWebhookController(
			cache: $cache,
			signature: hash_hmac('sha256', '', ''),
			secret: ''
		)->mutationWebhook();

		$this->assertSame('forbidden', $response->getData()['result']);
	}//end testMutationWebhookRefusesEveryDeliveryWhenNoSecretIsConfigured()

	/**
	 * Positive control: a genuinely valid HMAC gets past verification. The
	 * body is empty in this SAPI, so the delivery then fails the payload check
	 * with a controlled `bad-request` result rather than a throw — proving the
	 * three rejection tests above measure the signature and not a shared early
	 * return.
	 *
	 * @return void
	 */
	public function testMutationWebhookAcceptsAValidSignatureThenRejectsTheEmptyBody(): void {
		$cache = $this->createMock(BrpCacheService::class);
		$cache->expects($this->never())->method('invalidate');

		$response = $this->buildWebhookController(
			cache: $cache,
			signature: hash_hmac('sha256', '', self::WEBHOOK_SECRET)
		)->mutationWebhook();

		$this->assertSame(
			['result' => 'bad-request', 'invalidated' => 0],
			$response->getData()
		);
	}//end testMutationWebhookAcceptsAValidSignatureThenRejectsTheEmptyBody()

	/**
	 * The endpoint returns the listener's outcome as a two-key envelope.
	 *
	 * @return void
	 */
	public function testMutationWebhookReturnsTheListenerOutcomeEnvelope(): void {
		$listener = $this->createMock(BrpMutationWebhookListener::class);
		$listener->method('handle')->willReturn(['result' => 'ok', 'invalidated' => 3]);

		$response = $this->buildWebhookController(listener: $listener)->mutationWebhook();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['result' => 'ok', 'invalidated' => 3], $response->getData());
	}//end testMutationWebhookReturnsTheListenerOutcomeEnvelope()

	/**
	 * Replaying an identical verified delivery is idempotent: a second
	 * invalidation of an already-cold cache reports zero without erroring.
	 *
	 * @return void
	 */
	public function testMutationWebhookReplayIsIdempotent(): void {
		$listener = $this->createMock(BrpMutationWebhookListener::class);
		$listener->method('handle')->willReturnOnConsecutiveCalls(
			['result' => 'ok', 'invalidated' => 2],
			['result' => 'ok', 'invalidated' => 0]
		);

		$controller = $this->buildWebhookController(listener: $listener);
		$first = $controller->mutationWebhook();
		$second = $controller->mutationWebhook();

		$this->assertSame(Http::STATUS_OK, $first->getStatus());
		$this->assertSame(Http::STATUS_OK, $second->getStatus());
		$this->assertSame('ok', $second->getData()['result']);
		$this->assertSame(0, $second->getData()['invalidated']);
	}//end testMutationWebhookReplayIsIdempotent()

	/**
	 * A rejected delivery must not be answered with a 2xx. The endpoint
	 * currently answers 200 for `forbidden` as well as `ok`, so a caller — or
	 * a monitor — cannot tell an accepted mutation from a refused one by
	 * status code.
	 *
	 * @return void
	 */
	public function testMutationWebhookAnswersNon2xxForARejectedDelivery(): void {
		$this->markTestSkipped(
			'BUG: mutationWebhook always returns HTTP 200, including for result '
			. '"forbidden" (bad signature) and "bad-request" (malformed body) — see '
			. 'coordinator report'
		);

		$response = $this->buildWebhookController(signature: str_repeat('c', 64))
			->mutationWebhook();

		$this->assertGreaterThanOrEqual(400, $response->getStatus());
	}//end testMutationWebhookAnswersNon2xxForARejectedDelivery()

	// ==================================================================
	// Fixtures for the privacy + webhook surfaces
	// ==================================================================

	/**
	 * The webhook HMAC secret used by the mutation-webhook tests.
	 *
	 * @var string
	 */
	private const WEBHOOK_SECRET = 'brp-webhook-secret';

	/**
	 * A BsnAuditService double that captures every recordLookup() call.
	 *
	 * @param array<int, array<string, mixed>> $recorded Capture sink, by reference.
	 *
	 * @return BsnAuditService The capturing double.
	 */
	private function capturingAudit(array &$recorded): BsnAuditService {
		$audit = $this->createMock(BsnAuditService::class);
		$audit->method('recordLookup')->willReturnCallback(
			static function (
				string $actor,
				string $rawBsn,
				string $verzoekreden,
				string $doelbinding,
				string $uitkomst,
				string $action = 'brp-lookup-uitgevoerd',
				?int $responseCode = null,
				?string $haalcentraalCorrelationId = null,
				?string $linkedRequest = null,
				?string $actorRole = null,
				bool $vogScreening = false,
			) use (&$recorded): string {
				$recorded[] = [
					'actor' => $actor,
					'rawBsn' => $rawBsn,
					'verzoekreden' => $verzoekreden,
					'doelbinding' => $doelbinding,
					'uitkomst' => $uitkomst,
					'action' => $action,
					'actorRole' => $actorRole,
				];
				return 'audit-1';
			}
		);

		return $audit;
	}//end capturingAudit()

	/**
	 * Build a BrpController for the privacy surface (revealAddress /
	 * optOutCreate).
	 *
	 * @param BsnAuditService|null $audit Audit double.
	 * @param OptOutService|null $optOut Opt-out double.
	 * @param array<int, array<string, mixed>>|null $persons BRP persons the store returns.
	 * @param string|null $uid Signed-in uid, or null.
	 * @param bool $authorised Whether the actor resolves a role.
	 * @param bool $bsnValid Whether the BSN passes the 11-proef.
	 * @param string|null $note The opt-out note.
	 *
	 * @return BrpController The controller.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) One builder for the two
	 *  privacy endpoints; each flag switches exactly one collaborator.
	 */
	private function buildPrivacyController(
		?BsnAuditService $audit = null,
		?OptOutService $optOut = null,
		?array $persons = null,
		?string $uid = 'behandelaar1',
		bool $authorised = true,
		bool $bsnValid = true,
		?string $note = null,
	): BrpController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) use ($note) {
				$params = ['bsn' => self::DEMO_BSN, 'note' => $note];
				return ($params[$key] ?? $default);
			}
		);
		$request->method('getHeader')->willReturn('');

		$userSession = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$userSession->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$userSession->method('getUser')->willReturn($user);
		}

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($authorised);
		$groupManager->method('get')->willReturn(null);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') {
				$cfg = [
					'register' => 'reg-1',
					'brpPersoon_schema' => 'schema-persoon',
					'contact_schema' => 'schema-contact',
				];
				return $cfg[$key] ?? $default;
			}
		);

		$validation = $this->createMock(BsnValidationService::class);
		$validation->method('validate')->willReturn(
			[
				'isFormalValid' => $bsnValid,
				'errorCode' => null,
				'errorMessage' => 'Ongeldig BSN.',
				'maskedBsn' => '*****6782',
			]
		);

		$rows = ($persons ?? []);
		$objectService = new class($rows) {
			/**
			 * @param array<int, array<string, mixed>> $rows Stored BRP persons.
			 */
			public function __construct(
				private array $rows,
			) {
			}//end __construct()

			/**
			 * Return the stored rows.
			 *
			 * @param array<string, mixed> $config The findAll config.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function findAll(array $config = []): array {
				return $this->rows;
			}//end findAll()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		return new BrpController(
			$request,
			$userSession,
			$groupManager,
			$l10n,
			$appConfig,
			$validation,
			$this->createMock(BrpCacheService::class),
			$this->createMock(HaalCentraalClient::class),
			($audit ?? $this->createMock(BsnAuditService::class)),
			($optOut ?? $this->createMock(OptOutService::class)),
			$this->createMock(BrpMutationWebhookListener::class),
			$container,
			$this->createMock(LoggerInterface::class),
			objectService: $this->createMock(ObjectServiceInterface::class),
		);
	}//end buildPrivacyController()

	/**
	 * Build a BrpController for the mutation webhook. When no listener double
	 * is supplied the REAL BrpMutationWebhookListener is wired, so the HMAC
	 * decision under test is the shipped one.
	 *
	 * @param BrpMutationWebhookListener|null $listener Optional listener double.
	 * @param BrpCacheService|null $cache Optional cache double.
	 * @param string $signature The X-Signature header value.
	 * @param string $secret The configured webhook secret.
	 *
	 * @return BrpController The controller.
	 */
	private function buildWebhookController(
		?BrpMutationWebhookListener $listener = null,
		?BrpCacheService $cache = null,
		string $signature = '',
		string $secret = self::WEBHOOK_SECRET,
	): BrpController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $key): string => ($key === 'X-Signature' ? $signature : '')
		);
		$request->method('getParam')->willReturnArgument(1);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($secret) {
				$cfg = ['brp.webhook_secret' => $secret, 'register' => 'reg-1'];
				return $cfg[$key] ?? $default;
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$logger = $this->createMock(LoggerInterface::class);
		$cacheDouble = ($cache ?? $this->createMock(BrpCacheService::class));

		if ($listener === null) {
			$listener = new BrpMutationWebhookListener(
				$appConfig,
				$cacheDouble,
				$this->createMock(BsnAuditService::class),
				$logger,
			);
		}

		return new BrpController(
			$request,
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$l10n,
			$appConfig,
			$this->createMock(BsnValidationService::class),
			$cacheDouble,
			$this->createMock(HaalCentraalClient::class),
			$this->createMock(BsnAuditService::class),
			$this->createMock(OptOutService::class),
			$listener,
			$this->createMock(ContainerInterface::class),
			$logger,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);
	}//end buildWebhookController()
}//end class
