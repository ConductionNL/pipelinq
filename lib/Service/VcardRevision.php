<?php

/**
 * Pipelinq VcardRevision.
 *
 * Reads a vCard REV value as an ISO 8601 moment.
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use Exception;

/**
 * A vCard REV value (`20261027T091200Z`, or any ISO 8601 moment) as an ISO 8601 string.
 *
 * @spec openspec/specs/contact-person-page-as-board/spec.md#requirement-the-contact-page-shows-its-address-book-card
 */
class VcardRevision {
	/**
	 * The REV value as an ISO 8601 moment in UTC unless it names its own offset.
	 *
	 * @param string $rev The REV value.
	 *
	 * @return string|null The moment, or null when there is none.
	 *
	 * @spec openspec/specs/contact-person-page-as-board/spec.md#requirement-the-contact-page-shows-its-address-book-card
	 */
	public function toIso(string $rev): ?string {
		$rev = trim($rev);
		if ($rev === '') {
			return null;
		}

		$parts = [];
		if (preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z$/', $rev, $parts) === 1) {
			$rev = sprintf('%s-%s-%sT%s:%s:%s+00:00', $parts[1], $parts[2], $parts[3], $parts[4], $parts[5], $parts[6]);
		}

		try {
			$moment = new DateTimeImmutable(datetime: $rev);
		} catch (Exception) {
			return null;
		}

		return $moment->format(format: 'Y-m-d\TH:i:sP');
	}//end toIso()
}//end class
