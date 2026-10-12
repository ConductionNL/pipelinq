<?php

/**
 * Unit tests for WebFormBindingListener.
 *
 * Driven with OpenRegister's creating and updating events (stubs that mirror
 * production member for member) and the real WebFormBindingCheck, over a
 * validator fake with OpenRegister's signature.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener
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

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Pipelinq\Listener\WebFormBindingListener;
use OCA\Pipelinq\Service\WebForm\FormsQuestionReader;
use OCA\Pipelinq\Service\WebForm\WebFormBindingCheck;
use OCA\Pipelinq\Service\WebForm\WebFormFinder;
use OCA\Pipelinq\Tests\Unit\Service\WebForm\Fakes\FakeContainer;
use OCA\Pipelinq\Tests\Unit\Service\WebForm\Fakes\FakeObjectStore;
use OCA\Pipelinq\Tests\Unit\Service\WebForm\Fakes\ObjectServiceContract;
use OCA\Pipelinq\Tests\Unit\Service\WebForm\Fakes\FakeValidator;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A published form with findings is not saved; a valid one, a draft and other objects are.
 */
class WebFormBindingListenerTest extends TestCase {

	use ObjectServiceContract;

	/**
	 * The validator fake of the last built listener.
	 *
	 * @var FakeValidator
	 */
	private FakeValidator $validator;

	/**
	 * The listener over a validator with the given findings.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $findings  Slug to findings.
	 * @param bool                                            $withCheck False to leave OpenRegister's validator out.
	 *
	 * @return WebFormBindingListener The listener.
	 */
	private function listener(array $findings = [], bool $withCheck = true): WebFormBindingListener {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ['register' => '20', 'webForm_schema' => '40'][$key] ?? $default
		);
		$this->validator = new FakeValidator(findings: $findings);
		$services = [
			'OCA\OpenRegister\Db\SchemaMapper' => $this->validator,
		];
		if ($withCheck === true) {
			$services['OCA\OpenRegister\Service\Form\FormDestinationValidator'] = $this->validator;
		}

		$container = new FakeContainer(services: $services);
		$finder = new WebFormFinder($config, $this->contractOver(new FakeObjectStore()));

		return new WebFormBindingListener(
			$finder,
			new WebFormBindingCheck($container, $finder, new FormsQuestionReader($container)),
			$this->createMock(LoggerInterface::class)
		);
	}//end listener()

	/**
	 * A stored object of a schema.
	 *
	 * @param string               $schema The schema id.
	 * @param array<string, mixed> $body   The body.
	 *
	 * @return ObjectEntity The entity.
	 */
	private static function entity(string $schema, array $body): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('f-1');
		$entity->setSchema($schema);
		$entity->setObject($body);

		return $entity;
	}//end entity()

	/**
	 * A published lead form body.
	 *
	 * @param string $status The status.
	 *
	 * @return array<string, mixed> The body.
	 */
	private static function leadForm(string $status = 'published'): array {
		return [
			'key' => 'partner',
			'status' => $status,
			'destination' => ['register' => 'pipelinq', 'schema' => 'lead'],
			'mapping' => ['fields' => []],
		];
	}//end leadForm()

	/**
	 * Publishing a form into a lead that fills no title is refused with required-unmapped on title.
	 *
	 * @return void
	 */
	public function testCreatingAPublishedFormWithFindingsIsRefused(): void {
		$finding = ['property' => 'title', 'code' => 'required-unmapped', 'message' => '"title" is required.'];
		$event = new ObjectCreatingEvent(self::entity('40', self::leadForm()));

		$this->listener(findings: ['lead' => [$finding]])->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame([$finding], $event->getErrors()['findings']);
		$this->assertStringContainsString('"title" is required.', $event->getErrors()['message']);
	}//end testCreatingAPublishedFormWithFindingsIsRefused()

	/**
	 * The control: the same form with nothing found is saved.
	 *
	 * @return void
	 */
	public function testCreatingAValidFormIsSaved(): void {
		$event = new ObjectCreatingEvent(self::entity('40', self::leadForm()));

		$this->listener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertCount(1, $this->validator->calls);
	}//end testCreatingAValidFormIsSaved()

	/**
	 * An update that publishes a broken form is refused; the data a sibling listener modified is judged too.
	 *
	 * @return void
	 */
	public function testUpdatingToPublishedIsChecked(): void {
		$finding = ['property' => 'title', 'code' => 'required-unmapped', 'message' => 'x'];
		$event = new ObjectUpdatingEvent(self::entity('40', self::leadForm('draft')), self::entity('40', self::leadForm('draft')));
		$event->setModifiedData(['status' => 'published']);

		$this->listener(findings: ['lead' => [$finding]])->handle($event);

		$this->assertTrue($event->isPropagationStopped());
	}//end testUpdatingToPublishedIsChecked()

	/**
	 * A draft is saved as is, and objects of other schemas are not looked at.
	 *
	 * @return void
	 */
	public function testDraftsAndOtherSchemasPass(): void {
		$listener = $this->listener(findings: ['lead' => [['property' => 'title', 'code' => 'required-unmapped', 'message' => 'x']]]);

		$draft = new ObjectCreatingEvent(self::entity('40', self::leadForm('draft')));
		$listener->handle($draft);
		$this->assertFalse($draft->isPropagationStopped());

		$lead = new ObjectCreatingEvent(self::entity('30', self::leadForm()));
		$listener->handle($lead);
		$this->assertFalse($lead->isPropagationStopped());
		$this->assertSame([], $this->validator->calls);
	}//end testDraftsAndOtherSchemasPass()

	/**
	 * When the check cannot run, a published form is refused: it fails closed.
	 *
	 * @return void
	 */
	public function testCheckThatCannotRunRefuses(): void {
		$event = new ObjectCreatingEvent(self::entity('40', self::leadForm()));

		$this->listener(withCheck: false)->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('check-failed', $event->getErrors()['findings'][0]['code']);
	}//end testCheckThatCannotRunRefuses()
}//end class
