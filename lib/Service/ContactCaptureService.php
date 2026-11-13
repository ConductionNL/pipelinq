<?php

/**
 * Pipelinq contact capture.
 *
 * Turns pasted text (an email signature, the text of a business card) into
 * proposed contact fields. Rules first, for what a rule cannot get wrong in a
 * way a person would miss: email addresses, phone numbers (through
 * PhoneNormaliser, so they come out as CTI expects them), websites, a KvK
 * number labelled as one, and a Dutch postcode with its city. Then hermiq,
 * when it is installed, labels the lines that are left as name, organisation
 * or job title; without hermiq those lines are handed back unassigned, for the
 * person to place (D1).
 *
 * Nothing here writes. The result is a proposal the create form is filled
 * with; only the person's Save stores a contact (D3).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#requirement-pasted-text-becomes-proposed-contact-fields-req-ccc-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;


/**
 * Proposes contact fields from free text.
 *
 * @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#requirement-pasted-text-becomes-proposed-contact-fields-req-ccc-001
 */
class ContactCaptureService {

	/**
	 * The longest text read; anything beyond is not a signature.
	 *
	 * @var int
	 */
	public const MAX_LENGTH = 5000;

	private const EMAIL = '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i';

	private const URL = '/\b(?:https?:\/\/[^\s]+|www\.[^\s]+\.[a-z]{2,}[^\s]*)/i';

	private const KVK = '/\bk\.?\s?v\.?\s?k\.?(?:[\s\-]?(?:nr|nummer|number|no))?\.?\s*:?\s*(\d{8})\b/i';

	private const POSTCODE = '/\b(\d{4})\s?([A-Z]{2})\b[\s,]+([A-Za-z\x{00C0}-\x{024F}][A-Za-z\x{00C0}-\x{024F}\'\-\s]*)$/u';

	private const PHONE_LABEL = '/^(?:telefoon|mobile|mobiel|phone|direct|mob|tel|gsm|t|m)\b\.?\s*[:.]?\s*/i';

	private const STREET = '/^[A-Za-z\x{00C0}-\x{024F}][A-Za-z\x{00C0}-\x{024F}\'.\-\s]+\s\d+[A-Za-z]?(?:[\s\-]+\w{1,4})?$/u';

	private const NOISE = '/^(?:e|email|e-mail|mail|w|web|website|internet)\s*[:.]?\s*/i';

	/**
	 * Constructor.
	 *
	 * @param PhoneNormaliser      $phones   Phone numbers in international format.
	 * @param ContactLineLabeller  $labeller Hermiq's labels for the lines no rule placed.
	 *
	 * @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#requirement-pasted-text-becomes-proposed-contact-fields-req-ccc-001
	 */
	public function __construct(
		private readonly PhoneNormaliser $phones,
		private readonly ContactLineLabeller $labeller,
	) {
	}//end __construct()

	/**
	 * Propose contact fields for a text.
	 *
	 * @param string      $text   The pasted text.
	 * @param string|null $userId The user the model is asked for, when hermiq answers.
	 *
	 * @return array{fields: array<string, string>, emails: list<string>, phones: list<string>, unassigned: list<string>, labelledBy: string}
	 *         The proposed fields, every email and phone found, the lines left for the person, and whether hermiq labelled any.
	 *
	 * @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#requirement-pasted-text-becomes-proposed-contact-fields-req-ccc-001
	 */
	public function parse(string $text, ?string $userId = null): array {
		$run = ['fields' => [], 'emails' => [], 'phones' => [], 'left' => []];
		foreach ($this->lines(text: $text) as $line) {
			$this->applyRules(run: $run, line: $line);
		}

		$this->placeStreet(run: $run);
		$labelledBy = 'rules';
		$left       = $run['left'];
		$labels     = $this->labeller->labels(lines: $left, userId: $userId);
		foreach ($labels as $field => $line) {
			if (isset($run['fields'][$field]) === false) {
				$run['fields'][$field] = $line;
				$left = array_values(array_filter($left, static fn (string $l): bool => $l !== $line));
				$labelledBy = 'hermiq';
			}
		}

		return [
			'fields'     => $run['fields'],
			'emails'     => $run['emails'],
			'phones'     => $run['phones'],
			'unassigned' => $left,
			'labelledBy' => $labelledBy,
		];
	}//end parse()

	/**
	 * The text's non-empty lines, without signature separators, at most MAX_LENGTH characters read.
	 *
	 * @param string $text The text.
	 *
	 * @return list<string> The lines.
	 */
	private function lines(string $text): array {
		$text  = mb_substr($text, 0, self::MAX_LENGTH, 'UTF-8');
		$lines = [];
		$split = preg_split('/\R|\s+\|\s+/u', $text);
		if ($split === false) {
			$split = [];
		}

		foreach ($split as $line) {
			$line = trim((string) preg_replace('/\s+/u', ' ', $line));
			if ($line === '' || preg_match('/^[\-_=*~.]{2,}$/', $line) === 1) {
				continue;
			}

			$lines[] = $line;
		}

		return $lines;
	}//end lines()

