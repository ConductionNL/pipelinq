<?php

/**
 * Pipelinq PointsExpiryBatchJob.
 *
 * Daily batch job that expires points per programme expiry policy. Currently
 * supports the "inactivityMonths" policy: any account whose lastActivityDate is
 * older than the programme's window has its currentBalance moved to an
 * immutable PointsLedgerEntry of type "expiry". Accounts approaching expiry
 * (within advanceNoticeDays) receive a notification.
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
 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-005
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use DateTimeImmutable;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\LoyaltyAccountService;
use OCA\Pipelinq\Service\NotificationService;
use OCA\Pipelinq\Service\PointsLedgerService;
use OCA\Pipelinq\Util\PayloadToArray;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Daily points expiry batch job (loyalty-program REQ-LOY-005).
 *
 * Idempotent: re-running on the same day produces zero net effect because
 * expiring an account zeroes out lastActivityDate's eligibility window.
 *
 * @spec exclude loyalty has no spec at all; the change that specified it was archived
 *   and nothing inherited it
 */
class PointsExpiryBatchJob extends TimedJob {
	use PayloadToArray;

	private const DEFAULT_INTERVAL = 86400;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The time factory.
	 * @param IAppConfig $appConfig The app configuration.
	 * @param ContainerInterface $container The DI container.
	 * @param LoyaltyAccountService $loyaltyService The account service.
	 * @param PointsLedgerService $ledgerService The ledger service.
	 * @param NotificationService $notificationService The notification service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private IAppConfig $appConfig,
		private ContainerInterface $container,
		private LoyaltyAccountService $loyaltyService,
		private PointsLedgerService $ledgerService,
		private NotificationService $notificationService,
		private LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(
			seconds: $this->appConfig->getValueInt(
				Application::APP_ID,
				'loyalty.expiry.poll_interval_seconds',
				self::DEFAULT_INTERVAL
			)
		);
	}//end __construct()

	/**
	 * Run the expiry batch.
	 *
	 * @param mixed $argument Cron argument (unused).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-005
	 */
	protected function run($argument): void {
		$programmes = $this->getActiveProgrammes();
		$now = new DateTimeImmutable('now UTC');

		foreach ($programmes as $programme) {
			$programmeId = $this->extractUuid(object: $programme);
			if ($programmeId === null) {
				continue;
			}

			$window = new ExpiryWindow(programme: $programme, now: $now);
			if ($window->applies === false) {
				continue;
			}

			$this->processProgramme(programmeId: $programmeId, window: $window);
		}
	}//end run()

	/**
	 * Process every account of one programme, one failing account not stopping the rest.
	 *
	 * @param string       $programmeId The programme id.
	 * @param ExpiryWindow $window      The programme's cutoffs for this run.
	 *
	 * @return void
	 */
	private function processProgramme(string $programmeId, ExpiryWindow $window): void {
		$accounts = $this->loyaltyService->listAccountsForProgramme(
			programmeId: $programmeId,
			limit: 10000
		);

		foreach ($accounts as $account) {
			try {
				$this->processAccount(account: $account, window: $window);
			} catch (Throwable $e) {
				$this->logger->warning(
					'Pipelinq: expiry batch failed for one account; continuing',
					['accountId' => $this->extractUuid(object: $account), 'exception' => $e->getMessage()]
				);
			}
		}
	}//end processProgramme()

	/**
	 * Process a single account.
	 *
	 * @param array<string, mixed> $account The account.
	 * @param ExpiryWindow         $window  The programme's cutoffs for this run.
	 *
	 * @return void
	 */
	private function processAccount(array $account, ExpiryWindow $window): void {
		$accountId = (string)$this->extractUuid(object: $account);
		if ($accountId === '') {
			return;
		}

		$lastActivity = (string)($account['lastActivityDate'] ?? '');
		$balance = (int)($account['currentBalance'] ?? 0);
		if ($balance <= 0 || $lastActivity === '') {
			return;
		}

		if ($lastActivity < $window->cutoff->format('c')) {
			$this->expire(account: $account, window: $window);
			return;
		}

		if ($lastActivity < $window->noticeCutoff->format('c')) {
			$this->warnOfExpiry(
				account: $account,
				parameters: [
					'points' => $balance,
					'noticeDays' => $window->notice,
					'accountId' => $accountId,
				]
			);
		}
	}//end processAccount()

	/**
	 * Expire the whole balance of an inactive account.
	 *
	 * @param array<string, mixed> $account The account, already known to have an id and a balance.
	 * @param ExpiryWindow         $window  The programme's cutoffs for this run.
	 *
	 * @return void
	 */
	private function expire(array $account, ExpiryWindow $window): void {
		$accountId = (string)$this->extractUuid(object: $account);
		$balance = (int)($account['currentBalance'] ?? 0);
		$this->ledgerService->forOperation(operationId: 'expiry:'.$window->cutoff->format('Y-m-d'))->expirePoints(
			accountId: $accountId,
			amount: $balance,
			reason: 'inactivity-expiry'
		);
		$this->logger->info(
			'Pipelinq: expired points for inactive account',
			['accountId' => $accountId, 'points' => $balance]
		);
	}//end expire()

	/**
	 * Tell the customer their points are about to expire, best effort.
	 *
	 * @param array<string, mixed> $account    The account.
	 * @param array<string, mixed> $parameters The notification parameters, accountId included.
	 *
	 * @return void
	 */
	private function warnOfExpiry(array $account, array $parameters): void {
		$customerId = (string)($account['customerId'] ?? '');
		if ($customerId === '') {
			return;
		}

		try {
			$this->notificationService->sendNotification(
				userId: $customerId,
				subject: 'loyalty_points_expiring',
				parameters: $parameters,
				objectType: 'customerLoyaltyAccount',
				objectId: $parameters['accountId']
			);
		} catch (Throwable $e) {
			// Best effort.
			$this->logger->debug(
				'Pipelinq: advance-expiry notification failed',
				['accountId' => $parameters['accountId'], 'exception' => $e->getMessage()]
			);
		}
	}//end warnOfExpiry()

	/**
	 * Get active programmes.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function getActiveProgrammes(): array {
		$result = [];
		foreach ((new ActiveLoyaltyProgrammes(appConfig: $this->appConfig, container: $this->container))->rows() as $row) {
			$result[] = $this->toArray(value: $row);
		}

		return $result;
	}//end getActiveProgrammes()

	/**
	 * Extract UUID from an OR entity array.
	 *
	 * @param array<string, mixed> $object The OR entity.
	 *
	 * @return ?string
	 */
	private function extractUuid(array $object): ?string {
		$self = $object['@self'] ?? [];
		if (is_array($self) === true && isset($self['id']) === true) {
			return (string)$self['id'];
		}

		return $object['programmeId'] ?? $object['accountId'] ?? $object['uuid'] ?? $object['id'] ?? null;
	}//end extractUuid()
}//end class
