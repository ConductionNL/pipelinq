<?php

/**
 * Pipelinq CtiContactMatcher.
 *
 * Matches inbound phone numbers against OpenRegister contact / client objects.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/archive/2026-06-14-cti-screenpop-adapter/tasks.md#task-2.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\CtiContactMatcherInterface;
use OCA\Pipelinq\Util\PayloadToArray;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * CTI contact matcher.
 *
 * Resolves an incoming E.164 phone number to one or more contact / client
 * objects so the frontend can decide between single screen-pop, chooser modal
 * or new-contact intake.
 *
 * Matching uses substring fall-back: many stored numbers have not yet been
 * normalised to E.164 — to bridge that gap the last 9 digits of the inbound
 * number are checked against the raw stored value as well as against the
 * normalised one.
 *
 * @spec openspec/changes/archive/2026-06-14-cti-screenpop-adapter/tasks.md#task-2.3
 */
class CtiContactMatcher implements CtiContactMatcherInterface {
	use PayloadToArray;

	/**
	 * Maximum matches surfaced to the chooser modal.
	 *
	 * @var int
	 */
	public const MAX_MATCHES = 3;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container, used to lazily resolve OR.
	 * @param IAppConfig $appConfig The app config.
	 * @param PhoneNormaliser $phoneNormaliser Phone normaliser.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private PhoneNormaliser $phoneNormaliser,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Find contacts and clients whose phone number matches the supplied E.164 value.
	 *
	 * Returns an associative array shape:
	 *   ['matches' => [{...contact|client...}, ...], 'totalMatches' => int]
	 *
	 * - 0 matches: ['matches' => [], 'totalMatches' => 0]
	 * - 1 match : ['matches' => [<object>], 'totalMatches' => 1]
	 * - >1 match: ['matches' => [<top 3>], 'totalMatches' => N]
	 *
	 * @param string|null $e164Number Normalised E.164 phone number.
	 * @param string|null $orgId Org/tenant identifier (reserved).
	 *
	 * @return array{matches: array<int,array<string,mixed>>, totalMatches: int}
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $orgId is reserved for the forthcoming tenant scope; part of the stable public signature.
	 *
	 * @spec openspec/changes/archive/2026-06-14-cti-screenpop-adapter/tasks.md#task-2.3
	 */
	public function findByPhoneNumber(?string $e164Number, ?string $orgId = null): array {
		if ($e164Number === null || $e164Number === '') {
			return ['matches' => [], 'totalMatches' => 0];
		}

		$matches = $this->collectMatches(e164Number: $e164Number);

		$total = count($matches);
		return [
			'matches' => array_slice($matches, 0, self::MAX_MATCHES),
			'totalMatches' => $total,
		];
	}//end findByPhoneNumber()

