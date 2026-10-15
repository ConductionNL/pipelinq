<?php

/**
 * Unit tests for TouchpointService::recordSubmission and the SystemObjectStore it writes through.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/marketing-campaign-on-client/specs/marketing-origin-on-client/spec.md#requirement-contacts-and-clients-show-how-they-found-you-req-moc-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\SystemObjectStore;
use OCA\Pipelinq\Service\SystemServiceAccount;
use OCA\Pipelinq\Service\TouchpointService;
use OCA\Pipelinq\Tests\Unit\Support\InMemoryListObjectStore;
use OCA\Pipelinq\Tests\Unit\Support\InMemoryObjectService;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

require_once __DIR__ . '/../Support/InMemoryListObjectStore.php';
require_once __DIR__ . '/../Support/InMemoryObjectService.php';

/**
 * A web form submission keeps the contact's and the client's touches, written as the system account.
 *
 * @covers \OCA\Pipelinq\Service\TouchpointService
 * @covers \OCA\Pipelinq\Service\SystemObjectStore
 * @uses   \OCA\Pipelinq\Service\SystemServiceAccount
 * @uses   \OCA\Pipelinq\Service\Marketing\ListObjectStore
 */
class TouchpointRecordSubmissionTest extends TestCase {

	private const CONTACT = '11111111-1111-4111-8111-111111111111';
	private const CLIENT = '22222222-2222-4222-8222-222222222222';
	private const CAMPAIGN = '33333333-3333-4333-8333-333333333333';
	private const LEAD = '44444444-4444-4444-8444-444444444444';

	private InMemoryObjectService $objects;

	/** @var IUser|null Who the session says is acting. */
	private ?IUser $active = null;

	private IUser $system;

