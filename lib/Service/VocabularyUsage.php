<?php

/**
 * Pipelinq VocabularyUsage.
 *
 * Counts the records that still use a value of a managed vocabulary (a lead
 * source, a request channel), so removing a value in use can be refused.
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

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Counts the records of one schema whose field holds a value.
 *
 * @spec openspec/changes/pl-abstract-admin-sections/specs/admin-settings/spec.md#requirement-a-vocabulary-value-in-use-cannot-be-removed
 */
class VocabularyUsage {
	/**
	 * Constructor.
	 *
	 * @param IAppConfig             $appConfig     The app config holding the register and schema ids.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * How many records of the schema configured under `$schemaKey` hold
	 * `$value` in `$field`, narrowed by `$filters`.
	 *
	 * A count that cannot be made (no register or schema configured) is 0;
	 * a failing count is not swallowed, so a removal is never let through
	 * on a guess.
	 *
	 * @param string               $schemaKey The app config key of the schema id (`lead_schema`).
	 * @param string               $field     The field that holds the value.
	 * @param string               $value     The value.
	 * @param array<string, mixed> $filters   Further equality filters (`ticketType`).
	 *
	 * @return int The number of records.
	 *
	 * @spec openspec/changes/pl-abstract-admin-sections/specs/admin-settings/spec.md#requirement-a-vocabulary-value-in-use-cannot-be-removed
	 */
	public function countUses(string $schemaKey, string $field, string $value, array $filters = []): int {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema   = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
		if ($register === '' || $schema === '' || $value === '') {
			return 0;
		}

		return $this->objectService->count(
			[
				'filters' => array_merge(
					$filters,
					[
						'register' => $register,
						'schema' => $schema,
						$field => $value,
					]
				),
			]
		);
	}//end countUses()
}//end class