	/**
	 * Collect contact and client matches for a non-empty E.164 number.
	 *
	 * @param string $e164Number Normalised E.164 phone number.
	 *
	 * @return array<int,array<string,mixed>> All matches, contacts first; empty when no register is configured.
	 *
	 * @spec openspec/changes/archive/2026-06-14-cti-screenpop-adapter/tasks.md#task-2.3
	 */
	private function collectMatches(string $e164Number): array {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$contactSchema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'contact_schema', '');
		$clientSchema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'client_schema', '');

		if ($register === '') {
			return [];
		}

		$needles = PhoneNeedles::for(e164: $e164Number);

		return array_merge(
			$this->searchSchema(register: $register, schema: $contactSchema, needles: $needles, type: 'contact'),
			$this->searchSchema(register: $register, schema: $clientSchema, needles: $needles, type: 'client')
		);
	}//end collectMatches()

	/**
	 * Search a single schema (contact or client) for any of the needles.
	 *
	 * Uses ObjectService::findAll with a wide fetch then in-memory filter
	 * against any common phone-number property (`phone`, `phoneNumber`,
	 * `mobile`, `telephone`).
	 *
	 * @param string $register The register ID.
	 * @param string $schema The schema ID; an empty value searches nothing.
	 * @param array<int,string> $needles Digit fragments to test against.
	 * @param string $type Tag (contact|client) added to each result.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function searchSchema(string $register, string $schema, array $needles, string $type): array {
		if ($schema === '' || empty($needles) === true) {
			return [];
		}

		return $this->filterMatches(
			objects: $this->searchableObjects(register: $register, schema: $schema),
			needles: $needles,
			type: $type
		);
	}//end searchSchema()

	/**
	 * Fetch the objects of one schema for a phone search.
	 *
	 * @param string $register The register ID.
	 * @param string $schema The schema ID.
	 *
	 * @return array<mixed> The objects, or an empty list when the fetch fails or returns no list.
	 */
	private function searchableObjects(string $register, string $schema): array {
		try {
			/*
			 * @var \OCA\OpenRegister\Service\ObjectService $objectService
			 */

			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$objects = $this->objectsOf(objectService: $objectService, register: $register, schema: $schema, limit: 500);
		} catch (\Throwable $e) {
			$this->logger->warning('CTI contact matcher: OR findAll failed', ['exception' => $e->getMessage(), 'schema' => $schema]);
			return [];
		}//end try

		if (is_array($objects) === false) {
			return [];
		}

		return $objects;
	}//end searchableObjects()

	/**
	 * Fetch up to $limit objects of one schema through ObjectService::findAll.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $register The register ID.
	 * @param string $schema The schema ID.
	 * @param int $limit Maximum number of objects to fetch.
	 *
	 * @return mixed The findAll result.
	 */
	private function objectsOf(object $objectService, string $register, string $schema, int $limit): mixed {
		return $objectService->findAll(
			[
				'filters' => [
					'register' => $register,
					'schema' => $schema,
				],
				'limit' => $limit,
			]
		);
	}//end objectsOf()

	/**
	 * Keep the objects whose phone fields contain a needle, tagged with the match type.
	 *
	 * @param array<mixed> $objects The fetched objects.
	 * @param array<int,string> $needles Digit fragments to test against.
	 * @param string $type Tag (contact|client) added to each result.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function filterMatches(array $objects, array $needles, string $type): array {
		$matches = [];
		foreach ($objects as $object) {
			$data = $this->toArray(value: $object);
			if ($this->matchesAnyNeedle(record: $data, needles: $needles) === true) {
				$data['_matchType'] = $type;
				$matches[] = $data;
			}
		}

		return $matches;
	}//end filterMatches()

	/**
	 * Whether the record's phone-bearing fields contain any of the needles.
	 *
	 * @param array<string,mixed> $record The contact/client record.
	 * @param array<int,string> $needles Digit fragments to test against.
	 *
	 * @return bool
	 */
	private function matchesAnyNeedle(array $record, array $needles): bool {
		$candidates = $this->stringFields(source: $record, keys: ['phone', 'phoneNumber', 'mobile', 'telephone', 'tel', 'msisdn']);

		$rawObject = ($record['object'] ?? null);
		if (is_array($rawObject) === true) {
			$candidates = array_merge(
				$candidates,
				$this->stringFields(source: $rawObject, keys: ['phone', 'phoneNumber', 'mobile', 'telephone', 'tel'])
			);
		}

		return $this->anyCandidateContains(candidates: $candidates, needles: $needles);
	}//end matchesAnyNeedle()

	/**
	 * The string values of the given keys, in key order.
	 *
	 * @param array<mixed> $source The record to read.
	 * @param array<int,string> $keys The keys to read.
	 *
	 * @return array<int,string>
	 */
	private function stringFields(array $source, array $keys): array {
		$values = [];
		foreach ($keys as $key) {
			if (isset($source[$key]) === true && is_string($source[$key]) === true) {
				$values[] = $source[$key];
			}
		}

		return $values;
	}//end stringFields()

	/**
	 * Whether any cleaned candidate number contains any of the needles.
	 *
	 * @param array<int,string> $candidates Raw stored phone values.
	 * @param array<int,string> $needles Digit fragments to test against.
	 *
	 * @return bool
	 */
	private function anyCandidateContains(array $candidates, array $needles): bool {
		foreach ($candidates as $candidate) {
			$cleaned = preg_replace('/[^0-9+]/', '', $candidate) ?? '';
			if ($cleaned === '') {
				continue;
			}

			foreach ($needles as $needle) {
				if (str_contains($cleaned, $needle) === true) {
					return true;
				}
			}
		}

		return false;
	}//end anyCandidateContains()

	/**
	 * Normalise all stored phone numbers on contact and client records.
	 *
	 * Streams up to 5000 records per call (callers can loop). For each record
	 * that has a phone-bearing field and lacks an `e164` field, the normalised
	 * value is written back via OR `updateObject`.
	 *
	 * @return array{updated:int, skipped:int}
	 *
	 * @spec openspec/changes/archive/2026-06-14-cti-screenpop-adapter/tasks.md#task-2.3
	 */
	public function normaliseStoredPhoneNumbers(): array {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$contactSchema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'contact_schema', '');
		$clientSchema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'client_schema', '');
		$totals = ['updated' => 0, 'skipped' => 0];
		if ($register === '') {
			return $totals;
		}

		foreach (array_filter([$contactSchema, $clientSchema]) as $schema) {
			$totals = $this->normaliseSchema(register: $register, schema: $schema, totals: $totals);
		}

		return $totals;
	}//end normaliseStoredPhoneNumbers()

	/**
	 * Normalise the stored phone numbers of one schema and add the outcomes to the totals.
	 *
	 * @param string $register The register ID.
	 * @param string $schema The schema ID.
	 * @param array{updated:int, skipped:int} $totals The running totals.
	 *
	 * @return array{updated:int, skipped:int}
	 *
	 * @spec openspec/changes/archive/2026-06-14-cti-screenpop-adapter/tasks.md#task-2.3
	 */
	private function normaliseSchema(string $register, string $schema, array $totals): array {
		$fetched = $this->fetchForNormalise(register: $register, schema: $schema);
		if ($fetched === null) {
			return $totals;
		}

		[$objectService, $objects] = $fetched;
		foreach ($objects as $object) {
			$data = $this->toArray(value: $object);
			$outcome = $this->normaliseRecord(objectService: $objectService, data: $data, register: $register, schema: $schema);
			$totals[$outcome]++;
		}

		return $totals;
	}//end normaliseSchema()

	/**
	 * Fetch the ObjectService and the objects of one schema for the normalisation walk.
	 *
	 * @param string $register The register ID.
	 * @param string $schema The schema ID.
	 *
	 * @return array{0: object, 1: array<mixed>}|null The service and objects, or null when the fetch fails or returns no list.
	 *
	 * @spec openspec/changes/archive/2026-06-14-cti-screenpop-adapter/tasks.md#task-2.3
	 */
	private function fetchForNormalise(string $register, string $schema): array|null {
		try {
			/*
			 * @var \OCA\OpenRegister\Service\ObjectService $objectService
			 */

			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$objects = $this->objectsOf(objectService: $objectService, register: $register, schema: $schema, limit: 5000);
		} catch (\Throwable $e) {
			$this->logger->warning('CTI contact matcher migration: OR findAll failed', ['exception' => $e->getMessage(), 'schema' => $schema]);
			return null;
		}//end try

		if (is_array($objects) === false) {
			return null;
		}

		return [$objectService, $objects];
	}//end fetchForNormalise()

	/**
	 * Write the normalised number of one record back, when it needs one.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param array<string,mixed> $data The record.
	 * @param string $register The register ID.
	 * @param string $schema The schema ID.
	 *
	 * @return 'updated'|'skipped'
	 *
	 * @spec openspec/changes/archive/2026-06-14-cti-screenpop-adapter/tasks.md#task-2.3
	 */
	private function normaliseRecord(object $objectService, array $data, string $register, string $schema): string {
		$e164 = $this->targetE164(data: $data);
		$id = (string)($data['id'] ?? ($data['uuid'] ?? ''));
		if ($e164 === null || $id === '') {
			return 'skipped';
		}

		$operation = static fn () => $objectService->saveObject(['phoneE164' => $e164], [], $register, $schema, $id);
		return $this->runPhoneWrite(objectService: $objectService, operation: $operation, id: $id);
	}//end normaliseRecord()

	/**
	 * The E.164 value to store on a record, or null when there is nothing to write.
	 *
	 * @param array<string,mixed> $data The record.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/archive/2026-06-14-cti-screenpop-adapter/tasks.md#task-2.3
	 */
	private function targetE164(array $data): string|null {
		$raw = (string)($data['phone'] ?? ($data['phoneNumber'] ?? ($data['mobile'] ?? '')));
		if ($raw === '') {
			return null;
		}

		$normalised = $this->phoneNormaliser->normaliseForOrg($raw);
		if ($normalised['e164'] === null) {
			return null;
		}

		if (($data['phoneE164'] ?? null) === $normalised['e164']) {
			return null;
		}

		return $normalised['e164'];
	}//end targetE164()

	/**
	 * Run one phone write as system, logging a failure.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param callable $operation The write operation.
	 * @param string $id The object id, for the log.
	 *
	 * @return 'updated'|'skipped'
	 *
	 * @spec openspec/changes/archive/2026-06-14-cti-screenpop-adapter/tasks.md#task-2.3
	 */
	private function runPhoneWrite(object $objectService, callable $operation, string $id): string {
		try {
			$this->execAsSystem(objectService: $objectService, operation: $operation);
			return 'updated';
		} catch (\Throwable $e) {
			$this->logger->warning(
				'CTI contact matcher: updateObject failed',
				['exceptionClass' => get_class($e), 'exception' => $e->getMessage(), 'id' => $id]
			);
			return 'skipped';
		}
	}//end runPhoneWrite()

	/**
	 * Execute an OpenRegister write under the scoped system-operation context.
	 *
	 * The normaliseStoredPhoneNumbers() walk is invoked from the
	 * NormaliseCtiPhoneNumbers repair step, which runs without a user session
	 * (web-triggered upgrades, webcron), so OpenRegister RBAC denies every write as 'Anonymous'
	 * (NotAuthorizedException). ObjectService::runAsSystem scopes trusted-system
	 * elevation to the callable; older OpenRegister versions without it fall
	 * back to the direct call.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param callable $operation The write operation to execute.
	 *
	 * @return void The operation's result is deliberately not propagated: the sole
	 *              caller writes via saveObject and only needs success or failure,
	 *              which exceptions already signal.
	 *
	 * @spec exclude system-context adoption — back-compat elevation shim around OR writes, no behavioural spec surface.
	 */
	private function execAsSystem(object $objectService, callable $operation): void {
		if (method_exists($objectService, 'runAsSystem') === true) {
			$objectService->runAsSystem($operation);
			return;
		}

		$operation();
	}//end execAsSystem()
}//end class
