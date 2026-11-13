<?php

/**
 * Pipelinq PartyKindRegistryService.
 *
 * The fleet's one vocabulary of party kinds, and the answer to two questions a
 * consuming app asks: which kinds may this record type hold, and may this
 * party hold this kind on this record.
 *
 * WHAT THIS SERVICE DELIBERATELY DOES NOT KNOW. The acceptance target is an
 * opaque `<app>:<schema>:<type>` string. It is stored and matched and never
 * parsed: pipelinq holds no case type, no record type definition and no editor
 * for one. The declaration is the consuming app's policy; only the object is
 * pipelinq's.
 *
 * THE PERMISSIVE DEFAULT IS A FEATURE. Where a record type has declared no
 * acceptance, every active kind is offered and no kind is refused. An app that
 * has declared nothing keeps working, which is what makes it safe for this
 * capability to land before every app has declared anything.
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
 * @spec openspec/specs/party-kind-registry/spec.md#requirement-pipelinq-shall-hold-the-vocabulary-of-party-kinds-req-pkr-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The party kind vocabulary, the acceptance per record type, and the refusals.
 *
 * @spec openspec/specs/party-kind-registry/spec.md#requirement-a-party-link-with-an-unaccepted-kind-shall-be-refused-on-the-write-req-pkr-004
 */
class PartyKindRegistryService {
	/**
	 * Upper bound on the rows read per question.
	 *
	 * @var int
	 */
	private const LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config, holding the schema ids.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Every declared kind, keyed by code.
	 *
	 * Includes inactive kinds: a link already written has to keep resolving
	 * its kind and its label after that kind is retired, or a retired kind
	 * takes the meaning of old links with it.
	 *
	 * @return array<string, array<string, mixed>> The vocabulary.
	 *
	 * @spec openspec/specs/party-kind-registry/spec.md#requirement-pipelinq-shall-hold-the-vocabulary-of-party-kinds-req-pkr-001
	 */
	public function vocabulary(): array {
		$byCode = [];
		foreach ($this->read(schemaKey: 'partyKind_schema', filters: []) as $row) {
			$code = trim((string)($row['code'] ?? ''));
			if ($code !== '') {
				$byCode[$code] = $row;
			}
		}

		return $byCode;
	}//end vocabulary()

	/**
	 * The kinds a record type accepts, in the order they were declared.
	 *
	 * Where no acceptance exists, every ACTIVE kind is offered, so an app that
	 * has declared nothing keeps working. The declared order is never
	 * re-sorted: the first kind is what most handlers will take, and that is
	 * part of the declaration.
	 *
	 * @param string $recordType The `<app>:<schema>:<type>` literal.
	 *
	 * @return array<int, array<string, mixed>> The offered kinds, in order.
	 *
	 * @spec openspec/specs/party-kind-registry/spec.md#requirement-the-picker-shall-offer-only-the-declared-kinds-in-the-declared-order-req-pkr-003
	 */
	public function kindsFor(string $recordType): array {
		$vocabulary = $this->vocabulary();
		$declared = $this->acceptanceFor(recordType: $recordType);

		if ($declared === null) {
			return array_values(
				array_filter(
					$vocabulary,
					static fn (array $kind): bool => ($kind['active'] ?? true) !== false
				)
			);
		}

		$offered = [];
		foreach ($declared as $code) {
			$kind = ($vocabulary[$code] ?? null);
			if ($kind === null) {
				$this->logger->warning(
					'PartyKindRegistryService: an acceptance names a kind nobody declares',
					['recordType' => $recordType, 'kind' => $code]
				);
				continue;
			}

			$offered[] = $kind;
		}

		return $offered;
	}//end kindsFor()

