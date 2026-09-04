<?php

/**
 * Pipelinq DefaultSkillService.
 *
 * Service for creating the default agent skills in the Pipelinq application.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/skill-routing/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Service for creating the default agent skills.
 *
 * @spec openspec/specs/skill-routing/spec.md
 */
class DefaultSkillService {
	/**
	 * Default skill definitions.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private const DEFAULT_SKILLS = [
		[
			'title' => 'Algemene Dienstverlening',
			'description' => 'General public service',
			'categories' => ['algemeen'],
			'isActive' => true,
		],
		[
			'title' => 'Vergunningen',
			'description' => 'Permits and environmental law',
			'categories' => ['vergunningen', 'omgevingsrecht'],
			'isActive' => true,
		],
		[
			'title' => 'Belastingen',
			'description' => 'Municipal taxes',
			'categories' => ['belastingen'],
			'isActive' => true,
		],
		[
			'title' => 'WMO / Zorg',
			'description' => 'Social support and care',
			'categories' => ['wmo', 'zorg'],
			'isActive' => true,
		],
		[
			'title' => 'Klachten',
			'description' => 'Complaint handling',
			'categories' => ['klachten'],
			'isActive' => true,
		],
	];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config.
	 * @param LoggerInterface $logger The logger.
	 * @param RegisterResolverService $registerResolver The register resolver.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private RegisterResolverService $registerResolver,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Create default skills if none exist.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/skill-routing/spec.md
	 */
	public function createDefaultSkills(): void {
		$registerId = $this->registerResolver->resolve('skill');
		$skillSchemaId = $this->appConfig->getValueString(Application::APP_ID, 'skill_schema', '');

		if ($registerId === '' || $skillSchemaId === '') {
			$this->logger->warning(
				'Pipelinq: Cannot create default skills -- register or skill schema not configured'
			);
			return;
		}

		try {
			$objectService = $this->getObjectService();

			$existing = $objectService->findAll(
				[
					'filters' => [
						'register' => $registerId,
						'schema' => $skillSchemaId,
					],
					'limit' => 1,
				]
			);

			if (empty($existing) === false) {
				$this->logger->info('Pipelinq: Default skills already exist, skipping creation');
				return;
			}

			foreach (self::DEFAULT_SKILLS as $skillData) {
				$objectService->saveObject(
					$skillData,
					[],
					$registerId,
					$skillSchemaId,
					null
				);
				$this->logger->info("Pipelinq: Created default skill '{$skillData['title']}'");
			}
		} catch (\Exception $e) {
			$this->logger->error(
				'Pipelinq: Failed to create default skills',
				['exception' => $e->getMessage()]
			);
		}//end try
	}//end createDefaultSkills()

	/**
	 * Get the OpenRegister ObjectService via the container.
	 *
	 * @return object The object service.
	 */
	private function getObjectService(): object {
		return $this->objectService;
	}//end getObjectService()
}//end class
