<?php

/**
 * Pipelinq ClientHistorySummaryService.
 *
 * Writes a few sentences about what happened with a client lately, from the
 * client's tickets (contact moments, requests, complaints), leads and notes
 * of the last 90 days, through hermiq.
 *
 * WHAT GOES IN IS WHAT THE VIEWER MAY READ. Tickets and leads are read with
 * the viewer's RBAC on, so a record the viewer may not open never reaches the
 * model and so never reaches the summary. The input is bounded: newest first,
 * at most 40 records, each line cut short.
 *
 * NOTHING IS STORED. The summary is returned and forgotten, so it never
 * outlives the records it was written from.
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
 * @spec openspec/changes/clients-history-summary-and-risk/specs/client-insight/spec.md#requirement-a-clients-history-can-be-summarised-on-request-req-cin-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Competitor\RelevanceScorer;
use OCP\IAppConfig;
use OCP\IL10N;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * On-request history summary of one client.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Reads tickets, leads and notes, and calls hermiq.
 *
 * @spec openspec/changes/clients-history-summary-and-risk/specs/client-insight/spec.md#requirement-a-clients-history-can-be-summarised-on-request-req-cin-002
 */
class ClientHistorySummaryService {

	/**
	 * How far back the summary reads, in days.
	 *
	 * @var int
	 */
	public const WINDOW_DAYS = 90;

	/**
	 * The most records one summary reads.
	 *
	 * @var int
	 */
	public const MAX_RECORDS = 40;

	/**
	 * The longest text one record contributes, in characters.
	 *
	 * @var int
	 */
	private const MAX_LINE = 300;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container for the lazy hermiq resolve.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param ActivityTimelineReader $timelineReader Reads the client's notes.
	 * @param IAppConfig $appConfig Register and schema ids.
	 * @param IL10N $l10n The viewer's language.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly ObjectServiceInterface $objectService,
		private readonly ActivityTimelineReader $timelineReader,
		private readonly IAppConfig $appConfig,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether hermiq can write a summary on this instance.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/clients-history-summary-and-risk/specs/client-insight/spec.md#requirement-a-clients-history-can-be-summarised-on-request-req-cin-002
	 */
	public function available(): bool {
		return $this->provider() !== null;
	}//end available()

	/**
	 * Summarise a client's recent history as the viewer.
	 *
	 * @param string $clientId The client UUID.
	 * @param string $viewerId The viewer's user id.
	 * @param DateTimeImmutable|null $now The reference instant, now by default.
	 *
	 * @return array{summary: string, recordsRead: int, generatedAt: string}
	 *
	 * @throws RuntimeException When hermiq is absent or gives no answer.
	 *
	 * @spec openspec/changes/clients-history-summary-and-risk/specs/client-insight/spec.md#requirement-a-clients-history-can-be-summarised-on-request-req-cin-002
	 */
	public function summarise(string $clientId, string $viewerId, ?DateTimeImmutable $now=null): array {
		$when = ($now ?? new DateTimeImmutable('now'));
		$factory = $this->provider();
		if ($factory === null) {
			throw new RuntimeException('Hermiq is not available.');
		}

		$records = $this->records(clientId: $clientId, now: $when);
		if ($records === []) {
			return [
				'summary' => $this->l10n->t('Nothing happened with this client in the last 90 days.'),
				'recordsRead' => 0,
				'generatedAt' => $when->format(DATE_ATOM),
			];
		}

		try {
			$answer = trim((string)$factory->generateText($this->prompt(records: $records), $viewerId, true, null));
		} catch (Throwable $e) {
			$this->logger->info('ClientHistorySummaryService: hermiq did not answer', ['exception' => $e->getMessage()]);
			throw new RuntimeException('Hermiq did not answer.', 0, $e);
		}

		if ($answer === '') {
			throw new RuntimeException('Hermiq gave an empty answer.');
		}

		return [
			'summary' => mb_substr($answer, 0, 2000, 'UTF-8'),
			'recordsRead' => count($records),
			'generatedAt' => $when->format(DATE_ATOM),
		];
	}//end summarise()

