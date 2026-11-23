<?php

/**
 * Pipelinq MailIntakeAccounts.
 *
 * The Nextcloud Mail accounts an administrator names as ticket mailboxes
 * (requests-inbound-to-queue D4). The list lives in app config
 * `inboundMail.accounts` as a JSON list of Mail account ids; the accounts
 * themselves are read from the Mail app's own `mail_accounts` table. An id
 * that names no Mail account is never stored.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\MailIntake
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-names-the-ticket-mailboxes-req-itq-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\MailIntake;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCP\IAppConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the Mail accounts and the ticket mailbox list, and stores the list.
 *
 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-names-the-ticket-mailboxes-req-itq-003
 */
class MailIntakeAccounts {

	/**
	 * The app config key holding the ticket mailbox account ids.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'inboundMail.accounts';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection   $db        Reads the Mail app's accounts.
	 * @param IAppConfig      $appConfig Holds the ticket mailbox list.
	 * @param LoggerInterface $logger    Where an unreadable Mail table is logged.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Every Nextcloud Mail account on the instance, with its owner.
	 *
	 * Empty when Nextcloud Mail is not installed.
	 *
	 * @return list<array{id: int, address: string, name: string, owner: string}> The accounts, by id.
	 *
	 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-names-the-ticket-mailboxes-req-itq-003
	 */
	public function all(): array {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'email', 'name', 'user_id')
				->from('mail_accounts')
				->orderBy('id', 'ASC');

			$result   = $qb->executeQuery();
			$accounts = [];
			while (($row = $result->fetch()) !== false) {
				$accounts[] = [
					'id' => (int) ($row['id'] ?? 0),
					'address' => (string) ($row['email'] ?? ''),
					'name' => (string) ($row['name'] ?? ''),
					'owner' => (string) ($row['user_id'] ?? ''),
				];
			}

			$result->closeCursor();
		} catch (Throwable $e) {
			$this->logger->warning('Pipelinq: the Mail accounts could not be read', ['error' => $e->getMessage()]);
			$accounts = [];
		}//end try

		return $accounts;
	}//end all()

	/**
	 * The ticket mailbox account ids, as stored.
	 *
	 * @return list<int> The ids.
	 *
	 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-names-the-ticket-mailboxes-req-itq-003
	 */
	public function selected(): array {
		$decoded = json_decode($this->appConfig->getValueString(AppIdentity::APP_ID, self::CONFIG_KEY, '[]'), true);
		if (is_array($decoded) === false) {
			return [];
		}

		return $this->ids(values: $decoded);
	}//end selected()

	/**
	 * The ticket mailboxes: the selected ids that still name a Mail account.
	 *
	 * @return list<array{id: int, address: string, name: string, owner: string}> The accounts.
	 *
	 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-names-the-ticket-mailboxes-req-itq-003
	 */
	public function mailboxes(): array {
		$selected = $this->selected();

		return array_values(array_filter($this->all(), static fn (array $account): bool => in_array($account['id'], $selected, true)));
	}//end mailboxes()

	/**
	 * Store the ticket mailbox list, keeping only ids that name a Mail account.
	 *
	 * @param array<int|string, mixed> $values The ids the administrator chose.
	 *
	 * @return list<int> The ids stored.
	 *
	 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-names-the-ticket-mailboxes-req-itq-003
	 */
	public function select(array $values): array {
		$known = array_column($this->all(), 'id');
		$ids   = array_values(array_filter($this->ids(values: $values), static fn (int $id): bool => in_array($id, $known, true)));

		$this->appConfig->setValueString(AppIdentity::APP_ID, self::CONFIG_KEY, (string) json_encode($ids));

		return $ids;
	}//end select()

	/**
	 * Positive integer ids, each once, in the order given.
	 *
	 * @param array<int|string, mixed> $values The raw values.
	 *
	 * @return list<int> The ids.
	 */
	private function ids(array $values): array {
		$ids = [];
		foreach ($values as $value) {
			if ((is_int($value) === true || (is_string($value) === true && ctype_digit($value) === true)) && (int) $value > 0) {
				$ids[] = (int) $value;
			}
		}

		return array_values(array_unique($ids));
	}//end ids()
}//end class
