<?php

/**
 * Golden trace for BrpController::lookup() and revealAddress().
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-002-01
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-005-01
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use DateTimeInterface;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Controller\BrpController;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Listener\BrpMutationWebhookListener;
use OCA\Pipelinq\Service\BrpCacheService;
use OCA\Pipelinq\Service\BsnAudit\LookupAudit;
use OCA\Pipelinq\Service\BsnAuditService;
use OCA\Pipelinq\Service\BsnValidationService;
use OCA\Pipelinq\Service\HaalCentraalClient;
use OCA\Pipelinq\Service\HaalCentraalException;
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
use ReflectionMethod;
use RuntimeException;

/**
 * What a BRP lookup and an address reveal answer, which collaborators they
 * call (authorisation, cache, HaalCentraal, store, audit) and in what order,
 * for every branch. Calls are recorded by parameter name, leaving out
 * arguments at their default. Compared with BrpLookupGolden.json; set
 * BRP_LOOKUP_GOLDEN_RECORD=1 to rewrite the file, then run once more without it.
 *
 * @covers \OCA\Pipelinq\Controller\BrpController
 * @uses   \OCA\Pipelinq\Service\PersonLookup\LookupLinks
 * @uses   \OCA\Pipelinq\Service\PersonLookup\LookupQuery
 * @uses   \OCA\Pipelinq\Service\PersonLookup\LookupRun
 * @uses   \OCA\Pipelinq\Service\PersonLookup\PersonFetch
 * @uses   \OCA\Pipelinq\Service\BsnAudit\LookupAudit
 * @uses   \OCA\Pipelinq\Service\BsnAudit\LookupReason
 * @uses   \OCA\Pipelinq\Service\BsnAudit\LookupResult
 * @uses   \OCA\Pipelinq\Service\BsnAudit\LookupSubject
 * @uses   \OCA\Pipelinq\Service\BsnValidationService
 * @uses   \OCA\Pipelinq\Service\HaalCentraalException
 *
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-005-01
 */
class BrpLookupGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/BrpLookupGolden.json';

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
	 * The world of the current scenario.
	 *
	 * @var array<string, mixed>
	 */
	private array $world = [];

	/**
	 * Calls made during the current scenario.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * Run every scenario and compare it with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$actual = [];
		foreach ($this->scenarios() as $name => $world) {
			$actual[$name] = $this->scenario(world: $world);
		}

		$json = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)) . "\n";
		if (getenv('BRP_LOOKUP_GOLDEN_RECORD') !== false) {
			file_put_contents(self::GOLDEN, $json);
			$this->markTestSkipped('recorded');
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		$actual = json_decode($json, true);
		foreach ($expected as $name => $want) {
			$this->assertSame($want, ($actual[$name] ?? null), $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testGolden()

	/**
	 * Forged identity parameters change neither the answer nor any call.
	 *
	 * @return void
	 */
	public function testForgedIdentityParametersChangeNothing(): void {
		foreach ($this->scenarios() as $name => $world) {
			$this->assertSame(
				$this->scenario(world: [...$world, 'forged' => false]),
				$this->scenario(world: [...$world, 'forged' => true]),
				$name
			);
		}
	}//end testForgedIdentityParametersChangeNothing()

	/**
	 * Every audit entry names the session user, and every scenario that
	 * reaches the role check writes exactly one.
	 *
	 * @return void
	 */
	public function testEveryAuditEntryNamesTheSessionUser(): void {
		$audited = [];
		foreach ($this->scenarios() as $name => $world) {
			$entries = array_values(array_filter(
				$this->scenario(world: $world)['calls'],
				static fn (array $call): bool => isset($call['BsnAuditService::recordLookup']) === true
			));
			foreach ($entries as $entry) {
				$this->assertSame(self::ACTOR, $entry['BsnAuditService::recordLookup']['actor'], $name);
			}

			$audited[$name] = count($entries);
		}

		$this->assertSame(1, max($audited));
		$this->assertSame(0, min($audited));
		$this->assertGreaterThan(15, array_sum($audited));
	}//end testEveryAuditEntryNamesTheSessionUser()

	/**
	 * The scenarios, each a set of overrides on the default world.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function scenarios(): array {
		$person = ['@self' => ['id' => 'cached-1'], 'bsn' => self::BSN, 'givenNames' => 'Jan', 'indicationSecret' => '1', 'retentionTo' => '2026-12-01T00:00:00+00:00'];
		$links = ['linkedRequest' => 'req-7', 'gekoppeldContact' => 'contact-3', 'verifiedClient' => 'client-5', 'identityCheck' => ' paspoort '];
		return [
			'lookup: no session user'                           => ['user' => false],
			'lookup: not privileged'                            => ['privileged' => false],
			'lookup: verzoekreden missing'                      => ['params' => ['verzoekreden' => '  ']],
			'lookup: doelbinding missing'                       => ['params' => ['purposeBinding' => '']],
			'lookup: refused without a role'                    => ['admin' => false],
			'lookup: refused without a role, linked request'    => ['admin' => false, 'params' => $links],
			'lookup: refused, member of an unknown group'       => ['admin' => false, 'groups' => ['behandelaar-avg'], 'known' => []],
			'lookup: role from a default group'                 => ['admin' => false, 'groups' => ['behandelaar-avg']],
			'lookup: role from a configured group'              => ['admin' => false, 'groups' => ['g2'], 'config' => ['brp.allowed_groups' => ' g1, ,g2 ']],
			'lookup: configured groups replace the defaults'    => ['admin' => false, 'groups' => ['behandelaar-avg'], 'config' => ['brp.allowed_groups' => 'g1']],
			'lookup: invalid bsn with a message'                => ['valid' => ['isFormalValid' => false, 'errorMessage' => 'Elfproef faalt']],
			'lookup: invalid bsn without a message'             => ['valid' => ['isFormalValid' => false]],
			'lookup: remote hit, defaults'                      => [],
			'lookup: remote hit, basis and links'               => ['params' => [...$links, 'basis' => ' AVG art 6 ', 'vogScreening' => '1']],
			'lookup: remote hit, secret, no meta'               => ['remote' => ['givenNames' => 'Piet', 'indicationSecret' => '1'], 'params' => $links],
			'lookup: remote hit, no request schema'             => ['config' => ['brpLookupVerzoek_schema' => ''], 'params' => $links],
			'lookup: remote hit, store throws'                  => ['save' => 'throw', 'params' => $links],
			'lookup: remote hit, saved without an id'           => ['save' => 'noid', 'params' => $links],
			'lookup: remote hit, no person schema'              => ['config' => ['brpPersoon_schema' => ''], 'params' => $links],
			'lookup: remote hit, no contact or client schema'   => ['config' => ['contact_schema' => '', 'client_schema' => ''], 'params' => $links],
			'lookup: remote hit, contact and client not found'  => ['find' => 'null', 'params' => $links],
			'lookup: remote hit, contact and client empty'      => ['find' => 'empty', 'params' => $links],
			'lookup: remote hit, find throws'                   => ['find' => 'throw', 'params' => $links],
			'lookup: remote hit, no identity check'             => ['params' => [...$links, 'identityCheck' => '']],
			'lookup: remote hit, hash key already present'      => ['remote' => ['bsnHash' => 'x', 'lookupRequestId' => 'y', 'givenNames' => 'Kees', '_responseStatus' => 200], 'params' => $links],
			'lookup: cache write fails'                         => ['fail' => ['BrpCacheService::set' => 'throwable'], 'params' => $links],
			'lookup: opt-out write fails'                       => ['fail' => ['OptOutService::recordFromBrpResponse' => 'throwable'], 'params' => $links],
			'lookup: opt-out raises a haalcentraal timeout'     => ['fail' => ['OptOutService::recordFromBrpResponse' => 'timeout'], 'params' => $links],
			'lookup: cache hit'                                 => ['cached' => $person],
			'lookup: cache hit with links'                      => ['cached' => $person, 'params' => $links],
			'lookup: cache hit without a person id'             => ['cached' => ['givenNames' => 'Jan'], 'params' => $links],
			'lookup: not found'                                 => ['remote' => null, 'params' => $links],
			'lookup: haalcentraal timeout'                      => ['remote' => 'timeout'],
			'lookup: haalcentraal error'                        => ['remote' => 'error', 'params' => $links],
			'lookup: unexpected failure'                        => ['remote' => 'throwable'],
			'reveal: no session user'                           => ['action' => 'reveal', 'user' => false],
			'reveal: no role'                                   => ['action' => 'reveal', 'admin' => false],
			'reveal: no person schema'                          => ['action' => 'reveal', 'config' => ['brpPersoon_schema' => '']],
			'reveal: no person for the contact'                 => ['action' => 'reveal', 'findAll' => []],
			'reveal: search throws'                             => ['action' => 'reveal', 'findAll' => 'throw'],
			'reveal: audit write failed'                        => ['action' => 'reveal', 'auditId' => ''],
			'reveal: latest person, admin'                      => ['action' => 'reveal'],
			'reveal: latest person, group member'               => ['action' => 'reveal', 'admin' => false, 'groups' => ['behandelaar-burgerzaken']],
			'reveal: person without hash or residence'          => ['action' => 'reveal', 'findAll' => [['uuid' => 'p-9']]],
		];
	}//end scenarios()

	/**
	 * Run one scenario and return its answer and calls.
	 *
	 * @param array<string, mixed> $world Overrides on the default world.
	 *
	 * @return array<string, mixed>
	 */
	private function scenario(array $world): array {
		$this->world = [
			'action' => 'lookup',
			'user' => true,
			'privileged' => true,
			'admin' => true,
			'groups' => [],
			'known' => null,
			'forged' => true,
			'params' => [],
			'config' => [],
			'valid' => ['isFormalValid' => true, 'errorMessage' => null],
			'cached' => null,
			'remote' => ['givenNames' => 'Jan', 'indicationSecret' => '0', 'bsn' => self::BSN, '_correlationId' => 'corr-9', '_responseDurationMs' => 41, '_responseStatus' => 203],
			'save' => 'entity',
			'find' => 'entity',
			'findAll' => [
				['uuid' => 'p-1', 'bsnHash' => 'h-old', 'fetchedOn' => '2026-01-01', 'residence' => ['straat' => 'Oud']],
				['uuid' => 'p-2', 'bsnHash' => 'h-new', 'fetchedOn' => '2026-03-01', 'residence' => ['straat' => 'Geheim']],
				['uuid' => 'p-3', 'bsnHash' => 'h-mid', 'fetchedOn' => '2026-02-01'],
			],
			'auditId' => 'audit-1',
			'fail' => [],
			...$world,
		];
		$this->calls = [];
		$controller = $this->controller();
		if ($this->world['action'] === 'reveal') {
			$response = $controller->revealAddress('contact-1');
		} else {
			$response = $controller->lookup();
		}

		$text = (string)json_encode(['status' => $response->getStatus(), 'body' => $response->getData(), 'calls' => $this->calls], JSON_PRESERVE_ZERO_FRACTION);
		$text = (string)preg_replace('/\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d/', '<time>', $text);
		return json_decode($text, true);
	}//end scenario()

	/**
	 * A BrpController wired to recording doubles that answer from the world.
	 *
	 * @return BrpController
	 */
	private function controller(): BrpController {
		$params = [
			'bsn' => self::BSN,
			'verzoekreden' => ' Adresverificatie ',
			'purposeBinding' => ' Wet BRP ',
			...$this->world['params'],
		];
		if ($this->world['forged'] === true) {
			$params = [...self::FORGED, ...$params];
		}

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);
		$request->method('getParams')->willReturn($params);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn(self::ACTOR);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(($this->world['user'] === true ? $user : null));

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$config = [
			'register' => 'reg-1',
			'brpLookupVerzoek_schema' => 'schema-verzoek',
			'brpPersoon_schema' => 'schema-persoon',
			'contact_schema' => 'schema-contact',
			'client_schema' => 'schema-client',
			...$this->world['config'],
		];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		return new BrpController(
			$request,
			$session,
			$this->double(class: ObjectOwnerAccessPolicy::class, methods: ['isPrivileged']),
			$this->double(class: IGroupManager::class, methods: ['isAdmin', 'get']),
			$l10n,
			$appConfig,
			$this->double(class: BsnValidationService::class, methods: ['validate']),
			$this->double(class: BrpCacheService::class, methods: ['get', 'set']),
			$this->double(class: HaalCentraalClient::class, methods: ['lookupPersoon']),
			$this->double(class: BsnAuditService::class, methods: ['recordLookup']),
			$this->double(class: OptOutService::class, methods: ['recordFromBrpResponse']),
			$this->createMock(BrpMutationWebhookListener::class),
			$this->double(class: LoggerInterface::class, methods: ['error', 'warning', 'info']),
			objectService: $this->double(class: ObjectServiceInterface::class, methods: ['saveObject', 'find', 'findAll']),
		);
	}//end controller()

	/**
	 * A mock of the class whose given methods record their call and answer from the world.
	 *
	 * @param class-string       $class   The class to mock.
	 * @param array<int, string> $methods The methods to record.
	 *
	 * @return mixed
	 */
	private function double(string $class, array $methods): mixed {
		$mock = $this->createMock($class);
		foreach ($methods as $method) {
			$mock->method($method)->willReturnCallback(function (...$args) use ($class, $method) {
				$this->record(name: $class . '::' . $method, args: $args);
				return $this->answer(name: $class . '::' . $method, args: $args);
			});
		}

		return $mock;
	}//end double()

	/**
	 * Record one call by parameter name, leaving out arguments at their default.
	 *
	 * @param string            $name The class and method.
	 * @param array<int, mixed> $args The arguments.
	 *
	 * @return void
	 */
	private function record(string $name, array $args): void {
		if (str_ends_with($name, '::saveObject') === true) {
			$named = array_map(fn (mixed $value): mixed => $this->plain(value: $value), SaveCallShape::named(args: $args));
			$this->calls[] = [$this->shortName(name: $name) => $named];
			return;
		}

		$named = [];
		foreach ((new ReflectionMethod($name))->getParameters() as $i => $param) {
			if (array_key_exists($i, $args) === false) {
				break;
			}

			if ($param->isDefaultValueAvailable() === true && $args[$i] === $param->getDefaultValue()) {
				continue;
			}

			$named[$param->getName()] = $this->plain(value: $args[$i]);
		}

		$this->calls[] = [$this->shortName(name: $name) => $named];
	}//end record()

	/**
	 * A JSON-safe view of an argument.
	 *
	 * @param mixed $value The argument.
	 *
	 * @return mixed
	 */
	private function plain(mixed $value): mixed {
		if ($value instanceof LookupAudit) {
			return [
				'rawBsn' => $value->subject->rawBsn,
				'bsnHash' => $value->subject->bsnHash,
				'verzoekreden' => $value->reason->verzoekreden,
				'purposeBinding' => $value->reason->purposeBinding,
				'linkedRequest' => $value->reason->linkedRequest,
				'vogScreening' => $value->reason->vogScreening,
				'outcome' => $value->result->outcome,
				'action' => $value->result->action,
				'responseCode' => $value->result->responseCode,
				'correlationId' => $value->result->correlationId,
				'actorRole' => $value->actorRole,
			];
		}

		if ($value instanceof IUser) {
			return 'user:' . $value->getUID();
		}

		if ($value instanceof DateTimeInterface) {
			return $value->format(DATE_ATOM);
		}

		if (is_object($value) === true) {
			return get_class($value);
		}

		return $value;
	}//end plain()

	/**
	 * The answer of one recorded method, from the world.
	 *
	 * @param string            $name The class and method.
	 * @param array<int, mixed> $args The arguments.
	 *
	 * @return mixed
	 */
	private function answer(string $name, array $args): mixed {
		$world = $this->world;
		if (isset($world['fail'][$this->shortName(name: $name)]) === true) {
			$this->raise(how: $world['fail'][$this->shortName(name: $name)]);
		}

		switch ($this->shortName(name: $name)) {
			case 'ObjectOwnerAccessPolicy::isPrivileged':
				return ($world['privileged'] === true && $args[0] === self::ACTOR);
			case 'IGroupManager::isAdmin':
				return ($world['admin'] === true && $args[0] === self::ACTOR);
			case 'IGroupManager::get':
				return $this->group(groupId: $args[0]);
			case 'BrpCacheService::get':
				return $world['cached'];
			case 'BsnValidationService::validate':
				return $world['valid'];
			case 'BrpCacheService::set':
				return [...$args[0], '@self' => ['id' => 'person-1'], 'retentionTo' => '2027-01-01T00:00:00+00:00'];
			case 'HaalCentraalClient::lookupPersoon':
				return $this->remote();
			case 'BsnAuditService::recordLookup':
				return $world['auditId'];
			case 'OptOutService::recordFromBrpResponse':
				return true;
			case 'ObjectServiceInterface::saveObject':
				return $this->saved(object: $args[0], schema: (string)$args[1]->target->schema);
			case 'ObjectServiceInterface::find':
				return $this->found();
			case 'ObjectServiceInterface::findAll':
				if ($world['findAll'] === 'throw') {
					throw new RuntimeException('search down');
				}

				return $world['findAll'];
			default:
				return null;
		}//end switch
	}//end answer()

	/**
	 * A group whose membership answers only for the session user.
	 *
	 * @param string $groupId The group id.
	 *
	 * @return IGroup|null
	 */
	private function group(string $groupId): ?IGroup {
		if ($this->world['known'] !== null && in_array($groupId, $this->world['known'], true) === false) {
			return null;
		}

		$member = in_array($groupId, $this->world['groups'], true);
		$group = $this->createMock(IGroup::class);
		$group->method('inGroup')->willReturnCallback(function (IUser $user) use ($groupId, $member): bool {
			$this->calls[] = ['IGroup::inGroup' => ['group' => $groupId, 'user' => 'user:' . $user->getUID()]];
			return ($member === true && $user->getUID() === self::ACTOR);
		});
		return $group;
	}//end group()

	/**
	 * The class and method without the namespace.
	 *
	 * @param string $name The class and method.
	 *
	 * @return string
	 */
	private function shortName(string $name): string {
		return substr($name, ((int)strrpos($name, '\\') + 1));
	}//end shortName()

	/**
	 * Throw the failure the world names.
	 *
	 * @param string $how 'timeout', 'error' or 'throwable'.
	 *
	 * @return never
	 */
	private function raise(string $how): never {
		if ($how === 'timeout') {
			throw new HaalCentraalException('timed out', 0, 'corr-t');
		}

		if ($how === 'error') {
			throw new HaalCentraalException('bad gateway', 502, 'corr-e');
		}

		throw new RuntimeException('boom');
	}//end raise()

	/**
	 * The HaalCentraal answer of the world.
	 *
	 * @return array<string, mixed>|null
	 */
	private function remote(): ?array {
		$remote = $this->world['remote'];
		if (is_string($remote) === true) {
			$this->raise(how: $remote);
		}

		return $remote;
	}//end remote()

	/**
	 * The stored entity for one saveObject call, or a failure.
	 *
	 * @param array<string, mixed> $object The object saved.
	 * @param string               $schema The schema.
	 *
	 * @return ObjectEntityInterface
	 */
	private function saved(array $object, string $schema): ObjectEntityInterface {
		if ($this->world['save'] === 'throw') {
			throw new RuntimeException('store down');
		}

		$data = $object;
		if ($this->world['save'] !== 'noid') {
			$data = [...$object, '@self' => ['id' => 'saved-' . $schema]];
		}

		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}//end saved()

	/**
	 * The entity a find answers with, from the world.
	 *
	 * @return ObjectEntityInterface|null
	 */
	private function found(): ?ObjectEntityInterface {
		$mode = $this->world['find'];
		if ($mode === 'throw') {
			throw new RuntimeException('find down');
		}

		if ($mode === 'null') {
			return null;
		}

		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn(($mode === 'empty' ? [] : ['uuid' => 'existing', 'name' => 'Bestaand']));
		return $entity;
	}//end found()
}//end class
