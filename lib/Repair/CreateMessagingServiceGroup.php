<?php

/**
 * Pipelinq CreateMessagingServiceGroup repair step.
 *
 * Creates the `pipelinq-messaging-service` group the message and conversation
 * schemas grant create and update to, and puts the configured messaging
 * service account in it. It never picks an account: an admin does that in the
 * messaging settings, and until then the SMS and WhatsApp webhooks answer 503
 * and the admin overview says so.
 *
 * @category Repair
 * @package  OCA\Pipelinq\Repair
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

namespace OCA\Pipelinq\Repair;

use OCA\Pipelinq\Service\MessagingServiceAccount;

/**
 * Creates the messaging service group and enrols the configured account.
 *
 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-005-consent-gating-and-recording
 */
class CreateMessagingServiceGroup extends CreateServiceGroup {
	/**
	 * Constructor.
	 *
	 * @param MessagingServiceAccount $serviceAccount Creates the group and enrols the account.
	 */
	public function __construct(MessagingServiceAccount $serviceAccount) {
		parent::__construct(serviceAccount: $serviceAccount);
	}//end __construct()

	/**
	 * The repair step's name.
	 *
	 * @return string The name.
	 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-005-consent-gating-and-recording
	 */
	public function getName(): string {
		return 'Create the group the SMS and WhatsApp webhooks write with';
	}//end getName()

	/**
	 * The warning while no account is picked yet.
	 *
	 * @return string The warning.
	 */
	protected function noAccountWarning(): string {
		return 'The SMS and WhatsApp webhooks have no service account yet, so they save nothing. Pick one in the Pipelinq messaging settings.';
	}//end noAccountWarning()
}//end class
