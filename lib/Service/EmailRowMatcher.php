<?php

/**
 * Pipelinq EmailRowMatcher.
 *
 * Picks the OpenRegister rows whose email matches an address or a domain.
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
 * @spec openspec/specs/email-calendar-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Util\AnyPayloadToArray;
use OCA\Pipelinq\Util\RowIdOf;

/**
 * Picks contact and client ids out of OpenRegister rows by their `email` field.
 *
 * @spec openspec/specs/email-calendar-sync/spec.md
 */
final class EmailRowMatcher {
	use AnyPayloadToArray;
	use RowIdOf;

	/**
	 * The ids of the rows whose email equals the address.
	 *
	 * @param mixed  $rows  The rows the lookup returned.
	 * @param string $email Lowercased email to match.
	 *
	 * @return array<int,string> Matched object ids.
	 *
	 * @spec openspec/specs/email-calendar-sync/spec.md
	 */
	public function idsWithEmail(mixed $rows, string $email): array {
		if (is_array($rows) === false) {
			return [];
		}

		$ids = [];
		foreach ($rows as $row) {
			$id = $this->idWithEmail(row: $row, email: $email);
			if ($id !== '') {
				$ids[] = $id;
			}
		}

		return $ids;
	}//end idsWithEmail()
	/**
	 * The id of a row whose email equals the address.
	 *
	 * @param mixed  $row   One row.
	 * @param string $email Lowercased email to match.
	 *
	 * @return string The row id, '' when the row does not match or has no id.
	 */
	private function idWithEmail(mixed $row, string $email): string {
		$data = $this->toArray(object: $row);
		if ($data === null || strtolower((string)($data['email'] ?? '')) !== $email) {
			return '';
		}

		return $this->idOf(object: $data);
	}//end idWithEmail()
	/**
	 * The first organization row whose email ends in the suffix and that has an id.
	 *
	 * @param mixed  $rows   The organization rows the lookup returned.
	 * @param string $suffix The `@domain` suffix.
	 *
	 * @return array{entityType:string,entityId:string}|null The match, or null.
	 *
	 * @spec openspec/specs/email-calendar-sync/spec.md
	 */
	public function firstOrganizationWithSuffix(mixed $rows, string $suffix): ?array {
		if (is_array($rows) === false) {
			return null;
		}

		foreach ($rows as $row) {
			$id = $this->idWithEmailSuffix(row: $row, suffix: $suffix);
			if ($id !== '') {
				return ['entityType' => 'client', 'entityId' => $id];
			}
		}

		return null;
	}//end firstOrganizationWithSuffix()
	/**
	 * The id of a row whose email ends in the suffix.
	 *
	 * @param mixed  $row    One organization row.
	 * @param string $suffix The `@domain` suffix.
	 *
	 * @return string The row id, '' when the row does not match or has no id.
	 */
	private function idWithEmailSuffix(mixed $row, string $suffix): string {
		$data = $this->toArray(object: $row);
		if ($data === null) {
			return '';
		}

		$email = strtolower((string)($data['email'] ?? ''));
		if ($email === '' || str_ends_with($email, $suffix) === false) {
			return '';
		}

		return $this->idOf(object: $data);
	}//end idWithEmailSuffix()
}//end class