	/**
	 * The records the summary reads: inside the window, newest first, bounded.
	 *
	 * @param string $clientId The client UUID.
	 * @param DateTimeImmutable $now The reference instant.
	 *
	 * @return list<array{kind: string, date: string, text: string}>
	 *
	 * @spec openspec/changes/clients-history-summary-and-risk/specs/client-insight/spec.md#requirement-a-clients-history-can-be-summarised-on-request-req-cin-002
	 */
	public function records(string $clientId, DateTimeImmutable $now): array {
		$since = $now->modify('-' . self::WINDOW_DAYS . ' days');
		$records = [];

		foreach ($this->readAsViewer(schemaKey: 'ticket_schema', clientId: $clientId) as $ticket) {
			$records[] = $this->line(
				kind: (string)($ticket['ticketType'] ?? 'ticket'),
				date: ($ticket['occurredAt'] ?? ($ticket['@self']['created'] ?? null)),
				parts: [$ticket['title'] ?? '', $ticket['status'] ?? '', $ticket['description'] ?? '']
			);
		}

		foreach ($this->readAsViewer(schemaKey: 'lead_schema', clientId: $clientId) as $lead) {
			$records[] = $this->line(
				kind: 'lead',
				date: ($lead['@self']['updated'] ?? ($lead['@self']['created'] ?? null)),
				parts: [$lead['title'] ?? '', $lead['stage'] ?? '', $lead['status'] ?? '']
			);
		}

		foreach ($this->notes(clientId: $clientId) as $note) {
			$records[] = $this->line(kind: 'note', date: ($note['timestamp'] ?? null), parts: [$note['message'] ?? '']);
		}

		$records = array_values(
			array_filter(
				$records,
				static fn (?array $record): bool => $record !== null && new DateTimeImmutable($record['date']) >= $since
			)
		);
		usort($records, static fn (array $left, array $right): int => strcmp($right['date'], $left['date']));

		return array_slice($records, 0, self::MAX_RECORDS);
	}//end records()

	/**
	 * One record as a prompt line, or null when it has no readable date.
	 *
	 * @param string $kind What the record is.
	 * @param mixed $date Its date.
	 * @param array<int, mixed> $parts Its text fields.
	 *
	 * @return array{kind: string, date: string, text: string}|null
	 */
	private function line(string $kind, mixed $date, array $parts): ?array {
		if (is_string($date) === false || $date === '') {
			return null;
		}

		try {
			$iso = (new DateTimeImmutable($date))->format(DATE_ATOM);
		} catch (Throwable) {
			return null;
		}

		$texts = [];
		foreach ($parts as $part) {
			if (is_scalar($part) === true && trim((string)$part) !== '') {
				$texts[] = trim(strip_tags((string)$part));
			}
		}

		$text = implode(' | ', $texts);

		return ['kind' => $kind, 'date' => $iso, 'text' => mb_substr(preg_replace('/\s+/', ' ', $text) ?? '', 0, self::MAX_LINE, 'UTF-8')];
	}//end line()

	/**
	 * The client's objects of one schema, read with the viewer's rights.
	 *
	 * @param string $schemaKey The app-config key naming the schema.
	 * @param string $clientId The client UUID.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function readAsViewer(string $schemaKey, string $clientId): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$rows = $this->objectService->findAll(
				config: ['filters' => ['register' => $register, 'schema' => $schema, 'client' => $clientId], 'limit' => 200],
				_rbac: true,
				_multitenancy: true,
			);
		} catch (Throwable $e) {
			$this->logger->warning('ClientHistorySummaryService: read failed', ['schema' => $schemaKey, 'exception' => $e->getMessage()]);
			return [];
		}

		$out = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$out[] = $row;
			} elseif (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$out[] = (array)$row->jsonSerialize();
			}
		}

		return $out;
	}//end readAsViewer()

	/**
	 * The client's notes, or none when they cannot be read.
	 *
	 * @param string $clientId The client UUID.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function notes(string $clientId): array {
		try {
			return $this->timelineReader->notes(entityId: $clientId);
		} catch (Throwable $e) {
			$this->logger->info('ClientHistorySummaryService: notes not read', ['exception' => $e->getMessage()]);
			return [];
		}
	}//end notes()

	/**
	 * The prompt: plain instructions, then one line per record.
	 *
	 * @param list<array{kind: string, date: string, text: string}> $records The records.
	 *
	 * @return string
	 */
	private function prompt(array $records): string {
		$lines = [
			'Summarise in three to five plain sentences what happened with this client recently, for a colleague who is about to contact them.',
			'Name open issues and the last contact. Use only the records below. Do not guess.',
			'Write in the language with code "' . $this->l10n->getLanguageCode() . '".',
			'',
		];
		foreach ($records as $record) {
			$lines[] = substr($record['date'], 0, 10) . ' [' . $record['kind'] . '] ' . $record['text'];
		}

		return implode("\n", $lines);
	}//end prompt()

	/**
	 * Hermiq's provider factory, or null when hermiq is absent.
	 *
	 * @return object|null
	 */
	private function provider(): ?object {
		try {
			$factory = $this->container->get(RelevanceScorer::PROVIDER_CLASS);
		} catch (Throwable) {
			return null;
		}

		if (is_object($factory) === false || method_exists($factory, 'generateText') === false) {
			return null;
		}

		return $factory;
	}//end provider()
}//end class
