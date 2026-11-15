<?php

/**
 * Unit tests for FormsQuestionReader.
 *
 * Forms' entities are Nextcloud `Entity` subclasses whose getters answer
 * through `__call`. The fixtures here are real `Entity` subclasses for that
 * reason: a reader that asked `method_exists` would read nothing from them.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\WebForm
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/public-intake-forms/spec.md#requirement-a-nextcloud-forms-form-feeds-pipelinq-only-through-a-checked-binding
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\WebForm;

use OCA\Pipelinq\Service\WebForm\FormsQuestionReader;
use OCA\Pipelinq\Tests\Unit\Service\WebForm\Fakes\FakeContainer;
use OCP\AppFramework\Db\Entity;
use PHPUnit\Framework\TestCase;

/**
 * Questions become field definitions OpenRegister judges; answers become values a schema accepts.
 */
class FormsQuestionReaderTest extends TestCase {

	/**
	 * A Forms-like entity with magic getters.
	 *
	 * @param array<string, mixed> $fields Field to value.
	 *
	 * @return Entity The entity.
	 */
	private static function entity(array $fields): Entity {
		$entity = new class extends Entity {
			/**
			 * Field: type.
			 *
			 * @var string|null
			 */
			protected $type;

			/**
			 * Field: text.
			 *
			 * @var string|null
			 */
			protected $text;

			/**
			 * Field: isRequired.
			 *
			 * @var bool|null
			 */
			protected $isRequired;

			/**
			 * Field: extraSettings.
			 *
			 * @var array|null
			 */
			protected $extraSettings;

			/**
			 * Field: questionId.
			 *
			 * @var int|null
			 */
			protected $questionId;
		};
		foreach ($fields as $name => $value) {
			$entity->{'set' . ucfirst($name)}($value);
		}

		return $entity;
	}//end entity()

	/**
	 * A reader over a form with five questions and one submission.
	 *
	 * @return FormsQuestionReader The reader.
	 */
	private function reader(): FormsQuestionReader {
		$questions = new class {
			/**
			 * The rows.
			 *
			 * @var array<int, Entity>
			 */
			public array $rows = [];

			/**
			 * The form's questions.
			 *
			 * @param int $formId The form.
			 *
			 * @return array<int, Entity>
			 */
			public function findByForm(int $formId): array {
				return $this->rows;
			}
		};
		$questions->rows = [
			self::entity(['id' => 1, 'type' => 'short', 'text' => 'Email', 'isRequired' => true, 'extraSettings' => ['validationType' => 'email']]),
			self::entity(['id' => 2, 'type' => 'long', 'text' => 'Question', 'isRequired' => false, 'extraSettings' => []]),
			self::entity(['id' => 3, 'type' => 'multiple', 'text' => 'Topics', 'isRequired' => false, 'extraSettings' => []]),
			self::entity(['id' => 4, 'type' => 'datetime', 'text' => 'When', 'isRequired' => false, 'extraSettings' => []]),
			self::entity(['id' => 5, 'type' => 'linearscale', 'text' => 'Score', 'isRequired' => false, 'extraSettings' => []]),
		];
		$options = new class {
			/**
			 * The question's options.
			 *
			 * @param int $questionId The question.
			 *
			 * @return array<int, array<string, string>>
			 */
			public function findByQuestion(int $questionId): array {
				return [['text' => 'billing'], ['text' => 'login']];
			}
		};
		$answers = new class {
			/**
			 * The rows.
			 *
			 * @var array<int, Entity>
			 */
			public array $rows = [];

			/**
			 * A submission's answers.
			 *
			 * @param int $submissionId The submission.
			 *
			 * @return array<int, Entity>
			 */
			public function findBySubmission(int $submissionId): array {
				return $this->rows;
			}
		};
		$answers->rows = [
			self::entity(['questionId' => 1, 'text' => 'jane@example.com']),
			self::entity(['questionId' => 3, 'text' => 'billing']),
			self::entity(['questionId' => 3, 'text' => 'login']),
			self::entity(['questionId' => 4, 'text' => '2026-10-11 14:30']),
			self::entity(['questionId' => 5, 'text' => '4']),
			self::entity(['questionId' => 77, 'text' => 'from a deleted question']),
		];

		return new FormsQuestionReader(
			new FakeContainer(
				services: [
					FormsQuestionReader::QUESTION_MAPPER => $questions,
					FormsQuestionReader::OPTION_MAPPER => $options,
					FormsQuestionReader::ANSWER_MAPPER => $answers,
				]
			)
		);
	}//end reader()

