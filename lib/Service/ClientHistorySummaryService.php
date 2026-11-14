<?php

/**
 * Pipelinq ClientHistorySummaryService.
 *
 * Writes a short summary of a client's recent history on request, through
 * hermiq, from the records the viewer may read. Nothing is stored.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/clients-history-summary-and-risk/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Kernel\Contracts\NotesServiceInterface;
use OCA\Pipelinq\Util\PayloadToArray;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The summary is generated on request and never stored (design D3).
 *
 * Tickets (contact moments, requests, complaints) and leads are read through
 * OpenRegister with RBAC and multitenancy on, so a record the viewer may not
 * read never reaches the model. Notes are the comments on the client itself,
 * which the viewer may read once the client is readable. The input is bounded:
 * the last 90 days, newest first, at most 40 records. Hermiq is resolved by
 * name at run time; without it the service says summarising is not available
 * and reads nothing.
 *
 * @spec openspec/changes/clients-history-summary-and-risk/tasks.md#task-2.1
 */
class ClientHistorySummaryService {
	use PayloadToArray;

	/**
	 * Hermiq's provider factory, resolved by name at run time.
	 */
	public const PROVIDER_CLASS = 'OCA\\Hermiq\\Service\\Llm\\ProviderFactory';

	/**
	 * How far back records count, in days.
	 */
	public const WINDOW_DAYS = 90;

	/**
	 * The most records put in front of the model.
	 */
	public const MAX_RECORDS = 40;

	/**
	 * The most characters of one record's free text.
	 */
	public const MAX_TEXT = 300;

	/**
	 * The notes object type of a client.
	 */
	private const NOTE_OBJECT_TYPE = 'pipelinq_client';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister, read as the viewer.
	 * @param NotesServiceInterface  $notesService  The client's notes.
	 * @param IAppConfig             $appConfig     Register and schemas.
	 * @param ContainerInterface     $container     DI container for the lazy hermiq resolve.
	 * @param LoggerInterface        $logger        PSR logger.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly NotesServiceInterface $notesService,
		private readonly IAppConfig $appConfig,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether hermiq is there to write a summary.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/clients-history-summary-and-risk/tasks.md#task-2.1
	 */
	public function available(): bool {
		return ($this->provider() !== null);
	}//end available()

	/**
	 * A summary of the client's last 90 days, as far as the viewer may read them.
	 *
	 * `summary` is null when hermiq is absent, when there was nothing to read,
	 * or when hermiq gave no answer; `recordsRead` says how many records went in.
	 *
	 * @param string                 $clientId The client's id.
	 * @param string                 $viewer   The user the summary is written for.
	 * @param DateTimeImmutable|null $now      The moment to judge at; now when null.
	 *
	 * @return array{available:bool, summary:string|null, recordsRead:int, generatedAt:string|null}
	 *
	 * @spec openspec/changes/clients-history-summary-and-risk/tasks.md#task-2.1
	 */
	public function summarise(string $clientId, string $viewer, ?DateTimeImmutable $now = null): array {
		$factory = $this->provider();
		if ($factory === null) {
			return ['available' => false, 'summary' => null, 'recordsRead' => 0, 'generatedAt' => null];
		}

		$now     = ($now ?? new DateTimeImmutable('now'));
		$records = $this->records(clientId: $clientId, now: $now);
		$result  = ['available' => true, 'summary' => null, 'recordsRead' => count($records), 'generatedAt' => $now->format(DATE_ATOM)];
		if ($records === []) {
			return $result;
		}

		try {
			$answer = trim((string) $factory->generateText($this->prompt(records: $records), $viewer, true, null));
		} catch (Throwable $e) {
			$this->logger->info('ClientHistorySummaryService: hermiq did not answer', ['exception' => $e->getMessage()]);
			return $result;
		}

		if ($answer !== '') {
			$result['summary'] = $answer;
		}

		return $result;
	}//end summarise()

	/**
	 * The client's records of the window, newest first, at most MAX_RECORDS.
	 *
	 * @param string            $clientId The client's id.
	 * @param DateTimeImmutable $now      The moment to judge at.
	 *
	 * @return array<int,array{date:DateTimeImmutable, line:string}>
	 *
	 * @spec openspec/changes/clients-history-summary-and-risk/tasks.md#task-2.1
	 */
	public function records(string $clientId, DateTimeImmutable $now): array {
		$since   = $now->modify('-' . self::WINDOW_DAYS . ' days');
		$records = [];

		foreach ($this->rows(schemaKey: 'ticket_schema', clientId: $clientId) as $ticket) {
			$records[] = $this->record(date: $this->date(row: $ticket, fields: ['occurredAt']), line: $this->ticketLine(ticket: $ticket));
		}

		foreach ($this->rows(schemaKey: 'lead_schema', clientId: $clientId) as $lead) {
			$records[] = $this->record(date: $this->date(row: $lead, fields: ['updatedAt']), line: $this->leadLine(lead: $lead));
		}

		foreach ($this->notes(clientId: $clientId) as $note) {
			$records[] = $this->record(date: $this->date(row: $note, fields: ['timestamp']), line: 'Note: ' . $this->clip(text: $note['message'] ?? ''));
		}

		$records = array_values(
			array_filter(
				$records,
				static fn (?array $record): bool => $record !== null && $record['date'] >= $since && $record['date'] <= $now
			)
		);
		usort($records, static fn (array $left, array $right): int => ($right['date'] <=> $left['date']));

		return array_slice($records, 0, self::MAX_RECORDS);
	}//end records()

