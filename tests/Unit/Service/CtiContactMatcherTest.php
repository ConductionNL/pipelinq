<?php

/**
 * Unit tests for the CTI contact matcher.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\CtiContactMatcher;
use OCA\Pipelinq\Service\PhoneNormaliser;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Matching inbound numbers against stored contacts and clients, and the
 * one-off normalisation of the stored numbers.
 *
 * @covers \OCA\Pipelinq\Service\CtiContactMatcher
 * @uses   \OCA\Pipelinq\Service\PhoneNeedles
 * @uses   \OCA\Pipelinq\Util\PayloadToArray
 * @uses   \OCA\Pipelinq\Util\EntityPayload
 * @uses   \OCA\Pipelinq\Util\SerialisedPayload
 */
class CtiContactMatcherTest extends TestCase {

	/**
	 * An object service double whose findAll answers per schema and whose
	 * saveObject records the writes.
	 *
	 * @param array<string, mixed> $bySchema Rows keyed by schema, or a Throwable to throw.
	 * @param array<int, array>    $saved    Collected saves, by reference.
	 *
	 * @return object The double.
	 */
	private function objectService(array $bySchema, array &$saved): object {
		return new class($bySchema, $saved) {
			/**
			 * @param array<string, mixed> $bySchema Rows or Throwable per schema.
			 * @param array<int, array>    $saved    Save sink.
			 */
			public function __construct(private array $bySchema, private array &$saved) {
			}

			/**
			 * @param array<string, mixed> $config The find config.
			 *
			 * @return mixed The rows for the schema, or a thrown Throwable.
			 */
			public function findAll(array $config): mixed {
				$schema = $config['filters']['schema'];
				$answer = ($this->bySchema[$schema] ?? []);
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}

			/**
			 * Record a save.
			 *
			 * @return null
			 */
			public function saveObject(array $object, array $extend, string $register, string $schema, string $uuid): mixed {
				$this->saved[] = ['object' => $object, 'schema' => $schema, 'uuid' => $uuid];
				return null;
			}
		};
	}//end objectService()

	/**
	 * The matcher over the given config and object service.
	 *
	 * @param array<string, string>   $config    App-config values.
	 * @param object|null             $service   The object service double, or null for none.
	 * @param PhoneNormaliser|null    $normaliser The normaliser, or a silent mock.
	 * @param LoggerInterface|null    $logger    The logger to observe.
	 *
	 * @return CtiContactMatcher The matcher.
	 */
	private function matcher(array $config, ?object $service = null, ?PhoneNormaliser $normaliser = null, ?LoggerInterface $logger = null): CtiContactMatcher {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);
		$container = $this->createMock(ContainerInterface::class);
		if ($service !== null) {
			$container->method('get')->willReturn($service);
		} else {
			$container->method('get')->willThrowException(new RuntimeException('no object service'));
		}

