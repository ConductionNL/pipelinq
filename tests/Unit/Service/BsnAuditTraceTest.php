<?php

/**
 * Golden trace for the BSN audit record and the callers that write it.
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
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-005-01
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Controller\BrpController;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Listener\BrpMutationWebhookListener;
use OCA\Pipelinq\Service\BrpCacheService;
use OCA\Pipelinq\Service\BsnAudit\LookupAudit;
use OCA\Pipelinq\Service\BsnAudit\LookupReason;
use OCA\Pipelinq\Service\BsnAudit\LookupResult;
use OCA\Pipelinq\Service\BsnAudit\LookupSubject;
use OCA\Pipelinq\Service\BsnAuditService;
use OCA\Pipelinq\Service\BsnValidationService;
use OCA\Pipelinq\Service\HaalCentraalClient;
use OCA\Pipelinq\Service\OptOutService;
use OCA\Pipelinq\Tests\Unit\Support\SaveCallShape;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * What BsnAuditService::recordLookup() stores and logs for every field
 * combination, and what each caller hands it: the acting user always comes
 * from the session, never from a forged request parameter. Compared with
 * BsnAuditTraceGolden.json; set BSN_AUDIT_GOLDEN_RECORD=1 to rewrite the
 * file, then run once more without it.
 *
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-005-01
 */
class BsnAuditTraceTest extends TestCase {
	private const GOLDEN = __DIR__ . '/BsnAuditTraceGolden.json';

	private const BSN = '123456782';

	private const ACTOR = 'behandelaar1';

	/**
	 * Request parameters a caller could forge to pose as someone else.
	 *
	 * @var array<string, string>
	 */
	private const FORGED = [
		'userId' => 'mallory',
		'requestedBy' => 'mallory',
		'owner' => 'mallory',
		'actor' => 'mallory',
		'actorRole' => 'beheerder',
	];

	/**
	 * Calls made during the current scenario.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * Run the service and caller scenarios and compare them with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$actual = [];
		foreach ($this->serviceScenarios() as $name => $scenario) {
			$actual['service: ' . $name] = $this->runService(scenario: $scenario);
		}

		foreach ($this->callerScenarios() as $name => $run) {
			$this->calls = [];
			$status = $run();
			$actual['caller: ' . $name] = ['status' => $status, 'calls' => $this->calls];
		}

		$json = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n";
		if (getenv('BSN_AUDIT_GOLDEN_RECORD') !== false) {
			file_put_contents(self::GOLDEN, $json);
			$this->markTestSkipped('recorded');
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		foreach ($expected as $name => $want) {
			$this->assertSame($want, ($actual[$name] ?? null), $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testGolden()

	/**
	 * Every audit entry a caller writes names the session user (or the
	 * webhook's system actor), never a forged one.
	 *
	 * @return void
	 */
	public function testEveryAuditEntryNamesTheActingUser(): void {
		$actors = [];
		foreach ($this->callerScenarios() as $run) {
			$this->calls = [];
			$run();
			foreach ($this->calls as $call) {
				if (isset($call['recordLookup']) === true) {
					$actors[] = $call['recordLookup']['actor'];
				}
			}
		}

		$this->assertSame([self::ACTOR, self::ACTOR, self::ACTOR, self::ACTOR, 'system:brp-webhook'], $actors);
	}//end testEveryAuditEntryNamesTheActingUser()

	/**
	 * Service scenarios: the recordLookup fields plus the world around it.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function serviceScenarios(): array {
		$base = ['actor' => self::ACTOR, 'rawBsn' => self::BSN, 'verzoekreden' => 'Adresverificatie', 'purposeBinding' => 'Wet BRP', 'outcome' => 'succeeded'];
		$all = [
			...$base,
			'action' => 'brp-lookup-refused',
			'responseCode' => 403,
			'haalcentraalCorrelationId' => 'corr-1',
			'linkedRequest' => 'req-9',
			'actorRole' => 'behandelaar-burgerzaken',
			'vogScreening' => true,
		];
		return [
			'defaults'                         => ['fields' => $base, 'save' => 'aud-1'],
			'every field'                      => ['fields' => $all, 'save' => 'aud-2', 'ip' => '192.168.10.77'],
			'hash given wins over the raw bsn' => ['fields' => [...$base, 'bsnHash' => 'given-hash'], 'save' => 'aud-3', 'ip' => '2001:db8:1:2:3:4:5:6'],
			'empty hash falls back to hashing' => ['fields' => [...$base, 'rawBsn' => '', 'bsnHash' => ''], 'save' => ''],
			'nulls are dropped'                => ['fields' => [...$base, 'responseCode' => null, 'actorRole' => null], 'save' => 'aud-4'],
			'save throws'                      => ['fields' => $all, 'save' => 'throw'],
			'register not configured'          => ['fields' => $base, 'save' => 'x', 'config' => ['register' => '']],
			'schema not configured'            => ['fields' => $base, 'save' => 'x', 'config' => ['bsnAuditRecord_schema' => '']],
			'unparseable ip'                   => ['fields' => $base, 'save' => 'aud-5', 'ip' => 'not-an-ip'],
		];
	}//end serviceScenarios()

	/**
	 * Run recordLookup once against a recording world.
	 *
	 * @param array<string, mixed> $scenario The scenario.
	 *
	 * @return array<string, mixed>
	 */
	private function runService(array $scenario): array {
		$this->calls = [];
		$config = ['register' => 'reg-1', 'bsnAuditRecord_schema' => 'schema-audit', ...($scenario['config'] ?? [])];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);
		$request = $this->createMock(IRequest::class);
		$request->method('getRemoteAddress')->willReturn(($scenario['ip'] ?? '10.0.0.5'));

