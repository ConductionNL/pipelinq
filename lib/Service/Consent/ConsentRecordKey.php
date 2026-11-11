<?php

/**
 * Pipelinq ConsentRecordKey.
 *
 * Which ConsentRecord a lookup asks for.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Consent
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-pending-subscription-never-receives-a-blast
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Consent;

/**
 * The contact, the lower-cased channel and the list of one ConsentRecord;
 * no list is the channel-wide record.
 *
 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-pending-subscription-never-receives-a-blast
 */
final class ConsentRecordKey {
	/**
	 * Constructor.
	 *
	 * @param string      $contactId The contact being looked up.
	 * @param string      $channel   The lower-cased channel.
	 * @param string|null $listId    The list id, or null for the channel-wide record.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $contactId,
		public readonly string $channel,
		public readonly ?string $listId,
	) {
	}//end __construct()

	/**
	 * The OpenRegister filters for the candidate rows of this record.
	 *
	 * @param string $register The register slug.
	 * @param string $schema   The ConsentRecord schema slug.
	 *
	 * @return array<string, string> The filters.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-pending-subscription-never-receives-a-blast
	 */
	public function filters(string $register, string $schema): array {
		return [
			'contactId' => $this->contactId,
			'channel' => $this->channel,
			'register' => $register,
			'schema' => $schema,
		];
	}//end filters()

	/**
	 * Whether a row is the ConsentRecord for this contact, channel and scope.
	 *
	 * A defensive in-PHP re-check: OpenRegister's filter DSL ignores a key
	 * it does not recognise, and an ignored filter returns rows nobody asked
	 * for while looking exactly like a correct result.
	 *
	 * @param array<string, mixed> $row The candidate row.
	 *
	 * @return bool True when the row is the one asked for.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-pending-subscription-never-receives-a-blast
	 */
	public function matches(array $row): bool {
		if ($row === []) {
			return false;
		}

		if ((string)($row['contactId'] ?? '') !== $this->contactId) {
			return false;
		}

		if (strtolower((string)($row['channel'] ?? '')) !== $this->channel) {
			return false;
		}

		return (string)($row['listId'] ?? '') === $this->listScope();
	}//end matches()

	/**
	 * The list id a matching row carries: an empty string for the channel-wide record.
	 *
	 * @return string The list scope.
	 */
	private function listScope(): string {
		if ($this->listId !== null) {
			return $this->listId;
		}

		return '';
	}//end listScope()
}//end class
