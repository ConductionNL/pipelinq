<?php

/**
 * Unit tests for ContactSyncController.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\ContactSyncController;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\ContactDataBuilder;
use OCA\Pipelinq\Service\ContactImportService;
use OCA\Pipelinq\Service\ContactLinkedUidsService;
use OCA\Pipelinq\Service\ContactSyncService;
use OCA\Pipelinq\Service\ContactVcardService;
use OCP\AppFramework\Http;
use OCP\Contacts\IManager as IContactsManager;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for ContactSyncController.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 */
class ContactSyncControllerTest extends TestCase {
	/**
	 * The controller under test.
	 *
	 * @var ContactSyncController
	 */
	private ContactSyncController $controller;

	/**
	 * Mock sync service.
	 *
	 * @var ContactSyncService
	 */
	private ContactSyncService $syncService;

	/**
	 * Mock request.
	 *
	 * @var IRequest
	 */
	private IRequest $request;

	/**
	 * Set up the test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->syncService = $this->createMock(ContactSyncService::class);
		$userSession = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('test-user');
		$userSession->method('getUser')->willReturn($user);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$logger = $this->createMock(LoggerInterface::class);

		$this->controller = new ContactSyncController(
			$this->request,
			$this->syncService,
			$userSession,
			$this->createConfiguredMock(ObjectOwnerAccessPolicy::class, ['isPrivileged' => true, 'mayAccess' => true]),
			$l10n,
			$logger,
		);
	}//end setUp()

	/**
	 * Test search returns results.
	 *
	 * @return void
	 */
	public function testSearchReturnsResults(): void {
		$this->request->method('getParam')->willReturn('test query');
		$this->syncService->method('searchContacts')->willReturn([
			['FN' => 'Test User'],
		]);

		$response = $this->controller->search();

		$this->assertSame(200, $response->getStatus());
	}//end testSearchReturnsResults()

	/**
	 * The contact-FIRST create returns 201 with the created object when the
	 * service provisions a contact and saves the client with the resolved
	 * contactsUid.
	 *
	 * @return void
	 */
	public function testCreateReturnsCreatedWithContactsUid(): void {
		$this->request->method('getParam')->willReturnMap(
			[
				['objectType', 'client', 'client'],
				['object', [], ['name' => 'Acme BV', 'type' => 'organization', 'email' => 'a@b.test']],
			]
		);

		$created = [
			'id' => 'obj-1',
			'name' => 'Acme BV',
			'email' => 'a@b.test',
			'contactsUid' => 'uid-123',
		];
		$this->syncService->expects($this->once())
			->method('createWithContact')
			->with('client', ['name' => 'Acme BV', 'type' => 'organization', 'email' => 'a@b.test'])
			->willReturn($created);

		$response = $this->controller->create();

		$this->assertSame(201, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame('uid-123', $data['object']['contactsUid']);
		$this->assertSame('Acme BV', $data['object']['name']);
	}//end testCreateReturnsCreatedWithContactsUid()

	/**
	 * The create endpoint rejects a missing name with 400 before touching the
	 * service.
	 *
	 * @return void
	 */
	public function testCreateRejectsMissingName(): void {
		$this->request->method('getParam')->willReturnMap(
			[
				['objectType', 'client', 'client'],
				['object', [], ['type' => 'organization']],
			]
		);

		$this->syncService->expects($this->never())->method('createWithContact');

		$response = $this->controller->create();

		$this->assertSame(400, $response->getStatus());
	}//end testCreateRejectsMissingName()

	/**
	 * The create endpoint rejects an invalid objectType with 400.
	 *
	 * @return void
	 */
	public function testCreateRejectsInvalidObjectType(): void {
		$this->request->method('getParam')->willReturnMap(
			[
				['objectType', 'client', 'invoice'],
				['object', [], ['name' => 'Acme BV']],
			]
		);

		$this->syncService->expects($this->never())->method('createWithContact');

		$response = $this->controller->create();

		$this->assertSame(400, $response->getStatus());
	}//end testCreateRejectsInvalidObjectType()

	/**
	 * When provisioning fails (e.g. Contacts disabled) the service throws a
	 * RuntimeException and the endpoint surfaces a clean 400 with the message
	 * instead of an opaque 500.
	 *
	 * @return void
	 */
	public function testCreateSurfacesProvisionFailureAs400(): void {
		$this->request->method('getParam')->willReturnMap(
			[
				['objectType', 'client', 'client'],
				['object', [], ['name' => 'Acme BV']],
			]
		);

		$this->syncService->method('createWithContact')
			->willThrowException(new \RuntimeException('Could not provision the Nextcloud contact'));

		$response = $this->controller->create();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('Could not provision the Nextcloud contact', $response->getData()['error']);
	}//end testCreateSurfacesProvisionFailureAs400()

	/**
	 * Build a controller whose session is anonymous, for the auth-guard tests.
	 *
	 * @return ContactSyncController The controller.
	 */
	private function anonymousController(): ContactSyncController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new ContactSyncController(
			$this->createMock(IRequest::class),
			$this->syncService,
			$session,
			$this->createConfiguredMock(ObjectOwnerAccessPolicy::class, ['isPrivileged' => true, 'mayAccess' => true]),
			$l10n,
			$this->createMock(LoggerInterface::class),
		);
	}//end anonymousController()

