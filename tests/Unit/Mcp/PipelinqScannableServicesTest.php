<?php

/**
 * Unit tests for PipelinqScannableServices.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Mcp;

use OCA\Pipelinq\Mcp\PipelinqScannableServices;
use OCA\Pipelinq\Service\LeadService;
use OCA\Pipelinq\Service\TicketService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PipelinqScannableServices.
 */
class PipelinqScannableServicesTest extends TestCase {

	/**
	 * getScannableServiceClasses() declares exactly the two classes carrying
	 * `#[McpTool]`-attributed methods.
	 *
	 * @return void
	 */
	public function testGetScannableServiceClassesDeclaresLeadAndTicketServices(): void {
		$classes = (new PipelinqScannableServices())->getScannableServiceClasses();

		$this->assertSame(
			expected: [LeadService::class, TicketService::class],
			actual: $classes
		);
	}//end testGetScannableServiceClassesDeclaresLeadAndTicketServices()
}//end class
