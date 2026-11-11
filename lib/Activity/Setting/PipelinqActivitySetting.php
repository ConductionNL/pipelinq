<?php

/**
 * Pipelinq PipelinqActivitySetting.
 *
 * The group and the stream/mail defaults the Pipelinq activity settings share.
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

use OCP\Activity\ActivitySettings;
use OCP\IL10N;

/**
 * A Pipelinq activity setting: in the Pipelinq group, shown in the stream by
 * default, changeable for stream and mail, and mailed only when a setting
 * says so.
 *
 * @spec openspec/specs/notifications-activity/spec.md#requirement-per-category-notification-preferences-v1
 */
abstract class PipelinqActivitySetting extends ActivitySettings {
	/**
	 * Get the group identifier for this setting.
	 *
	 * @return string The group identifier.
	 * @spec openspec/specs/notifications-activity/spec.md#requirement-per-category-notification-preferences-v1
	 */
	public function getGroupIdentifier(): string {
		return 'pipelinq';
	}//end getGroupIdentifier()

	/**
	 * Get the group name for this setting.
	 *
	 * @return string The group name.
	 * @spec openspec/specs/notifications-activity/spec.md#requirement-per-category-notification-preferences-v1
	 */
	public function getGroupName(): string {
		return $this->l10n()->t('Pipelinq');
	}//end getGroupName()

	/**
	 * Whether the user can change the stream setting.
	 *
	 * @return bool True if changeable.
	 * @spec openspec/specs/notifications-activity/spec.md#requirement-per-category-notification-preferences-v1
	 */
	public function canChangeStream(): bool {
		return true;
	}//end canChangeStream()

	/**
	 * Whether the stream is enabled by default.
	 *
	 * @return bool True if enabled by default.
	 * @spec openspec/specs/notifications-activity/spec.md#requirement-per-category-notification-preferences-v1
	 */
	public function isDefaultEnabledStream(): bool {
		return true;
	}//end isDefaultEnabledStream()

	/**
	 * Whether the user can change the mail setting.
	 *
	 * @return bool True if changeable.
	 * @spec openspec/specs/notifications-activity/spec.md#requirement-per-category-notification-preferences-v1
	 */
	public function canChangeMail(): bool {
		return true;
	}//end canChangeMail()

	/**
	 * Whether mail is enabled by default.
	 *
	 * @return bool True if enabled by default.
	 * @spec openspec/specs/notifications-activity/spec.md#requirement-per-category-notification-preferences-v1
	 */
	public function isDefaultEnabledMail(): bool {
		return false;
	}//end isDefaultEnabledMail()

	/**
	 * The localization service the setting translates its names with.
	 *
	 * @return IL10N The localization service.
	 */
	abstract protected function l10n(): IL10N;
}//end class
