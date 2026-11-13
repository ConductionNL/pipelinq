<?php

/**
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\CallReview;

use OCA\Pipelinq\Service\CallReview\CallSummaryWriter;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A call summary comes from hermiq, or is empty.
 *
 * @covers \OCA\Pipelinq\Service\CallReview\CallSummaryWriter
 *
 * @spec openspec/changes/contact-moments-call-review/tasks.md#task-1.4
 */
class CallSummaryWriterTest extends TestCase {

	/**
	 * The writer over a container that answers the given provider, or throws.
	 *
	 * @param object|null $provider The hermiq provider, or null when hermiq is absent.
	 *
	 * @return CallSummaryWriter
	 */
	private function writer(?object $provider): CallSummaryWriter {
		$container = $this->createMock(ContainerInterface::class);
		if ($provider === null) {
			$container->method('get')->willThrowException(new RuntimeException('no hermiq'));
		} else {
			$container->method('get')->with(CallSummaryWriter::PROVIDER_CLASS)->willReturn($provider);
		}

		return new CallSummaryWriter($container, $this->createMock(LoggerInterface::class));
	}//end writer()

	/**
	 * With hermiq the summary is its answer, asked with the transcript as the acting user.
	 *
	 * @return void
	 */
	public function testWithHermiqTheSummaryIsItsAnswer(): void {
		$provider = new class {
			/** @var array<int, mixed> */
			public array $asked = [];

			/**
			 * Answer a prompt.
			 *
			 * @param string      $prompt The prompt.
			 * @param string|null $userId The acting user.
			 *
			 * @return string
			 */
			public function generateText(string $prompt, ?string $userId): string {
				$this->asked = [$prompt, $userId];
				return "  De beller vroeg naar haar afvalpas. Er wordt een nieuwe gestuurd.  ";
			}
		};

		$summary = $this->writer($provider)->summarise('Goedemiddag, ik bel over mijn afvalpas.', 'anna');

		$this->assertSame('De beller vroeg naar haar afvalpas. Er wordt een nieuwe gestuurd.', $summary);
		$this->assertStringEndsWith('Goedemiddag, ik bel over mijn afvalpas.', $provider->asked[0]);
		$this->assertStringContainsString('at most five sentences', $provider->asked[0]);
		$this->assertSame('anna', $provider->asked[1]);
	}//end testWithHermiqTheSummaryIsItsAnswer()

	/**
	 * Without hermiq, with a silent or failing one, or without a transcript the summary is empty.
	 *
	 * @return void
	 */
	public function testOtherwiseTheSummaryIsEmpty(): void {
		$failing = new class {
			/**
			 * Fail.
			 *
			 * @return string
			 */
			public function generateText(): string {
				throw new RuntimeException('busy');
			}
		};

		$this->assertSame('', $this->writer(null)->summarise('Hallo'));
		$this->assertSame('', $this->writer(new \stdClass())->summarise('Hallo'));
		$this->assertSame('', $this->writer($failing)->summarise('Hallo'));
		$this->assertSame('', $this->writer($failing)->summarise('   '));
	}//end testOtherwiseTheSummaryIsEmpty()
}//end class
