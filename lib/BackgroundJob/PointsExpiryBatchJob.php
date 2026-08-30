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
 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-005
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\LoyaltyAccountService;
use OCA\Pipelinq\Service\NotificationService;
use OCA\Pipelinq\Service\PointsLedgerService;
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
 */
class PointsExpiryBatchJob extends TimedJob {
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
	 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-005
	 */
	protected function run($argument): void {
		$programmes = $this->getActiveProgrammes();
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

		foreach ($programmes as $programme) {
			$programmeId = $this->extractUuid(object: $programme);
			if ($programmeId === null) {
				continue;
			}

			$policy = $programme['expiryPolicy'] ?? [];
			if (is_array($policy) === false) {
				continue;
			}

			$type = (string)($policy['type'] ?? 'none');
			$months = (int)($policy['value'] ?? 0);
			$notice = (int)($policy['advanceNoticeDays'] ?? 30);
			if ($type !== 'inactivityMonths' || $months <= 0) {
				continue;
			}

			$cutoff = $now->modify("-{$months} months");
			$noticeCutoff = $now->modify('-' . max(0, $months - (int)ceil($notice / 30)) . ' months');

			$accounts = $this->loyaltyService->listAccountsForProgramme(
				programmeId: $programmeId,
				limit: 10000
			);

			foreach ($accounts as $account) {
				try {
					$this->processAccount(
						account: $account,
						cutoff: $cutoff,
						noticeCutoff: $noticeCutoff,
						notice: $notice
					);
				} catch (Throwable $e) {
					$this->logger->warning(
						'Pipelinq: expiry batch failed for one account; continuing',
						['accountId' => $this->extractUuid(object: $account), 'exception' => $e->getMessage()]
					);
				}
			}
		}//end foreach
	}//end run()

	/**
	 * Process a single account.
	 *
	 * @param array<string, mixed> $account The account.
	 * @param DateTimeImmutable $cutoff Cutoff for expiry.
	 * @param DateTimeImmutable $noticeCutoff Cutoff for advance notice.
	 * @param int $notice Advance notice days.
	 *
	 * @return void
	 */
	private function processAccount(
		array $account,
		DateTimeImmutable $cutoff,
		DateTimeImmutable $noticeCutoff,
		int $notice,
	): void {
		$accountId = (string)$this->extractUuid(object: $account);
		if ($accountId === '') {
			return;
		}

		$lastActivity = (string)($account['lastActivityDate'] ?? '');
		$balance = (int)($account['currentBalance'] ?? 0);
		if ($balance <= 0 || $lastActivity === '') {
			return;
		}

		if ($lastActivity < $cutoff->format('c')) {
			// Expire.
			$this->ledgerService->expirePoints(
				accountId: $accountId,
				amount: $balance,
				reason: 'inactivity-expiry'
			);
			$this->logger->info(
				'Pipelinq: expired points for inactive account',
				['accountId' => $accountId, 'points' => $balance]
			);
			return;
		}

		if ($lastActivity < $noticeCutoff->format('c')) {
			// Advance notice.
			$customerId = (string)($account['customerId'] ?? '');
			if ($customerId === '') {
				return;
			}

			try {
				$this->notificationService->sendNotification(
					userId: $customerId,
					subject: 'loyalty_points_expiring',
					parameters: [
						'points' => $balance,
						'noticeDays' => $notice,
						'accountId' => $accountId,
					],
					objectType: 'customerLoyaltyAccount',
					objectId: $accountId
				);
			} catch (Throwable $e) {
				// Best effort.
				$this->logger->debug(
					'Pipelinq: advance-expiry notification failed',
					['accountId' => $accountId, 'exception' => $e->getMessage()]
				);
			}
		}//end if
	}//end processAccount()

	/**
	 * Get active programmes.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function getActiveProgrammes(): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'loyaltyProgramme_schema', '');
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$rows = $this->container->get('OCA\OpenRegister\Service\ObjectService')->findAll(
				config: [
					'filters' => [
						'status' => 'active',
						'register' => $register,
						'schema' => $schema,
					],
					'limit' => 500,
				]
			);
		} catch (Throwable $e) {
			return [];
		}

		$result = [];
		$list = [];
		if (is_array($rows) === true) {
			$list = $rows;
		}

		foreach ($list as $row) {
			$result[] = $this->toArray(object: $row);
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

	/**
	 * Normalise OR entity to array.
	 *
	 * @param mixed $object The OR entity or array.
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialised = $object->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$payload = $object->getObject();
			if (is_array($payload) === true) {
				return $payload;
			}
		}

		return [];
	}//end toArray()
}//end class