	/**
	 * The ordered kinds a record type declared, or null when it declared none.
	 *
	 * Null and [] are different answers and are kept apart deliberately: null
	 * means nothing was declared, which is permissive, and [] means a record
	 * type declared that it accepts no party at all.
	 *
	 * @param string $recordType The `<app>:<schema>:<type>` literal.
	 *
	 * @return array<int, string>|null The declared codes, or null.
	 */
	public function acceptanceFor(string $recordType): ?array {
		$recordType = trim($recordType);
		if ($recordType === '') {
			return null;
		}

		// Matched as an opaque string. No parsing, no app id validation, and
		// no assumption about what the three segments mean.
		$rows = $this->read(
			schemaKey: 'partyKindAcceptance_schema',
			filters: ['recordType' => $recordType],
		);

		foreach ($rows as $row) {
			if ((string)($row['recordType'] ?? '') !== $recordType) {
				continue;
			}

			$kinds = ($row['kinds'] ?? []);
			if (is_array($kinds) === false) {
				return [];
			}

			return array_values(array_map(static fn (mixed $k): string => trim((string)$k), $kinds));
		}

		return null;
	}//end acceptanceFor()

	/**
	 * Whether a party may be linked to a record under a kind.
	 *
	 * The same answer serves the picker, an import, an API call and a flow, so
	 * none of them can go around the rule the others obey.
	 *
	 * @param string $recordType The `<app>:<schema>:<type>` literal.
	 * @param string $recordId The record the party would be linked to.
	 * @param string $kind The party kind code.
	 * @param string $party The party being linked.
	 *
	 * @return array{allowed: bool, reason: string} The answer, with the reason
	 *   a refusal names.
	 *
	 * @spec openspec/specs/party-kind-registry/spec.md#requirement-a-party-link-with-an-unaccepted-kind-shall-be-refused-on-the-write-req-pkr-004
	 */
	public function mayLink(string $recordType, string $recordId, string $kind, string $party = ''): array {
		$recordType = trim($recordType);
		$kind = trim($kind);

		$refusal = $this->kindRefusal(recordType: $recordType, kind: $kind);
		if ($refusal !== '') {
			return ['allowed' => false, 'reason' => $refusal];
		}

		if ($this->holdsOne(kind: $kind) === false) {
			return ['allowed' => true, 'reason' => ''];
		}

		$holder = self::otherHolder(links: $this->linksOn(recordType: $recordType, recordId: $recordId), slot: ['kind' => $kind, 'party' => $party]);
		if ($holder !== null) {
			return self::slotTaken(kind: $kind, holder: $holder);
		}

		return ['allowed' => true, 'reason' => ''];
	}//end mayLink()

	/**
	 * Why a kind may not be linked to a record type at all; '' when it may.
	 *
	 * @param string $recordType The trimmed `<app>:<schema>:<type>` literal.
	 * @param string $kind       The trimmed party kind code.
	 *
	 * @return string
	 */
	private function kindRefusal(string $recordType, string $kind): string {
		if ($kind === '') {
			return 'A party kind is required.';
		}

		$declared = $this->acceptanceFor(recordType: $recordType);
		if ($declared !== null && in_array($kind, $declared, true) === false) {
			return "{$recordType} does not accept a party of kind {$kind}.";
		}

		return '';
	}//end kindRefusal()

	/**
	 * Whether a kind holds at most one party per record.
	 *
	 * @param string $kind The party kind code.
	 *
	 * @return bool
	 */
	private function holdsOne(string $kind): bool {
		$vocabulary = $this->vocabulary();
		$declaredKind = ($vocabulary[$kind] ?? null);

		return (int)($declaredKind['maxPerRecord'] ?? 0) === 1;
	}//end holdsOne()

	/**
	 * The party other than the one being linked that holds the kind's slot on
	 * the record through a link that has not ended; null when the slot is free.
	 *
	 * @param array<int, array<string, mixed>> $links The links on the record.
	 * @param array{kind: string, party: string} $slot The kind and the party being linked.
	 *
	 * @return string|null
	 */
	private static function otherHolder(array $links, array $slot): ?string {
		foreach ($links as $link) {
			if ((string)($link['kind'] ?? '') !== $slot['kind']) {
				continue;
			}

			if (trim((string)($link['validUntil'] ?? '')) !== '') {
				// An ended link does not hold the slot. Replacing is an
				// explicit act: end the existing link, then write the new one.
				continue;
			}

			$holder = (string)($link['party'] ?? '');
			if ($holder !== $slot['party']) {
				return $holder;
			}
		}

		return null;
	}//end otherHolder()

	/**
	 * The refusal of a held slot, naming its holder.
	 *
	 * @param string $kind   The party kind code.
	 * @param string $holder The party that holds it.
	 *
	 * @return array{allowed: bool, reason: string}
	 */
	private static function slotTaken(string $kind, string $holder): array {
		return [
			'allowed' => false,
			'reason' => "This record already holds a {$kind}: party {$holder}.",
		];
	}//end slotTaken()

