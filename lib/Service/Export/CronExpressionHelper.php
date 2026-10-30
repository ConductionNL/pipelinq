<?php

/**
 * Pipelinq CronExpressionHelper.
 *
 * Lightweight 5-field UNIX cron parser/validator with shortcut support and a
 * Dutch/English human-readable preview, used to validate export-job schedules
 * server-side (the source of truth — the frontend preview is convenience only).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Export
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Export;

use DateTimeImmutable;

/**
 * Validates and describes 5-field UNIX cron expressions and @-shortcuts.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) A complete cron field parser
 *  (ranges, lists, steps, shortcuts, dom/dow OR-semantics) is inherently
 *  branchy; the surface is small and fully unit-tested.
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)     See class note — per-field
 *  matching and the human-readable preview enumerate many small cases.
 * @SuppressWarnings(PHPMD.NPathComplexity)          See class note.
 * @SuppressWarnings(PHPMD.ElseExpression)           The else mirrors the cron range/step
 *  parser's two symmetric branches and reads clearer than an early return here.
 *
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-002
 */
class CronExpressionHelper {
	/**
	 * Supported @-shortcuts mapped to their 5-field equivalents.
	 *
	 * @var array<string, string>
	 */
	private const SHORTCUTS = [
		'@hourly' => '0 * * * *',
		'@daily' => '0 0 * * *',
		'@weekly' => '0 0 * * 0',
		'@monthly' => '0 0 1 * *',
		'@yearly' => '0 0 1 1 *',
	];

	/**
	 * Inclusive value bounds per cron field (minute, hour, dom, month, dow).
	 *
	 * @var array<int, array{0: int, 1: int}>
	 */
	private const BOUNDS = [
		[0, 59],
		[0, 23],
		[1, 31],
		[1, 12],
		[0, 7],
	];

	/**
	 * Whether a cron expression (5-field or @-shortcut) is syntactically valid.
	 *
	 * @param string $expression The cron expression.
	 *
	 * @return bool True when valid.
	 *
	 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-002
	 */
	public function isValid(string $expression): bool {
		$expression = trim($expression);
		if ($expression === '') {
			return false;
		}

		if (isset(self::SHORTCUTS[strtolower($expression)]) === true) {
			return true;
		}

		$fields = preg_split('/\s+/', $expression);
		if ($fields === false || count($fields) !== 5) {
			return false;
		}

		foreach ($fields as $index => $field) {
			if ($this->isFieldValid(field: $field, bounds: self::BOUNDS[$index]) === false) {
				return false;
			}
		}

		return true;
	}//end isValid()

	/**
	 * Normalise an expression to its canonical 5-field form (shortcuts expanded).
	 *
	 * @param string $expression The cron expression.
	 *
	 * @return string The 5-field expression, or the trimmed input when unknown.
	 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#requirement-destination-configuration-and-validation-req-bie-001
	 */
	public function normalize(string $expression): string {
		$expression = trim($expression);
		$lower = strtolower($expression);
		if (isset(self::SHORTCUTS[$lower]) === true) {
			return self::SHORTCUTS[$lower];
		}

		return $expression;
	}//end normalize()

	/**
	 * Build a short human-readable preview of a cron expression.
	 *
	 * Recognises the common "every day at HH:MM" / "every hour" / "every week"
	 * shapes; falls back to echoing the canonical expression for complex ones.
	 *
	 * @param string $expression The cron expression.
	 * @param string $locale 'nl' or 'en' (defaults to English).
	 *
	 * @return string The human-readable description.
	 *
	 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-002
	 */
	public function describe(string $expression, string $locale = 'en'): string {
		if ($this->isValid(expression: $expression) === false) {
			return '';
		}

		$isDutch = ($locale === 'nl');
		$normalized = $this->normalize(expression: $expression);
		$fields = preg_split('/\s+/', $normalized);
		if ($fields === false || count($fields) !== 5) {
			return $normalized;
		}

		[$minute, $hour, $dom, $month, $dow] = $fields;

		// Every hour: "0 * * * *".
		if ($minute === '0' && $hour === '*' && $dom === '*' && $month === '*' && $dow === '*') {
			if ($isDutch === true) {
				return 'Elk uur';
			}

			return 'Every hour';
		}

		// Fixed minute + hour, every day: "M H * * *".
		if (ctype_digit($minute) === true && ctype_digit($hour) === true
			&& $dom === '*' && $month === '*' && $dow === '*'
		) {
			$time = sprintf('%02d:%02d', (int)$hour, (int)$minute);
			if ($isDutch === true) {
				return "Elke dag om {$time}";
			}

			return "Every day at {$time}";
		}

		// Weekly on Sunday: "M H * * 0".
		if (ctype_digit($minute) === true && ctype_digit($hour) === true
			&& $dom === '*' && $month === '*' && ($dow === '0' || $dow === '7')
		) {
			$time = sprintf('%02d:%02d', (int)$hour, (int)$minute);
			if ($isDutch === true) {
				return "Elke zondag om {$time}";
			}

			return "Every Sunday at {$time}";
		}

		return $normalized;
	}//end describe()

