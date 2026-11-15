<?php

/**
 * Unit test for JobRegistrar: the kernel's BlastSendJob contract is bound to the job.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use OCA\Pipelinq\BackgroundJob\BlastSendJob;
use OCA\Pipelinq\BackgroundJob\JobRegistrar;
use OCA\Pipelinq\Kernel\Contracts\BlastSendJobInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * Tests for JobRegistrar.
 *
 * @covers \OCA\Pipelinq\BackgroundJob\JobRegistrar
 */
class JobRegistrarTest extends TestCase {

	/**
	 * The contract is aliased to the job, once.
	 *
	 * @return void
	 */
	public function testTheBlastSendJobContractIsAliasedToTheJob(): void {
		$context = $this->createMock(IRegistrationContext::class);
		$context->expects($this->once())->method('registerServiceAlias')->with(BlastSendJobInterface::class, BlastSendJob::class);

		(new JobRegistrar())->register($context);
	}//end testTheBlastSendJobContractIsAliasedToTheJob()
}//end class
