<?php

/**
 * Pipelinq EmailMatchJob.
 *
 * Timed job (every 5 minutes) that resolves Nextcloud Mail message addresses to
 * Pipelinq CRM entities and links each matched message to its entity THROUGH the
 * OpenRegister `email` integration leaf. Pipelinq owns the CRM matching rule and
 * the per-user settings only; the leaf owns the link record (ADR-022). Each user
 * is processed under their own scope (only their configured account and their own
 * messages), and a failure for one user never aborts the run for the others.
 *
 * @category BackgroundJob
 * @package  OCA\Pipelinq\BackgroundJob
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Service\EmailLeafLinkAdapter;
use OCA\Pipelinq\Service\EmailMatchService;
use OCA\Pipelinq\Service\MailMessageProvider;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Matches NC Mail messages to CRM entities and links them via the email leaf.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/email-calendar-sync/tasks.md#task-2.1
 */
class EmailMatchJob extends TimedJob
{

    /**
     * Run interval in seconds (5 minutes).
     *
     * @var int
     */
    private const INTERVAL = 300;

    /**
     * Constructor.
     *
     * @param ITimeFactory         $time         The time factory.
     * @param IUserManager         $userManager  The user manager (per-user iteration).
     * @param EmailMatchService    $matchService The CRM matching rule + settings.
     * @param MailMessageProvider  $provider     Candidate-message provider.
     * @param EmailLeafLinkAdapter $linkAdapter  The OR email-leaf link adapter.
     * @param LoggerInterface      $logger       The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private IUserManager $userManager,
        private EmailMatchService $matchService,
        private MailMessageProvider $provider,
        private EmailLeafLinkAdapter $linkAdapter,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        $this->setInterval(seconds: self::INTERVAL);
        $this->setTimeSensitivity(sensitivity: self::TIME_INSENSITIVE);
    }//end __construct()

    /**
     * Execute the matching run for every sync-enabled user.
     *
     * @param mixed $argument The job argument (unused).
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function run($argument): void
    {
        if ($this->linkAdapter->isAvailable() === false) {
            $this->logger->info('EmailMatchJob: email leaf unavailable, skipping run');
            return;
        }

        $this->userManager->callForSeenUsers(
            function (IUser $user): ?bool {
                $this->runForUser(userId: $user->getUID());
                return null;
            }
        );
    }//end run()

    /**
     * Run the matching pipeline for a single user, isolating failures.
     *
     * @param string $userId The user id.
     *
     * @return void
     *
     * @spec openspec/changes/email-calendar-sync/tasks.md#task-2.1
     */
    public function runForUser(string $userId): void
    {
        if ($this->matchService->isSyncEnabled(userId: $userId) === false) {
            return;
        }

        $accountId = $this->matchService->getSyncAccount(userId: $userId);
        if ($accountId === null) {
            return;
        }

        try {
            $linked = $this->processUser(userId: $userId, accountId: $accountId);
            $this->matchService->recordRun(userId: $userId, linked: $linked, error: null);
        } catch (Throwable $e) {
            // Static message only — never leak internals into stored status (ADR-005).
            $this->matchService->recordRun(userId: $userId, linked: 0, error: 'Sync failed');
            $this->logger->error(
                'EmailMatchJob: user run failed',
                ['userId' => $userId, 'exception' => $e->getMessage()]
            );
        }//end try
    }//end runForUser()

    /**
     * Resolve and link every candidate message for one user/account.
     *
     * @param string $userId    The user id.
     * @param int    $accountId The configured mail account id.
     *
     * @return int The number of NEW links created this run.
     */
    private function processUser(string $userId, int $accountId): int
    {
        $linked   = 0;
        $messages = $this->provider->getCandidateMessages(userId: $userId, accountId: $accountId);

        foreach ($messages as $message) {
            // Per-user scoping: only ever link the user's own configured account.
            if ($message['accountId'] !== $accountId) {
                continue;
            }

            $entities = $this->resolveEntities(userId: $userId, addresses: $message['addresses']);
            foreach ($entities as $entity) {
                $isNew = $this->linkAdapter->linkMessage(
                    objectUuid: $entity['uuid'],
                    mailAccountId: $accountId,
                    mailMessageId: $message['messageId'],
                    messageUid: $message['messageUid']
                );
                if ($isNew === true) {
                    $linked++;
                }
            }
        }//end foreach

        return $linked;
    }//end processUser()

    /**
     * Resolve all entity references for a message's addresses (deduped).
     *
     * Excluded addresses are skipped; public-domain-only senders resolve to no
     * organization. The same entity matched by multiple addresses is linked once.
     *
     * @param string             $userId    The user id (for the exclude list).
     * @param array<int, string> $addresses The message's sender/recipient addresses.
     *
     * @return array<int, array{type: string, uuid: string}> Deduped entity references.
     */
    private function resolveEntities(string $userId, array $addresses): array
    {
        $byUuid = [];
        foreach ($addresses as $address) {
            if ($address === '') {
                continue;
            }

            if ($this->matchService->isExcluded(userId: $userId, address: $address) === true) {
                continue;
            }

            foreach ($this->matchService->resolveAddress(address: $address) as $entity) {
                $byUuid[$entity['uuid']] = $entity;
            }
        }

        return array_values($byUuid);
    }//end resolveEntities()
}//end class