	/**
	 * The prompt: what to write, then the records, one per line.
	 *
	 * @param array<int,array{date:DateTimeImmutable, line:string}> $records The records.
	 *
	 * @return string
	 */
	private function prompt(array $records): string {
		$lines = [
			'Summarise in at most five sentences what happened with this client lately, for an account manager about to contact them.',
			'Use only the records below. Name open matters and the tone of recent contact. Do not invent facts.',
			'',
		];
		foreach ($records as $record) {
			$lines[] = $record['date']->format('Y-m-d') . ' ' . $record['line'];
		}

		return implode("\n", $lines);
	}//end prompt()

	/**
	 * A record, or null when it has no date.
	 *
	 * @param DateTimeImmutable|null $date The record's date.
	 * @param string                 $line The record as one line.
	 *
	 * @return array{date:DateTimeImmutable, line:string}|null
	 */
	private function record(?DateTimeImmutable $date, string $line): ?array {
		if ($date === null) {
			return null;
		}

		return ['date' => $date, 'line' => $line];
	}//end record()

	/**
	 * A ticket as one line.
	 *
	 * @param array<string,mixed> $ticket The ticket.
	 *
	 * @return string
	 */
	private function ticketLine(array $ticket): string {
		$kind = (string) ($ticket['ticketType'] ?? 'ticket');
		if ($kind === 'interaction') {
			$kind = 'contact moment';
		}

		$parts = [ucfirst($kind) . ': ' . $this->clip(text: $ticket['title'] ?? '')];
		foreach (['status', 'channel', 'direction'] as $field) {
			if (is_string($ticket[$field] ?? null) === true && $ticket[$field] !== '') {
				$parts[] = $field . ' ' . $ticket[$field];
			}
		}

		$description = $this->clip(text: $ticket['description'] ?? '');
		if ($description !== '') {
			$parts[] = $description;
		}

		return implode('; ', $parts);
	}//end ticketLine()

	/**
	 * A lead as one line.
	 *
	 * @param array<string,mixed> $lead The lead.
	 *
	 * @return string
	 */
	private function leadLine(array $lead): string {
		$parts = ['Lead: ' . $this->clip(text: $lead['title'] ?? '')];
		foreach (['status', 'stage'] as $field) {
			if (is_string($lead[$field] ?? null) === true && $lead[$field] !== '') {
				$parts[] = $field . ' ' . $lead[$field];
			}
		}

		if (is_numeric($lead['value'] ?? null) === true) {
			$parts[] = 'value ' . $lead['value'];
		}

		return implode('; ', $parts);
	}//end leadLine()

	/**
	 * Free text, on one line and clipped.
	 *
	 * @param mixed $text The text.
	 *
	 * @return string
	 */
	private function clip(mixed $text): string {
		if (is_string($text) === false) {
			return '';
		}

		$flat = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));

		return mb_substr($flat, 0, self::MAX_TEXT, 'UTF-8');
	}//end clip()

	/**
	 * The client's rows of a configured schema, read as the viewer; [] when unconfigured or unreadable.
	 *
	 * @param string $schemaKey The app-config key of the schema.
	 * @param string $clientId  The client's id.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function rows(string $schemaKey, string $clientId): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema   = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$rows = $this->objectService->findAll(
				config: ['filters' => ['register' => $register, 'schema' => $schema, 'client' => $clientId], 'limit' => (self::MAX_RECORDS * 5)],
				_rbac: true,
				_multitenancy: true,
			);
		} catch (Throwable $e) {
			$this->logger->warning('ClientHistorySummaryService: records could not be read', ['schema' => $schemaKey, 'exception' => $e->getMessage()]);
			return [];
		}

		return array_map(fn (mixed $row): array => $this->toArray(value: $row), $rows);
	}//end rows()

	/**
	 * The client's notes; [] when they cannot be read.
	 *
	 * @param string $clientId The client's id.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function notes(string $clientId): array {
		try {
			return $this->notesService->getNotes(objectType: self::NOTE_OBJECT_TYPE, objectId: $clientId);
		} catch (Throwable $e) {
			$this->logger->warning('ClientHistorySummaryService: notes could not be read', ['exception' => $e->getMessage()]);
			return [];
		}
	}//end notes()

	/**
	 * A row's date: the first of the named fields that reads, else its `@self` updated or created.
	 *
	 * @param array<string,mixed> $row    The row.
	 * @param array<int,string>   $fields The fields to try first.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function date(array $row, array $fields): ?DateTimeImmutable {
		$self = [];
		if (is_array($row['@self'] ?? null) === true) {
			$self = $row['@self'];
		}

		$candidates = array_merge(
			array_map(static fn (string $field): mixed => ($row[$field] ?? null), $fields),
			[($self['updated'] ?? null), ($self['created'] ?? null)]
		);

		foreach ($candidates as $value) {
			if (is_string($value) === false || trim($value) === '') {
				continue;
			}

			try {
				return new DateTimeImmutable($value);
			} catch (Throwable) {
				continue;
			}
		}

		return null;
	}//end date()

	/**
	 * Hermiq's provider factory, or null when hermiq is absent.
	 *
	 * @return object|null
	 */
	private function provider(): ?object {
		try {
			$factory = $this->container->get(self::PROVIDER_CLASS);
		} catch (Throwable) {
			return null;
		}

		if (is_object($factory) === false || method_exists($factory, 'generateText') === false) {
			return null;
		}

		return $factory;
	}//end provider()
}//end class