	/**
	 * Each Forms question type becomes the field type FormFieldRules judges; choice questions carry their options.
	 *
	 * @return void
	 */
	public function testQuestionsBecomeFieldDefinitions(): void {
		$questions = $this->reader()->questions(formId: 3);

		$this->assertSame(['1', '2', '3', '4', '5'], array_map('strval', array_keys($questions)));
		$this->assertSame('email', $questions['1']['type']);
		$this->assertTrue($questions['1']['required']);
		$this->assertSame('textarea', $questions['2']['type']);
		$this->assertSame('multichoice', $questions['3']['type']);
		$this->assertSame(['billing', 'login'], $questions['3']['options']);
		$this->assertSame('datetime', $questions['4']['type']);
		$this->assertSame('integer', $questions['5']['type']);
		$this->assertArrayNotHasKey('options', $questions['2']);
	}//end testQuestionsBecomeFieldDefinitions()

	/**
	 * Answers are keyed by question; several options become a list, dates and numbers the schema's formats.
	 *
	 * @return void
	 */
	public function testAnswersBecomeSchemaValues(): void {
		$reader = $this->reader();
		$answers = $reader->answers(submissionId: 9, questions: $reader->questions(formId: 3));

		$this->assertSame('jane@example.com', $answers['1']);
		$this->assertSame(['billing', 'login'], $answers['3']);
		$this->assertMatchesRegularExpression('/^2026-10-11T14:30:00[+-]\d{2}:\d{2}$/', $answers['4']);
		$this->assertSame(4, $answers['5']);
		$this->assertArrayNotHasKey('77', $answers, 'An answer to a question no longer on the form is dropped');
	}//end testAnswersBecomeSchemaValues()

	/**
	 * Without Forms installed the reader says so.
	 *
	 * @return void
	 */
	public function testAvailableOnlyWithForms(): void {
		$this->assertTrue($this->reader()->available());
		$this->assertFalse((new FormsQuestionReader(new FakeContainer()))->available());
	}//end testAvailableOnlyWithForms()

	/**
	 * A reader over the given questions, option mapper and answers.
	 *
	 * @param array<int, Entity> $questionRows The questions.
	 * @param object|null        $options      The option mapper, or null for none.
	 * @param array<int, Entity> $answerRows   The answers.
	 *
	 * @return FormsQuestionReader The reader.
	 */
	private static function readerOver(array $questionRows, ?object $options, array $answerRows = []): FormsQuestionReader {
		$questions = new class ($questionRows) {
			/**
			 * Constructor.
			 *
			 * @param array<int, Entity> $rows The rows.
			 */
			public function __construct(private readonly array $rows) {
			}

			/**
			 * The form's questions.
			 *
			 * @param int $formId The form.
			 *
			 * @return array<int, Entity>
			 */
			public function findByForm(int $formId): array {
				return $this->rows;
			}
		};
		$answers = new class ($answerRows) {
			/**
			 * Constructor.
			 *
			 * @param array<int, Entity> $rows The rows.
			 */
			public function __construct(private readonly array $rows) {
			}

			/**
			 * A submission's answers.
			 *
			 * @param int $submissionId The submission.
			 *
			 * @return array<int, Entity>
			 */
			public function findBySubmission(int $submissionId): array {
				return $this->rows;
			}
		};
		$services = [
			FormsQuestionReader::QUESTION_MAPPER => $questions,
			FormsQuestionReader::ANSWER_MAPPER => $answers,
		];
		if ($options !== null) {
			$services[FormsQuestionReader::OPTION_MAPPER] = $options;
		}

		return new FormsQuestionReader(new FakeContainer(services: $services));
	}//end readerOver()

