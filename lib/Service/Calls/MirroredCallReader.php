<?php

/**
 * Pipelinq MirroredCallReader.
 *
 * Finds the open mirrored call of one agent.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Calls
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/kcc-agent-panel/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Calls;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\BackgroundServiceAccount;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The one way a mirrored call reaches a signed-in user.
 *
 * No user reads the `mirroredCall` schema, so the read runs as the background
 * service account with OpenRegister's access checks on. What decides who gets
 * a call is this class: an agent gets a call only when the row names that
 * agent, compared again here after the store answered. The agent gets the
 * number and the times of the call and nothing else. The clients, contacts
 * and tickets the mirror found for the number were read without access
 * checks, so they are never handed on: the card looks the number up again as
 * the agent.
 *
 * @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-the-incoming-call-card-also-shows-a-mirrored-call
 */
class MirroredCallReader {
	/**
	 * How long after it started a call can still be incoming, in seconds.
	 */
	public const MAX_AGE_SECONDS = 7200;

	/**
	 * How many of the agent's newest call events are read.
	 */
	private const LOOKBACK = 40;

	/**
	 * The kinds that end a call for the agent it rang for.
	 */
	private const CLOSING = ['ended', 'transferred'];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Reads which register and schema hold the mirror.
	 * @param ContainerInterface $container Finds OpenRegister's object service at run time.
	 * @param BackgroundServiceAccount $account The account the read runs as.
	 * @param LoggerInterface $logger Logger. A caller's number MUST NOT be written here.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private ContainerInterface $container,
		private BackgroundServiceAccount $account,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether calls are mirrored on this instance: the schema is there and the account can be used.
	 *
	 * Asked on every poll of the card, so it logs nothing.
	 *
	 * @return bool True when a mirrored call can be read.
	 *
	 * @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-the-incoming-call-card-also-shows-a-mirrored-call
	 */
	public function isActive(): bool {
		if ($this->appConfig->getValueString(Application::APP_ID, 'register', '') === '') {
			return false;
		}

		if ($this->appConfig->getValueString(Application::APP_ID, 'mirroredCall_schema', '') === '') {
			return false;
		}

		return $this->account->status()['usable'] === true;
	}//end isActive()

	/**
	 * The newest call that rang for this agent and has not ended.
	 *
	 * @param string $agentId The agent's user id.
	 * @param int|null $now Unix time to measure against; null is the clock.
	 *
	 * @return array{id: string, fromNumber: string, startedAt: string, answeredAt: string}|null The call, or null.
	 *
	 * @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-the-incoming-call-card-also-shows-a-mirrored-call
	 */
	public function openCallFor(string $agentId, ?int $now = null): ?array {
		if ($agentId === '' || $this->isActive() === false) {
			return null;
		}

		try {
			$rows = $this->account->runAs(
				operation: fn (): array => (array)$this->container->get('OCA\\OpenRegister\\Service\\ObjectService')->findAll(
					config: [
						'filters' => [
							'register' => $this->appConfig->getValueString(Application::APP_ID, 'register', ''),
							'schema' => $this->appConfig->getValueString(Application::APP_ID, 'mirroredCall_schema', ''),
							'agentId' => $agentId,
						],
						'limit' => self::LOOKBACK,
						'sort' => ['mirroredAt' => 'desc'],
					],
					_rbac: true,
					_multitenancy: false,
				)
			);
		} catch (Throwable $e) {
			$this->logger->warning('MirroredCallReader: the mirror could not be read', ['exception' => $e->getMessage()]);
			return null;
		}//end try

		return $this->newestOpen(rows: $rows, agentId: $agentId, now: ($now ?? time()));
	}//end openCallFor()

	/**
	 * Pick the newest call of this agent that no later event closed.
	 *
	 * @param array<int, mixed> $rows The agent's newest call events.
	 * @param string $agentId The agent's user id.
	 * @param int $now Unix time.
	 *
	 * @return array{id: string, fromNumber: string, startedAt: string, answeredAt: string}|null The call, or null.
	 */
	private function newestOpen(array $rows, string $agentId, int $now): ?array {
		$calls = $this->eventsPerCall(rows: $rows, agentId: $agentId);

		$newest = null;
		$newestStart = 0;
		foreach ($calls as $events) {

			$started = strtotime((string)($events['ringing']['mirroredAt'] ?? ''));
			if ($started === false || ($now - $started) > self::MAX_AGE_SECONDS || $started <= $newestStart) {
				continue;
			}

			$newestStart = $started;
			$newest = [
				'id' => (string)($events['ringing']['id'] ?? ($events['ringing']['uuid'] ?? '')),
				'fromNumber' => (string)($events['ringing']['callerNumber'] ?? ''),
				'startedAt' => (string)$events['ringing']['mirroredAt'],
				'answeredAt' => (string)($events['answered']['mirroredAt'] ?? ''),
			];
		}//end foreach

		if ($newest === null || $newest['id'] === '') {
			return null;
		}

		return $newest;
	}//end newestOpen()

	/**
	 * The agent's call events per call, without the calls a later event closed or that never rang.
	 *
	 * @param array<int, mixed> $rows The agent's newest call events.
	 * @param string $agentId The agent's user id.
	 *
	 * @return array<string, array<string, array<string, mixed>>> Per call, its events by kind.
	 */
	private function eventsPerCall(array $rows, string $agentId): array {
		$calls = [];
		foreach ($rows as $row) {
			$data = $this->toArray(row: $row);
			// Compared again: a store that ignored the filter must not hand an agent a colleague's call.
			if (($data['agentId'] ?? null) !== $agentId) {
				continue;
			}

			$call = (string)($data['callId'] ?? '') . '|' . (string)($data['sourceId'] ?? '');
			$kind = (string)($data['kind'] ?? '');
			$calls[$call][$kind] = $data;
		}

		return array_filter(
			$calls,
			static fn (array $events): bool => isset($events['ringing']) === true && array_intersect(self::CLOSING, array_keys($events)) === []
		);
	}//end eventsPerCall()

	/**
	 * A row as a plain array.
	 *
	 * @param mixed $row An OpenRegister entity or an array.
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $row): array {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$row = $row->jsonSerialize();
		}

		if (is_array($row) === true) {
			return $row;
		}

		return [];
	}//end toArray()
}//end class
