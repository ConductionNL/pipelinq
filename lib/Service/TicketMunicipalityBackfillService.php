<?php

/**
 * Pipelinq TicketMunicipalityBackfillService.
 *
 * Gives existing tickets their client's municipality, once, on upgrade: a
 * ticket without a municipality whose client has one takes it. A ticket that
 * already has one is never changed. The writes are made in OpenRegister's
 * system scope with the object events withheld, so nobody is notified of a
 * label being filled in.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/requests-municipality-per-contact/specs/served-municipality/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Copies each client's municipality onto its tickets that have none; idempotent.
 *
 * @spec openspec/changes/requests-municipality-per-contact/specs/served-municipality/spec.md
 */
class TicketMunicipalityBackfillService {

	/**
	 * The most clients or tickets read in one run.
	 */
	private const SCAN_LIMIT = 10000;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig             $appConfig     The register and schema ids.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param ObjectEventSilence     $silence       Whether the writes stay silent.
	 * @param IGroupManager          $groupManager  Finds an administrator to write as.
	 * @param LoggerInterface        $logger        Logs failed reads and writes.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
		private readonly ObjectEventSilence $silence,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Fill in the municipality of every ticket whose client has one.
	 *
	 * @return array{status: string, filled: int, skipped: int, failed: int} What happened.
	 *
	 * @spec openspec/changes/requests-municipality-per-contact/specs/served-municipality/spec.md
	 */
	public function backfill(): array {
		$counts   = ['status' => 'done', 'filled' => 0, 'skipped' => 0, 'failed' => 0];
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$clients  = $this->appConfig->getValueString(Application::APP_ID, 'client_schema', '');
		$tickets  = $this->appConfig->getValueString(Application::APP_ID, 'ticket_schema', '');
		if ($register === '' || $clients === '' || $tickets === '') {
			$counts['status'] = 'not-configured';
			return $counts;
		}

		if ($this->silence->isSupported() === false) {
			$counts['status'] = 'no-silent-path';
			return $counts;
		}

		$result = $this->objectService->runAsSystem(
			fn (): array => $this->backfillInScope(register: $register, clients: $clients, tickets: $tickets, counts: $counts)
		);
		if (is_array($result) === false) {
			$counts['status'] = 'scope-inactive';
			return $counts;
		}

		return $result;
	}//end backfill()

	/**
	 * The backfill itself, inside the system scope.
	 *
	 * @param string                                                         $register The register id.
	 * @param string                                                         $clients  The client schema id.
	 * @param string                                                         $tickets  The ticket schema id.
	 * @param array{status: string, filled: int, skipped: int, failed: int} $counts   The counts so far.
	 *
	 * @return array{status: string, filled: int, skipped: int, failed: int} The counts.
	 */
	private function backfillInScope(string $register, string $clients, string $tickets, array $counts): array {
		if ($this->silence->isActive() === false) {
			$counts['status'] = 'scope-inactive';
			return $counts;
		}

		try {
			$municipalities = $this->municipalitiesByClient(rows: $this->read(register: $register, schema: $clients));
			$rows           = $this->read(register: $register, schema: $tickets);
		} catch (Throwable $e) {
			$this->logger->warning('Pipelinq: could not read clients or tickets for the municipality backfill', ['exception' => $e->getMessage()]);
			$counts['status'] = 'read-failed';
			return $counts;
		}

		$actingAdmin = $this->actingAdmin();
		foreach ($rows as $ticket) {
			$uuid         = self::idOf(row: $ticket);
			$municipality = ($municipalities[self::reference(value: ($ticket['client'] ?? null))] ?? '');
			if ($uuid === '' || $municipality === '' || self::reference(value: ($ticket['municipality'] ?? null)) !== '') {
				$counts['skipped']++;
				continue;
			}

			// Checked at every write: a scope that ended part-way would let the rest be heard.
			if ($this->silence->isActive() === false) {
				$counts['status'] = 'scope-inactive';
				break;
			}

			$payload = $ticket;
			unset($payload['@self']);
			$payload['municipality'] = $municipality;
			try {
				$this->objectService->saveObject(
					object: $payload,
					extend: [],
					register: $register,
					schema: $tickets,
					uuid: $uuid,
					_rbac: false,
					_multitenancy: false,
					silent: true,
					currentUser: $actingAdmin,
				);
				$counts['filled']++;
			} catch (Throwable $e) {
				$counts['failed']++;
				$this->logger->error('Pipelinq: could not fill in the municipality of a ticket', ['uuid' => $uuid, 'exception' => $e->getMessage()]);
			}
		}//end foreach

		return $counts;
	}//end backfillInScope()

	/**
	 * Every object of a schema, as arrays.
	 *
	 * @param string $register The register id.
	 * @param string $schema   The schema id.
	 *
	 * @return list<array<string, mixed>> The objects.
	 */
	private function read(string $register, string $schema): array {
		$rows = $this->objectService->findAll(
			config: ['filters' => ['register' => $register, 'schema' => $schema], 'limit' => self::SCAN_LIMIT],
			_rbac: false,
			_multitenancy: false,
		);

		$objects = [];
		foreach ($rows as $row) {
			$objects[] = self::toArray(row: $row);
		}

		return $objects;
	}//end read()

	/**
	 * The municipality of each client that has one, by client id.
	 *
	 * @param list<array<string, mixed>> $rows The clients.
	 *
	 * @return array<string, string> The municipality ids.
	 */
	private function municipalitiesByClient(array $rows): array {
		$map = [];
		foreach ($rows as $client) {
			$id           = self::idOf(row: $client);
			$municipality = self::reference(value: ($client['municipality'] ?? null));
			if ($id !== '' && $municipality !== '') {
				$map[$id] = $municipality;
			}
		}

		return $map;
	}//end municipalitiesByClient()

	/**
	 * An administrator to write as; null when there is none.
	 *
	 * @return IUser|null The administrator.
	 */
	private function actingAdmin(): ?IUser {
		$admins = ($this->groupManager->get('admin')?->getUsers() ?? []);
		return (array_values($admins)[0] ?? null);
	}//end actingAdmin()

	/**
	 * An object's id.
	 *
	 * @param array<string, mixed> $row The object.
	 *
	 * @return string The id; empty when it has none.
	 */
	private static function idOf(array $row): string {
		return (string) ($row['@self']['id'] ?? ($row['id'] ?? ''));
	}//end idOf()

	/**
	 * The id a reference holds: the string itself, or an expanded object's id.
	 *
	 * @param mixed $value The reference.
	 *
	 * @return string The id; empty when there is none.
	 */
	private static function reference(mixed $value): string {
		if (is_array($value) === true) {
			return self::idOf(row: $value);
		}

		if (is_string($value) === true) {
			return trim($value);
		}

		return '';
	}//end reference()

	/**
	 * A found object as an array.
	 *
	 * @param mixed $row The object.
	 *
	 * @return array<string, mixed> Its data.
	 */
	private static function toArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (($row instanceof \JsonSerializable) === true) {
			$data = $row->jsonSerialize();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return [];
	}//end toArray()
}//end class