	/**
	 * Whether a cron expression matches a given minute.
	 *
	 * Evaluates all five fields against the supplied time (truncated to the
	 * minute). Day-of-month and day-of-week use cron's OR semantics only when
	 * both are restricted; otherwise the restricted field applies.
	 *
	 * @param string $expression The cron expression.
	 * @param \DateTimeInterface $when The instant to test.
	 *
	 * @return bool True when the schedule fires at this minute.
	 *
	 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-004-01
	 */
	public function isDue(string $expression, \DateTimeInterface $when): bool {
		$fields = $this->fieldsOf(expression: $expression);
		if ($fields === null) {
			return false;
		}

		return ($this->dayMatches(fields: $fields, when: $when) === true
			&& $this->fieldMatches(field: $fields[1], value: (int)$when->format('G'), bounds: self::BOUNDS[1]) === true
			&& $this->fieldMatches(field: $fields[0], value: (int)$when->format('i'), bounds: self::BOUNDS[0]) === true);
	}//end isDue()

	/**
	 * The first minute after a moment at which a cron expression fires.
	 *
	 * Parses the expression once and steps a day at a time while the day does
	 * not match, an hour at a time while the hour does not, then by the minute.
	 *
	 * @param string $expression The cron expression.
	 * @param \DateTimeInterface $from The moment after which to look.
	 * @param int $horizonDays How far ahead to look.
	 *
	 * @return DateTimeImmutable|null The next firing minute, or null when the
	 *                                  expression is invalid or never fires in the horizon.
	 *
	 * @spec openspec/specs/bi-export-board/spec.md#requirement-a-job-shows-its-last-run-and-its-next-run
	 */
	public function nextRunAfter(string $expression, \DateTimeInterface $from, int $horizonDays=400): ?DateTimeImmutable {
		$fields = $this->fieldsOf(expression: $expression);
		if ($fields === null) {
			return null;
		}

		$start = (new DateTimeImmutable('now', $from->getTimezone()))->setTimestamp($from->getTimestamp());
		$when  = $start->setTime((int)$start->format('G'), (int)$start->format('i'))->modify('+1 minute');
		$limit = $start->modify('+'.$horizonDays.' days');

		while ($when <= $limit) {
			if ($this->dayMatches(fields: $fields, when: $when) === false) {
				$when = $when->modify('+1 day')->setTime(0, 0);
				continue;
			}

			if ($this->fieldMatches(field: $fields[1], value: (int)$when->format('G'), bounds: self::BOUNDS[1]) === false) {
				$when = $when->setTime((int)$when->format('G'), 0)->modify('+1 hour');
				continue;
			}

			if ($this->fieldMatches(field: $fields[0], value: (int)$when->format('i'), bounds: self::BOUNDS[0]) === true) {
				return $when;
			}

			$when = $when->modify('+1 minute');
		}

		return null;
	}//end nextRunAfter()

	/**
	 * The five fields of a valid expression, or null.
	 *
	 * @param string $expression The cron expression.
	 *
	 * @return array<int, string>|null Minute, hour, day of month, month, day of week.
	 */
	private function fieldsOf(string $expression): ?array {
		if ($this->isValid(expression: $expression) === false) {
			return null;
		}

		$fields = preg_split('/\s+/', $this->normalize(expression: $expression));
		if ($fields === false || count($fields) !== 5) {
			return null;
		}

		return $fields;
	}//end fieldsOf()

	/**
	 * Whether the month and the day fields match a date.
	 *
	 * Day of month and day of week use cron's OR semantics only when both are
	 * restricted; otherwise the restricted field applies.
	 *
	 * @param array<int, string> $fields The five fields.
	 * @param \DateTimeInterface $when The date.
	 *
	 * @return bool True when the date matches.
	 */
	private function dayMatches(array $fields, \DateTimeInterface $when): bool {
		[, , $dom, $month, $dow] = $fields;

		if ($this->fieldMatches(field: $month, value: (int)$when->format('n'), bounds: self::BOUNDS[3]) === false) {
			return false;
		}

		$domOk = $this->fieldMatches(field: $dom, value: (int)$when->format('j'), bounds: self::BOUNDS[2]);
		$dowOk = $this->dowMatches(field: $dow, value: (int)$when->format('w'));

		if ($dom !== '*' && $dow !== '*') {
			return ($domOk === true || $dowOk === true);
		}

		return ($domOk === true && $dowOk === true);
	}//end dayMatches()