	/**
	 * The service over an in-memory OpenRegister holding one contact with a client.
	 *
	 * @param ContainerInterface|null $container A container to use instead of the in-memory one.
	 *
	 * @return TouchpointService
	 */
	private function build(?ContainerInterface $container = null): TouchpointService {
		$this->objects = new InMemoryObjectService();
		$this->objects->put('contact', ['uuid' => self::CONTACT, 'name' => 'Jane Doe', 'email' => 'jane@example.com', 'client' => self::CLIENT]);
		$this->objects->put('client', ['uuid' => self::CLIENT, 'name' => 'Gemeente Zeist']);
		$this->objects->actor = fn () => $this->active;

		$this->system = $this->createMock(IUser::class);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->system);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn () => $this->active);
		$session->method('setVolatileActiveUser')->willReturnCallback(function (?IUser $user): void {
			$this->active = $user;
		});
		$account = new SystemServiceAccount(userManager: $users, userSession: $session, random: $this->createMock(ISecureRandom::class));

		if ($container === null) {
			$container = $this->createMock(ContainerInterface::class);
			$container->method('get')->willReturn($this->objects);
		}

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default) => $default);

		$store = new SystemObjectStore(container: $container, appConfig: $appConfig, account: $account, logger: $this->createMock(LoggerInterface::class));

		return new TouchpointService(new InMemoryListObjectStore([]), $store);
	}//end build()

	/**
	 * Spec scenario: a first submission sets both touches on the contact and its client, and writes the touchpoint.
	 *
	 * @return void
	 */
	public function testAFirstSubmissionSetsBothTouchesAndTheTouchpoint(): void {
		$service = $this->build();
		$result = $service->recordSubmission(
			contactId: self::CONTACT,
			campaignId: self::CAMPAIGN,
			utm: ['campaign' => 'webinar-ai-voor-gemeenten', 'source' => 'linkedin', 'medium' => 'social'],
			landingPage: 'https://www.example.nl/webinar',
			extra: ['leadId' => self::LEAD, 'nonce' => 'n-1', 'occurredAt' => '2026-10-15T09:00:00+00:00'],
		);

		$this->assertSame(['contact', 'client'], $result['touched']);
		$this->assertNotNull($result['touchpoint']);

		$contact = $this->objects->find(self::CONTACT);
		$client = $this->objects->find(self::CLIENT);
		foreach ([$contact, $client] as $record) {
			$this->assertSame('webinar-ai-voor-gemeenten', $record['firstTouch']['campaign']);
			$this->assertSame('linkedin', $record['lastTouch']['source']);
			$this->assertSame('https://www.example.nl/webinar', $record['lastTouch']['landingPage']);
			$this->assertSame('2026-10-15T09:00:00+00:00', $record['lastTouch']['at']);
		}

		$touchpoint = $this->objects->ofSchema('touchpoint')[0];
		$this->assertSame(self::CLIENT, $touchpoint['clientId']);
		$this->assertSame('https://www.example.nl/webinar', $touchpoint['landingPage']);
		$this->assertSame('social', $touchpoint['channel']);
	}//end testAFirstSubmissionSetsBothTouchesAndTheTouchpoint()

	/**
	 * Spec scenario: a later submission changes only the last touch.
	 *
	 * @return void
	 */
	public function testALaterSubmissionChangesOnlyTheLastTouch(): void {
		$service = $this->build();
		$service->recordSubmission(contactId: self::CONTACT, campaignId: '', utm: ['campaign' => 'voorjaar'], landingPage: '');
		$service->recordSubmission(contactId: self::CONTACT, campaignId: '', utm: ['campaign' => 'najaar'], landingPage: '');

		$contact = $this->objects->find(self::CONTACT);
		$this->assertSame('voorjaar', $contact['firstTouch']['campaign']);
		$this->assertSame('najaar', $contact['lastTouch']['campaign']);
		$this->assertSame([], $this->objects->ofSchema('touchpoint'), 'no campaign, no touchpoint: the schema requires one');
	}//end testALaterSubmissionChangesOnlyTheLastTouch()

	/**
	 * A contact without a client gets its touches; nothing is written for a client.
	 *
	 * @return void
	 */
	public function testAContactWithoutAClientWritesOnlyTheContact(): void {
		$service = $this->build();
		$this->objects->put('contact', ['uuid' => self::CONTACT, 'name' => 'Jane Doe']);

		$result = $service->recordSubmission(contactId: self::CONTACT, campaignId: '', utm: ['campaign' => 'voorjaar'], landingPage: '');

		$this->assertSame(['contact'], $result['touched']);
		$this->assertArrayNotHasKey('lastTouch', $this->objects->find(self::CLIENT));
	}//end testAContactWithoutAClientWritesOnlyTheContact()

	/**
	 * Every call runs as the system account with access checks on, and a signed-in visitor gets their session back.
	 *
	 * @return void
	 */
	public function testEveryCallRunsAsTheSystemAccountWithAccessChecksOn(): void {
		$service = $this->build();
		$visitor = $this->createMock(IUser::class);
		$this->active = $visitor;

		$service->recordSubmission(contactId: self::CONTACT, campaignId: self::CAMPAIGN, utm: ['campaign' => 'voorjaar'], landingPage: '');

		$this->assertNotSame([], $this->objects->calls);
		foreach ($this->objects->calls as $call) {
			$this->assertTrue($call['rbac'], $call['method'] . ' on ' . (string)$call['schema'] . ' ran with access checks off');
			$this->assertFalse($call['multitenancy']);
			$this->assertSame($this->system, $call['actor'], $call['method'] . ' did not run as the system account');
		}

		$this->assertSame($visitor, $this->active);
	}//end testEveryCallRunsAsTheSystemAccountWithAccessChecksOn()

	/**
	 * No contact: only the touchpoint is written. A contact whose client is gone: only the contact is touched.
	 *
	 * @return void
	 */
	public function testAMissingContactOrClientIsSkipped(): void {
		$service = $this->build();
		$result = $service->recordSubmission(contactId: ' ', campaignId: self::CAMPAIGN, utm: [], landingPage: '');
		$this->assertSame([], $result['touched']);
		$this->assertNotNull($result['touchpoint']);
		$this->assertArrayNotHasKey('contactId', $this->objects->ofSchema('touchpoint')[0]);

		$this->objects->put('contact', ['uuid' => self::CONTACT, 'name' => 'Jane Doe', 'client' => 'gone']);
		$result = $service->recordSubmission(contactId: self::CONTACT, campaignId: '', utm: ['campaign' => 'voorjaar'], landingPage: '');
		$this->assertSame(['contact'], $result['touched']);
	}//end testAMissingContactOrClientIsSkipped()

	/**
	 * Campaign values are trimmed and cut at 100 characters; unknown keys never reach a record.
	 *
	 * @return void
	 */
	public function testCampaignValuesAreCappedAndUnknownKeysDropped(): void {
		$service = $this->build();
		$service->recordSubmission(
			contactId: self::CONTACT,
			campaignId: '',
			utm: ['campaign' => '  ' . str_repeat('a', 500) . '  ', 'status' => 'won', 'source' => ['not', 'text']],
			landingPage: str_repeat('p', 900),
		);

		$touch = $this->objects->find(self::CONTACT)['lastTouch'];
		$this->assertSame(100, mb_strlen($touch['campaign']));
		$this->assertSame(500, mb_strlen($touch['landingPage']));
		$this->assertArrayNotHasKey('status', $touch);
		$this->assertArrayNotHasKey('source', $touch);
	}//end testCampaignValuesAreCappedAndUnknownKeysDropped()

	/**
	 * OpenRegister missing: nothing is written, nothing throws, the visitor's submit is not harmed.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingIsWrittenAndNothingThrows(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no OpenRegister'));
		$service = $this->build(container: $container);

		$result = $service->recordSubmission(contactId: self::CONTACT, campaignId: self::CAMPAIGN, utm: ['campaign' => 'x'], landingPage: '');

		$this->assertSame(['touchpoint' => null, 'touched' => []], $result);
		$this->assertNull($this->active, 'the session is restored after a failure');
	}//end testWithoutOpenRegisterNothingIsWrittenAndNothingThrows()

	/**
	 * Without the system store (a reader-only construction) recordSubmission writes nothing.
	 *
	 * @return void
	 */
	public function testWithoutASystemStoreNothingIsWritten(): void {
		$service = new TouchpointService(new InMemoryListObjectStore([]));
		$this->assertSame(['touchpoint' => null, 'touched' => []], $service->recordSubmission(contactId: self::CONTACT, campaignId: self::CAMPAIGN, utm: [], landingPage: ''));
	}//end testWithoutASystemStoreNothingIsWritten()

	/**
	 * The stored touchpoint and touches validate against the real register fragments; an undeclared key is the control.
	 *
	 * @return void
	 */
	public function testThePayloadsValidateAgainstTheRealFragments(): void {
		$service = $this->build();
		$service->recordSubmission(
			contactId: self::CONTACT,
			campaignId: self::CAMPAIGN,
			utm: ['campaign' => 'voorjaar', 'source' => 'nieuwsbrief', 'medium' => 'email', 'term' => 't', 'content' => 'c'],
			landingPage: 'https://www.example.nl/actie',
			extra: ['leadId' => self::LEAD, 'nonce' => 'n-1', 'sourceRef' => 'page-1'],
		);

		$campaigns = $this->fragment('98-marketing-campaigns.json')['components']['schemas'];
		$origin = $this->fragment('98-marketing-origin-on-client.json')['components']['schemas'];

		$touchpointSchema = $campaigns['touchpoint'];
		$touchpointSchema['properties'] = array_merge($touchpointSchema['properties'], $origin['touchpoint']['properties']);
		$sent = $this->objects->ofSchema('touchpoint')[0];
		unset($sent['uuid']);
		$this->assertValid($touchpointSchema, $sent);

		foreach (['contact', 'client'] as $slug) {
			$record = $this->objects->find($slug === 'contact' ? self::CONTACT : self::CLIENT);
			foreach (['firstTouch', 'lastTouch'] as $key) {
				$schema = $origin[$slug]['properties'][$key];
				// OpenRegister refuses an unknown top-level key, and `readOnly` would refuse the touch write itself.
				$this->assertArrayNotHasKey('serverSet', $schema);
				$this->assertArrayNotHasKey('readOnly', $schema);
				$this->assertTrue($schema['x-openregister']['serverSet']);
				$this->assertTrue($schema['hideOnForm']);
				$this->assertSame($key === 'firstTouch', ($schema['immutable'] ?? false));
				$this->assertValid(['properties' => ['touch' => $schema]], ['touch' => $record[$key]]);
			}
		}

		// The control: a key the fragment does not declare is refused.
		$this->expectExceptionMessage('must be declared');
		$this->assertValid(['properties' => ['touch' => $origin['contact']['properties']['lastTouch']]], ['touch' => ['utm_campaign' => 'x']]);
	}//end testThePayloadsValidateAgainstTheRealFragments()

	/**
	 * One register fragment, decoded.
	 *
	 * @param string $file The file under lib/Settings/register.d.
	 *
	 * @return array<string, mixed>
	 */
	private function fragment(string $file): array {
		$fragment = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/register.d/' . $file), true);
		$this->assertIsArray($fragment, $file . ' must be valid JSON');
		return $fragment;
	}//end fragment()

	/**
	 * Validate a payload: every key (one level into objects) declared, then types, formats and lengths by Opis.
	 *
	 * @param array<string, mixed> $schema  The schema.
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return void
	 */
	private function assertValid(array $schema, array $payload): void {
		foreach ($payload as $key => $value) {
			if (isset($schema['properties'][$key]) === false) {
				throw new RuntimeException("'{$key}' must be declared or storage drops it");
			}

			if (is_array($value) === true && isset($schema['properties'][$key]['properties']) === true) {
				foreach (array_keys($value) as $inner) {
					if (isset($schema['properties'][$key]['properties'][$inner]) === false) {
						throw new RuntimeException("'{$key}.{$inner}' must be declared or storage drops it");
					}
				}
			}
		}

		$jsonSchema = json_decode((string)json_encode(['type' => 'object', 'required' => ($schema['required'] ?? []), 'properties' => $this->strip($schema['properties'])]));
		$result = (new Validator())->validate(json_decode((string)json_encode($payload)), $jsonSchema);
		$this->assertTrue($result->isValid(), (string)json_encode($result->error()?->args()));
	}//end assertValid()

	/**
	 * Keep only what JSON Schema validates; OpenRegister's own keys ($ref, facetable, serverSet) go.
	 *
	 * @param array<string, mixed> $properties The properties.
	 *
	 * @return array<string, mixed>
	 */
	private function strip(array $properties): array {
		$out = [];
		foreach ($properties as $name => $property) {
			$kept = array_intersect_key($property, array_flip(['type', 'enum', 'maxLength', 'format']));
			if (isset($property['properties']) === true) {
				$kept['properties'] = $this->strip($property['properties']);
			}

			$out[$name] = $kept;
		}

		return $out;
	}//end strip()
}//end class
