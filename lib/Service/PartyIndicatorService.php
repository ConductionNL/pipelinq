<?php

/**
 * Pipelinq PartyIndicatorService.
 *
 * A standing indicator on a party, resolved live wherever that party is shown,
 * and an answer to the one question a consuming app asks before it acts: given
 * this party and this act, is it blocked, and by which indicator.
 *
 * Two decisions are load-bearing and are worth stating where the code is.
 *
 * NOTHING IS COPIED ONTO A CASE. The tempting implementation stamps the
 * indicator onto a case when the case is created. That is wrong in both
 * directions: a person who dies after the case opens keeps a case that says
 * they are alive, and an indicator lifted after a dispute leaves stale copies
 * on twenty cases. Neither shows up as a failure. So every read resolves, and
 * a period on the value makes a lifted indicator stop showing on its own date.
 *
 * PIPELINQ ANSWERS, IT DOES NOT INTERCEPT. Enforcing the block here would mean
 * pipelinq knowing about every outbound path in the fleet, which is exactly
 * the coupling the ownership rule exists to prevent. The contract is a
 * question with a clear answer; a caller that does not ask is a defect in the
 * caller, and the gate for that belongs with the send path.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/party-fields-and-indicators/spec.md#requirement-an-indicator-shall-be-a-declared-vocabulary-with-dated-values-req-pfi-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolve a party's indicators, and answer whether one blocks an act.
 *
 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/party-fields-and-indicators/spec.md#requirement-a-partys-indicators-shall-resolve-live-on-every-surface-showing-that-party-req-pfi-003
 */
class PartyIndicatorService {
	/**
	 * The acts a caller can ask about, and the effect each one reads.
	 *
	 * @var array<string, string>
	 */
	public const ACTS = [
		'send' => 'blocksOutbound',
		'publishAddress' => 'blocksAddressPublication',
		'handle' => 'requiresAcknowledgement',
	];

	/**
	 * Upper bound on the values read for one party.
	 *
	 * @var int
	 */
	private const VALUE_LIMIT = 200;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config, holding the schema ids.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param IUserSession $userSession The acting user, recorded on an acknowledgement.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether a dated value applies on a given day.
	 *
	 * A value whose `validUntil` has passed stops applying on its own date,
	 * with nobody editing it. That is the whole reason the period is stored
	 * rather than the fact.
	 *
	 * @param array<string, mixed> $value The stored indicator value.
	 * @param DateTimeImmutable|null $on The day to judge it on; today by default.
	 *
	 * @return bool True when the value applies.
	 *
	 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/party-fields-and-indicators/spec.md#requirement-an-indicator-shall-be-a-declared-vocabulary-with-dated-values-req-pfi-002
	 */
	public function applies(array $value, ?DateTimeImmutable $on = null): bool {
		$on = ($on ?? new DateTimeImmutable('today'));

		$from = $this->day(value: ($value['validFrom'] ?? null));
		if ($from !== null && $from > $on) {
			return false;
		}

		$until = $this->day(value: ($value['validUntil'] ?? null));

		return ($until === null || $until >= $on);
	}//end applies()

	/**
	 * Every indicator a party carries today, resolved live.
	 *
	 * @param string $partyId The party record's uuid.
	 * @param DateTimeImmutable|null $on The day to resolve on; today by default.
	 *
	 * @return array<int, array<string, mixed>> The indicators, each with its
	 *   code, label, severity, effects, period, source and acknowledgement.
	 *
	 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/party-fields-and-indicators/spec.md#requirement-a-partys-indicators-shall-resolve-live-on-every-surface-showing-that-party-req-pfi-003
	 */
	public function resolve(string $partyId, ?DateTimeImmutable $on = null): array {
		$partyId = trim($partyId);
		if ($partyId === '') {
			return [];
		}

		$vocabulary = $this->vocabulary();
		$resolved = [];

		foreach ($this->valuesFor(partyId: $partyId) as $value) {
			if ($this->applies(value: $value, on: $on) === false) {
				continue;
			}

			$code = (string)($value['indicator'] ?? '');
			$declared = ($vocabulary[$code] ?? null);
			if ($declared === null) {
				// A value against a code nobody declares is reported rather
				// than dropped: a dropped safety flag is silent, and silence
				// is the failure this whole capability exists to prevent.
				$this->logger->warning(
					'PartyIndicatorService: an indicator value names an undeclared indicator',
					['party' => $partyId, 'indicator' => $code]
				);
				$declared = ['code' => $code, 'label' => $code, 'severity' => 'warning'];
			}

			$resolved[] = [
				'id' => (string)($value['id'] ?? $value['uuid'] ?? ''),
				'code' => $code,
				'label' => (string)($declared['label'] ?? $code),
				'severity' => (string)($declared['severity'] ?? 'warning'),
				'blocksOutbound' => (bool)($declared['blocksOutbound'] ?? false),
				'blocksAddressPublication' => (bool)($declared['blocksAddressPublication'] ?? false),
				'requiresAcknowledgement' => (bool)($declared['requiresAcknowledgement'] ?? false),
				'validFrom' => (string)($value['validFrom'] ?? ''),
				'validUntil' => (string)($value['validUntil'] ?? ''),
				'source' => (string)($value['source'] ?? ''),
				'acknowledgedBy' => (string)($value['acknowledgedBy'] ?? ''),
				'acknowledgedAt' => (string)($value['acknowledgedAt'] ?? ''),
			];
		}

		return $resolved;
	}//end resolve()

