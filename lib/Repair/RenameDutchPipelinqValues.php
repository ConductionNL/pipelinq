<?php

/**
 * Translates the Dutch ENUM VALUES stored in this app's shard tables.
 *
 * The schema edit changes the DECLARATION; every row already written still
 * holds the Dutch string, and a filter on the new value then returns NULL
 * rather than an error — so the feature reports "nothing found" instead of
 * failing. This rewrites the stored rows.
 *
 * Scoped by COLUMN, never by the value alone. The same string means different
 * things on different columns, and a migration matching on the string would
 * corrupt every column that shares it.
 *
 * NOT migrated, deliberately: `zgwResourceType` (80-zgw-api-bridge) and
 * `actorType` (82-vng-klantinteracties). Those schemas ARE the mapping onto
 * ZGW and VNG Klantinteracties, and a mapping is configuration — the
 * standard's vocabulary stays in the standard's language.
 *
 * Idempotent: an already-migrated row matches no WHERE clause.
 *
 * @category  Repair
 * @package   OCA\Pipelinq\Repair
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

use OCP\DB\Exception;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Migrates stored Dutch enum values to their English spelling.
 */
class RenameDutchPipelinqValues implements IRepairStep {

	/**
	 * Property name => old value => new value.
	 *
	 * @var array<string, array<string, string>>
	 */
	public const VALUE_MAP = [
		'vatClass' => [
			'hoog' => 'high',
			'laag' => 'low',
			'nul' => 'zero',
			'vrijgesteld' => 'exempt',
		],
		'type' => [
			'terugbelverzoek' => 'callbackRequest',
			'opvolgtaak' => 'followUpTask',
			'informatievraag' => 'informationRequest',
		],
		'status' => [
			'in_behandeling' => 'in_progress',
			'afgerond' => 'completed',
			'verlopen' => 'expired',
			'actief' => 'active',
			'gepauzeerd' => 'paused',
			'beeindigd' => 'ended',
			'concept' => 'draft',
			'geblokkerd' => 'blocked',
			'gedeactiveerd' => 'deactivated',
			'in-behandeling' => 'in-progress',
			'meer-info-nodig' => 'more-info-needed',
			'afgehandeld' => 'handled',
			'afgewezen' => 'rejected',
		],
		'priority' => [
			'laag' => 'low',
			'normaal' => 'normal',
			'hoog' => 'high',
		],
		'context' => [
			'contact-aanmaken' => 'create-contact',
			'overig' => 'other',
		],
		'responseStatus' => [
			'geslaagd' => 'succeeded',
			'niet-gevonden' => 'not-found',
			'fout' => 'error',
			'geweigerd-onbevoegd' => 'refused-unauthorised',
		],
		'gender' => [
			'man' => 'male',
			'vrouw' => 'female',
			'onbekend' => 'unknown',
		],
		'action' => [
			'brp-lookup-uitgevoerd' => 'brp-lookup-executed',
			'brp-lookup-mislukt' => 'brp-lookup-failed',
			'brp-lookup-geweigerd' => 'brp-lookup-refused',
			'brp-adres-onthuld' => 'brp-address-revealed',
			'brp-retentie-uitgevoerd' => 'brp-retention-executed',
			'brp-rtbf-gepseudonimiseerd' => 'brp-rtbf-pseudonymised',
		],
		'outcome' => [
			'geslaagd' => 'succeeded',
			'niet-gevonden' => 'not-found',
			'fout' => 'error',
			'geweigerd-onbevoegd' => 'refused-unauthorised',
			'adres-onthuld' => 'address-revealed',
			'gepseudonimiseerd' => 'pseudonymised',
			'afgehandeld' => 'handled',
			'doorverbonden' => 'transferred',
			'vervolgactie' => 'followUpAction',
			'opgelost' => 'resolved',
			'doorverwezen' => 'referred',
			'terugbelverzoek' => 'callbackRequest',
		],
		'source' => [
			'lokaal' => 'local',
		],
		'reason' => [
			'geheimhouding-gemeente' => 'confidentiality-municipality',
			'geheimhouding-brp' => 'confidentiality-brp',
			'lokale-contact-opt-out' => 'local-contact-opt-out',
		],
		'appliesTo' => [
			'klacht' => 'complaint',
		],
		'ticketType' => [
			'contactmoment' => 'interaction',
		],
		'voucherStatus' => [
			'gereserveerd' => 'reserved',
			'gebruikt' => 'used',
			'vervallen' => 'lapsed',
			'geannuleerd' => 'cancelled',
		],
	];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection   $db     Database connection.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Step name shown by `occ maintenance:repair`.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Translate stored Dutch Pipelinq enum values';
	}//end getName()

	/**
	 * Convert a property name to the column MagicMapper materialised.
	 *
	 * Mirrors `MagicMapper::sanitizeColumnName()`, which applies ONLY the
	 * ([a-z0-9])([A-Z]) boundary — no acronym rule. A column name spelled any
	 * other way matches nothing and the migration is a silent no-op.
	 *
	 * @param string $name Property name.
	 *
	 * @return string Column name.
	 */
	private function columnFor(string $name): string {
		$column = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $name);
		$column = strtolower((string)$column);
		$column = preg_replace('/[^a-z0-9_]/', '_', $column);
		$column = preg_replace('/_+/', '_', (string)$column);
		return rtrim((string)$column, '_');
	}//end columnFor()

	/**
	 * Rewrite the stored values, one column at a time.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 */
	public function run(IOutput $output): void {
		$tables = $this->shardTables();
		if ($tables === []) {
			$output->info('RenameDutchPipelinqValues: no Pipelinq shard tables on this install; nothing to do.');
			return;
		}

		$updated = 0;
		foreach ($tables as $table) {
			$columns = $this->columnsOf(table: $table);
			foreach (self::VALUE_MAP as $property => $values) {
				$column = $this->columnFor(name: $property);
				if (in_array($column, $columns, true) === false) {
					continue;
				}

				foreach ($values as $old => $new) {
					$updated += $this->rewrite(table: $table, column: $column, old: $old, new: $new);
				}
			}
		}

		$output->info(sprintf('RenameDutchPipelinqValues: %d row value(s) translated.', $updated));
	}//end run()

	/**
	 * Rewrite one value in one column.
	 *
	 * @param string $table  Shard table.
	 * @param string $column Column name.
	 * @param string $old    Stored Dutch value.
	 * @param string $new    English replacement.
	 *
	 * @return int Rows affected.
	 */
	private function rewrite(string $table, string $column, string $old, string $new): int {
		$sql = 'UPDATE ' . $this->quote(identifier: $table)
			. ' SET ' . $this->quote(identifier: $column) . ' = ?'
			. ' WHERE ' . $this->quote(identifier: $column) . ' = ?';

		try {
			return $this->db->executeStatement($sql, [$new, $old]);
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameDutchPipelinqValues: value rewrite failed.',
				['table' => $table, 'column' => $column, 'exception' => $e->getMessage()]
			);
			return 0;
		}
	}//end rewrite()

	/**
	 * Discover this app's shard tables.
	 *
	 * @return array<int, string>
	 */
	private function shardTables(): array {
		try {
			$stmt = $this->db->prepare(
				'SELECT table_name FROM information_schema.tables WHERE table_name LIKE :pattern'
			);
			$stmt->bindValue('pattern', '%openregister\_table\_%');
			$stmt->execute();
			$rows = $stmt->fetchAll();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'RenameDutchPipelinqValues: could not list tables; skipping.',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		return array_map(static fn (array $r): string => (string)$r['table_name'], $rows);
	}//end shardTables()

	/**
	 * Read a table's column names.
	 *
	 * @param string $table Table name.
	 *
	 * @return array<int, string>
	 */
	private function columnsOf(string $table): array {
		try {
			$stmt = $this->db->prepare(
				'SELECT column_name FROM information_schema.columns WHERE table_name = :table'
			);
			$stmt->bindValue('table', $table);
			$stmt->execute();
			$rows = $stmt->fetchAll();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'RenameDutchPipelinqValues: could not read columns; skipping table.',
				['table' => $table, 'exception' => $e->getMessage()]
			);
			return [];
		}

		return array_map(static fn (array $r): string => (string)$r['column_name'], $rows);
	}//end columnsOf()

	/**
	 * Quote an identifier for the active platform.
	 *
	 * @param string $identifier Table or column name.
	 *
	 * @return string
	 */
	private function quote(string $identifier): string {
		return $this->db->getDatabasePlatform()->quoteSingleIdentifier($identifier);
	}//end quote()
}//end class
