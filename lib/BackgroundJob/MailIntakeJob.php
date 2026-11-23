<?php

/**
 * Pipelinq MailIntakeJob.
 *
 * Five-minute background job that takes the new mail of every ticket mailbox
 * into the Queue (requests-inbound-to-queue D4). The work is
 * {@see \OCA\Pipelinq\Service\MailIntake\MailIntakeService::run()}; this job
 * only schedules it and logs what it did. With no ticket mailbox named it
 * reads nothing.
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
 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-names-the-ticket-mailboxes-req-itq-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Service\MailIntake\MailIntakeService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs the ticket mailbox intake every five minutes.
 *
 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-names-the-ticket-mailboxes-req-itq-003
 */
class MailIntakeJob extends TimedJob {

	/**
	 * Run interval in seconds (5 minutes).
	 *
	 * @var int
	 */
	private const INTERVAL = 300;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory      $time   Time factory.
	 * @param MailIntakeService $intake The ticket mailbox intake.
	 * @param LoggerInterface   $logger Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly MailIntakeService $intake,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL);
	}//end __construct()

	/**
	 * Take in the new mail of every ticket mailbox; a failure is logged, never thrown.
	 *
	 * @param mixed $argument Unused job argument (TimedJob contract).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is required by TimedJob::run().
	 *
	 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-names-the-ticket-mailboxes-req-itq-003
	 */
	protected function run(mixed $argument): void {
		try {
			$totals = $this->intake->run();
		} catch (Throwable $e) {
			$this->logger->error('Pipelinq: the ticket mailbox intake failed', ['exception' => $e->getMessage()]);
			return;
		}

		if ($totals['mailboxes'] > 0) {
			$this->logger->info('Pipelinq: ticket mailbox intake complete', $totals);
		}
	}//end run()
}//end class
