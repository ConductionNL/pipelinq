<?php

/**
 * Unit tests for WebFormBindingCheck.
 *
 * OpenRegister's FormDestinationValidator is not loadable in this suite, so a
 * fake with its signature records what the check hands it. That the shipped
 * seed forms pass the REAL validator against the REAL merged ticket schema is
 * checked outside this suite and recorded in the pull request.
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
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/public-intake-forms/spec.md#requirement-a-public-pipelinq-form-names-its-destination-and-is-checked-when-published
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\WebForm;

use OCA\Pipelinq\Service\WebForm\FormsQuestionReader;
use OCA\Pipelinq\Service\WebForm\WebFormBindingCheck;
use OCA\Pipelinq\Service\WebForm\WebFormFinder;
use OCA\Pipelinq\Tests\Unit\Service\WebForm\Fakes\FakeContainer;
use OCA\Pipelinq\Tests\Unit\Service\WebForm\Fakes\FakeObjectStore;
use OCA\Pipelinq\Tests\Unit\Service\WebForm\Fakes\ObjectServiceContract;
use OCA\Pipelinq\Tests\Unit\Service\WebForm\Fakes\FakeValidator;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * A published form that could never produce a valid destination is named, finding by finding.
 */
class WebFormBindingCheckTest extends TestCase {

	use ObjectServiceContract;

	/**
	 * The validator fake of the last built check.
	 *
	 * @var FakeValidator
	 */
	private FakeValidator $validator;

	/**
	 * A check over a validator returning the given findings per slug.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $findings    Slug to findings.
	 * @param array<int, mixed>                               $storedForms Published forms already stored.
	 * @param array<string, object>                           $forms       Nextcloud Forms mappers, when installed.
	 *
	 * @return WebFormBindingCheck The check.
	 */
	private function check(array $findings = [], array $storedForms = [], array $forms = []): WebFormBindingCheck {
		$this->validator = new FakeValidator(findings: $findings);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ['register' => '20', 'webForm_schema' => '40'][$key] ?? $default
		);
		$container = new FakeContainer(
			services: array_merge(
				[
					'OCA\OpenRegister\Service\Form\FormDestinationValidator' => $this->validator,
					'OCA\OpenRegister\Db\SchemaMapper' => $this->validator,
				],
				$forms
			)
		);

