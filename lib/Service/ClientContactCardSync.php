<?php

/**
 * Pipelinq ClientContactCardSync.
 *
 * Writes a Nextcloud Contacts card for every client under the uid the client
 * already names in `contactsUid`. An example set (or an import) can write
 * clients whose uid links to no card yet; this pass gives each of them its
 * card, so the client page and every app that names the uid find a contact.
 *
 * The card is written with the same property builder and writer the normal
 * write-back uses: a card that holds the uid is updated where it lives, a
 * missing card is created under that uid. The client itself is not changed.
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
 * @spec openspec/changes/example-set-zuiddrecht/tasks.md#task-2.5
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Kernel\Contracts\ClientContactCardSyncInterface;
use OCP\Contacts\IManager as IContactsManager;
use Override;
use RuntimeException;

/**
 * Writes a Contacts card per client under the client's own uid.
 *
 * @spec openspec/changes/example-set-zuiddrecht/tasks.md#task-2.5
 */
class ClientContactCardSync implements ClientContactCardSyncInterface {

	/**
	 * Clients read per page.
	 *
	 * @var int
	 */
	private const PAGE = 200;

	/**
	 * Constructor.
	 *
	 * @param IContactsManager            $contactsManager The acting user's addressbooks.
	 * @param ContactObjectReader         $objects         Reads the clients page by page.
	 * @param ContactVcardPropertyBuilder $propBuilder     Builds the card's properties from a client.
	 * @param ContactVcardWriterService   $writer          Writes the card under a given uid.
	 */
	public function __construct(
		private readonly IContactsManager $contactsManager,
		private readonly ContactObjectReader $objects,
		private readonly ContactVcardPropertyBuilder $propBuilder,
		private readonly ContactVcardWriterService $writer,
	) {
	}//end __construct()

	/**
	 * Write a Contacts card for every client under the uid the client names.
	 *
	 * @param bool $dryRun Report what would be written without writing.
	 *
	 * @return array{written: list<array{id: string, name: string, uid: string}>,
	 *               skipped: list<array{id: string, name: string}>,
	 *               failed: list<array{id: string, name: string, uid: string}>} The clients per outcome.
	 *
	 * @throws RuntimeException When Contacts is unavailable, or the contact register or client schema is not configured.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $dryRun is the command's --dry-run option.
	 *
	 * @spec openspec/changes/example-set-zuiddrecht/tasks.md#task-2.5
	 */
	#[Override]
	public function run(bool $dryRun=false): array {
		if ($this->contactsManager->isEnabled() === false) {
			throw new RuntimeException('Nextcloud Contacts is not available for the acting user.');
		}

		$report = ['written' => [], 'skipped' => [], 'failed' => []];
		foreach ($this->clients() as $client) {
			[$bucket, $entry] = $this->syncOne(client: $client, dryRun: $dryRun);
			$report[$bucket][] = $entry;
		}

		return $report;
	}//end run()

	/**
	 * Write one client's card, or say why not.
	 *
	 * @param array<string, mixed> $client The client object.
	 * @param bool                 $dryRun Report without writing.
	 *
	 * @return array{0: string, 1: array<string, string>} The report bucket and the client's entry.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $dryRun is the command's --dry-run option.
	 *
	 * @spec openspec/changes/example-set-zuiddrecht/tasks.md#task-2.5
	 */
	private function syncOne(array $client, bool $dryRun): array {
		$entry = [
			'id'   => (string) ($client['id'] ?? $client['@self']['id'] ?? ''),
			'name' => (string) ($client['name'] ?? ''),
		];
		$uid   = trim((string) ($client['contactsUid'] ?? ''));
		if ($uid === '') {
			return ['skipped', $entry];
		}

		$entry['uid'] = $uid;
		if ($dryRun === true) {
			return ['written', $entry];
		}

		$written = $this->writer->writeVcard(
			properties: $this->propBuilder->buildProperties(objData: $client, objectType: 'client'),
			existingUid: $uid
		);
		if ($written !== $uid) {
			return ['failed', $entry];
		}

		return ['written', $entry];
	}//end syncOne()

	/**
	 * Every client the acting user may read, page by page.
	 *
	 * @return list<array<string, mixed>> The clients.
	 *
	 * @throws RuntimeException When the contact register or the client schema is not configured.
	 */
	private function clients(): array {
		$clients = [];
		$offset  = 0;
		do {
			$page = $this->objects->page(objectType: 'client', limit: self::PAGE, offset: $offset);
			if ($page === null) {
				throw new RuntimeException('The contact register or the client schema is not configured.');
			}

			$clients = array_merge($clients, $page);
			$offset += self::PAGE;
			$full    = (count($page) === self::PAGE);
		} while ($full === true);

		return $clients;
	}//end clients()
}//end class
