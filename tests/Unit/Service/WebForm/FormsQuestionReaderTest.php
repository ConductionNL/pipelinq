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
}//end class
