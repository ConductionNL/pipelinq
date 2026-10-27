<?php

/**
 * Pipelinq ContactSyncSettingsService.
 *
 * A user's own contacts sync: whether saves are written back to Nextcloud
 * Contacts, how many records are linked, which addressbook takes new cards,
 * and a full run that writes every linked record back.
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\Contacts\IManager as IContactsManager;
use OCP\IAppConfig;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * The per-user contacts sync settings and their status.
 *
 * @spec openspec/changes/contacts-sync-settings/specs/contacts-sync/spec.md
 */
class ContactSyncSettingsService {

	/**
	 * The user setting that turns write-back on or off.
	 *
	 * @var string
	 */
	public const KEY_WRITE_BACK = 'contacts_sync_write_back';

	/**
	 * The user setting holding the last full run, as JSON.
	 *
	 * @var string
	 */
	public const KEY_LAST_RUN = 'contacts_sync_last_run';

	/**
	 * The most linked records one run writes back.
	 *
	 * @var int
	 */
	private const RUN_LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param IConfig                $config          Per-user settings.
	 * @param IAppConfig             $appConfig       The app config (register and schema ids).
	 * @param IContactsManager       $contactsManager The user's addressbooks.
	 * @param ContactSyncService     $syncService     Writes one record back.
	 * @param ContactVcardWriterService $writerService Knows the addressbook new cards go to.
	 * @param ObjectServiceInterface $objectService   Reads the linked records.
	 * @param LoggerInterface        $logger          The logger.
	 */
	public function __construct(
		private IConfig $config,
		private IAppConfig $appConfig,
		private IContactsManager $contactsManager,
		private ContactSyncService $syncService,
		private ContactVcardWriterService $writerService,
		private readonly ObjectServiceInterface $objectService,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether a user's saves are written back to Nextcloud Contacts (on unless turned off).
	 *
	 * @param string $userId The user.
	 *
	 * @return bool True when write-back is on.
	 *
	 * @spec openspec/changes/contacts-sync-settings/specs/contacts-sync/spec.md
	 */
	public function writesBack(string $userId): bool {
		return $this->config->getUserValue(
			userId: $userId,
			appName: Application::APP_ID,
			key: self::KEY_WRITE_BACK,
			default: '1'
		) !== '0';
	}//end writesBack()

	/**
	 * Turn a user's write-back on or off.
	 *
	 * @param string $userId    The user.
	 * @param bool   $writeBack Whether saves go to Nextcloud Contacts.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/contacts-sync-settings/specs/contacts-sync/spec.md
	 */
	public function saveWriteBack(string $userId, bool $writeBack): void {
		$value = '0';
		if ($writeBack === true) {
			$value = '1';
		}

		$this->config->setUserValue(
			userId: $userId,
			appName: Application::APP_ID,
			key: self::KEY_WRITE_BACK,
			value: $value
		);
	}//end saveWriteBack()

	/**
	 * The status the settings section shows.
	 *
	 * @param string $userId The user.
	 *
	 * @return array{writeBack: bool, linked: int, addressBook: string, lastRun: array<string, mixed>|null} The status.
	 *
	 * @spec openspec/changes/contacts-sync-settings/specs/contacts-sync/spec.md
	 */
	public function status(string $userId): array {
		$addressBook = '';
		if ($this->contactsManager->isEnabled() === true) {
			$addressBook = $this->writerService->newCardAddressBookName();
		}

		return [
			'writeBack' => $this->writesBack(userId: $userId),
			'linked' => count($this->linkedRecords()),
			'addressBook' => $addressBook,
			'lastRun' => $this->lastRun(userId: $userId),
		];
	}//end status()

	/**
	 * Write every linked record back to Nextcloud Contacts, and remember the run.
	 *
	 * @param string $userId The user who asked.
	 * @param string $now    The moment of the run (ISO 8601).
	 *
	 * @return array{at: string, updated: int, failed: array<int, array{name: string}>} The run.
	 *
	 * @spec openspec/changes/contacts-sync-settings/specs/contacts-sync/spec.md
	 */
	public function run(string $userId, string $now): array {
		$updated = 0;
		$failed = [];
		foreach ($this->linkedRecords() as $record) {
			$uid = null;
			try {
				$uid = $this->syncService->syncToContacts(objectType: $record['type'], objectId: $record['id']);
			} catch (\Throwable $e) {
				$this->logger->warning('Pipelinq: a linked record was not written back', ['exception' => $e->getMessage()]);
			}

			if ($uid === null) {
				$failed[] = ['name' => $record['name']];
				continue;
			}

			$updated++;
		}

		$run = ['at' => $now, 'updated' => $updated, 'failed' => $failed];
		$this->config->setUserValue(
			userId: $userId,
			appName: Application::APP_ID,
			key: self::KEY_LAST_RUN,
			value: (string)json_encode($run)
		);

		return $run;
	}//end run()

	/**
	 * The last full run a user started, or null before the first.
	 *
	 * @param string $userId The user.
	 *
	 * @return array<string, mixed>|null The run.
	 */
	private function lastRun(string $userId): ?array {
		$stored = $this->config->getUserValue(
			userId: $userId,
			appName: Application::APP_ID,
			key: self::KEY_LAST_RUN,
			default: ''
		);
		$run = json_decode($stored, true);
		if (is_array($run) === false) {
			return null;
		}

		return $run;
	}//end lastRun()

	/**
	 * The clients and contact persons linked to a Nextcloud contact.
	 *
	 * @return array<int, array{type: string, id: string, name: string}> The records.
	 */
	private function linkedRecords(): array {
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($registerId === '') {
			return [];
		}

		$records = [];
		foreach (['client', 'contact'] as $type) {
			$schemaId = $this->appConfig->getValueString(Application::APP_ID, $type . '_schema', '');
			if ($schemaId === '') {
				continue;
			}

			try {
				$objects = $this->objectService->findAll(
					config: [
						'filters' => ['@self' => ['register' => $registerId, 'schema' => $schemaId]],
						'limit' => self::RUN_LIMIT,
					]
				);
			} catch (\Throwable $e) {
				$this->logger->warning('Pipelinq: linked records not read', ['exception' => $e->getMessage()]);
				continue;
			}

			foreach ($objects as $object) {
				$data = $this->toArray(object: $object);
				if (empty($data['contactsUid']) === true) {
					continue;
				}

				$records[] = [
					'type' => $type,
					'id' => (string)($data['id'] ?? ($data['@self']['id'] ?? '')),
					'name' => (string)($data['name'] ?? ''),
				];
			}
		}//end foreach

		return $records;
	}//end linkedRecords()

	/**
	 * An OpenRegister result as an array.
	 *
	 * @param mixed $object The result.
	 *
	 * @return array<string, mixed> The data.
	 */
	private function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$data = $object->jsonSerialize();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return [];
	}//end toArray()
}//end class
