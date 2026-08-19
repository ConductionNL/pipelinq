<?php

/**
 * Pipelinq PortalExportService.
 *
 * AVG Art. 15 data export for portal accounts. Assembles the machine-readable
 * record the portal holds about a user — their contact profile, their full
 * audit trail, and the list of documents they can access — into a JSON bundle,
 * mints a long-lived (30-day) signed download token for it, and emails the link.
 * The export only ever contains the requesting account's own data, gathered
 * through the same per-customer-scoped services the live portal uses (REQ-010).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Portal
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/customer-portal/specs.md#REQ-010
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;

/**
 * Builds AVG data exports for portal accounts.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Aggregates the data sources an
 *  export legitimately spans (audit, invoices, profile, signing, mail).
 */
class PortalExportService
{
    /**
     * Export download-link validity in minutes (30 days).
     *
     * @var int
     */
    private const TTL_MINUTES = (30 * 24 * 60);

    /**
     * Constructor.
     *
     * @param PortalProfileService   $profile  The profile service.
     * @param PortalAuditService     $audit    The audit service.
     * @param PortalInvoiceService   $invoices The invoice facade.
     * @param DocumentSigningService $signing  The document signing service.
     * @param PortalMailService      $mail     The mail service.
     * @param ITimeFactory           $time     The time factory.
     * @param IL10N                  $l10n     The localisation service.
     */
    public function __construct(
        private PortalProfileService $profile,
        private PortalAuditService $audit,
        private PortalInvoiceService $invoices,
        private DocumentSigningService $signing,
        private PortalMailService $mail,
        private ITimeFactory $time,
        private IL10N $l10n,
    ) {
    }//end __construct()

    /**
     * Assemble the export payload for an account (own data only).
     *
     * @param array<string, mixed> $account The authenticated account.
     *
     * @return array<string, mixed> The export payload.
     */
    public function buildExport(array $account): array
    {
        $accountId = (string) ($account['@self']['id'] ?? $account['id'] ?? $account['uuid'] ?? '');

        $invoicePage = $this->invoices->getForAccount($account, 1, 100);

        return [
            'exportedAt'  => $this->time->getDateTime()->format(DATE_ATOM),
            'account'     => $this->profile->present(account: $account),
            'auditEvents' => array_map(
                static fn (array $event): array => [
                    'eventType'  => ($event['eventType'] ?? null),
                    'outcome'    => ($event['outcome'] ?? null),
                    'occurredAt' => ($event['occurredAt'] ?? null),
                ],
                $this->audit->getForAccount($accountId)
            ),
            'documents'   => array_map(
                static fn (array $invoice): array => [
                    'type'   => 'invoice',
                    'number' => ($invoice['invoiceNumber'] ?? null),
                    'date'   => ($invoice['date'] ?? null),
                    'amount' => ($invoice['amount'] ?? null),
                ],
                $invoicePage['items']
            ),
        ];
    }//end buildExport()

    /**
     * Request an export: build it, mint a 30-day signed link, email it.
     *
     * @param array<string, mixed> $account  The authenticated account.
     * @param string               $tenantId The tenant id.
     *
     * @return array{downloadUrl: string, expiresAt: int} The download descriptor.
     */
    public function requestExport(array $account, string $tenantId): array
    {
        $accountId = (string) ($account['@self']['id'] ?? $account['id'] ?? $account['uuid'] ?? '');
        $signed    = $this->signing->generateUrl(
            objectId: $accountId,
            objectType: 'data-export',
            accountId: $accountId,
            ttlMinutes: self::TTL_MINUTES
        );

        $this->mail->send(
            (string) ($account['email'] ?? ''),
            $this->l10n->t('Your data export is ready'),
            $this->l10n->t('Your data export is ready to download. The link is valid for 30 days.')
        );

        $this->audit->log(accountId: $accountId, tenantId: $tenantId, eventType: 'data-export-requested', outcome: 'success');

        return ['downloadUrl' => $signed['path'], 'expiresAt' => $signed['expiresAt']];
    }//end requestExport()
}//end class
