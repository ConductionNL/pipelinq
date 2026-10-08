<?php

/**
 * Unit tests for SurveyOptOutService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\SurveyOptOutService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the standing survey opt-out.
 */
class SurveyOptOutServiceTest extends TestCase {
	/**
	 * Every object written through the double.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $written = [];

	/**
	 * The contact rows the double answers with.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $contacts = [];

	/**
	 * Whether the read throws.
	 *
	 * @var bool
	 */
	private bool $readThrows = false;

	/**
	 * Build the service over doubles.
	 *
	 * @param bool $configured Whether the contact schema is configured.
	 *
	 * @return SurveyOptOutService The service under test.
	 */
	private function service(bool $configured = true): SurveyOptOutService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($configured): string {
				$values = ['register' => 'reg-1', 'contact_schema' => 'sch-contact'];
				if ($configured === false) {
					$values['contact_schema'] = '';
				}

				return ($values[$key] ?? $default);
			}
		);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config): array {
				if ($this->readThrows === true) {
					throw new RuntimeException('the store is down');
				}

				return (($config['filters']['schema'] ?? '') === 'sch-contact' ? $this->contacts : []);
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntityInterface {
				$this->written[] = $object;

				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn($object);

				return $entity;
			}
		);

		return new SurveyOptOutService($appConfig, $objectService, $this->createMock(LoggerInterface::class));
	}//end service()

	/**
	 * Every row the uid resolves to carries the flag afterwards.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-survey-fatigue-throttling-and-opt-out
	 */
	public function testEveryMatchingContactRowIsFlagged(): void {
		$this->contacts = [
			['id' => 'contact-1', 'contactsUid' => 'uid-1'],
			['id' => 'contact-2', 'contactsUid' => 'uid-1'],
		];

		$this->service()->record(contactRef: 'uid-1');

		$this->assertCount(2, $this->written);
		$this->assertTrue($this->written[0]['surveyOptOut']);
		$this->assertTrue($this->written[1]['surveyOptOut']);
		$this->assertSame('contact-2', $this->written[1]['id']);
	}//end testEveryMatchingContactRowIsFlagged()

	/**
	 * An empty uid writes nothing rather than flagging every contact.
	 *
	 * @return void
	 */
	public function testAnEmptyContactRefWritesNothing(): void {
		$this->contacts = [['id' => 'contact-1', 'contactsUid' => 'uid-1']];

		$this->service()->record(contactRef: '  ');

		$this->assertSame([], $this->written);
	}//end testAnEmptyContactRefWritesNothing()

	/**
	 * An unconfigured contact schema writes nothing.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredSchemaWritesNothing(): void {
		$this->contacts = [['id' => 'contact-1', 'contactsUid' => 'uid-1']];

		$this->service(configured: false)->record(contactRef: 'uid-1');

		$this->assertSame([], $this->written);
	}//end testAnUnconfiguredSchemaWritesNothing()

	/**
	 * A read that throws is reported, not propagated.
	 *
	 * @return void
	 */
	public function testAReadFailureDoesNotEscape(): void {
		$this->readThrows = true;
		$this->contacts = [['id' => 'contact-1', 'contactsUid' => 'uid-1']];

		$this->service()->record(contactRef: 'uid-1');

		$this->assertSame([], $this->written);
	}//end testAReadFailureDoesNotEscape()
}//end class