	/**
	 * Whether an act on a party is blocked, and by which indicator.
	 *
	 * The answer names the indicator, its label and its severity, so a caller
	 * can show a person why an act was refused instead of a bare refusal.
	 *
	 * @param string $partyId The party record's uuid.
	 * @param string $act One of the ACTS keys.
	 * @param DateTimeImmutable|null $on The day to judge on; today by default.
	 *
	 * @return array{blocked: bool, act: string, indicators: array<int, array<string, mixed>>}
	 *   The answer.
	 *
	 * @throws InvalidArgumentException When the act is not one this service answers.
	 *
	 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/party-fields-and-indicators/spec.md#requirement-pipelinq-shall-answer-whether-an-indicator-blocks-an-act-and-shall-not-intercept-it-req-pfi-004
	 */
	public function isBlocked(string $partyId, string $act, ?DateTimeImmutable $on = null): array {
		$effect = (self::ACTS[$act] ?? null);
		if ($effect === null) {
			throw new InvalidArgumentException(
				"Unknown act {$act}; this service answers about " . implode(', ', array_keys(self::ACTS)) . '.'
			);
		}

		$blocking = array_values(
			array_filter(
				$this->resolve(partyId: $partyId, on: $on),
				static fn (array $indicator): bool => ($indicator[$effect] ?? false) === true
			)
		);

		return [
			'blocked' => ($blocking !== []),
			'act' => $act,
			'indicators' => $blocking,
		];
	}//end isBlocked()

