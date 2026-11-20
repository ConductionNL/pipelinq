<?php

/**
 * Checks a website form against the destination it names, when the form is made.
 *
 * Decision 179: every form knows its destination and must be valid against
 * that object's schema, checked when the form is saved and again on submit.
 * Decision 181, Q9: a finding refuses the save from the first release, with
 * no report mode. The judging itself is OpenRegister's
 * FormDestinationValidator, so a pipelinq form and a dossiq form are held to
 * one set of rules; this class only resolves what it is handed.
 *
 * For a form bound to Nextcloud Forms (Q6), each field is a question id, and
 * the field's type and options come from the question as it is now, not from
 * what the binding claims, so a binding is checked against what the form can
 * really produce.
 *
 * Every submit is written by pipelinq's own system account (see
 * WebFormIntakeService), a signed-in subject, so the destination is checked
 * for the `authenticated` audience whatever the form's own audience is.
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
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/public-intake-forms/spec.md#requirement-a-public-pipelinq-form-names-its-destination-and-is-checked-when-published
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\WebForm;

use OCA\Pipelinq\Kernel\Contracts\WebFormBindingCheckInterface;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * The findings that stop a website form from being published.
 *
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/public-intake-forms/spec.md#requirement-a-public-pipelinq-form-names-its-destination-and-is-checked-when-published
 */
class WebFormBindingCheck implements WebFormBindingCheckInterface {

	/**
	 * A published form names no destination.
	 *
	 * @var string
	 */
	public const DESTINATION_MISSING = 'destination-missing';

	/**
	 * The destination schema does not exist.
	 *
	 * @var string
	 */
	public const DESTINATION_UNKNOWN = 'destination-unknown';

	/**
	 * Another published form already uses this key.
	 *
	 * @var string
	 */
	public const KEY_TAKEN = 'key-taken';

	/**
	 * A mapped question is not on the Nextcloud Forms form.
	 *
	 * @var string
	 */
	public const QUESTION_UNKNOWN = 'question-unknown';

	/**
	 * The form is bound to Nextcloud Forms, which is not installed.
	 *
	 * @var string
	 */
	public const FORMS_MISSING = 'forms-not-installed';

	/**
	 * The finding of a published form that names no destination.
	 *
	 * @var array{property: string, code: string, message: string}
	 */
	private const MISSING_DESTINATION_FINDING = [
		'property' => '',
		'code' => self::DESTINATION_MISSING,
		'message' => 'A published form must name the object a submit creates.',
	];

	/**
	 * The finding of a form bound to Nextcloud Forms while Forms is not installed.
	 *
	 * @var array{property: string, code: string, message: string}
	 */
	private const FORMS_MISSING_FINDING = [
		'property' => '',
		'code' => self::FORMS_MISSING,
		'message' => 'This form is bound to Nextcloud Forms, which is not installed.',
	];

	/**
	 * OpenRegister's validator, resolved by name: pipelinq boots without OpenRegister.
	 *
	 * @var string
	 */
	private const VALIDATOR = 'OCA\OpenRegister\Service\Form\FormDestinationValidator';

