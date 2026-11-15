<?php

/**
 * Pipelinq PreferenceChoice.
 *
 * One preference-centre save: the contact the signed token names and the
 * lists they ticked (as a lookup by list id).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/marketing-lists/spec.md#requirement-the-preference-centre-shows-and-saves-a-subscribers-lists
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

/**
 * A contact's ticked lists; built by PreferenceCentreService.
 *
 * @spec openspec/specs/marketing-lists/spec.md#requirement-the-preference-centre-shows-and-saves-a-subscribers-lists
 */
final class PreferenceChoice {
	/**
	 * Constructor.
	 *
	 * @param string               $contactId The contact the token names.
	 * @param array<string, mixed> $selected  The ticked list ids, as keys.
	 */
	public function __construct(
		public readonly string $contactId,
		public readonly array $selected,
	) {
	}//end __construct()
}//end class