	/**
	 * Record that a handler has seen an indicator that demands it.
	 *
	 * @param string $valueId The indicator value's uuid.
	 *
	 * @return array<string, mixed> `status` plus either `indicatorValue` or `error`.
	 *
	 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/contactmomenten/spec.md#requirement-the-contact-moment-panel-shall-show-the-partys-indicators-req-cmi-001
	 */
	public function acknowledge(string $valueId): array {
		$valueId = trim($valueId);
		$schema = $this->schemaId(key: 'partyIndicatorValue_schema');
		if ($valueId === '' || $schema === '') {
			return ['status' => 400, 'error' => 'An indicator value is required.'];
		}

		try {
			$entity = $this->objectService->find(id: $valueId);
		} catch (Throwable $e) {
			$this->logger->debug(
				'PartyIndicatorService: could not read the indicator value',
				['uuid' => $valueId, 'exception' => $e->getMessage()]
			);
			$entity = null;
		}

		if ($entity === null) {
			return ['status' => 404, 'error' => 'That indicator value could not be read.'];
		}

		$data = $entity->jsonSerialize();
		if (is_array($data) === false) {
			return ['status' => 404, 'error' => 'That indicator value could not be read.'];
		}

		$data['acknowledgedBy'] = ($this->userSession->getUser()?->getUID() ?? '');
		$data['acknowledgedAt'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

		try {
			$this->objectService->saveObject(
				object: $data,
				register: $this->registerId(),
				schema: $schema,
				uuid: $valueId,
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'PartyIndicatorService: the acknowledgement could not be saved',
				['uuid' => $valueId, 'exception' => $e->getMessage()]
			);

			return ['status' => 500, 'error' => 'The acknowledgement could not be saved.'];
		}

		return ['status' => 200, 'indicatorValue' => $data];
	}//end acknowledge()

	/**
	 * The indicator values two merging parties end up with.
	 *
	 * UNIONED, never survived. If one record says the person is deceased and
	 * the other does not, the merged party is deceased. Picking a winner here
	 * would drop a safety flag by algorithm, silently, which is the worst
	 * failure available to this change. Field values keep following the
	 * survivorship rules; indicators deliberately do not.
	 *
	 * @param array<int, array<string, mixed>> $winner The surviving record's values.
	 * @param array<int, array<string, mixed>> $loser The merged record's values.
	 *
	 * @return array<int, array<string, mixed>> The union, one entry per code.
	 *
	 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/party-fields-and-indicators/spec.md#requirement-a-merge-shall-survive-field-values-and-shall-union-indicators-req-pfi-006
	 */
	public function unionForMerge(array $winner, array $loser): array {
		$union = [];

		foreach (array_merge($winner, $loser) as $value) {
			$code = trim((string)($value['indicator'] ?? $value['code'] ?? ''));
			if ($code === '') {
				continue;
			}

			if (isset($union[$code]) === false) {
				$union[$code] = $value;
				continue;
			}

			// Two values for one code: keep the one that is still open, and
			// otherwise the one that runs longest. An open period outranks a
			// closed one because a lifted flag must not end a standing one.
			$union[$code] = $this->widerPeriod(a: $union[$code], b: $value);
		}

		return array_values($union);
	}//end unionForMerge()

	/**
	 * Of two values for one code, the one that applies longest.
	 *
	 * @param array<string, mixed> $a One value.
	 * @param array<string, mixed> $b The other.
	 *
	 * @return array<string, mixed> The wider of the two.
	 */
	private function widerPeriod(array $a, array $b): array {
		$untilA = $this->day(value: ($a['validUntil'] ?? null));
		$untilB = $this->day(value: ($b['validUntil'] ?? null));

		if ($untilA === null) {
			return $a;
		}

		if ($untilB === null) {
			return $b;
		}

		return ($untilB > $untilA ? $b : $a);
	}//end widerPeriod()

	/**
	 * The declared indicators, keyed by code.
	 *
	 * @return array<string, array<string, mixed>> The vocabulary.
	 */
	private function vocabulary(): array {
		$rows = $this->read(schemaKey: 'partyIndicator_schema', filters: []);

		$byCode = [];
		foreach ($rows as $row) {
			$code = trim((string)($row['code'] ?? ''));
			if ($code !== '') {
				$byCode[$code] = $row;
			}
		}

		return $byCode;
	}//end vocabulary()

	/**
	 * The stored indicator values for one party.
	 *
	 * @param string $partyId The party record's uuid.
	 *
	 * @return array<int, array<string, mixed>> The values.
	 */
	private function valuesFor(string $partyId): array {
		return $this->read(
			schemaKey: 'partyIndicatorValue_schema',
			filters: ['party' => $partyId],
		);
	}//end valuesFor()

	/**
	 * Read rows of one schema as plain arrays.
	 *
	 * Returns [] rather than throwing when the surface is unconfigured, so a
	 * party page on an unprovisioned instance renders without indicators
	 * instead of failing. The empty case is logged.
	 *
	 * @param string $schemaKey The app-config key holding the schema id.
	 * @param array<string, mixed> $filters Extra filters.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function read(string $schemaKey, array $filters): array {
		$register = $this->registerId();
		$schema = $this->schemaId(key: $schemaKey);
		if ($register === '' || $schema === '') {
			$this->logger->debug(
				'PartyIndicatorService: the indicator surface is not configured',
				['schemaKey' => $schemaKey]
			);

			return [];
		}

		try {
			$rows = $this->objectService->findAll(
				[
					'filters' => array_merge(['register' => $register, 'schema' => $schema], $filters),
					'limit' => self::VALUE_LIMIT,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'PartyIndicatorService: the indicator read failed',
				['schemaKey' => $schemaKey, 'exception' => $e->getMessage()]
			);

			return [];
		}

		$out = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$out[] = $row;
				continue;
			}

			if (($row instanceof \JsonSerializable) === true) {
				$data = $row->jsonSerialize();
				if (is_array($data) === true) {
					$out[] = $data;
				}
			}
		}

		return $out;
	}//end read()

	/**
	 * The pipelinq register id.
	 *
	 * @return string The register id ('' when unconfigured).
	 */
	private function registerId(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'register', '');
	}//end registerId()

	/**
	 * One schema id from the app config.
	 *
	 * @param string $key The app-config key.
	 *
	 * @return string The schema id ('' when unconfigured).
	 */
	private function schemaId(string $key): string {
		return $this->appConfig->getValueString(Application::APP_ID, $key, '');
	}//end schemaId()

	/**
	 * Parse a stored day, or null when it is absent or unusable.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return DateTimeImmutable|null The day.
	 */
	private function day(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return new DateTimeImmutable((new DateTimeImmutable($value))->format('Y-m-d'));
		} catch (Throwable $e) {
			return null;
		}
	}//end day()
}//end class
