<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Dossiq;

use OCA\Pipelinq\Dossiq\CaseTypeContributionProvider;
use OCA\Pipelinq\Service\ConfigFileLoaderService;
use OCA\Pipelinq\Service\TicketService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * The case type pipelinq hands to dossiq names a register, schema and
 * properties that exist in pipelinq's own register configuration, with
 * subtypes the ticket schema accepts under the labels it gives them.
 *
 * @covers \OCA\Pipelinq\Dossiq\CaseTypeContributionProvider
 * @uses   \OCA\Pipelinq\Service\ConfigFileLoaderService
 * @uses   \OCA\Pipelinq\Service\ConfigFragmentValue
 */
class CaseTypeContributionSchemaTest extends TestCase {
	/**
	 * The register configuration as the import composes it.
	 *
	 * @return array<string, mixed>
	 */
	private function configuration(): array {
		$apps = $this->createMock(originalClassName: IAppManager::class);
		$apps->method('getAppPath')->willReturn(dirname(__DIR__, 3));

		return (new ConfigFileLoaderService(appManager: $apps))->loadConfigurationFile();
	}

	/**
	 * The one contributed case type.
	 *
	 * @return array<string, mixed>
	 */
	private function caseType(): array {
		return (new CaseTypeContributionProvider())->getCaseTypes()[0];
	}

	public function testTheRegisterAndSchemaExist(): void {
		$configuration = $this->configuration();
		$caseType      = $this->caseType();

		$this->assertArrayHasKey($caseType['register'], $configuration['components']['registers']);
		$this->assertArrayHasKey($caseType['schema'], $configuration['components']['schemas']);
	}

	public function testTheNamedPropertiesAreTicketProperties(): void {
		$caseType   = $this->caseType();
		$properties = $this->configuration()['components']['schemas'][$caseType['schema']]['properties'];

		foreach (['discriminator', 'assigneeProperty', 'statusProperty'] as $key) {
			$this->assertArrayHasKey($caseType[$key], $properties, $key.' names no ticket property');
		}
	}

	public function testTheDiscriminatorIsRequiredSoEveryTicketHasASubtype(): void {
		$caseType = $this->caseType();
		$schema   = $this->configuration()['components']['schemas'][$caseType['schema']];

		$this->assertContains($caseType['discriminator'], $schema['required']);
	}

	public function testEverySubtypeIsAnAcceptedValueUnderTheSchemasLabel(): void {
		$caseType      = $this->caseType();
		$discriminator = $this->configuration()['components']['schemas'][$caseType['schema']]['properties'][$caseType['discriminator']];

		foreach ($caseType['subtypes'] as $subtype) {
			$this->assertContains($subtype['value'], $discriminator['enum']);
			$this->assertSame($discriminator['x-enum-labels'][$subtype['value']], $subtype['title']);
		}
	}

	public function testTheSubtypesAreTheTicketServicesOwnTypes(): void {
		$this->assertSame(
			[TicketService::TYPE_REQUEST, TicketService::TYPE_COMPLAINT, TicketService::TYPE_CONTACTMOMENT],
			array_column($this->caseType()['subtypes'], 'value')
		);
	}

	public function testTheCaseTypeIsDescribedForPeople(): void {
		$caseType = $this->caseType();

		$this->assertSame('Ticket', $caseType['title']);
		$this->assertNotSame('', trim($caseType['description']));
		$this->assertSame('pipelinq', $caseType['register']);
		$this->assertSame(['assignee', 'status'], [$caseType['assigneeProperty'], $caseType['statusProperty']]);
	}
}