		return new CtiContactMatcher(
			$container,
			$appConfig,
			($normaliser ?? $this->createMock(PhoneNormaliser::class)),
			($logger ?? $this->createMock(LoggerInterface::class)),
		);
	}//end matcher()

	/**
	 * A blank or missing number matches nothing without touching the store.
	 *
	 * @return void
	 */
	public function testABlankNumberMatchesNothing(): void {
		$matcher = $this->matcher(config: ['register' => 'r', 'contact_schema' => 'c']);

		$this->assertSame(['matches' => [], 'totalMatches' => 0], $matcher->findByPhoneNumber(e164Number: null));
		$this->assertSame(['matches' => [], 'totalMatches' => 0], $matcher->findByPhoneNumber(e164Number: ''));
	}//end testABlankNumberMatchesNothing()

	/**
	 * Without a register configured nothing is searched.
	 *
	 * @return void
	 */
	public function testNoRegisterMatchesNothing(): void {
		$matcher = $this->matcher(config: ['contact_schema' => 'c']);

		$this->assertSame(['matches' => [], 'totalMatches' => 0], $matcher->findByPhoneNumber(e164Number: '+31612345678'));
	}//end testNoRegisterMatchesNothing()

	/**
	 * Contacts and clients are searched, tagged by type, and matched on the number's tail.
	 *
	 * @return void
	 */
	public function testContactsAndClientsAreMatchedByTheNumberTail(): void {
		$saved = [];
		$service = $this->objectService(
			bySchema: [
				'sch-contact' => [
					['id' => 'ct-0', 'phone' => 'no digits here'],
					['id' => 'ct-1', 'phone' => '06 1234 5678'],
					['id' => 'ct-2', 'phone' => '020 000 0000'],
				],
				'sch-client' => [
					['id' => 'cl-1', 'object' => ['mobile' => '+31612345678']],
				],
			],
			saved: $saved
		);
		$matcher = $this->matcher(
			config: ['register' => 'r', 'contact_schema' => 'sch-contact', 'client_schema' => 'sch-client'],
			service: $service
		);

		$result = $matcher->findByPhoneNumber(e164Number: '+31612345678');

		$this->assertSame(2, $result['totalMatches']);
		$this->assertSame(['ct-1', 'cl-1'], array_column($result['matches'], 'id'));
		$this->assertSame(['contact', 'client'], array_column($result['matches'], '_matchType'));
	}//end testContactsAndClientsAreMatchedByTheNumberTail()

	/**
	 * At most three matches are returned, though the total counts them all.
	 *
	 * @return void
	 */
	public function testAtMostThreeMatchesAreReturned(): void {
		$saved = [];
		$rows = [];
		for ($i = 1; $i <= 5; $i++) {
			$rows[] = ['id' => 'ct-' . $i, 'phoneNumber' => '+31612345678'];
		}

		$matcher = $this->matcher(
			config: ['register' => 'r', 'contact_schema' => 'sch-contact'],
			service: $this->objectService(bySchema: ['sch-contact' => $rows], saved: $saved)
		);

		$result = $matcher->findByPhoneNumber(e164Number: '+31612345678');

		$this->assertSame(5, $result['totalMatches']);
		$this->assertCount(3, $result['matches']);
	}//end testAtMostThreeMatchesAreReturned()

	/**
	 * A failing read is logged and contributes no matches.
	 *
	 * @return void
	 */
	public function testAFailingReadIsLoggedAndYieldsNothing(): void {
		$sink = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'CTI contact matcher: OR findAll failed',
			$this->callback(static fn (array $c): bool => $c['schema'] === 'sch-contact')
		);
		$matcher = $this->matcher(
			config: ['register' => 'r', 'contact_schema' => 'sch-contact'],
			service: $this->objectService(bySchema: ['sch-contact' => new RuntimeException('OR down')], saved: $sink),
			logger: $logger
		);

		$this->assertSame(['matches' => [], 'totalMatches' => 0], $matcher->findByPhoneNumber(e164Number: '+31612345678'));
	}//end testAFailingReadIsLoggedAndYieldsNothing()

	/**
	 * The stored numbers are normalised, writing only where the E.164 form is new.
	 *
	 * @return void
	 */
	public function testStoredNumbersAreNormalisedWhereTheyChange(): void {
		$saved = [];
		$service = $this->objectService(
			bySchema: [
				'sch-contact' => [
					['id' => 'ct-1', 'phone' => '06 1234 5678'],
					['id' => 'ct-2', 'phone' => '06 1234 5678', 'phoneE164' => '+31612345678'],
					['id' => 'ct-3', 'phone' => ''],
					['id' => 'ct-4', 'phone' => 'onzin'],
					['phone' => '06 1111 1111'],
				],
			],
			saved: $saved
		);
		$normaliser = $this->createMock(PhoneNormaliser::class);
		$normaliser->method('normaliseForOrg')->willReturnCallback(
			static fn (string $raw): array => (($raw === '' || $raw === 'onzin') ? ['e164' => null, 'raw' => $raw] : ['e164' => '+31612345678', 'raw' => $raw])
		);
		$matcher = $this->matcher(
			config: ['register' => 'r', 'contact_schema' => 'sch-contact'],
			service: $service,
			normaliser: $normaliser
		);

		$result = $matcher->normaliseStoredPhoneNumbers();

		$this->assertSame(['updated' => 1, 'skipped' => 4], $result);
		$this->assertSame('ct-1', $saved[0]['uuid']);
		$this->assertSame(['phoneE164' => '+31612345678'], $saved[0]['object']);
	}//end testStoredNumbersAreNormalisedWhereTheyChange()

	/**
	 * Without a register the normalisation does nothing.
	 *
	 * @return void
	 */
	public function testNormalisationWithoutARegisterDoesNothing(): void {
		$matcher = $this->matcher(config: ['contact_schema' => 'sch-contact']);

		$this->assertSame(['updated' => 0, 'skipped' => 0], $matcher->normaliseStoredPhoneNumbers());
	}//end testNormalisationWithoutARegisterDoesNothing()

	/**
	 * An object match on the nested object.phone field is found and tagged.
	 *
	 * @return void
	 */
	public function testANestedObjectPhoneIsMatched(): void {
		$saved = [];
		$matcher = $this->matcher(
			config: ['register' => 'r', 'contact_schema' => 'sch-contact'],
			service: $this->objectService(
				bySchema: ['sch-contact' => [['id' => 'ct-1', 'object' => ['telephone' => '+31612345678']]]],
				saved: $saved
			)
		);

		$this->assertSame(1, $matcher->findByPhoneNumber(e164Number: '+31612345678')['totalMatches']);
	}//end testANestedObjectPhoneIsMatched()

	/**
	 * A non-array answer from the store is treated as no rows.
	 *
	 * @return void
	 */
	public function testANonArrayAnswerIsNoMatches(): void {
		$saved = [];
		$matcher = $this->matcher(
			config: ['register' => 'r', 'contact_schema' => 'sch-contact'],
			service: $this->objectService(bySchema: ['sch-contact' => 'oops'], saved: $saved)
		);

		$this->assertSame(['matches' => [], 'totalMatches' => 0], $matcher->findByPhoneNumber(e164Number: '+31612345678'));
		$this->assertSame(['updated' => 0, 'skipped' => 0], $matcher->normaliseStoredPhoneNumbers());
	}//end testANonArrayAnswerIsNoMatches()

	/**
	 * A failing read during normalisation is logged and the schema is skipped.
	 *
	 * @return void
	 */
	public function testAFailingReadDuringNormalisationIsSkipped(): void {
		$saved = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'CTI contact matcher migration: OR findAll failed',
			$this->callback(static fn (array $c): bool => $c['schema'] === 'sch-contact')
		);
		$matcher = $this->matcher(
			config: ['register' => 'r', 'contact_schema' => 'sch-contact'],
			service: $this->objectService(bySchema: ['sch-contact' => new RuntimeException('OR down')], saved: $saved),
			logger: $logger
		);

		$this->assertSame(['updated' => 0, 'skipped' => 0], $matcher->normaliseStoredPhoneNumbers());
	}//end testAFailingReadDuringNormalisationIsSkipped()

	/**
	 * A row without an id is skipped, and a write that throws is logged and counts as skipped.
	 *
	 * @return void
	 */
	public function testAnUnwritableRowIsSkipped(): void {
		$service = new class {
			/**
			 * @param array<string, mixed> $config The find config.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function findAll(array $config): array {
				return [['id' => 'ct-1', 'phone' => '06 1234 5678']];
			}

			/**
			 * Always fails the write.
			 *
			 * @return never
			 */
			public function saveObject(array $object, array $extend, string $register, string $schema, string $uuid): mixed {
				throw new RuntimeException('read only');
			}
		};
		$normaliser = $this->createMock(PhoneNormaliser::class);
		$normaliser->method('normaliseForOrg')->willReturn(['e164' => '+31612345678', 'raw' => '06 1234 5678']);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('CTI contact matcher: updateObject failed', $this->anything());
		$matcher = $this->matcher(
			config: ['register' => 'r', 'contact_schema' => 'sch-contact'],
			service: $service,
			normaliser: $normaliser,
			logger: $logger
		);

		$this->assertSame(['updated' => 0, 'skipped' => 1], $matcher->normaliseStoredPhoneNumbers());
	}//end testAnUnwritableRowIsSkipped()

	/**
	 * When the store can run as the system user, the write goes through that path.
	 *
	 * @return void
	 */
	public function testAWriteRunsAsSystemWhenSupported(): void {
		$service = new class {
			/**
			 * Collected saves.
			 *
			 * @var array<int, string>
			 */
			public array $saved = [];

			/**
			 * @param array<string, mixed> $config The find config.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function findAll(array $config): array {
				return [['id' => 'ct-1', 'phone' => '06 1234 5678']];
			}

			/**
			 * Run the operation as the system user.
			 *
			 * @param callable $operation The write.
			 *
			 * @return void
			 */
			public function runAsSystem(callable $operation): void {
				$operation();
			}

			/**
			 * Record a save.
			 *
			 * @return null
			 */
			public function saveObject(array $object, array $extend, string $register, string $schema, string $uuid): mixed {
				$this->saved[] = $uuid;
				return null;
			}
		};
		$normaliser = $this->createMock(PhoneNormaliser::class);
		$normaliser->method('normaliseForOrg')->willReturn(['e164' => '+31612345678', 'raw' => '06 1234 5678']);
		$matcher = $this->matcher(
			config: ['register' => 'r', 'contact_schema' => 'sch-contact'],
			service: $service,
			normaliser: $normaliser
		);

		$this->assertSame(['updated' => 1, 'skipped' => 0], $matcher->normaliseStoredPhoneNumbers());
		$this->assertSame(['ct-1'], $service->saved);
	}//end testAWriteRunsAsSystemWhenSupported()

}//end class
