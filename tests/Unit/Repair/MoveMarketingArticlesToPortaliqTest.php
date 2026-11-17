<?php

/**
 * Unit tests for MoveMarketingArticlesToPortaliq.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/marketing-articles-move-to-portaliq/specs/marketing-articles/spec.md#requirement-existing-pipelinq-articles-move-to-portaliq-once
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Repair;

use OCA\Pipelinq\Repair\MoveMarketingArticlesToPortaliq;
use OCA\Pipelinq\Service\Marketing\ArticleMoveService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The step reports the move's counts and is never fatal.
 */
class MoveMarketingArticlesToPortaliqTest extends TestCase {

	/**
	 * The counts are reported on one line, with the renamed slugs.
	 *
	 * @return void
	 */
	public function testTheCountsAreReported(): void {
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('Article move done: 2 moved, 1 already moved, 0 failed, 1 references rewritten; slugs renamed with -pipelinq: lente');
		$output->expects($this->never())->method('warning');

		$this->step(result: ['status' => 'done', 'moved' => 2, 'alreadyMoved' => 1, 'failed' => 0, 'references' => 1, 'renamedSlugs' => ['lente']])->run($output);
	}//end testTheCountsAreReported()

	/**
	 * Failures are reported as such.
	 *
	 * @return void
	 */
	public function testFailuresAreReported(): void {
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('Article move failures: 0 moved, 0 already moved, 3 failed, 0 references rewritten');

		$this->step(result: ['status' => 'failures', 'moved' => 0, 'alreadyMoved' => 0, 'failed' => 3, 'references' => 0, 'renamedSlugs' => []])->run($output);
	}//end testFailuresAreReported()

	/**
	 * Without portaliq the step says it skipped.
	 *
	 * @return void
	 */
	public function testWithoutPortaliqTheStepSaysItSkipped(): void {
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('Article move skipped: portaliq or its article schema is not available');

		$this->step(result: ['status' => 'portaliq-missing', 'moved' => 0, 'alreadyMoved' => 0, 'failed' => 0, 'references' => 0, 'renamedSlugs' => []])->run($output);
	}//end testWithoutPortaliqTheStepSaysItSkipped()

	/**
	 * An exception is logged and warned, not thrown.
	 *
	 * @return void
	 */
	public function testAnExceptionIsNeverFatal(): void {
		$move = $this->createMock(ArticleMoveService::class);
		$move->method('run')->willThrowException(new RuntimeException('boom'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')->with('Article move failed: boom');

		(new MoveMarketingArticlesToPortaliq($move, $logger))->run($output);
		$this->assertStringContainsString('portaliq', (new MoveMarketingArticlesToPortaliq($move, $logger))->getName());
	}//end testAnExceptionIsNeverFatal()

	/**
	 * The step over a move answering a fixed result.
	 *
	 * @param array<string, mixed> $result The move's answer.
	 *
	 * @return MoveMarketingArticlesToPortaliq The step.
	 */
	private function step(array $result): MoveMarketingArticlesToPortaliq {
		$move = $this->createMock(ArticleMoveService::class);
		$move->method('run')->willReturn($result);

		return new MoveMarketingArticlesToPortaliq($move, $this->createMock(LoggerInterface::class));
	}//end step()
}//end class
