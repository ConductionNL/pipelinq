<?php

/**
 * Pipelinq MessagingServiceAccountCheck.
 *
 * Tells the admin, in the administration overview, when the SMS and WhatsApp
 * webhooks have no usable service account. Until one is picked every provider
 * callback is answered with 503 and nothing is written: no inbound message,
 * no STOP, no contact moment.
 *
 * @category SetupCheck
 * @package  OCA\Pipelinq\SetupCheck
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

namespace OCA\Pipelinq\SetupCheck;

use OCA\Pipelinq\Service\MessagingServiceAccount;
use OCP\IL10N;

/**
 * Warns while the messaging service account is unset, unknown, disabled or outside its group.
 *
 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-005-consent-gating-and-recording
 */
class MessagingServiceAccountCheck extends ServiceAccountCheck {
	/**
	 * Constructor.
	 *
	 * @param MessagingServiceAccount $serviceAccount The account to check.
	 * @param IL10N                   $l10n           The localisation service.
	 */
	public function __construct(MessagingServiceAccount $serviceAccount, IL10N $l10n) {
		parent::__construct(serviceAccount: $serviceAccount, l10n: $l10n);
	}//end __construct()

	/**
	 * The name in the administration overview.
	 *
	 * @return string The name.
	 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-005-consent-gating-and-recording
	 */
	public function getName(): string {
		return $this->l10n->t('Pipelinq SMS and WhatsApp service account');
	}//end getName()

	/**
	 * What a usable account does.
	 *
	 * @param string $userId The account.
	 *
	 * @return string The sentence.
	 */
	protected function usableText(string $userId): string {
		return $this->l10n->t('Incoming SMS and WhatsApp messages are saved as %s.', [$userId]);
	}//end usableText()

	/**
	 * What stops working until an admin picks an account.
	 *
	 * @return string The sentence.
	 */
	protected function consequenceText(): string {
		return $this->l10n->t(
			'Until you choose one in the Pipelinq settings, incoming SMS and WhatsApp messages are not saved, and a STOP reply is not recorded.'
		);
	}//end consequenceText()
}//end class
