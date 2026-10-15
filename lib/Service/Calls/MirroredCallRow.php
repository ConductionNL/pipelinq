<?php

/**
 * Pipelinq MirroredCallRow.
 *
 * Turns what a call event says into a row the mirroredCall schema accepts.
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

/**
 * The row of one call event.
 *
 * What an event says comes from outside: a telephony platform's webhook, by
 * way of another app. Nothing of it is trusted. A call id, source or kind that
 * cannot be a key refuses the event. Every other field is brought inside the
 * bounds of the `mirroredCall` schema or left empty, so one event gives one
 * row of a bounded size. CallMirrorTest pins these bounds to the schema.
 *
 * @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-a-call-event-is-mirrored-and-resolved-once
 */
class MirroredCallRow {
	/**
	 * What can happen to a call.
	 */
	public const KINDS = ['ringing', 'answered', 'ended', 'transferred'];

	/**
	 * The longest text the schema takes for a call id, a source, a name or a case reference.
	 */
	public const MAX_TEXT = 255;

	/**
	 * How many case references one event may carry into a row.
	 */
	public const MAX_OPEN_CASES = 20;

	/**
	 * How many clients and contacts one row names.
	 */
	public const MAX_MATCHES = 10;

	/**
	 * The longest call the schema takes, in seconds: two days.
	 */
	public const MAX_DURATION = 172800;

	/**
	 * A caller's number: E.164, or nothing.
	 */
	public const NUMBER_PATTERN = '/^\+[1-9][0-9]{1,14}$/';

	/**
	 * An ISO 8601 moment as a telephony source sends it.
	 */
	private const MOMENT_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/';

	/**
	 * A Nextcloud user id.
	 */
	private const AGENT_PATTERN = '/^[a-zA-Z0-9 _.@\-\']{1,64}$/';

	/**
	 * What makes an event unique, or null when the event cannot be a row.
	 *
	 * A call id or source that is empty, too long for the schema or carries a
	 * control character is refused, not cut: a cut id could make two calls one.
	 *
	 * @param array<string, mixed> $call The event.
	 *
	 * @return array{callId: string, kind: string, sourceId: string}|null The key.
	 *
	 * @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-a-call-event-is-mirrored-and-resolved-once
	 */
	public function key(array $call): ?array {
		$key = [];
		foreach (['callId', 'kind', 'sourceId'] as $field) {
			$value = ($call[$field] ?? '');
			if (is_string($value) === false && is_int($value) === false) {
				return null;
			}

			$value = trim((string)$value);
			if ($value === '' || mb_strlen($value) > self::MAX_TEXT || preg_match('/\p{C}/u', $value) !== 0) {
				return null;
			}

			$key[$field] = $value;
		}

		if (in_array($key['kind'], self::KINDS, true) === false) {
			return null;
		}

		return $key;
	}//end key()

	/**
	 * The row for one call event, every field inside the schema's bounds. `inPipelinq` is empty: the mirror fills it.
	 *
	 * The resolved party of another app is not stored: it can carry a citizen
	 * service number, and the mirror needs a name to show, not an identifier.
	 * The caller's number is stored once, in `callerNumber`, and nowhere else.
	 *
	 * @param array<string, mixed> $call The event.
	 * @param int $now Unix time.
	 *
	 * @return array<string, mixed> The row, valid against the mirroredCall schema.
	 *
	 * @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-a-call-event-is-mirrored-and-resolved-once
	 */
	public function build(array $call, int $now): array {
		$number = $this->matching(value: ($call['callerNumber'] ?? ''), pattern: self::NUMBER_PATTERN);
		$moment = $this->matching(value: ($call['at'] ?? ''), pattern: self::MOMENT_PATTERN);
		if ($moment !== '' && strtotime($moment) === false) {
			$moment = '';
		}

		$duration = ($call['durationSeconds'] ?? 0);
		if (is_int($duration) === false) {
			$duration = 0;
		}

		$openCases = [];
		foreach ((array)($call['openCases'] ?? []) as $case) {
			if (count($openCases) === self::MAX_OPEN_CASES) {
				break;
			}

			if (is_string($case) === true) {
				$case = ['reference' => $case];
			}

			// Only the reference and where it lives: whatever else a source put beside them stays out.
			$reference = '';
			$source = '';
			if (is_array($case) === true) {
				$reference = $this->text(value: ($case['reference'] ?? ''));
				$source = $this->text(value: ($case['source'] ?? ''));
			}

			if ($reference !== '') {
				$openCases[] = ['reference' => $reference, 'source' => $source];
			}
		}//end foreach

		return [
			'callId' => trim((string)$call['callId']),
			'kind' => (string)$call['kind'],
			'sourceId' => trim((string)$call['sourceId']),
			'callerNumber' => $number,
			'callerName' => $this->text(value: ($call['callerName'] ?? '')),
			'agentId' => $this->matching(value: ($call['agentId'] ?? ''), pattern: self::AGENT_PATTERN),
			'at' => $moment,
			'durationSeconds' => min(self::MAX_DURATION, max(0, $duration)),
			'openCases' => $openCases,
			'inPipelinq' => [],
			'mirroredAt' => gmdate('Y-m-d\TH:i:s\Z', $now),
		];
	}//end build()

	/**
	 * A value when it is a string matching the pattern, else ''.
	 *
	 * @param mixed $value What the event carried.
	 * @param string $pattern The pattern.
	 *
	 * @return string The value, or ''.
	 */
	private function matching(mixed $value, string $pattern): string {
		if (is_string($value) === true && preg_match($pattern, $value) === 1) {
			return $value;
		}

		return '';
	}//end matching()

	/**
	 * One line of text from outside: no control characters, at most what the schema takes.
	 *
	 * @param mixed $value What the event carried.
	 *
	 * @return string The text, or '' when it was not text.
	 */
	private function text(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return mb_substr(trim((string)preg_replace('/\p{C}+/u', ' ', $value)), 0, self::MAX_TEXT);
	}//end text()
}//end class
