<?php

/**
 * Pipelinq ClientManagementIntegration.
 *
 * Bridge between the client-management contact lifecycle and the
 * messaging consent audit log. Hooks into Contact deletion to
 * propagate GDPR Art. 17 erasure to the messagingConsentRecord rows
 * we own.
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
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#4.4
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * ClientManagementIntegration — propagate Contact deletion to consent.
 *
 * Public entry points:
 * - onContactDeleted(contactId) — listener hook that cascades
 *   `messagingConsentRecord` deletion via ConsentService.
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#4.4
 */
class ClientManagementIntegration {
	/**
	 * Constructor.
	 *
	 * @param ConsentService $consentService Consent audit log.
	 * @param LoggerInterface $logger Logger.
	 * @param IntegriqConsentClient $integriq Tells integriq to drop the contact link and keep the opt-out.
	 *
	 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#4.4
	 */
	public function __construct(
		private ConsentService $consentService,
		private LoggerInterface $logger,
		private IntegriqConsentClient $integriq,
	) {
	}//end __construct()

	/**
	 * Cascade contact deletion to the consent log.
	 *
	 * @param string $contactId Contact UUID.
	 *
	 * @return int Number of consent rows deleted.
	 *
	 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#4.4
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-contact-erasure-keeps-the-opt-out-in-integriq-req-cii-005
	 */
	public function onContactDeleted(string $contactId): int {
		if ($contactId === '') {
			return 0;
		}

		// integriq keeps the opt-out under the address and drops the contact
		// link and the evidence (Ruben, 2026-10-05, decision 5). pipelinq never
		// asks integriq to delete an opt-out.
		$erased = $this->integriq->eraseContact(contactRef: $contactId);
		if ($erased['recorded'] === false) {
			$this->logger->warning(
				'ClientManagementIntegration.onContactDeleted: integriq did not clear the contact link',
				['contactId' => $contactId, 'code' => $erased['code']]
			);
		}

		try {
			return $this->consentService->deleteForContact(contactId: $contactId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ClientManagementIntegration.onContactDeleted: failed',
				['contactId' => $contactId, 'exception' => $e->getMessage()]
			);
			return 0;
		}
	}//end onContactDeleted()
}//end class
