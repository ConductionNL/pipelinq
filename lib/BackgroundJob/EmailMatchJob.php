<?php

/**
 * Pipelinq EmailMatchJob.
 *
 * Five-minute background job that consumes the OpenRegister `email` leaf to
 * link new inbound Nextcloud Mail messages to CRM entities (client, contact,
 * lead, request) by address and corporate domain. Pipelinq owns the matching
 * rule; the OR `email` leaf owns the link record (ADR-022 leaf-first).
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
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/email-calendar-sync/specs/email-calendar-sync/spec.md#req-crm-email-matching
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Service\EmailMatchService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Periodic CRM email-matching job.
 *
 * Iterates every Nextcloud user; for users with sync enabled and a
 * configured account the job calls `EmailMatchService::runForUser`
 * which scans new inbound Mail messages and links matches via the OR
 * `email` leaf. Per-user errors are caught + logged so a single bad
 * inbox never stops the next user from being processed.
 *
 * @spec openspec/changes/email-calendar-sync/specs/email-calendar-sync/spec.md#req-crm-email-matching
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
     * @param ITimeFactory      $time              Time factory.
     * @param EmailMatchService $emailMatchService Matching service.
     * @param IUserManager      $userManager       User manager.
     * @param LoggerInterface   $logger            Logger.
     */
    public function __construct(
        ITimeFactory $time,
        private EmailMatchService $emailMatchService,
        private IUserManager $userManager,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        $this->setInterval(seconds: self::INTERVAL);

    }//end __construct()

    /**
     * Run the matching pass for every user.
     *
     * @param mixed $argument Unused job argument (TimedJob contract).
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is required by
     *  TimedJob::run().
     *
     * @spec openspec/changes/email-calendar-sync/specs/email-calendar-sync/spec.md#req-crm-email-matching
     */
    protected function run(mixed $argument): void
    {
        $totalLinked  = 0;
        $totalScanned = 0;
        $userErrors   = 0;

        $this->userManager->callForAllUsers(
            function (IUser $user) use (&$totalLinked, &$totalScanned, &$userErrors): void {
                $userId = $user->getUID();
                try {
                    $result        = $this->emailMatchService->runForUser(userId: $userId);
                    $totalLinked  += (int) $result['linked'];
                    $totalScanned += (int) $result['scanned'];
                } catch (Throwable $e) {
                    $userErrors++;
                    $this->emailMatchService->writeStatus(
                        userId: $userId,
                        linked: 0,
                        scanned: 0,
                        error: 'Match run failed'
                    );
                    $this->logger->warning(
                        'Pipelinq: email match job failed for user',
                        ['userId' => $userId]
                    );
                }
            }
        );

        $this->logger->info(
            'Pipelinq: email match job complete',
            [
                'linked'  => $totalLinked,
                'scanned' => $totalScanned,
                'errors'  => $userErrors,
            ]
        );

    }//end run()
}//end class