	/**
	 * Judge a batch of links by exactly the rule one link is judged by.
	 *
	 * The accepted rows land and the refused rows are reported with the same
	 * message a single write would have given, because an import that used a
	 * looser rule is how the rule stops being one.
	 *
	 * @param string $recordType The `<app>:<schema>:<type>` literal.
	 * @param string $recordId The record.
	 * @param array<int, array<string, string>> $rows Each with `party` and `kind`.
	 *
	 * @return array{accepted: array<int, array<string, string>>, refused: array<int, array<string, string>>}
	 *   The verdict per row.
	 *
	 * @spec openspec/specs/party-kind-registry/spec.md#requirement-a-party-link-with-an-unaccepted-kind-shall-be-refused-on-the-write-req-pkr-004
	 */
	public function judgeBatch(string $recordType, string $recordId, array $rows): array {
		$accepted = [];
		$refused = [];
		$taken = [];

		foreach ($rows as $row) {
			$kind = trim((string)($row['kind'] ?? ''));
			$party = trim((string)($row['party'] ?? ''));

			$answer = $this->mayLink(
				recordType: $recordType,
				recordId: $recordId,
				kind: $kind,
				party: $party,
			);

			// A batch judges itself as well as the store: two aanvragers in
			// one import must not both land because neither was written yet.
			if ($answer['allowed'] === true && isset($taken[$kind]) === true && $this->holdsOne(kind: $kind) === true) {
				$answer = self::slotTaken(kind: $kind, holder: $taken[$kind]);
			}

			if ($answer['allowed'] === true) {
				$accepted[] = ['party' => $party, 'kind' => $kind];
				$taken[$kind] = $party;
				continue;
			}

			$refused[] = ['party' => $party, 'kind' => $kind, 'reason' => $answer['reason']];
		}

		return ['accepted' => $accepted, 'refused' => $refused];
	}//end judgeBatch()

	/**
	 * The links currently written on one record.
	 *
	 * @param string $recordType The `<app>:<schema>:<type>` literal.
	 * @param string $recordId The record.
	 *
	 * @return array<int, array<string, mixed>> The links.
	 */
	public function linksOn(string $recordType, string $recordId): array {
		$recordId = trim($recordId);
		if ($recordId === '') {
			return [];
		}

		return $this->read(
			schemaKey: 'partyLink_schema',
			filters: ['recordType' => trim($recordType), 'recordId' => $recordId],
		);
	}//end linksOn()

	/**
	 * Read rows of one schema as plain arrays.
	 *
	 * @param string $schemaKey The app-config key holding the schema id.
	 * @param array<string, mixed> $filters Extra filters.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function read(string $schemaKey, array $filters): array {
		$scope = $this->scopeOf(schemaKey: $schemaKey);
		if ($scope === null) {
			return [];
		}

		try {
			$rows = $this->objectService->findAll(
				[
					'filters' => array_merge($scope, $filters),
					'limit' => self::LIMIT,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'PartyKindRegistryService: the read failed',
				['schemaKey' => $schemaKey, 'exception' => $e->getMessage()]
			);

			return [];
		}

		return self::plainRows(rows: $rows);
	}//end read()

	/**
	 * The register and schema filters of a schema key; null (logged) when either is unset.
	 *
	 * @param string $schemaKey The app-config key holding the schema id.
	 *
	 * @return array{register: string, schema: string}|null
	 */
	private function scopeOf(string $schemaKey): ?array {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(AppIdentity::APP_ID, $schemaKey, '');
		if ($register === '' || $schema === '') {
			$this->logger->debug(
				'PartyKindRegistryService: the party kind surface is not configured',
				['schemaKey' => $schemaKey]
			);

			return null;
		}

		return ['register' => $register, 'schema' => $schema];
	}//end scopeOf()

	/**
	 * The rows as plain arrays: arrays as they are, serialisable entities by
	 * what they serialise to; anything else is dropped.
	 *
	 * @param iterable<mixed> $rows The rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function plainRows($rows): array {
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
	}//end plainRows()
}//end class
