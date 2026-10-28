<?php

/**
 * Tests for ClientBusinessController: GET /api/clients/{id}/business.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\ClientBusinessController;
use OCA\Pipelinq\Service\ClientBusinessReader;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * The client's business through the API.
 */
class ClientBusinessControllerTest extends TestCase {

	/**
	 * The answer carries the sentences and the reader's status code.
	 *
	 * @return void
	 */
	public function testIndexAnswersWithTheReadersStatus(): void {
		$reader = $this->createMock(originalClassName: ClientBusinessReader::class);
		$reader->method('businessOf')->willReturnMap(
			[
				['c1', ['status' => 200, 'projectsLine' => 'No projects.']],
				['gone', ['status' => 404, 'message' => 'Client not found']],
			]
		);
		$controller = new ClientBusinessController(
			request: $this->createMock(originalClassName: IRequest::class),
			reader: $reader
		);

		$found = $controller->index(id: 'c1');
		$this->assertSame(expected: 200, actual: $found->getStatus());
		$this->assertSame(expected: ['projectsLine' => 'No projects.'], actual: $found->getData());
		$this->assertSame(expected: 404, actual: $controller->index(id: 'gone')->getStatus());

	}//end testIndexAnswersWithTheReadersStatus()
}//end class
