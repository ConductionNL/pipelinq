<?php

/**
 * Unit tests for RoutingService::getSuggestedAgents() on a lead.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Service\RoutingService;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Lead routing suggestions (pipelinq#2049).
 *
 * RoutingService matches colleagues on the record's `category`. The lead
 * schema declared no category, and OpenRegister keeps only declared
 * properties, so a lead never carried one and every lead suggestion answered
 * noMatch. The lead fixture here is therefore stored the way OpenRegister
 * stores it: reduced to the properties the shipped lead schema declares.
 */
class RoutingServiceTest extends TestCase {

	/**
	 * A lead with a category gets the colleague whose skill covers it.
	 *
	 * @return void
	 */
	public function testALeadWithACategoryGetsAMatchingColleague(): void {
		$lead = self::storedAsDeclared(
			[
				'title' => 'Parkeerbeheer voor gemeente Oss',
				'client' => 'client-1',
				'pipeline' => 'pipeline-1',
				'status' => 'open',
				'category' => 'vergunningen',
			]
		);

		$result = $this->service(lead: $lead)->getSuggestedAgents(entityType: 'lead', entityId: 'lead-1');

		$this->assertFalse($result['noMatch'], 'the lead category did not survive the lead schema');
		$this->assertCount(1, $result['suggestions']);
		$this->assertSame('anna', $result['suggestions'][0]['userId']);
		$this->assertSame('Vergunningen', $result['suggestions'][0]['matchedSkill']);
	}//end testALeadWithACategoryGetsAMatchingColleague()

	/**
	 * A lead without a category still validates and answers noMatch.
	 *
	 * @return void
	 */
	public function testALeadWithoutACategoryAnswersNoMatch(): void {
		$lead = self::storedAsDeclared(['title' => 'Zonder categorie', 'client' => 'client-1', 'pipeline' => 'pipeline-1']);

		$result = $this->service(lead: $lead)->getSuggestedAgents(entityType: 'lead', entityId: 'lead-1');

		$this->assertTrue($result['noMatch']);
		$this->assertSame([], $result['suggestions']);
	}//end testALeadWithoutACategoryAnswersNoMatch()

	/**
	 * The category is optional, so leads saved before it existed still validate.
	 *
	 * @return void
	 */
	public function testTheLeadCategoryIsAnOptionalString(): void {
		$schema = self::mergedLeadSchema();

		$this->assertSame('string', ($schema['properties']['category']['type'] ?? null));
		$this->assertNotContains('category', ($schema['required'] ?? []));
	}//end testTheLeadCategoryIsAnOptionalString()

	/**
	 * Build the service over one stored lead, one skill and one agent.
	 *
	 * @param array<string, mixed> $lead The stored lead payload.
	 *
	 * @return RoutingService The service.
	 */
	private function service(array $lead): RoutingService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'register' => 'pipelinq',
				'lead_schema' => 'lead',
				'skill_schema' => 'skill',
				'agentProfile_schema' => 'agentProfile',
				default => $default,
			}
		);

		$ticketService = $this->createMock(TicketService::class);
		$ticketService->method('getSchemaId')->willReturn('ticket');
		$ticketService->method('findByType')->willReturn([]);

		$entity = new ObjectEntity();
		$entity->setUuid('lead-1');
		$entity->setSchema('lead');
		$entity->setObject($lead);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturn($entity);
		$objectService->method('count')->willReturn(0);
		$objectService->method('findAll')->willReturnCallback(
			static fn (array $config = []): array => match ($config['filters']['schema'] ?? '') {
				'skill' => [['id' => 'skill-1', 'title' => 'Vergunningen', 'categories' => ['vergunningen'], 'isActive' => true]],
				'agentProfile' => [['userId' => 'anna', 'displayName' => 'Anna de Vries', 'skills' => ['skill-1'], 'isAvailable' => true, 'maxConcurrent' => 10]],
				default => [],
			}
		);

		return new RoutingService(
			appConfig: $appConfig,
			ticketService: $ticketService,
			logger: $this->createMock(LoggerInterface::class),
			objectService: $objectService,
		);
	}//end service()

	/**
	 * Keep only the properties the shipped lead schema declares.
	 *
	 * @param array<string, mixed> $payload The posted payload.
	 *
	 * @return array<string, mixed> The payload as stored.
	 */
	private static function storedAsDeclared(array $payload): array {
		$declared = (self::mergedLeadSchema()['properties'] ?? []);
		return array_intersect_key($payload, $declared);
	}//end storedAsDeclared()

	/**
	 * The lead schema as the install imports it: the monolith with every
	 * register.d fragment deep-merged on top, in filename order.
	 *
	 * @return array<string, mixed> The merged lead schema.
	 */
	private static function mergedLeadSchema(): array {
		$settings = dirname(__DIR__, 3) . '/lib/Settings';
		$files = array_merge([$settings . '/pipelinq_register.json'], glob($settings . '/register.d/*.json'));
		$merged = [];
		foreach ($files as $file) {
			$data = json_decode((string)file_get_contents($file), true);
			$fragment = ($data['components']['schemas']['lead'] ?? null);
			if (is_array($fragment) === true) {
				$merged = array_replace_recursive($merged, $fragment);
			}
		}

		return $merged;
	}//end mergedLeadSchema()
}//end class
