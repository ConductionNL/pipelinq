<?php

/**
 * Pipelinq EmptyVariableCounter.
 *
 * Counts, for each variable a mail template uses, the recipients the send
 * would give no value for, so the preview can say so before anything is sent.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/marketing-mail-templates/spec.md#REQ-MMT-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

use OCA\Pipelinq\Kernel\Contracts\BlastServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ComplianceServiceInterface;

/**
 * Counts the recipients without a value for each variable a template uses.
 *
 * The values are read the way `MailTransportService::buildRenderedMail()`
 * fills them at send: `email`, `contactId`, `unsubscribe_link` and
 * `preferredName`. A variable the send does not fill reaches every recipient
 * without a value, so it counts every recipient. The `{{articles}}` marker is
 * a block, not a recipient's value, and is left out.
 *
 * @spec openspec/specs/marketing-mail-templates/spec.md#REQ-MMT-002
 */
class EmptyVariableCounter {
	/**
	 * The template fields a variable can appear in.
	 *
	 * @var array<int, string>
	 */
	private const FIELDS = ['subject', 'bodyHtml', 'bodyText'];

	/**
	 * Markers in a template that are not a recipient's value.
	 *
	 * @var array<int, string>
	 */
	private const NOT_RECIPIENT_VALUES = ['articles'];

	/**
	 * The recipient row field each variable the send fills is read from.
	 *
	 * `preferredName` is not a row field: it is read through the name reader.
	 *
	 * @var array<string, string>
	 */
	private const ROW_FIELDS = [
		'email' => 'email',
		'contactId' => 'contactId',
		'unsubscribe_link' => 'unsubscribeUrl',
	];

	/**
	 * Constructor.
	 *
	 * @param ComplianceServiceInterface $templates      Reads the template.
	 * @param BlastServiceInterface      $audiences      Resolves an audience the way the send does.
	 * @param RecipientNameReader        $recipientNames Reads the name a recipient is greeted by.
	 */
	public function __construct(
		private readonly ComplianceServiceInterface $templates,
		private readonly BlastServiceInterface $audiences,
		private readonly RecipientNameReader $recipientNames,
	) {
	}//end __construct()

	/**
	 * For a template and an audience, the recipients and the variables they lack.
	 *
	 * The audience is a segment or a mailing list, gated for consent as the
	 * send gates it, on the template's channel. Without either there are no
	 * recipients and so no gaps.
	 *
	 * @param string $templateId Template UUID or slug.
	 * @param string $segmentId  Segment UUID or slug, or ''.
	 * @param string $listId     MailingList UUID or slug, or ''.
	 *
	 * @return array{recipients: int, variables: array<int, string>, gaps: array<int, array{variable: string, count: int}>}|null
	 *         The report, or null for an unknown template.
	 *
	 * @spec openspec/specs/marketing-mail-templates/spec.md#REQ-MMT-002
	 */
	public function report(string $templateId, string $segmentId, string $listId): ?array {
		$template = $this->templates->getTemplateById(templateId: $templateId);
		if ($template === null) {
			return null;
		}

		$recipients = $this->audiences->audienceRecipients(
			segmentId: $segmentId,
			listId: $listId,
			channel: (string)($template['channel'] ?? 'email'),
		);

		return [
			'recipients' => count($recipients),
			'variables' => $this->usedVariables(template: $template),
			'gaps' => $this->count(template: $template, recipients: $recipients),
		];
	}//end report()

	/**
	 * The variables a template uses, in the order they first appear.
	 *
	 * Both `{{name}}` and `{{{name}}}` count.
	 *
	 * @param array<string, mixed> $template The template row.
	 *
	 * @return array<int, string> The variable names.
	 *
	 * @spec openspec/specs/marketing-mail-templates/spec.md#REQ-MMT-002
	 */
	public function usedVariables(array $template): array {
		$text = '';
		foreach (self::FIELDS as $field) {
			$text .= "\n".(string)($template[$field] ?? '');
		}

		preg_match_all('/\{\{\{?\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}?\}\}/', $text, $matches);

		return array_values(
			array_diff(array_unique($matches[1]), self::NOT_RECIPIENT_VALUES)
		);
	}//end usedVariables()

	/**
	 * For each used variable, how many recipients have no value for it.
	 *
	 * Variables every recipient has a value for are left out, so an empty
	 * list means nothing is missing.
	 *
	 * @param array<string, mixed>             $template   The template row.
	 * @param array<int, array<string, mixed>> $recipients The recipient rows the send would queue.
	 *
	 * @return array<int, array{variable: string, count: int}> The gaps, in template order.
	 *
	 * @spec openspec/specs/marketing-mail-templates/spec.md#REQ-MMT-002
	 */
	public function count(array $template, array $recipients): array {
		$gaps = [];
		foreach ($this->usedVariables(template: $template) as $variable) {
			$missing = 0;
			foreach ($recipients as $recipient) {
				if ($this->valueOf(variable: $variable, recipient: $recipient) === '') {
					$missing++;
				}
			}

			if ($missing > 0) {
				$gaps[] = ['variable' => $variable, 'count' => $missing];
			}
		}

		return $gaps;
	}//end count()

	/**
	 * The value the send gives one recipient for one variable.
	 *
	 * @param string               $variable  The variable name.
	 * @param array<string, mixed> $recipient The recipient row.
	 *
	 * @return string The trimmed value, or '' when the send fills none.
	 */
	private function valueOf(string $variable, array $recipient): string {
		if ($variable === 'preferredName') {
			return trim($this->recipientNames->greetingName(recipientId: (string)($recipient['contactId'] ?? '')));
		}

		if (isset(self::ROW_FIELDS[$variable]) === false) {
			return '';
		}

		return trim((string)($recipient[self::ROW_FIELDS[$variable]] ?? ''));
	}//end valueOf()
}//end class
