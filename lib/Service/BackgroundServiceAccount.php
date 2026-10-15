<?php

/**
 * Pipelinq BackgroundServiceAccount.
 *
 * The Nextcloud account pipelinq's background work writes as. A background job
 * and a listener for another app's event have no Nextcloud session, so
 * OpenRegister sees Anonymous. They act as one account an admin picks, with
 * OpenRegister's checks on; the schemas they write grant that account's
 * group. Today that is one schema: `mirroredCall`, the call mirror.
 *
 * A missing, unknown, disabled or ungrouped account refuses before anything
 * runs: the work logs a warning and writes nothing. Nothing falls back to a
 * write without access checks.
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
 * @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-the-mirror-writes-as-the-background-service-account
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;

/**
 * Resolves the background service account and runs background writes as it.
 *
 * @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-the-mirror-writes-as-the-background-service-account
 */
class BackgroundServiceAccount extends ServiceAccount {
	/**
	 * App-config key holding the uid of the background service account.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'background_service_account';

	/**
	 * The group the schemas a background job writes grant.
	 *
	 * @var string
	 */
	public const GROUP = 'pipelinq-background-service';

	/**
	 * The app-config key holding the uid.
	 *
	 * @return string The key.
	 * @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-the-mirror-writes-as-the-background-service-account
	 */
	protected function configKey(): string {
		return self::CONFIG_KEY;
	}//end configKey()

	/**
	 * The group the background schemas grant.
	 *
	 * @return string The group id.
	 * @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-the-mirror-writes-as-the-background-service-account
	 */
	public function group(): string {
		return self::GROUP;
	}//end group()

	/**
	 * Refuse: say so in the log and throw, so the work skips.
	 *
	 * @param string $userId The configured uid.
	 * @param string $reason Why the account cannot be used.
	 *
	 * @return never
	 *
	 * @throws ServiceAccountUnavailableException Always.
	 * @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-the-mirror-writes-as-the-background-service-account
	 */
	protected function refuse(string $userId, string $reason): never {
		$this->logger->warning(
			'Pipelinq: no usable background service account, so calls are not mirrored and old mirrored calls are not removed',
			['app' => Application::APP_ID, 'userId' => $userId, 'reason' => $reason, 'group' => self::GROUP]
		);
		throw new ServiceAccountUnavailableException('No usable background service account.');
	}//end refuse()
}//end class