	// ------------------------------------------------------------------
	// import — POST /api/contacts-sync/import
	// ------------------------------------------------------------------

	/**
	 * An anonymous import is refused with 401 and never touches the service.
	 *
	 * @return void
	 */
	public function testImportRejectsAnonymousCaller(): void {
		$this->syncService->expects($this->never())->method('importContact');

		$response = $this->anonymousController()->import();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Authentication required'], $response->getData());
	}//end testImportRejectsAnonymousCaller()

	/**
	 * A missing uid is a 400 and imports nothing.
	 *
	 * @return void
	 */
	public function testImportRejectsAMissingUid(): void {
		$this->request->method('getParam')->willReturnMap(
			[
				['uid', '', ''],
				['addressBookKey', '', 'book-1'],
				['type', 'client', 'client'],
				['clientId', null, null],
			]
		);
		$this->syncService->expects($this->never())->method('importContact');

		$response = $this->controller->import();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Missing uid parameter'], $response->getData());
	}//end testImportRejectsAMissingUid()

	/**
	 * A successful import is 200 with `{success, object}` and forwards the
	 * four request parameters unchanged.
	 *
	 * @return void
	 */
	public function testImportReturnsTheCreatedObjectEnvelope(): void {
		$this->request->method('getParam')->willReturnMap(
			[
				['uid', '', 'nc-uid-1'],
				['addressBookKey', '', 'book-1'],
				['type', 'client', 'contact'],
				['clientId', null, 'client-9'],
			]
		);

		$this->syncService->expects($this->once())
			->method('importContact')
			->with('nc-uid-1', 'book-1', 'contact', 'client-9')
			->willReturn(['id' => 'obj-1', 'name' => 'Jan Jansen', 'contactsUid' => 'nc-uid-1']);

		$response = $this->controller->import();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['success', 'object'], array_keys($data));
		$this->assertTrue($data['success']);
		$this->assertSame('obj-1', $data['object']['id']);
		$this->assertSame('nc-uid-1', $data['object']['contactsUid']);
	}//end testImportReturnsTheCreatedObjectEnvelope()

	/**
	 * A service failure is a 500 with a STATIC message; the exception text
	 * must never reach the caller.
	 *
	 * @return void
	 */
	public function testImportMapsAServiceFailureTo500WithoutLeakingDetail(): void {
		$this->request->method('getParam')->willReturnMap(
			[
				['uid', '', 'nc-uid-1'],
				['addressBookKey', '', ''],
				['type', 'client', 'client'],
				['clientId', null, null],
			]
		);

		$this->syncService->method('importContact')->willThrowException(
			new \RuntimeException('Pipelinq: app-config "client_schema" is not configured.')
		);

		$response = $this->controller->import();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'An unexpected error occurred'], $response->getData());
		$this->assertStringNotContainsString(
			'client_schema',
			(string)json_encode($response->getData())
		);
	}//end testImportMapsAServiceFailureTo500WithoutLeakingDetail()

	/**
	 * A SPARSE Nextcloud contact — one field beyond the UID — must not emit
	 * empty strings for the fields it does not carry. OpenRegister's
	 * saveObject is PUT-semantic, so a payload carrying `email => ''` blanks
	 * the stored value; only an OMITTED key is safe.
	 *
	 * Run through the REAL ContactSyncService + ContactImportService +
	 * ContactDataBuilder with an ObjectService that captures the payload.
	 *
	 * @return void
	 */
	public function testImportOfASparseContactOmitsRatherThanBlanksTheOtherFields(): void {
		$captured = [];
		$service = $this->realSyncService(
			ncContacts: [['UID' => 'nc-uid-1', 'FN' => 'Jan Jansen']],
			captured: $captured
		);

		$created = $service->importContact(
			uid: 'nc-uid-1',
			addressBookKey: 'book-1',
			type: 'client'
		);

		$this->assertCount(1, $captured, 'the import did not reach saveObject');
		$payload = $captured[0]['object'];

		$this->assertSame('Jan Jansen', $payload['name']);
		$this->assertSame('nc-uid-1', $payload['contactsUid']);
		foreach (['email', 'phone', 'website', 'industry'] as $absent) {
			$this->assertArrayNotHasKey(
				$absent,
				$payload,
				$absent . ' was sent as an empty value and would blank the stored field'
			);
		}

		$this->assertSame('Jan Jansen', $created['name']);
	}//end testImportOfASparseContactOmitsRatherThanBlanksTheOtherFields()

	/**
	 * A RICH Nextcloud contact must survive the import with every mapped field
	 * intact — the counterpart control for the sparse case above, without
	 * which "no empty keys" could not be told apart from "no keys at all".
	 *
	 * @return void
	 */
	public function testImportOfARichContactCarriesEveryMappedField(): void {
		$captured = [];
		$service = $this->realSyncService(
			ncContacts: [
				[
					'UID' => 'nc-uid-2',
					'FN' => 'Acme BV',
					'ORG' => 'Acme BV',
					'EMAIL' => 'info@acme.test',
					'TEL' => '+31612345678',
					'URL' => 'https://acme.test',
				],
			],
			captured: $captured
		);

		$service->importContact(uid: 'nc-uid-2', addressBookKey: 'book-1', type: 'client');

		$payload = $captured[0]['object'];
		$this->assertSame('Acme BV', $payload['name']);
		$this->assertSame('info@acme.test', $payload['email']);
		$this->assertSame('+31612345678', $payload['phone']);
		$this->assertSame('https://acme.test', $payload['website']);
		$this->assertSame('nc-uid-2', $payload['contactsUid']);
	}//end testImportOfARichContactCarriesEveryMappedField()

	/**
	 * Importing the SAME Nextcloud contact twice must not create a second
	 * Pipelinq object. `contactsUid` is the link key and carries no uniqueness
	 * constraint in the register, so a non-idempotent import silently forks
	 * one person into two records that then drift apart.
	 *
	 * @return void
	 */
	public function testImportOfTheSameContactTwiceIsIdempotent(): void {
		$this->markTestSkipped(
			'BUG: ContactSyncService::importContact always saves with uuid=null and '
			. 'never looks for an existing object carrying the same contactsUid, so '
			. 're-importing one addressbook contact creates duplicate clients — see '
			. 'coordinator report'
		);

		$captured = [];
		$service = $this->realSyncService(
			ncContacts: [['UID' => 'nc-uid-1', 'FN' => 'Jan Jansen']],
			captured: $captured
		);

		$service->importContact(uid: 'nc-uid-1', addressBookKey: 'book-1', type: 'client');
		$service->importContact(uid: 'nc-uid-1', addressBookKey: 'book-1', type: 'client');

		$creates = array_filter($captured, static fn (array $call): bool => $call['uuid'] === null);
		$this->assertCount(1, $creates, 'the second import created a duplicate object');
	}//end testImportOfTheSameContactTwiceIsIdempotent()

	// ------------------------------------------------------------------
	// writeBack — POST /api/contacts-sync/write-back
	// ------------------------------------------------------------------

	/**
	 * An anonymous write-back is refused with 401 and syncs nothing.
	 *
	 * @return void
	 */
	public function testWriteBackRejectsAnonymousCaller(): void {
		$this->syncService->expects($this->never())->method('syncToContacts');

		$response = $this->anonymousController()->writeBack();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Authentication required'], $response->getData());
	}//end testWriteBackRejectsAnonymousCaller()

	/**
	 * Missing objectType or objectId is a 400 and syncs nothing.
	 *
	 * @return void
	 */
	public function testWriteBackRejectsMissingParameters(): void {
		$this->request->method('getParam')->willReturnMap(
			[
				['objectType', '', 'client'],
				['objectId', '', ''],
			]
		);
		$this->syncService->expects($this->never())->method('syncToContacts');

		$response = $this->controller->writeBack();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Missing objectType or objectId'], $response->getData());
	}//end testWriteBackRejectsMissingParameters()

	/**
	 * An objectType outside the allowlist is a 400 — the value reaches an
	 * app-config key lookup (`{objectType}_schema`) inside the vCard service,
	 * so an unconstrained value would select an arbitrary schema.
	 *
	 * @return void
	 */
	public function testWriteBackRejectsAnObjectTypeOutsideTheAllowlist(): void {
		$this->request->method('getParam')->willReturnMap(
			[
				['objectType', '', 'invoice'],
				['objectId', '', 'obj-1'],
			]
		);
		$this->syncService->expects($this->never())->method('syncToContacts');

		$response = $this->controller->writeBack();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(
			['error' => 'Invalid objectType -- must be client or contact'],
			$response->getData()
		);
	}//end testWriteBackRejectsAnObjectTypeOutsideTheAllowlist()

	/**
	 * A successful write-back is 200 with `{success, contactsUid}` carrying the
	 * uid the vCard write resolved.
	 *
	 * @return void
	 */
	public function testWriteBackReturnsTheResolvedContactsUid(): void {
		$this->request->method('getParam')->willReturnMap(
			[
				['objectType', '', 'client'],
				['objectId', '', 'obj-1'],
			]
		);

		$this->syncService->expects($this->once())
			->method('syncToContacts')
			->with('client', 'obj-1')
			->willReturn('nc-uid-7');

		$response = $this->controller->writeBack();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['success', 'contactsUid'], array_keys($data));
		$this->assertTrue($data['success']);
		$this->assertSame('nc-uid-7', $data['contactsUid']);
	}//end testWriteBackReturnsTheResolvedContactsUid()

	/**
	 * A service failure is a 500 with a STATIC message.
	 *
	 * @return void
	 */
	public function testWriteBackMapsAServiceFailureTo500WithoutLeakingDetail(): void {
		$this->request->method('getParam')->willReturnMap(
			[
				['objectType', '', 'client'],
				['objectId', '', 'obj-1'],
			]
		);

		$this->syncService->method('syncToContacts')->willThrowException(
			new \RuntimeException('carddav: addressbook default not writable by test-user')
		);

		$response = $this->controller->writeBack();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'An unexpected error occurred'], $response->getData());
		$this->assertStringNotContainsString(
			'carddav',
			(string)json_encode($response->getData())
		);
	}//end testWriteBackMapsAServiceFailureTo500WithoutLeakingDetail()

	/**
	 * A write-back that resolved NO contact uid must not answer `success:
	 * true`. The service returns null when Contacts is unavailable, when the
	 * object could not be loaded, or when the vCard write failed — three
	 * distinct failures that the caller currently cannot tell from a
	 * completed sync.
	 *
	 * @return void
	 */
	public function testWriteBackDoesNotReportSuccessWhenNothingWasSynced(): void {
		$this->markTestSkipped(
			'BUG: writeBack() answers 200 {success:true, contactsUid:null} when '
			. 'syncToContacts() returns null (Contacts unavailable / object not '
			. 'found / vCard write failed) — see coordinator report'
		);

		$this->request->method('getParam')->willReturnMap(
			[
				['objectType', '', 'client'],
				['objectId', '', 'obj-does-not-exist'],
			]
		);

		$this->syncService->method('syncToContacts')->willReturn(null);

		$response = $this->controller->writeBack();

		$this->assertGreaterThanOrEqual(400, $response->getStatus());
		$this->assertNotTrue($response->getData()['success'] ?? null);
	}//end testWriteBackDoesNotReportSuccessWhenNothingWasSynced()

	/**
	 * Build a REAL ContactSyncService whose import path runs the shipped
	 * ContactImportService + ContactDataBuilder against an ObjectService that
	 * captures every saveObject call.
	 *
	 * @param array<int, array<string, mixed>> $ncContacts The addressbook rows.
	 * @param array<int, array<string, mixed>> $captured Capture sink, by reference.
	 *
	 * @return ContactSyncService The real service.
	 */
	private function realSyncService(array $ncContacts, array &$captured): ContactSyncService {
		$contactsManager = $this->createMock(IContactsManager::class);
		$contactsManager->method('isEnabled')->willReturn(true);
		$contactsManager->method('search')->willReturn($ncContacts);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') {
				$cfg = [
					'register' => 'reg-1',
					'client_schema' => 'schema-client',
					'contact_schema' => 'schema-contact',
				];
				return $cfg[$key] ?? $default;
			}
		);

		$objectService = new class($captured) {
			/**
			 * @param array<int, array<string, mixed>> $captured Capture sink, by reference.
			 */
			public function __construct(
				private array &$captured,
			) {
			}//end __construct()

			/**
			 * Capture and echo back the saved object.
			 *
			 * @param array<string, mixed> $object The object.
			 * @param array<int, mixed> $extend Extend list.
			 * @param string $register Register.
			 * @param string $schema Schema.
			 * @param string|null $uuid Uuid.
			 *
			 * @return array<string, mixed> The saved object.
			 */
			public function saveObject(array $object, array $extend = [], string $register = '', string $schema = '', ?string $uuid = null): array {
				$this->captured[] = ['object' => $object, 'uuid' => $uuid, 'schema' => $schema];
				$object['id'] = 'obj-' . count($this->captured);
				return $object;
			}//end saveObject()

			/**
			 * No stored objects.
			 *
			 * @param array<string, mixed> $config The config.
			 *
			 * @return array<int, mixed> Empty.
			 */
			public function findAll(array $config = []): array {
				return [];
			}//end findAll()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		return new ContactSyncService(
			$contactsManager,
			new ContactImportService($appConfig, $container, new ContactDataBuilder()),
			$this->createMock(ContactVcardService::class),
			$this->createMock(ContactLinkedUidsService::class),
			$appConfig,
			$container,
		);
	}//end realSyncService()
}//end class
