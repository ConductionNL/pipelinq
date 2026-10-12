<?php

/**
 * Pipelinq IncomingCallFinder.
 *
 * Answers one question for the incoming-call card: which call is on this
 * agent's line right now. It reads the contactmoment the telephony webhook
 * wrote, so it adds no store of its own.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Cti
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/screen-pop-incoming-call/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Cti;

use OCA\Pipelinq\Service\CtiService;
use OCA\Pipelinq\Service\TicketService;

/**
 * Finds the inbound call that is still open on an agent's line.
 *
 * With no telephony platform configured, or with the screen pop switched off,
 * it answers "not enabled" WITHOUT reading a single ticket: an install that
 * has no telephony pays nothing for the card.
 *
 * @spec openspec/changes/screen-pop-incoming-call/specs/cti-screenpop-adapter/spec.md#requirement-the-incoming-call-card-opens-the-screen-pop-req-cti-012
 */
class IncomingCallFinder {
	/**
	 * A call older than this is not "incoming" any more, whatever its record
	 * says: an `ended` event that never arrived must not keep a card open.
	 */
	public const MAX_AGE_SECONDS = 7200;

	/**
	 * How many of the agent's newest telephone contactmoments are read.
	 */
	private const LOOKBACK = 5;

	/**
	 * Constructor.
	 *
	 * @param CtiService $ctiService Reads the telephony configuration.
	 * @param TicketService $ticketService Reads the contactmoments the webhook wrote.
	 */
	public function __construct(
		private CtiService $ctiService,
		private TicketService $ticketService,
	) {
	}//end __construct()

	/**
	 * The open inbound call on an agent's line, if there is one.
	 *
	 * @param string $userId The agent's Nextcloud uid.
	 * @param int|null $now Unix time to measure a call's age against; null is the clock.
	 *
	 * @return array{enabled: bool, delayMs: int, call: array<string, string>|null}
	 *
	 * @spec openspec/changes/screen-pop-incoming-call/specs/cti-screenpop-adapter/spec.md#requirement-the-incoming-call-card-opens-the-screen-pop-req-cti-012
	 */
	public function forAgent(string $userId, ?int $now = null): array {
		$config = $this->ctiService->loadConfig();
		$platform = (string)($config['platform'] ?? '');
		if ($userId === '' || $platform === '' || ($config['screen_pop_enabled'] ?? true) === false) {
			return ['enabled' => false, 'delayMs' => 0, 'call' => null];
		}

		$rows = $this->ticketService->findByType(
			ticketType: TicketService::TYPE_CONTACTMOMENT,
			extraFilters: [
				'assignee' => $userId,
				'channel' => 'telephony',
				'direction' => 'inbound',
			],
			limit: self::LOOKBACK,
			sort: ['started_at' => 'desc'],
		);

		return [
			'enabled' => true,
			'delayMs' => max(0, (int)($config['screen_pop_delay_ms'] ?? 0)),
			'call' => $this->newestOpenCall(rows: $rows, userId: $userId, now: ($now ?? time())),
		];
	}//end forAgent()

	/**
	 * Pick the newest call that has not ended and is not stale.
	 *
	 * The rows are checked again here against the agent, the channel and the
	 * direction. A store that ignored one of the filters would otherwise show
	 * an agent a colleague's caller.
	 *
	 * @param array<int, mixed> $rows The rows the ticket service returned.
	 * @param string $userId The agent's uid.
	 * @param int $now Unix time.
	 *
	 * @return array<string, string>|null The call, or null when none is open.
	 */
	private function newestOpenCall(array $rows, string $userId, int $now): ?array {
		$newest = null;
		$newestStart = 0;
		foreach ($rows as $row) {
			$data = $this->toArray(row: $row);
			$started = strtotime((string)($data['started_at'] ?? ''));
			if ($started === false || $this->isOpenCallOf(data: $data, userId: $userId) === false) {
				continue;
			}

			if (($now - $started) > self::MAX_AGE_SECONDS || $started <= $newestStart) {
				continue;
			}

			$newestStart = $started;
			$newest = [
				'id' => (string)($data['id'] ?? ($data['uuid'] ?? '')),
				'fromNumber' => (string)($data['from_number'] ?? ''),
				'queueName' => (string)($data['queue_name'] ?? ''),
				'extension' => (string)($data['cti_extension'] ?? ''),
				'startedAt' => (string)$data['started_at'],
				'answeredAt' => (string)($data['answered_at'] ?? ''),
			];
		}//end foreach

		if ($newest === null || $newest['id'] === '') {
			return null;
		}

		return $newest;
	}//end newestOpenCall()

	/**
	 * Whether a row is an inbound telephone call of this agent that has not ended.
	 *
	 * @param array<string, mixed> $data The row.
	 * @param string $userId The agent's uid.
	 *
	 * @return bool True when it is.
	 */
	private function isOpenCallOf(array $data, string $userId): bool {
		if (($data['assignee'] ?? '') !== $userId || ($data['direction'] ?? '') !== 'inbound') {
			return false;
		}

		if (($data['channel'] ?? '') !== 'telephony') {
			return false;
		}

		return (string)($data['ended_at'] ?? '') === '';
	}//end isOpenCallOf()

	/**
	 * A row as a plain array.
	 *
	 * @param mixed $row An OpenRegister entity or an array.
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$value = $row->jsonSerialize();
			if (is_array($value) === true) {
				return $value;
			}
		}

		return [];
	}//end toArray()
}//end class
