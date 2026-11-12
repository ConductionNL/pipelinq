<?php

/**
 * Pipelinq ContactMomentCases
 *
 * The ordered cases a contact moment is filed on, and which one is primary.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * The case set and its primary, as a filing act leaves them.
 *
 * @spec openspec/specs/contactmomenten/spec.md#requirement-a-contact-moment-cannot-be-left-with-no-case-req-cms-006
 */
final class ContactMomentCases {

	/**
	 * Constructor.
	 *
	 * @param array<int, string> $set     The ordered case references.
	 * @param string             $primary The primary case reference.
	 */
	public function __construct(
		public readonly array $set,
		public readonly string $primary,
	) {
	}//end __construct()
}//end class