		return new WebFormBindingCheck($container, new WebFormFinder($config, $this->contractOver(new FakeObjectStore(rows: ['40' => $storedForms]))), new FormsQuestionReader($container));
	}//end check()

	/**
	 * A published ticket form.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed> The form.
	 */
	private function form(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'f-1',
				'key' => 'website-support',
				'status' => 'published',
				'destination' => ['register' => 'pipelinq', 'schema' => 'ticket'],
				'mapping' => ['fields' => [['field' => 'title', 'property' => 'title', 'type' => 'text']], 'fixed' => ['ticketType' => 'request']],
			],
			$overrides
		);
	}//end form()

	/**
	 * A valid form has no findings, and the validator judged its mapping for a signed-in writer.
	 *
	 * @return void
	 */
	public function testValidFormHasNoFindings(): void {
		$this->assertSame([], $this->check()->findings(form: $this->form()));

		$this->assertCount(1, $this->validator->calls);
		$this->assertSame('ticket', $this->validator->calls[0]['schema']);
		$this->assertSame(['audience' => 'authenticated'], $this->validator->calls[0]['options']);
		$this->assertSame('title', $this->validator->calls[0]['mapping']['fields'][0]['property']);
	}//end testValidFormHasNoFindings()

	/**
	 * A form into a lead without a title is refused with required-unmapped on title.
	 *
	 * @return void
	 */
	public function testValidatorFindingsRefuse(): void {
		$finding = ['property' => 'title', 'code' => 'required-unmapped', 'message' => '"title" is required'];
		$findings = $this->check(findings: ['lead' => [$finding]])->findings(
			form: $this->form(['destination' => ['register' => 'pipelinq', 'schema' => 'lead'], 'mapping' => ['fields' => []]])
		);

		$this->assertSame([$finding], $findings);
	}//end testValidatorFindingsRefuse()

	/**
	 * A draft is not checked: it takes no submits, and may be saved half made.
	 *
	 * @return void
	 */
	public function testDraftIsNotChecked(): void {
		$this->assertSame([], $this->check()->findings(form: $this->form(['status' => 'draft', 'destination' => null])));
		$this->assertSame([], $this->validator->calls);
	}//end testDraftIsNotChecked()

	/**
	 * A published form without a destination is not published.
	 *
	 * @return void
	 */
	public function testPublishedWithoutDestinationIsRefused(): void {
		$findings = $this->check()->findings(form: $this->form(['destination' => null]));

		$this->assertSame([WebFormBindingCheck::DESTINATION_MISSING], array_column($findings, 'code'));
	}//end testPublishedWithoutDestinationIsRefused()

	/**
	 * A destination schema that does not exist is named.
	 *
	 * @return void
	 */
	public function testUnknownDestinationIsRefused(): void {
		$findings = $this->check()->findings(form: $this->form(['destination' => ['register' => 'pipelinq', 'schema' => 'enquirie']]));

		$this->assertSame([WebFormBindingCheck::DESTINATION_UNKNOWN], array_column($findings, 'code'));
		$this->assertSame([], $this->validator->calls);
	}//end testUnknownDestinationIsRefused()

	/**
	 * A second published form on a key is a conflict; the form itself is not.
	 *
	 * @return void
	 */
	public function testKeyTakenByAnotherPublishedForm(): void {
		$stored = [['@self' => ['id' => 'f-other'], 'key' => 'website-support', 'status' => 'published']];
		$this->assertSame(
			[WebFormBindingCheck::KEY_TAKEN],
			array_column($this->check(storedForms: $stored)->findings(form: $this->form()), 'code')
		);

		$self = [['@self' => ['id' => 'f-1'], 'key' => 'website-support', 'status' => 'published']];
		$this->assertSame([], $this->check(storedForms: $self)->findings(form: $this->form()));
	}//end testKeyTakenByAnotherPublishedForm()

	/**
	 * Several writes are each checked and each finding names its write.
	 *
	 * @return void
	 */
	public function testEveryWriteIsCheckedAndNamed(): void {
		$finding = ['property' => 'contactsUid', 'code' => 'required-unmapped', 'message' => 'x'];
		$findings = $this->check(findings: ['contact' => [$finding]])->findings(
			form: $this->form(
				[
					'destination' => null,
					'writes' => [
						['as' => 'contact', 'register' => 'pipelinq', 'schema' => 'contact', 'mapping' => ['fields' => []]],
						['as' => 'lead', 'register' => 'pipelinq', 'schema' => 'lead', 'mapping' => ['fields' => []]],
					],
				]
			)
		);

		$this->assertSame(['contact', 'lead'], array_column($this->validator->calls, 'schema'));
		$this->assertSame([array_merge(['write' => 'contact'], $finding)], $findings);
	}//end testEveryWriteIsCheckedAndNamed()

	/**
	 * The finder's writesOf reads `writes` first, else one write from destination and mapping, else none.
	 *
	 * @return void
	 */
	public function testWritesOf(): void {
		$finder = new WebFormFinder($this->createMock(IAppConfig::class), $this->contractOver(new FakeObjectStore()));
		$this->assertSame(
			[['as' => 'destination', 'register' => 'pipelinq', 'schema' => 'ticket', 'mapping' => ['fields' => []]]],
			$finder->writesOf(form: ['destination' => ['register' => 'pipelinq', 'schema' => 'ticket'], 'mapping' => ['fields' => []]])
		);
		$this->assertSame([], $finder->writesOf(form: ['destination' => ['schema' => ' ']]));
		$this->assertSame([['as' => 'a', 'schema' => 'lead']], $finder->writesOf(form: ['writes' => [['as' => 'a', 'schema' => 'lead'], 'junk']]));
	}//end testWritesOf()

	/**
	 * A binding to Nextcloud Forms is judged with the questions' real types and options, not the binding's claims.
	 *
	 * @return void
	 */
	public function testFormsBindingTakesTypesFromTheQuestions(): void {
		$questions = new class {
			/**
			 * The form's questions.
			 *
			 * @param int $formId The form.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findByForm(int $formId): array {
				return [
					['id' => 11, 'type' => 'short', 'text' => 'Email', 'isRequired' => true, 'extraSettings' => ['validationType' => 'email']],
					['id' => 12, 'type' => 'dropdown', 'text' => 'Priority', 'isRequired' => false, 'extraSettings' => []],
				];
			}
		};
		$options = new class {
			/**
			 * The question's options.
			 *
			 * @param int $questionId The question.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findByQuestion(int $questionId): array {
				return [['text' => 'low'], ['text' => 'urgent']];
			}
		};
		$check = $this->check(forms: [FormsQuestionReader::QUESTION_MAPPER => $questions, FormsQuestionReader::OPTION_MAPPER => $options]);

		$findings = $check->findings(
			form: $this->form(
				[
					'formsFormId' => 5,
					'mapping' => [
						'fields' => [
							['field' => '11', 'property' => 'reporterEmail', 'type' => 'number'],
							['field' => '12', 'property' => 'priority', 'type' => 'text', 'options' => ['made-up']],
							['field' => '99', 'property' => 'title', 'type' => 'text'],
						],
					],
				]
			)
		);

		$this->assertSame([WebFormBindingCheck::QUESTION_UNKNOWN], array_column($findings, 'code'));
		$judged = $this->validator->calls[0]['mapping']['fields'];
		$this->assertCount(2, $judged);
		$this->assertSame('email', $judged[0]['type']);
		$this->assertSame('select', $judged[1]['type']);
		$this->assertSame(['low', 'urgent'], $judged[1]['options']);
	}//end testFormsBindingTakesTypesFromTheQuestions()

	/**
	 * A binding to Nextcloud Forms on an instance without Forms is refused.
	 *
	 * @return void
	 */
	public function testFormsBindingWithoutFormsIsRefused(): void {
		$findings = $this->check()->findings(form: $this->form(['formsFormId' => 5]));

		$this->assertSame([WebFormBindingCheck::FORMS_MISSING], array_column($findings, 'code'));
	}//end testFormsBindingWithoutFormsIsRefused()
}//end class
