<?php

/**
 * Pipelinq MailMessageProvider.
 *
 * Seam that yields the Nextcloud Mail messages the matching job should consider
 * for a given user and account. The live implementation reads from the NC Mail
 * app (which exposes no public OCP message API and requires the app to be
 * installed plus a user context), so it is isolated behind this interface to keep
 * the matching job unit-testable without a running mail account.
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
 * Yields candidate Mail messages for the matching job.
 */
interface MailMessageProvider
{
    /**
     * Fetch candidate messages for a user/account since the last run.
     *
     * Each returned message is an array with keys:
     *  - `accountId`  (int)    NC Mail account id
     *  - `messageId`  (int)    NC Mail message id
     *  - `messageUid` (string) IMAP UID (may be empty)
     *  - `addresses`  (array<int,string>) sender + recipient addresses
     *
     * @param string $userId    The user id.
     * @param int    $accountId The configured mail account id.
     *
     * @return array<int, array{accountId: int, messageId: int, messageUid: string, addresses: array<int, string>}>
     *
     * @spec openspec/changes/email-calendar-sync/tasks.md#task-2.1
     */
    public function getCandidateMessages(string $userId, int $accountId): array;
}//end interface