	/**
	 * Apply the rules to one line; a line no rule consumes is left for labelling.
	 *
	 * @param array<string, mixed> $run  The proposal so far, by reference.
	 * @param string               $line The line.
	 *
	 * @return void
	 */
	private function applyRules(array &$run, string $line): void {
		$rest = $this->takeEmails(run: $run, line: $line);
		$rest = $this->takeUrl(run: $run, line: $rest);
		$rest = $this->takeKvk(run: $run, line: $rest);
		if ($this->takePostcode(run: $run, line: $rest) === true || $this->takePhone(run: $run, line: $rest) === true) {
			return;
		}

		$rest = trim((string) preg_replace(self::NOISE, '', $rest));
		$rest = trim($rest, " \t,;|");
		if ($rest !== '') {
			$run['left'][] = $rest;
		}
	}//end applyRules()

	/**
	 * Take every email address off a line.
	 *
	 * @param array<string, mixed> $run  The proposal, by reference.
	 * @param string               $line The line.
	 *
	 * @return string What is left of the line.
	 */
	private function takeEmails(array &$run, string $line): string {
		if (preg_match_all(self::EMAIL, $line, $matches) < 1) {
			return $line;
		}

		foreach ($matches[0] as $email) {
			$email = strtolower(rtrim($email, '.'));
			if (in_array($email, $run['emails'], true) === false) {
				$run['emails'][] = $email;
			}
		}

		$run['fields']['email'] = ($run['fields']['email'] ?? $run['emails'][0]);

		return trim((string) preg_replace(self::EMAIL, '', $line));
	}//end takeEmails()

	/**
	 * Take a website off a line.
	 *
	 * @param array<string, mixed> $run  The proposal, by reference.
	 * @param string               $line The line.
	 *
	 * @return string What is left of the line.
	 */
	private function takeUrl(array &$run, string $line): string {
		if (preg_match(self::URL, $line, $match) !== 1) {
			return $line;
		}

		$url = rtrim($match[0], '.,;)');
		if (preg_match('/^https?:\/\//i', $url) !== 1) {
			$url = 'https://' . $url;
		}

		$run['fields']['website'] = ($run['fields']['website'] ?? $url);

		return trim(str_replace($match[0], '', $line));
	}//end takeUrl()

	/**
	 * Take a KvK number labelled as one off a line.
	 *
	 * @param array<string, mixed> $run  The proposal, by reference.
	 * @param string               $line The line.
	 *
	 * @return string What is left of the line.
	 */
	private function takeKvk(array &$run, string $line): string {
		if (preg_match(self::KVK, $line, $match) !== 1) {
			return $line;
		}

		$run['fields']['kvkNumber'] = ($run['fields']['kvkNumber'] ?? $match[1]);

		return trim(str_replace($match[0], '', $line));
	}//end takeKvk()

	/**
	 * Take a Dutch postcode with its city.
	 *
	 * @param array<string, mixed> $run  The proposal, by reference.
	 * @param string               $line The line.
	 *
	 * @return bool Whether the line was a postcode line.
	 */
	private function takePostcode(array &$run, string $line): bool {
		if (preg_match(self::POSTCODE, $line, $match) !== 1 || isset($run['fields']['postalCode']) === true) {
			return false;
		}

		$run['fields']['postalCode'] = $match[1] . ' ' . strtoupper($match[2]);
		$run['fields']['city']       = trim($match[3]);
		$before = trim(substr($line, 0, (int) strpos($line, $match[0])), " \t,");
		if ($before !== '') {
			$run['left'][] = $before;
		}

		$run['postcodeAt'] = count($run['left']);

		return true;
	}//end takePostcode()

	/**
	 * Take a phone number: a labelled one, or a line that is nothing but a number.
	 *
	 * @param array<string, mixed> $run  The proposal, by reference.
	 * @param string               $line The line.
	 *
	 * @return bool Whether the line was a phone line.
	 */
	private function takePhone(array &$run, string $line): bool {
		$labelled = preg_match(self::PHONE_LABEL, $line) === 1;
		$number   = trim((string) preg_replace(self::PHONE_LABEL, '', $line));
		$digits   = strlen((string) preg_replace('/\D/', '', $number));
		if (preg_match('/^\+?[\d\s()\-.\/]+$/', $number) !== 1 || $digits < 9 || $digits > 15) {
			return false;
		}

		if ($labelled === false && preg_match('/^(?:\+|0)/', $number) !== 1) {
			return false;
		}

		$e164  = $this->phones->normaliseForOrg(rawNumber: $number)['e164'];
		$phone = ($e164 ?? $number);
		if (in_array($phone, $run['phones'], true) === false) {
			$run['phones'][] = $phone;
		}

		$run['fields']['phone'] = ($run['fields']['phone'] ?? $phone);

		return true;
	}//end takePhone()

	/**
	 * The line just before the postcode line is the street, when it reads as one.
	 *
	 * @param array<string, mixed> $run The proposal, by reference.
	 *
	 * @return void
	 */
	private function placeStreet(array &$run): void {
		$position = ($run['postcodeAt'] ?? null);
		unset($run['postcodeAt']);
		if ($position === null || $position < 1) {
			return;
		}

		$candidate = $run['left'][$position - 1];
		if (preg_match(self::STREET, $candidate) !== 1) {
			return;
		}

		$run['fields']['street'] = $candidate;
		array_splice($run['left'], $position - 1, 1);
	}//end placeStreet()
}//end class
