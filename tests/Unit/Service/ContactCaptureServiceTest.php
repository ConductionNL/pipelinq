<?php

/**
 * Unit tests for ContactCaptureService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#requirement-pasted-text-becomes-proposed-contact-fields-req-ccc-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ContactCaptureService;
use OCA\Pipelinq\Service\ContactLineLabeller;
use OCA\Pipelinq\Service\PhoneNormaliser;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Real signature and card layouts, with and without hermiq.
 *
 * @covers \OCA\Pipelinq\Service\ContactCaptureService
 * @covers \OCA\Pipelinq\Service\ContactLineLabeller
 * @uses   \OCA\Pipelinq\Service\PhoneNormaliser
 */
class ContactCaptureServiceTest extends TestCase {

	/**
	 * The prompt hermiq was asked, when it was.
	 *
	 * @var string|null
	 */
	private ?string $prompt = null;

	/**
	 * The service, with hermiq absent or answering with the given text.
	 *
	 * @param string|null    $answer   Hermiq's answer, or null for no hermiq.
	 * @param RuntimeException|null $failure An error hermiq throws instead.
	 *
	 * @return ContactCaptureService The service.
	 */
	private function service(?string $answer = null, ?RuntimeException $failure = null): ContactCaptureService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);
		$phones    = new PhoneNormaliser($config, $this->createMock(LoggerInterface::class));
		$container = $this->createMock(ContainerInterface::class);
		if ($answer === null && $failure === null) {
			$container->method('get')->willThrowException(new class('no hermiq') extends RuntimeException implements NotFoundExceptionInterface {
			});
		} else {
			$test     = $this;
			$provider = new class($answer, $failure, $test) {
				/**
				 * Constructor.
				 *
				 * @param string|null           $answer  The answer.
				 * @param RuntimeException|null $failure The failure.
				 * @param ContactCaptureServiceTest $test The test, which keeps the prompt.
				 */
				public function __construct(private ?string $answer, private ?RuntimeException $failure, private ContactCaptureServiceTest $test) {
				}

				/**
				 * Answer a prompt.
				 *
				 * @param string $prompt The prompt.
				 *
				 * @return string The answer.
				 */
				public function generateText(string $prompt): string {
					$this->test->remember(prompt: $prompt);
					if ($this->failure !== null) {
						throw $this->failure;
					}

					return (string) $this->answer;
				}
			};
			$container->method('get')->with(ContactLineLabeller::PROVIDER_CLASS)->willReturn($provider);
		}

		return new ContactCaptureService(phones: $phones, labeller: new ContactLineLabeller(container: $container, logger: $this->createMock(LoggerInterface::class)));
	}//end service()

	/**
	 * Keep the prompt the provider double was asked.
	 *
	 * @param string $prompt The prompt.
	 *
	 * @return void
	 */
	public function remember(string $prompt): void {
		$this->prompt = $prompt;
	}//end remember()

	/**
	 * A Dutch signature without hermiq: rules fill email, phone, website and address; the rest is handed to the user.
	 *
	 * @return void
	 */
	public function testADutchSignatureWithoutHermiq(): void {
		$text = "Met vriendelijke groet,\n\nJan de Vries\nAccountmanager\nBouwbedrijf Noord B.V.\nKeizersgracht 12\n1015 CJ Amsterdam\nT: 020 123 4567\nM: 06-12345678\nE: J.deVries@BouwNoord.nl\nwww.bouwnoord.nl\nKvK: 12345678";

		$result = $this->service()->parse(text: $text);

		$this->assertSame(
			[
				'street' => 'Keizersgracht 12',
				'postalCode' => '1015 CJ',
				'city' => 'Amsterdam',
				'phone' => '+31201234567',
				'email' => 'j.devries@bouwnoord.nl',
				'website' => 'https://www.bouwnoord.nl',
				'kvkNumber' => '12345678',
			],
			$this->sorted($result['fields'], ['street', 'postalCode', 'city', 'phone', 'email', 'website', 'kvkNumber'])
		);
		$this->assertSame(['+31201234567', '+31612345678'], $result['phones']);
		$this->assertSame(['Met vriendelijke groet', 'Jan de Vries', 'Accountmanager', 'Bouwbedrijf Noord B.V.'], $result['unassigned']);
		$this->assertSame('rules', $result['labelledBy']);
	}//end testADutchSignatureWithoutHermiq()

	/**
	 * An English signature with hermiq: hermiq labels name, organisation and job title.
	 *
	 * @return void
	 */
	public function testAnEnglishSignatureWithHermiq(): void {
		$text   = "Sarah Johnson\nHead of Procurement | Northwind Ltd\nPhone: +44 20 7946 0958\nsarah.johnson@northwind.co.uk\nhttps://northwind.co.uk/contact";
		$answer = '{"name": "Sarah Johnson", "organisation": "Northwind Ltd", "jobTitle": "Head of Procurement"}';

		$result = $this->service(answer: $answer)->parse(text: $text, userId: 'rep');

		$this->assertSame('Sarah Johnson', $result['fields']['name']);
		$this->assertSame('Northwind Ltd', $result['fields']['organisation']);
		$this->assertSame('Head of Procurement', $result['fields']['jobTitle']);
		$this->assertSame('+442079460958', $result['fields']['phone']);
		$this->assertSame('sarah.johnson@northwind.co.uk', $result['fields']['email']);
		$this->assertSame('https://northwind.co.uk/contact', $result['fields']['website']);
		$this->assertSame([], $result['unassigned']);
		$this->assertSame('hermiq', $result['labelledBy']);
		$this->assertStringContainsString("Sarah Johnson\nHead of Procurement\nNorthwind Ltd", (string) $this->prompt);
	}//end testAnEnglishSignatureWithHermiq()

	/**
	 * A business card on one line per item, the street and postcode on one line.
	 *
	 * @return void
	 */
	public function testACardWithTheAddressOnOneLine(): void {
		$text = "Petra Bakker\nDirecteur\nBakker & Zn\nDorpsstraat 5a, 3811 AB Amersfoort\n+31 (0)33 456 78 90\npetra@bakkerzn.nl";

		$result = $this->service()->parse(text: $text);

		$this->assertSame('Dorpsstraat 5a', $result['fields']['street']);
		$this->assertSame('3811 AB', $result['fields']['postalCode']);
		$this->assertSame('Amersfoort', $result['fields']['city']);
		$this->assertSame('petra@bakkerzn.nl', $result['fields']['email']);
		$this->assertSame(['Petra Bakker', 'Directeur', 'Bakker & Zn'], $result['unassigned']);
	}//end testACardWithTheAddressOnOneLine()

	/**
	 * Two email addresses and a separator: both addresses are kept, the first proposed.
	 *
	 * @return void
	 */
	public function testSeveralEmailsAndASeparator(): void {
		$text = "--\nK. Smit\ninfo@smit.nl / k.smit@smit.nl\nMobiel 0612345678\nK.v.K. nr. 87654321";

		$result = $this->service()->parse(text: $text);

		$this->assertSame(['info@smit.nl', 'k.smit@smit.nl'], $result['emails']);
		$this->assertSame('info@smit.nl', $result['fields']['email']);
		$this->assertSame('+31612345678', $result['fields']['phone']);
		$this->assertSame('87654321', $result['fields']['kvkNumber']);
		$this->assertSame(['K. Smit', '/'], $result['unassigned']);
	}//end testSeveralEmailsAndASeparator()

	/**
	 * A short number or a year is not a phone; a PO box before the postcode is the address line.
	 *
	 * @return void
	 */
	public function testNumbersThatAreNotPhonesStayUnassigned(): void {
		$text = "Opgericht 1998\nRef 12345\nPostbus 100\n2500 AA Den Haag";

		$result = $this->service()->parse(text: $text);

		$this->assertArrayNotHasKey('phone', $result['fields']);
		$this->assertSame('Postbus 100', $result['fields']['street']);
		$this->assertSame('Den Haag', $result['fields']['city']);
		$this->assertSame(['Opgericht 1998', 'Ref 12345'], $result['unassigned']);
	}//end testNumbersThatAreNotPhonesStayUnassigned()

	/**
	 * Hermiq can label a line but not invent one, and a broken or failing answer labels nothing.
	 *
	 * @return void
	 */
	public function testHermiqCanOnlyLabelLinesItWasGiven(): void {
		$text = "Jan Jansen\nAcme";

		$invented = $this->service(answer: '{"name": "John Johnson", "organisation": "Acme", "jobTitle": null}')->parse(text: $text);
		$this->assertSame(['organisation' => 'Acme'], $invented['fields']);
		$this->assertSame(['Jan Jansen'], $invented['unassigned']);

		$twice = $this->service(answer: '{"name": "Acme", "organisation": "Acme"}')->parse(text: $text);
		$this->assertSame(['name' => 'Acme'], $twice['fields']);

		$broken = $this->service(answer: 'I think the name is Jan')->parse(text: $text);
		$this->assertSame([], $broken['fields']);
		$this->assertSame('rules', $broken['labelledBy']);

		$failing = $this->service(failure: new RuntimeException('model down'))->parse(text: $text);
		$this->assertSame(['Jan Jansen', 'Acme'], $failing['unassigned']);
	}//end testHermiqCanOnlyLabelLinesItWasGiven()

	/**
	 * Hermiq is not asked when the rules placed every line, and empty text proposes nothing.
	 *
	 * @return void
	 */
	public function testNothingLeftAsksNoModel(): void {
		$result = $this->service(answer: '{"name": "x"}')->parse(text: "a@b.nl\n\n   ");

		$this->assertNull($this->prompt);
		$this->assertSame(['email' => 'a@b.nl'], $result['fields']);
		$this->assertSame(
			['fields' => [], 'emails' => [], 'phones' => [], 'unassigned' => [], 'labelledBy' => 'rules'],
			$this->service()->parse(text: '')
		);
	}//end testNothingLeftAsksNoModel()

	/**
	 * Fields in a fixed order, for comparison.
	 *
	 * @param array<string, string> $fields The fields.
	 * @param list<string>          $order  The order.
	 *
	 * @return array<string, string> The fields in that order.
	 */
	private function sorted(array $fields, array $order): array {
		$out = [];
		foreach ($order as $key) {
			$out[$key] = ($fields[$key] ?? null);
		}

		return $out;
	}//end sorted()
}//end class
