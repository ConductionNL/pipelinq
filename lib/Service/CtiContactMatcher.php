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
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-2.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
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
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-2.3
 */
class CtiContactMatcher {
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
	 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-2.3
	 */
	public function findByPhoneNumber(?string $e164Number, ?string $orgId = null): array {
		if ($e164Number === null || $e164Number === '') {
			return ['matches' => [], 'totalMatches' => 0];
		}

		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$contactSchema = $this->appConfig->getValueString(Application::APP_ID, 'contact_schema', '');
		$clientSchema = $this->appConfig->getValueString(Application::APP_ID, 'client_schema', '');

		if ($register === '') {
			return ['matches' => [], 'totalMatches' => 0];
		}

		$needles = $this->needles(e164: $e164Number);

		$matches = [];
		if ($contactSchema !== '') {
			$matches = array_merge(
				$matches,
				$this->searchSchema(register: $register, schema: $contactSchema, needles: $needles, type: 'contact')
			);
		}

		if ($clientSchema !== '') {
			$matches = array_merge(
				$matches,
				$this->searchSchema(register: $register, schema: $clientSchema, needles: $needles, type: 'client')
			);
		}

		$total = count($matches);
		return [
			'matches' => array_slice($matches, 0, self::MAX_MATCHES),
			'totalMatches' => $total,
		];
	}//end findByPhoneNumber()

	/**
	 * Build a list of phone-number fragments to test against stored records.
	 *
	 * @param string $e164 Normalised E.164 number.
	 *
	 * @return array<int,string>
	 */
	private function needles(string $e164): array {
		$digitsOnly = preg_replace('/[^0-9]/', '', $e164) ?? '';
		$needles = [$e164, $digitsOnly];
		if (strlen($digitsOnly) >= 9) {
			$needles[] = substr($digitsOnly, -9);
		}

		if (strlen($digitsOnly) >= 8) {
			$needles[] = substr($digitsOnly, -8);
		}

		return array_values(array_unique(array_filter($needles)));
	}//end needles()

	/**
	 * Search a single schema (contact or client) for any of the needles.
	 *
	 * Uses ObjectService::findAll with a wide fetch then in-memory filter
	 * against any common phone-number property (`phone`, `phoneNumber`,
	 * `mobile`, `telephone`).
	 *
	 * @param string $register The register ID.
	 * @param string $schema The schema ID.
	 * @param array<int,string> $needles Digit fragments to test against.
	 * @param string $type Tag (contact|client) added to each result.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function searchSchema(string $register, string $schema, array $needles, string $type): array {
		if (empty($needles) === true) {
			return [];
		}

		try {
			/*
			 * @var \OCA\OpenRegister\Service\ObjectService $objectService
			 */

			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$objects = $objectService->findAll(
				[
					'filters' => [
						'register' => $register,
						'schema' => $schema,
					],
					'limit' => 500,
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'CTI contact matcher: OR findAll failed',
				['exception' => $e->getMessage(), 'schema' => $schema]
			);
			return [];
		}//end try

		if (is_array($objects) === false) {
			return [];
		}

		$matches = [];
		foreach ($objects as $object) {
			$data = $this->toArray(object: $object);
			if ($this->matchesAnyNeedle(record: $data, needles: $needles) === true) {
				$data['_matchType'] = $type;
				$matches[] = $data;
			}
		}

		return $matches;
	}//end searchSchema()

	/**
	 * Normalise an OR object (entity or array) to a plain array.
	 *
	 * @param mixed $object The OR object (entity, array or jsonSerialize-capable).
	 *
	 * @return array<string,mixed>
	 */
	private function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialised = $object->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$inner = $object->getObject();
			if (is_array($inner) === true) {
				return $inner;
			}
		}

		return [];
	}//end toArray()

	/**
	 * Whether the record's phone-bearing fields contain any of the needles.
	 *
	 * @param array<string,mixed> $record The contact/client record.
	 * @param array<int,string> $needles Digit fragments to test against.
	 *
	 * @return bool
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Flat nested scans over candidate fields; extraction adds no clarity.
	 */
	private function matchesAnyNeedle(array $record, array $needles): bool {
		$candidates = [];
		foreach (['phone', 'phoneNumber', 'mobile', 'telephone', 'tel', 'msisdn'] as $key) {
			if (isset($record[$key]) === true && is_string($record[$key]) === true) {
				$candidates[] = $record[$key];
			}
		}

		$rawObject = ($record['object'] ?? null);
		if (is_array($rawObject) === true) {
			foreach (['phone', 'phoneNumber', 'mobile', 'telephone', 'tel'] as $key) {
				if (isset($rawObject[$key]) === true && is_string($rawObject[$key]) === true) {
					$candidates[] = $rawObject[$key];
				}
			}
		}

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
	}//end matchesAnyNeedle()

	/**
	 * Normalise all stored phone numbers on contact and client records.
	 *
	 * Streams up to 5000 records per call (callers can loop). For each record
	 * that has a phone-bearing field and lacks an `e164` field, the normalised
	 * value is written back via OR `updateObject`.
	 *
	 * @return array{updated:int, skipped:int}
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Sequential guard clauses streaming records; extraction adds no clarity.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Sequential guard clauses streaming records; extraction adds no clarity.
	 *
	 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-2.3
	 */
	public function normaliseStoredPhoneNumbers(): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$contactSchema = $this->appConfig->getValueString(Application::APP_ID, 'contact_schema', '');
		$clientSchema = $this->appConfig->getValueString(Application::APP_ID, 'client_schema', '');
		if ($register === '') {
			return ['updated' => 0, 'skipped' => 0];
		}

		$updated = 0;
		$skipped = 0;

		foreach (array_filter([$contactSchema, $clientSchema]) as $schema) {
			try {
				/*
				 * @var \OCA\OpenRegister\Service\ObjectService $objectService
				 */

				$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
				$objects = $objectService->findAll(
					[
						'filters' => [
							'register' => $register,
							'schema' => $schema,
						],
						'limit' => 5000,
					]
				);
			} catch (\Throwable $e) {
				$this->logger->warning(
					'CTI contact matcher migration: OR findAll failed',
					['exception' => $e->getMessage(), 'schema' => $schema]
				);
				continue;
			}//end try

			if (is_array($objects) === false) {
				continue;
			}

			foreach ($objects as $object) {
				$data = $this->toArray(object: $object);
				$raw = (string)($data['phone'] ?? ($data['phoneNumber'] ?? ($data['mobile'] ?? '')));
				if ($raw === '') {
					$skipped++;
					continue;
				}

				$normalised = $this->phoneNormaliser->normaliseForOrg($raw);
				if ($normalised['e164'] === null) {
					$skipped++;
					continue;
				}

				if (($data['phoneE164'] ?? null) === $normalised['e164']) {
					$skipped++;
					continue;
				}

				$id = (string)($data['id'] ?? ($data['uuid'] ?? ''));
				if ($id === '') {
					$skipped++;
					continue;
				}

				try {
					$this->execAsSystem(
						objectService: $objectService,
						operation: static fn () => $objectService->saveObject(
							['phoneE164' => $normalised['e164']],
							[],
							$register,
							$schema,
							$id
						)
					);
					$updated++;
				} catch (\Throwable $e) {
					$this->logger->warning(
						'CTI contact matcher: updateObject failed',
						['exceptionClass' => get_class($e), 'exception' => $e->getMessage(), 'id' => $id]
					);
					$skipped++;
				}
			}//end foreach
		}//end foreach

		return ['updated' => $updated, 'skipped' => $skipped];
	}//end normaliseStoredPhoneNumbers()

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