	/**
	 * Short questions follow their validation type; list types without a counterpart become arrays; unknown types none.
	 *
	 * @return void
	 */
	public function testEveryFormsTypeMapsToAFieldType(): void {
		$questions = self::readerOver(
			[
				self::entity(['id' => 1, 'type' => 'short', 'text' => 'Phone', 'extraSettings' => ['validationType' => 'phone']]),
				self::entity(['id' => 2, 'type' => 'short', 'text' => 'Amount', 'extraSettings' => ['validationType' => 'number']]),
				self::entity(['id' => 3, 'type' => 'short', 'text' => 'Code', 'extraSettings' => ['validationType' => 'regex']]),
				self::entity(['id' => 4, 'type' => 'grid', 'text' => 'Grid', 'extraSettings' => []]),
				self::entity(['id' => 5, 'type' => 'ranking', 'text' => 'Ranking', 'extraSettings' => []]),
				self::entity(['id' => 6, 'type' => 'file', 'text' => 'Upload', 'extraSettings' => []]),
				self::entity(['id' => 7, 'type' => 'time', 'text' => 'At', 'extraSettings' => []]),
				self::entity(['id' => 8, 'type' => 'somethingNew', 'text' => 'New', 'extraSettings' => []]),
			],
			null
		)->questions(formId: 1);

		$this->assertSame(
			['1' => 'tel', '2' => 'number', '3' => 'text', '4' => 'array', '5' => 'array', '6' => 'files', '7' => 'time', '8' => ''],
			array_map(static fn (array $q): string => $q['type'], array_combine(array_map('strval', array_keys($questions)), $questions))
		);
		$this->assertFalse($questions['1']['required']);
	}//end testEveryFormsTypeMapsToAFieldType()

	/**
	 * A choice question has no options without the option mapper, or when the mapper fails.
	 *
	 * @return void
	 */
	public function testOptionsAreEmptyWhenTheyCannotBeRead(): void {
		$dropdown = [self::entity(['id' => 9, 'type' => 'dropdown', 'text' => 'Pick', 'extraSettings' => []])];
		$failing = new class {
			/**
			 * Fails as a broken Forms install would.
			 *
			 * @param int $questionId The question.
			 *
			 * @return array<int, mixed>
			 */
			public function findByQuestion(int $questionId): array {
				throw new \RuntimeException('Forms table missing');
			}
		};

		$this->assertSame([], self::readerOver($dropdown, null)->questions(formId: 1)['9']['options']);
		$this->assertSame('select', self::readerOver($dropdown, null)->questions(formId: 1)['9']['type']);
		$this->assertSame([], self::readerOver($dropdown, $failing)->questions(formId: 1)['9']['options']);
	}//end testOptionsAreEmptyWhenTheyCannotBeRead()

	/**
	 * An option row that is neither an array nor has a getter reads as an empty text.
	 *
	 * @return void
	 */
	public function testUnreadableOptionRowsReadAsEmpty(): void {
		$odd = new class {
			/**
			 * Rows of shapes Forms does not return.
			 *
			 * @param int $questionId The question.
			 *
			 * @return array<int, mixed>
			 */
			public function findByQuestion(int $questionId): array {
				return ['plain string', new \stdClass(), ['text' => 'real']];
			}
		};

		$radio = self::entity(['id' => 2, 'type' => 'multiple_unique', 'text' => 'One', 'extraSettings' => []]);
		$questions = self::readerOver([$radio], $odd)->questions(formId: 1);

		$this->assertSame('radio', $questions['2']['type']);
		$this->assertSame(['', '', 'real'], $questions['2']['options']);
	}//end testUnreadableOptionRowsReadAsEmpty()

	/**
	 * Times gain seconds, numbers become floats, and values that do not parse stay as typed.
	 *
	 * @return void
	 */
	public function testAnswersThatDoNotParseStayAsTyped(): void {
		$questions = [
			'1' => ['type' => 'time'],
			'2' => ['type' => 'time'],
			'3' => ['type' => 'number'],
			'4' => ['type' => 'number'],
			'5' => ['type' => 'integer'],
			'6' => ['type' => 'datetime'],
			'7' => ['type' => 'files'],
		];
		$reader = self::readerOver(
			[],
			null,
			[
				self::entity(['questionId' => 1, 'text' => '09:15']),
				self::entity(['questionId' => 2, 'text' => '9.15am']),
				self::entity(['questionId' => 3, 'text' => '2.5']),
				self::entity(['questionId' => 4, 'text' => 'two']),
				self::entity(['questionId' => 5, 'text' => 'many']),
				self::entity(['questionId' => 6, 'text' => 'tomorrow']),
				self::entity(['questionId' => 7, 'text' => 'a.pdf']),
				self::entity(['text' => 'no question']),
			]
		);

		$this->assertSame(
			['1' => '09:15:00', '2' => '9.15am', '3' => 2.5, '4' => 'two', '5' => 'many', '6' => 'tomorrow', '7' => ['a.pdf']],
			$reader->answers(submissionId: 1, questions: $questions)
		);
	}//end testAnswersThatDoNotParseStayAsTyped()
}//end class