		$service = new BsnAuditService(
			$appConfig,
			$request,
			$this->recordingLogger(),
			objectService: $this->auditStore(save: $scenario['save']),
		);
		$uuid = $this->record(service: $service, fields: $scenario['fields']);

		foreach ($this->calls as $i => $call) {
			if (isset($call['saveObject']) === true) {
				$row = $call['saveObject']['object'];
				$span = (new \DateTimeImmutable($row['moment']))->diff(new \DateTimeImmutable($row['retainUntil']));
				$this->calls[$i]['saveObject']['retainYears'] = $span->y;
			}
		}

		$text = json_encode(['uuid' => $uuid, 'calls' => $this->calls]);
		$text = preg_replace('/\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[^"]*/', '<time>', $text);
		return json_decode($text, true);
	}//end runService()

	/**
	 * Call recordLookup with the given fields.
	 *
	 * @param BsnAuditService      $service The service.
	 * @param array<string, mixed> $fields  The audit fields by name.
	 *
	 * @return string The audit record uuid.
	 */
	private function record(BsnAuditService $service, array $fields): string {
		return $service->recordLookup(
			actor: $fields['actor'],
			lookup: new LookupAudit(
				subject: new LookupSubject(rawBsn: $fields['rawBsn'], bsnHash: ($fields['bsnHash'] ?? null)),
				reason: new LookupReason(
					verzoekreden: $fields['verzoekreden'],
					purposeBinding: $fields['purposeBinding'],
					linkedRequest: ($fields['linkedRequest'] ?? null),
					vogScreening: ($fields['vogScreening'] ?? false),
				),
				result: new LookupResult(
					outcome: $fields['outcome'],
					action: ($fields['action'] ?? 'brp-lookup-executed'),
					responseCode: ($fields['responseCode'] ?? null),
					correlationId: ($fields['haalcentraalCorrelationId'] ?? null),
				),
				actorRole: ($fields['actorRole'] ?? null),
			),
		);
	}//end record()

	/**
	 * The callers of recordLookup, each with forged identity parameters.
	 *
	 * @return array<string, callable(): int>
	 */
	private function callerScenarios(): array {
		return [
			'lookup refused for a caller without a role' => fn (): int => $this->controller(role: false)->lookup()->getStatus(),
			'lookup executed by an admin'                => fn (): int => $this->controller(role: true)->lookup()->getStatus(),
			'lookup executed by a group member'          => fn (): int => $this->controller(role: true, viaGroup: true)->lookup()->getStatus(),
			'address revealed'                           => fn (): int => $this->controller(role: true)->revealAddress('contact-1')->getStatus(),
			'webhook invalidates the cache'              => fn (): int => $this->webhookResult(),
		];
	}//end callerScenarios()

	/**
	 * A BrpController whose request carries forged identity parameters and
	 * whose collaborators only answer for the session user.
	 *
	 * @param bool $role     Whether the session user resolves a role.
	 * @param bool $viaGroup Resolve the role through group membership instead of admin.
	 *
	 * @return BrpController
	 */
	private function controller(bool $role, bool $viaGroup = false): BrpController {
		$params = [
			...self::FORGED,
			'bsn' => self::BSN,
			'verzoekreden' => 'Adresverificatie',
			'purposeBinding' => 'Wet BRP',
			'linkedRequest' => 'req-7',
			'vogScreening' => '1',
		];
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);
		$request->method('getParams')->willReturn($params);
		$request->method('getHeader')->willReturn('');

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn(self::ACTOR);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(
			static fn (string $uid): bool => ($role === true && $viaGroup === false && $uid === self::ACTOR)
		);
		$group = $this->createMock(IGroup::class);
		$group->method('inGroup')->willReturnCallback(
			static fn (IUser $member): bool => ($role === true && $viaGroup === true && $member->getUID() === self::ACTOR)
		);
		$groups->method('get')->willReturn($group);

		$policy = $this->createMock(ObjectOwnerAccessPolicy::class);
		$policy->method('isPrivileged')->willReturnCallback(static fn (string $uid): bool => ($uid === self::ACTOR));

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ([
				'register' => 'reg-1',
				'brpLookupVerzoek_schema' => 'schema-verzoek',
				'brpPersoon_schema' => 'schema-persoon',
			][$key] ?? $default)
		);

		$validation = $this->createMock(BsnValidationService::class);
		$validation->method('validate')->willReturn(
			['isFormalValid' => true, 'errorCode' => null, 'errorMessage' => null, 'maskedBsn' => '*****6782']
		);

		$cache = $this->createMock(BrpCacheService::class);
		$cache->method('get')->willReturn(null);
		$cache->method('set')->willReturnCallback(static fn (array $person, ?int $ttl = null): array => $person);

		$haalCentraal = $this->createMock(HaalCentraalClient::class);
		$haalCentraal->method('lookupPersoon')->willReturn(
			['givenNames' => 'Jan', 'indicationSecret' => '0', 'bsnHash' => 'h-jan', '_correlationId' => 'corr-9', '_responseStatus' => 200]
		);

		$store = $this->createMock(ObjectServiceInterface::class);
		$store->method('saveObject')->willReturnCallback(static function (array $object): ObjectEntityInterface {
			$entity = new ObjectEntity();
			$entity->setUuid('saved-uuid');
			$entity->setObject($object);
			return $entity;
		});
		$store->method('findAll')->willReturn([['uuid' => 'p-1', 'bsnHash' => 'h-person', 'fetchedOn' => '2026-01-01', 'residence' => ['straat' => 'Geheim']]]);

		return new BrpController(
			$request,
			$session,
			$policy,
			$groups,
			$l10n,
			$appConfig,
			$validation,
			$cache,
			$haalCentraal,
			$this->capturingAudit(),
			$this->createMock(OptOutService::class),
			$this->createMock(BrpMutationWebhookListener::class),
			$this->createMock(LoggerInterface::class),
			objectService: $store,
		);
	}//end controller()

	/**
	 * Deliver one signed mutation webhook and return its result as a status.
	 *
	 * @return int 1 when the delivery was accepted.
	 */
	private function webhookResult(): int {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'brp.webhook_secret' ? 's3cret' : $default)
		);
		$cache = $this->createMock(BrpCacheService::class);
		$cache->method('invalidate')->willReturn(2);

		$listener = new BrpMutationWebhookListener($appConfig, $cache, $this->capturingAudit(), $this->createMock(LoggerInterface::class));
		$body = (string)json_encode(['burgerservicenummer' => self::BSN, 'userId' => 'mallory']);
		$result = $listener->handle($body, hash_hmac('sha256', $body, 's3cret'));
		return (int)($result['result'] === BrpMutationWebhookListener::RESULT_OK);
	}//end webhookResult()

	/**
	 * A BsnAuditService double that records each recordLookup call by field name.
	 *
	 * @return BsnAuditService
	 */
	private function capturingAudit(): BsnAuditService {
		$audit = $this->createMock(BsnAuditService::class);
		$audit->method('recordLookup')->willReturnCallback(function (...$args): string {
			$this->calls[] = ['recordLookup' => $this->fields(args: $args)];
			return 'audit-1';
		});
		return $audit;
	}//end capturingAudit()

	/**
	 * The audit fields of one recordLookup call, by name.
	 *
	 * @param array<int, mixed> $args The call's arguments.
	 *
	 * @return array<string, mixed>
	 */
	private function fields(array $args): array {
		$lookup = $args[1];
		return [
			'actor' => $args[0],
			'rawBsn' => $lookup->subject->rawBsn,
			'verzoekreden' => $lookup->reason->verzoekreden,
			'purposeBinding' => $lookup->reason->purposeBinding,
			'outcome' => $lookup->result->outcome,
			'action' => $lookup->result->action,
			'responseCode' => $lookup->result->responseCode,
			'haalcentraalCorrelationId' => $lookup->result->correlationId,
			'linkedRequest' => $lookup->reason->linkedRequest,
			'actorRole' => $lookup->actorRole,
			'vogScreening' => $lookup->reason->vogScreening,
			'bsnHash' => $lookup->subject->bsnHash,
		];
	}//end fields()

	/**
	 * A logger that records info and error calls.
	 *
	 * @return LoggerInterface
	 */
	private function recordingLogger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $message, array $context = []) use ($level): void {
				$this->calls[] = ['log:' . $level => ['message' => $message, 'context' => $context]];
			});
		}

		return $logger;
	}//end recordingLogger()

	/**
	 * An audit store whose saveObject returns an entity with the given uuid, or throws.
	 *
	 * @param string $save The uuid (empty for none) or 'throw'.
	 *
	 * @return ObjectServiceInterface
	 */
	private function auditStore(string $save): ObjectServiceInterface {
		$store = $this->createMock(ObjectServiceInterface::class);
		$store->method('saveObject')->willReturnCallback(function (...$args) use ($save): ObjectEntityInterface {
			$flat = SaveCallShape::flat(args: $args);
			$this->calls[] = ['saveObject' => ['object' => $flat[0], 'extend' => $flat[1], 'register' => $flat[2], 'schema' => $flat[3]]];
			if ($save === 'throw') {
				throw new RuntimeException('store down');
			}

			$entity = new ObjectEntity();
			if ($save !== '') {
				$entity->setUuid($save);
			}

			return $entity;
		});
		return $store;
	}//end auditStore()
}//end class
