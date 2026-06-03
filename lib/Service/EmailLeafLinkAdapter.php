<?php

/**
 * Pipelinq EmailLeafLinkAdapter.
 *
 * Thin seam between the pipelinq matching job and the OpenRegister `email`
 * integration leaf. Pipelinq owns the CRM matching rule; the leaf owns the link
 * record (`openregister_email_links`). This interface lets the job depend on the
 * leaf's link capability without coupling to OR's concrete service, and keeps
 * the unit tests free of the running NC Mail app.
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
 * @spec openspec/changes/email-calendar-sync/specs/email-calendar-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * Capability the matching job needs from the OpenRegister email leaf.
 */
interface EmailLeafLinkAdapter
{
    /**
     * Whether the email leaf (and its NC Mail backing) is available.
     *
     * @return bool True when links can be created.
     *
     * @spec openspec/changes/email-calendar-sync/tasks.md#task-2.1
     */
    public function isAvailable(): bool;

    /**
     * Link a Nextcloud Mail message to an OpenRegister object via the leaf.
     *
     * Implementations MUST be idempotent: linking the same message to the same
     * object twice creates at most one link.
     *
     * @param string $objectUuid    The matched CRM object uuid.
     * @param int    $mailAccountId The NC Mail account id.
     * @param int    $mailMessageId The NC Mail message id.
     * @param string $messageUid    The IMAP message UID (may be empty).
     *
     * @return bool True when a NEW link was created, false when it already existed.
     *
     * @spec openspec/changes/email-calendar-sync/tasks.md#task-2.1
     */
    public function linkMessage(string $objectUuid, int $mailAccountId, int $mailMessageId, string $messageUid): bool;
}//end interface
