<?php

/**
 * Pipelinq MessagingServiceAccount.
 *
 * The Nextcloud account the SMS and WhatsApp provider webhooks write as. A
 * provider callback has no Nextcloud session, so OpenRegister would see
 * Anonymous. After the callback's signature checked out, the webhook acts as
 * one account an admin picks, with OpenRegister's checks on; the message and
 * conversation schemas grant that account's group. Lookups of configuration,
 * providers and contacts stay reads without RBAC; nothing is written that way.
 * A missing, unknown, disabled or ungrouped account refuses with 503 before
 * anything is written, so the provider retries.
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
 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-005-consent-gating-and-recording
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;

/**
 * Resolves the messaging service account and runs webhook writes as it.
 *
 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-005-consent-gating-and-recording
 */
class MessagingServiceAccount extends ServiceAccount {
	/**
	 * App-config key holding the uid of the messaging service account.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'messaging_service_account';

	/**
	 * The group the message and conversation schemas grant create and update to.
	 *
	 * @var string
	 */
	public const GROUP = 'pipelinq-messaging-service';

	/**
	 * The app-config key holding the uid.
	 *
	 * @return string The key.
	 */
	protected function configKey(): string {
		return self::CONFIG_KEY;
	}//end configKey()

	/**
	 * The group the messaging schemas grant.
	 *
	 * @return string The group id.
	 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-005-consent-gating-and-recording
	 */
	public function group(): string {
		return self::GROUP;
	}//end group()

	/**
	 * Refuse every webhook write with 503.
	 *
	 * @param string $userId The configured uid.
	 * @param string $reason Why the account cannot be used.
	 *
	 * @return never
	 *
	 * @throws ServiceAccountUnavailableException Always.
	 */
	protected function refuse(string $userId, string $reason): never {
		$this->logger->error(
			'Pipelinq messaging: no usable messaging service account, the SMS and WhatsApp webhooks write nothing',
			['app' => Application::APP_ID, 'userId' => $userId, 'reason' => $reason]
		);
		throw new ServiceAccountUnavailableException('No usable messaging service account.');
	}//end refuse()
}//end class
