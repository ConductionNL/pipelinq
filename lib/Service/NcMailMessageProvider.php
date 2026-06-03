<?php

/**
 * Pipelinq NcMailMessageProvider.
 *
 * Live {@see MailMessageProvider} backed by the Nextcloud Mail app. NC Mail
 * exposes no public OCP API for enumerating messages; the supported path is to
 * read the app's own database tables (as the OpenRegister email leaf does) under
 * a user context. That read is only meaningful against a running instance with a
 * configured mail account, so this provider returns no candidates until that
 * environment-specific wiring is supplied. The matching pipeline (resolve →
 * link) is fully implemented and exercised in unit tests via a stub provider;
 * the live enumeration is documented as deferred (needs a live mail account).
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

use OCP\App\IAppManager;

/**
 * NC Mail-backed candidate-message provider.
 *
 * @spec openspec/changes/email-calendar-sync/tasks.md#task-2.1
 */
class NcMailMessageProvider implements MailMessageProvider
{
    /**
     * Constructor.
     *
     * @param IAppManager $appManager The app manager (NC Mail availability probe).
     */
    public function __construct(
        private IAppManager $appManager,
    ) {
    }//end __construct()

    /**
     * {@inheritDoc}
     *
     * Returns an empty candidate list when NC Mail is absent. When present, live
     * enumeration of mail messages is environment-specific (requires a configured
     * account + user context) and is supplied by deployment wiring; absent that,
     * no candidates are produced so the job is a safe no-op rather than failing.
     *
     * @param string $userId    The user id.
     * @param int    $accountId The configured mail account id.
     *
     * @return array<int, array{accountId: int, messageId: int, messageUid: string, addresses: array<int, string>}>
     *
     * @spec openspec/changes/email-calendar-sync/tasks.md#task-2.1
     */
    public function getCandidateMessages(string $userId, int $accountId): array
    {
        if ($this->appManager->isEnabledForUser('mail') === false) {
            return [];
        }

        // Live enumeration requires a configured NC Mail account and user
        // context; see class docblock. No candidates until that wiring exists.
        return [];
    }//end getCandidateMessages()
}//end class
