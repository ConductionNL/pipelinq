<?php

/**
 * Reads a Nextcloud Forms form's questions and a submission's answers, in the field vocabulary OpenRegister judges.
 *
 * 🔴 IT NAMES NO FORMS CLASS. Nextcloud Forms is optional: every mapper is
 * reached through the container by its class name as a string, and each
 * entity is read with duck typing, so an instance without Forms boots as
 * before and this class answers "not available".
 *
 * The question types are Forms' own (`lib/Constants.php`: short, long,
 * multiple, multiple_unique, dropdown, date, datetime, time, file,
 * linearscale, color, grid, ranking). Each becomes the field type
 * OpenRegister's FormFieldRules knows, so the binding is checked against
 * what the question can really produce. A type with no counterpart is left
 * empty, which the validator does not judge rather than guess.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\WebForm
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/public-intake-forms/spec.md#requirement-a-nextcloud-forms-form-feeds-pipelinq-only-through-a-checked-binding
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\WebForm;

use DateTimeInterface;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Nextcloud Forms questions and answers, by duck typing.
 *
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/public-intake-forms/spec.md#requirement-a-nextcloud-forms-form-feeds-pipelinq-only-through-a-checked-binding
 */
class FormsQuestionReader {

	/**
	 * Forms' question mapper.
	 *
	 * @var string
	 */
	public const QUESTION_MAPPER = 'OCA\Forms\Db\QuestionMapper';

	/**
	 * Forms' option mapper.
	 *
	 * @var string
	 */
	public const OPTION_MAPPER = 'OCA\Forms\Db\OptionMapper';

	/**
	 * Forms' answer mapper.
	 *
	 * @var string
	 */
	public const ANSWER_MAPPER = 'OCA\Forms\Db\AnswerMapper';

	/**
	 * Forms question type to the field type OpenRegister's FormFieldRules judges.
	 *
	 * @var array<string, string>
	 */
	private const FIELD_TYPES = [
		'short' => 'text',
		'long' => 'textarea',
		'multiple' => 'multichoice',
		'multiple_unique' => 'radio',
		'dropdown' => 'select',
		'date' => 'date',
		'datetime' => 'datetime',
		'time' => 'time',
		'file' => 'files',
		'linearscale' => 'integer',
		'color' => 'text',
	];

	/**
	 * A short question's validation type to the field type it produces.
	 *
	 * @var array<string, string>
	 */
	private const SHORT_TYPES = [
		'email' => 'email',
		'phone' => 'tel',
		'number' => 'number',
	];

	/**
	 * Question types whose answers are a list, one row per chosen option.
	 *
	 * @var array<int, string>
	 */
	private const LIST_TYPES = ['multiple', 'file', 'grid', 'ranking'];

	/**
	 * Question types offering fixed options.
	 *
	 * @var array<int, string>
	 */
	private const OPTION_TYPES = ['multiple', 'multiple_unique', 'dropdown'];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Reaches Forms' mappers when Forms is installed.
	 *
	 * @spec exclude constructor wiring only; it makes no decision a requirement can describe
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {

	}//end __construct()

	/**
	 * Whether Nextcloud Forms is installed.
	 *
	 * @return bool True when its question mapper resolves.
	 *
	 * @spec openspec/changes/website-forms-submit-into-tickets/specs/public-intake-forms/spec.md#requirement-a-nextcloud-forms-form-feeds-pipelinq-only-through-a-checked-binding
	 */
	public function available(): bool {
		return $this->container->has(self::QUESTION_MAPPER);

	}//end available()

	/**
	 * A form's questions as field definitions, keyed by question id.
	 *
	 * @param int $formId The Forms form id.
	 *
	 * @return array<string, array<string, mixed>> Question id to `{ type, text, required, options? }`.
	 *
	 * @spec openspec/changes/website-forms-submit-into-tickets/specs/public-intake-forms/spec.md#requirement-a-nextcloud-forms-form-feeds-pipelinq-only-through-a-checked-binding
	 */
	public function questions(int $formId): array {
		$questions = [];
		foreach ((array)$this->container->get(self::QUESTION_MAPPER)->findByForm($formId) as $question) {
			$id = (string)$this->read(subject: $question, key: 'id');
			$formsType = (string)$this->read(subject: $question, key: 'type');
			$field = [
				'type' => $this->fieldType(formsType: $formsType, extra: (array)($this->read(subject: $question, key: 'extraSettings') ?? [])),
				'text' => (string)$this->read(subject: $question, key: 'text'),
				'required' => (bool)$this->read(subject: $question, key: 'isRequired'),
			];
			if (in_array($formsType, self::OPTION_TYPES, true) === true) {
				$field['options'] = $this->options(questionId: (int)$id);
			}

			$questions[$id] = $field;
		}

		return $questions;

	}//end questions()

