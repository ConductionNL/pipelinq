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
class ClientManagementIntegration
{
    /**
     * Constructor.
     *
     * @param ConsentService  $consentService Consent audit log.
     * @param LoggerInterface $logger         Logger.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#4.4
     */
    public function __construct(
        private ConsentService $consentService,
        private LoggerInterface $logger,
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
     */
    public function onContactDeleted(string $contactId): int
    {
        if ($contactId === '') {
            return 0;
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
