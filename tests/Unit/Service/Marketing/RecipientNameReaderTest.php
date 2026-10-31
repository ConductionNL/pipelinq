<?php

/**
 * Unit tests for RecipientNameReader.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/clients-preferred-name/specs/client-preferred-name/spec.md#requirement-the-preferred-name-is-used-where-a-person-is-addressed-req-cpn-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\Pipelinq\Service\Marketing\RecipientNameReader;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The name a mail greets its recipient by.
 *
 * @covers \OCA\Pipelinq\Service\Marketing\RecipientNameReader
 *
 * @spec openspec/changes/clients-preferred-name/specs/client-preferred-name/spec.md#requirement-the-preferred-name-is-used-where-a-person-is-addressed-req-cpn-002
 */
class RecipientNameReaderTest extends TestCase {
	/**
	 * Build a reader over rows keyed `<schema>/<id>`.
	 *
	 * @param array<string, mixed> $rows The stored objects.
	 * @param array<string, string> $config App config values.
	 * @param bool $broken Whether OpenRegister is missing.
	 *
	 * @return RecipientNameReader
	 */
	private function reader(array $rows, array $config = ['register' => 'pipelinq', 'contact_schema' => 'contact', 'client_schema' => 'client'], bool $broken = false): RecipientNameReader {
		$objectService = new class($rows) {
			/**
			 * @param array<string, mixed> $rows The stored objects.
			 */
			public function __construct(private array $rows) {
			}//end __construct()

			public function find(string $id, $register = null, $schema = null): mixed {
				if ($register !== 'pipelinq') {
					throw new RuntimeException('wrong register: ' . $register);
				}

				$row = ($this->rows[$schema . '/' . $id] ?? null);
				if ($row === 'throws') {
					throw new RuntimeException('not found');
				}

				return $row;
			}//end find()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function () use ($objectService, $broken) {
				if ($broken === true) {
					throw new RuntimeException('OpenRegister is not installed');
				}

				return $objectService;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default): string => ($config[$key] ?? $default)
		);

		return new RecipientNameReader($container, $appConfig, new NullLogger());
	}//end reader()

	/**
	 * A contact person with a preferred name is greeted by it.
	 *
	 * @return void
	 */
	public function testThePreferredNameWins(): void {
		$reader = $this->reader(rows: ['contact/c-1' => ['name' => 'Johannes Jansen', 'preferredName' => ' Jan ']]);

		$this->assertSame('Jan', $reader->greetingName('c-1'));
	}//end testThePreferredNameWins()

	/**
	 * Without a preferred name the full name stands in, also when the field
	 * holds only spaces.
	 *
	 * @return void
	 */
	public function testTheFullNameStandsIn(): void {
		$reader = $this->reader(
			rows: [
				'contact/c-1' => ['name' => 'Johannes Jansen'],
				'contact/c-2' => ['name' => 'Hanna de Wit', 'preferredName' => '  '],
			]
		);

		$this->assertSame('Johannes Jansen', $reader->greetingName('c-1'));
		$this->assertSame('Hanna de Wit', $reader->greetingName('c-2'));
	}//end testTheFullNameStandsIn()

	/**
	 * A list names a recipient by the full name, never the preferred one;
	 * an unknown recipient has none.
	 *
	 * @return void
	 */
	public function testAListReadsTheFullName(): void {
		$reader = $this->reader(rows: ['contact/c-1' => ['name' => 'Johannes Jansen', 'preferredName' => 'Jan']]);

		$this->assertSame(expected: 'Johannes Jansen', actual: $reader->fullName('c-1'));
		$this->assertSame(expected: '', actual: $reader->fullName('c-9'));
		$this->assertSame(expected: '', actual: $reader->fullName(''));
	}//end testAListReadsTheFullName()

	/**
	 * A recipient that is a client is found after the contact lookup misses,
	 * whether that miss is a null or a refusal. An entity is read through
	 * getObject().
	 *
	 * @return void
	 */
	public function testAClientIsFoundAfterTheContactLookupMisses(): void {
		$entity = new class {
			/**
			 * @return array<string, mixed>
			 */
			public function getObject(): array {
				return ['name' => 'Johannes Jansen', 'type' => 'person', 'preferredName' => 'Jan'];
			}//end getObject()
		};
		$reader = $this->reader(rows: ['client/k-1' => $entity, 'contact/k-2' => 'throws', 'client/k-2' => ['name' => 'Gemeente Zuiddrecht']]);

		$this->assertSame('Jan', $reader->greetingName('k-1'));
		$this->assertSame('Gemeente Zuiddrecht', $reader->greetingName('k-2'));
	}//end testAClientIsFoundAfterTheContactLookupMisses()

	/**
	 * No name comes back for a recipient that is no record here, for an empty
	 * id, for an install without a register or schemas, and when OpenRegister
	 * is missing.
	 *
	 * @return void
	 */
	public function testAnUnknownRecipientHasNoName(): void {
		$rows = ['contact/c-1' => ['name' => 'Johannes Jansen', 'preferredName' => 'Jan']];

		$this->assertSame('', $this->reader(rows: $rows)->greetingName('someone@example.com'));
		$this->assertSame('', $this->reader(rows: $rows)->greetingName(''));
		$this->assertSame('', $this->reader(rows: $rows, config: ['contact_schema' => 'contact'])->greetingName('c-1'));
		$this->assertSame('', $this->reader(rows: $rows, config: ['register' => 'pipelinq'])->greetingName('c-1'));
		$this->assertSame('', $this->reader(rows: $rows, broken: true)->greetingName('c-1'));
		// Control: the same row is read when everything is in place.
		$this->assertSame('Jan', $this->reader(rows: $rows)->greetingName('c-1'));
	}//end testAnUnknownRecipientHasNoName()
}//end class