	/**
	 * Whether a single value matches a cron field.
	 *
	 * @param string $field The field text.
	 * @param int $value The value to test.
	 * @param array{0: int, 1: int} $bounds The inclusive bounds.
	 *
	 * @return bool True when the value matches.
	 */
	private function fieldMatches(string $field, int $value, array $bounds): bool {
		if ($field === '*') {
			return true;
		}

		foreach (explode(',', $field) as $part) {
			if ($this->partMatches(part: $part, value: $value, bounds: $bounds) === true) {
				return true;
			}
		}

		return false;
	}//end fieldMatches()

	/**
	 * Day-of-week match treating both 0 and 7 as Sunday.
	 *
	 * @param string $field The dow field.
	 * @param int $value The PHP day-of-week (0=Sunday).
	 *
	 * @return bool True when the day matches.
	 */
	private function dowMatches(string $field, int $value): bool {
		if ($this->fieldMatches(field: $field, value: $value, bounds: self::BOUNDS[4]) === true) {
			return true;
		}

		// Sunday is both 0 and 7 in cron.
		if ($value === 0) {
			return $this->fieldMatches(field: $field, value: 7, bounds: self::BOUNDS[4]);
		}

		return false;
	}//end dowMatches()

	/**
	 * Whether a value matches a single field part (handles * /step, ranges).
	 *
	 * @param string $part The field part.
	 * @param int $value The value to test.
	 * @param array{0: int, 1: int} $bounds The inclusive bounds.
	 *
	 * @return bool True when the value matches.
	 */
	private function partMatches(string $part, int $value, array $bounds): bool {
		$step = 1;
		if (str_contains($part, '/') === true) {
			[$range, $stepText] = explode('/', $part, 2);
			if (ctype_digit($stepText) === false || (int)$stepText < 1) {
				return false;
			}

			$step = (int)$stepText;
			$part = $range;
		}

		$low = $bounds[0];
		$high = $bounds[1];
		if ($part !== '*') {
			if (str_contains($part, '-') === true) {
				[$lowText, $highText] = explode('-', $part, 2);
				if (ctype_digit($lowText) === false || ctype_digit($highText) === false) {
					return false;
				}

				$low = (int)$lowText;
				$high = (int)$highText;
			} else {
				if (ctype_digit($part) === false) {
					return false;
				}

				$low = (int)$part;
				$high = (int)$part;
			}
		}

		if ($value < $low || $value > $high) {
			return false;
		}

		return ((($value - $low) % $step) === 0);
	}//end partMatches()

	/**
	 * Validate one cron field against inclusive bounds.
	 *
	 * Accepts a wildcard, a step suffix, comma lists, ranges `a-b`, and single
	 * integers within bounds.
	 *
	 * @param string $field The field text.
	 * @param array{0: int, 1: int} $bounds The inclusive [min, max] bounds.
	 *
	 * @return bool True when the field is valid.
	 */
	private function isFieldValid(string $field, array $bounds): bool {
		if ($field === '*') {
			return true;
		}

		foreach (explode(',', $field) as $part) {
			if ($this->isFieldPartValid(part: $part, bounds: $bounds) === false) {
				return false;
			}
		}

		return true;
	}//end isFieldValid()

	/**
	 * Validate a single comma-separated cron field part.
	 *
	 * @param string $part The field part.
	 * @param array{0: int, 1: int} $bounds The inclusive bounds.
	 *
	 * @return bool True when valid.
	 */
	private function isFieldPartValid(string $part, array $bounds): bool {
		$step = 1;
		if (str_contains($part, '/') === true) {
			[$range, $stepText] = explode('/', $part, 2);
			if (ctype_digit($stepText) === false || (int)$stepText < 1) {
				return false;
			}

			$step = (int)$stepText;
			$part = $range;
		}

		if ($part === '*') {
			return $step >= 1;
		}

		if (str_contains($part, '-') === true) {
			[$lowText, $highText] = explode('-', $part, 2);
			if (ctype_digit($lowText) === false || ctype_digit($highText) === false) {
				return false;
			}

			$low = (int)$lowText;
			$high = (int)$highText;
			return $low >= $bounds[0] && $high <= $bounds[1] && $low <= $high;
		}

		if (ctype_digit($part) === false) {
			return false;
		}

		$value = (int)$part;
		return $value >= $bounds[0] && $value <= $bounds[1];
	}//end isFieldPartValid()
}//end class
