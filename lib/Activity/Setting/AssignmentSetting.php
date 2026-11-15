<?php

/**
 * Pipelinq AssignmentSetting.
 *
 * Activity setting for lead and request assignment notifications.
 *
 * @category Activity
 * @package  OCA\Pipelinq\Activity\Setting
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Activity\Setting;

use OCP\IL10N;

/**
 * Activity setting for assignment events.
 *
 * @spec openspec/specs/notifications-activity/spec.md#requirement-per-category-notification-preferences-v1
 */
class AssignmentSetting extends PipelinqActivitySetting {
	/**
	 * Constructor.
	 *
	 * @param IL10N $l The localization service.
	 */
	public function __construct(
		private IL10N $l,
	) {
	}//end __construct()

	/**
	 * Get the identifier for this setting.
	 *
	 * @return string The setting identifier.
	 * @spec openspec/specs/notifications-activity/spec.md#requirement-per-category-notification-preferences-v1
	 */
	public function getIdentifier(): string {
		return 'pipelinq_assignment';
	}//end getIdentifier()

	/**
	 * Get the name for this setting.
	 *
	 * @return string The setting name.
	 * @spec openspec/specs/notifications-activity/spec.md#requirement-per-category-notification-preferences-v1
	 */
	public function getName(): string {
		return $this->l->t('Lead & request assignments');
	}//end getName()

	/**
	 * Get the priority for this setting.
	 *
	 * @return int The priority.
	 * @spec openspec/specs/notifications-activity/spec.md#requirement-per-category-notification-preferences-v1
	 */
	public function getPriority(): int {
		return 50;
	}//end getPriority()

	/**
	 * The localization service the setting translates its names with.
	 *
	 * @return IL10N The localization service.
	 */
	protected function l10n(): IL10N {
		return $this->l;
	}//end l10n()
}//end class