	/**
	 * OpenRegister's schema mapper.
	 *
	 * @var string
	 */
	private const SCHEMA_MAPPER = 'OCA\OpenRegister\Db\SchemaMapper';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface  $container Reaches OpenRegister's validator and schema mapper.
	 * @param WebFormFinder       $finder    Finds another published form on the same key.
	 * @param FormsQuestionReader $questions Reads a bound Nextcloud Forms form's questions.
	 *
	 * @spec exclude constructor wiring only; it makes no decision a requirement can describe
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly WebFormFinder $finder,
		private readonly FormsQuestionReader $questions,
	) {

	}//end __construct()

	/**
	 * Every reason this form may not be published; empty when it may.
	 *
	 * A draft or unpublished form is not checked: it takes no submits, and an
	 * author must be able to save one half made.
	 *
	 * @param array<string, mixed> $form The form body, with its `id` when it is stored.
	 *
	 * @return array<int, array<string, mixed>> The findings: `{ write?, field?, property, code, message }`.
	 *
	 * @spec openspec/changes/website-forms-submit-into-tickets/specs/public-intake-forms/spec.md#requirement-a-public-pipelinq-form-names-its-destination-and-is-checked-when-published
	 */
	public function findings(array $form): array {
		if (($form['status'] ?? null) !== WebFormFinder::PUBLISHED) {
			return [];
		}

		$writes = $this->finder->writesOf(form: $form);
		if ($writes === []) {
			return [self::MISSING_DESTINATION_FINDING];
		}

		$findings = $this->keyFindings(form: $form);
		$formsFormId = (int)($form['formsFormId'] ?? 0);
		if ($formsFormId > 0 && $this->questions->available() === false) {
			$findings[] = self::FORMS_MISSING_FINDING;
			return $findings;
		}

		$check = new WriteCheck(questions: $this->boundQuestions(formsFormId: $formsFormId), named: count($writes) > 1);
		foreach ($writes as $write) {
			array_push($findings, ...$this->writeFindings(check: $check, write: $write));
		}

		return $findings;

	}//end findings()

	/**
	 * The questions of the bound Nextcloud Forms form, or null for an unbound form.
	 *
	 * @param int $formsFormId The bound form id, 0 when unbound.
	 *
	 * @return array<string, array<string, mixed>>|null
	 */
	private function boundQuestions(int $formsFormId): ?array {
		if ($formsFormId <= 0) {
			return null;
		}

		return $this->questions->questions(formId: $formsFormId);
	}//end boundQuestions()

	/**
	 * A finding when another published form already answers to this key.
	 *
	 * @param array<string, mixed> $form The form body.
	 *
	 * @return array<int, array<string, mixed>> Zero or one finding.
	 */
	private function keyFindings(array $form): array {
		$key = (string)($form['key'] ?? '');
		foreach ($this->finder->allPublishedByKey(key: $key) as $other) {
			if ((string)($other['id'] ?? '') !== (string)($form['id'] ?? '')) {
				return [
					[
						'property' => 'key',
						'code' => self::KEY_TAKEN,
						'message' => sprintf('Another published form already uses the key "%s".', $key),
					],
				];
			}
		}

		return [];

	}//end keyFindings()

	/**
	 * Findings for one write.
	 *
	 * @param WriteCheck           $check The bound questions and whether to name the write in each finding.
	 * @param array<string, mixed> $write The write.
	 *
	 * @return array<int, array<string, mixed>> The findings.
	 */
	private function writeFindings(WriteCheck $check, array $write): array {
		$findings = $this->destinationFindings(check: $check, write: $write);
		if ($check->named === false) {
			return $findings;
		}

		return array_map(
			static fn (array $finding): array => array_merge(['write' => (string)($write['as'] ?? '')], $finding),
			$findings
		);

	}//end writeFindings()

	/**
	 * Findings on one write's destination: unknown, or what the mapping validator says.
	 *
	 * @param WriteCheck           $check The bound questions.
	 * @param array<string, mixed> $write The write.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function destinationFindings(WriteCheck $check, array $write): array {
		$schemaRef = (string)($write['schema'] ?? '');
		$schema = $this->schemaOf(schemaRef: $schemaRef);
		if ($schema === null) {
			return [
				[
					'property' => '',
					'code' => self::DESTINATION_UNKNOWN,
					'message' => sprintf('The destination "%s" does not exist.', $schemaRef),
				],
			];
		}

		$findings = [];
		$mapping = (array)($write['mapping'] ?? []);
		if ($check->questions !== null) {
			[$mapping, $findings] = $this->fromQuestions(mapping: $mapping, questions: $check->questions);
		}

		array_push(
			$findings,
			...$this->container->get(self::VALIDATOR)->validate(
				mapping: $mapping,
				schema: $schema,
				options: ['audience' => 'authenticated']
			)
		);

		return $findings;
	}//end destinationFindings()

	/**
	 * The destination schema, or null when it does not resolve.
	 *
	 * @param string $schemaRef The write's schema reference.
	 *
	 * @return object|null
	 */
	private function schemaOf(string $schemaRef): ?object {
		try {
			return $this->container->get(self::SCHEMA_MAPPER)->find($schemaRef, _multitenancy: false);
		} catch (Throwable) {
			return null;
		}
	}//end schemaOf()

	/**
	 * The mapping with each field's type and options taken from its Forms question.
	 *
	 * @param array<string, mixed>                $mapping   The binding's mapping.
	 * @param array<string, array<string, mixed>> $questions The form's questions.
	 *
	 * @return array{0: array<string, mixed>, 1: array<int, array<string, mixed>>} The mapping and the unknown-question findings.
	 */
	private function fromQuestions(array $mapping, array $questions): array {
		$fields = [];
		$findings = [];
		foreach ((array)($mapping['fields'] ?? []) as $field) {
			if (is_array($field) === false) {
				continue;
			}

			$questionId = (string)($field['field'] ?? '');
			if (isset($questions[$questionId]) === false) {
				$findings[] = [
					'field' => $questionId,
					'property' => (string)($field['property'] ?? ''),
					'code' => self::QUESTION_UNKNOWN,
					'message' => sprintf('Question "%s" is not on the Nextcloud Forms form.', $questionId),
				];
				continue;
			}

			$question = $questions[$questionId];
			$field['type'] = $question['type'];
			unset($field['options']);
			if (isset($question['options']) === true) {
				$field['options'] = $question['options'];
			}

			$fields[] = $field;
		}

		$mapping['fields'] = $fields;

		return [$mapping, $findings];

	}//end fromQuestions()
}//end class