	/**
	 * A submission's answers, question id to value.
	 *
	 * Forms stores one row per answer, so a question with several chosen
	 * options arrives as several rows and becomes a list. Dates come in Forms'
	 * own formats (`Y-m-d H:i`, `H:i`) and leave in the ones a schema's
	 * `date-time` and `time` formats accept.
	 *
	 * @param int                                 $submissionId The Forms submission id.
	 * @param array<string, array<string, mixed>> $questions    The form's questions, from questions().
	 *
	 * @return array<string, mixed> Question id to answer.
	 *
	 * @spec openspec/changes/website-forms-submit-into-tickets/specs/public-intake-forms/spec.md#requirement-a-nextcloud-forms-form-feeds-pipelinq-only-through-a-checked-binding
	 */
	public function answers(int $submissionId, array $questions): array {
		$answers = [];
		foreach ((array)$this->container->get(self::ANSWER_MAPPER)->findBySubmission($submissionId) as $answer) {
			$questionId = (string)$this->read(subject: $answer, key: 'questionId');
			if ($questionId === '' || isset($questions[$questionId]) === false) {
				continue;
			}

			$text = (string)$this->read(subject: $answer, key: 'text');
			$type = (string)($questions[$questionId]['type'] ?? '');
			if (in_array($type, ['multichoice', 'files'], true) === true) {
				$answers[$questionId][] = $text;
				continue;
			}

			$answers[$questionId] = $this->normalise(type: $type, text: $text);
		}

		return $answers;

	}//end answers()

	/**
	 * The field type a Forms question produces.
	 *
	 * @param string               $formsType The Forms question type.
	 * @param array<string, mixed> $extra     The question's extra settings.
	 *
	 * @return string The field type, or an empty string when there is no counterpart.
	 */
	private function fieldType(string $formsType, array $extra): string {
		if ($formsType === 'short') {
			return (self::SHORT_TYPES[(string)($extra['validationType'] ?? '')] ?? 'text');
		}

		if (in_array($formsType, self::LIST_TYPES, true) === true && isset(self::FIELD_TYPES[$formsType]) === false) {
			return 'array';
		}

		return (self::FIELD_TYPES[$formsType] ?? '');

	}//end fieldType()

	/**
	 * A question's option texts.
	 *
	 * @param int $questionId The question id.
	 *
	 * @return array<int, string> The option texts.
	 */
	private function options(int $questionId): array {
		if ($this->container->has(self::OPTION_MAPPER) === false) {
			return [];
		}

		$texts = [];
		try {
			foreach ((array)$this->container->get(self::OPTION_MAPPER)->findByQuestion($questionId) as $option) {
				$texts[] = (string)$this->read(subject: $option, key: 'text');
			}
		} catch (Throwable) {
			return [];
		}

		return $texts;

	}//end options()

	/**
	 * One answer in the format the destination schema accepts.
	 *
	 * @param string $type The field type.
	 * @param string $text The stored answer.
	 *
	 * @return mixed The value.
	 */
	private function normalise(string $type, string $text): mixed {
		if ($type === 'datetime') {
			$moment = date_create_immutable_from_format('Y-m-d H:i', $text);
			if ($moment !== false) {
				return $moment->format(DateTimeInterface::ATOM);
			}
		}

		if ($type === 'time' && preg_match('/^\d{2}:\d{2}$/', $text) === 1) {
			return $text . ':00';
		}

		if (in_array($type, ['integer', 'number'], true) === true && is_numeric($text) === true) {
			if ($type === 'integer') {
				return (int)$text;
			}

			return (float)$text;
		}

		return $text;

	}//end normalise()

	/**
	 * Read one field off an entity, array or getter.
	 *
	 * @param mixed  $subject The thing to read.
	 * @param string $key     The field name.
	 *
	 * @return mixed The value, or null.
	 */
	private function read(mixed $subject, string $key): mixed {
		if (is_array($subject) === true) {
			return ($subject[$key] ?? null);
		}

		if (is_object($subject) === false) {
			return null;
		}

		// Nextcloud entities answer getters through __call, which method_exists
		// cannot see; is_callable can.
		$getter = 'get' . ucfirst($key);
		if (is_callable([$subject, $getter]) === true) {
			return $subject->$getter();
		}

		return null;

	}//end read()
}//end class
