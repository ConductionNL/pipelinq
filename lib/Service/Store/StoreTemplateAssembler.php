<?php

/**
 * Pipelinq StoreTemplateAssembler.
 *
 * Builds a store item from this instance's own configuration. A kind names
 * the schemas a template of that kind may carry (a subset of the install
 * allowlist), `publishable()` offers the local objects of those schemas, and
 * `components()` turns the chosen ones into the `components` an install
 * writes back: copies without their identity, so the item says what to
 * create and never which object it came from.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Store
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/store-publish-templates/specs/pipelinq-store-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Store;

use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\RegisterResolverService;
use OCA\Pipelinq\Service\Settings\SchemaSlugMap;
use OCP\IAppConfig;

/**
 * Offers local configuration for a store item and assembles its components.
 */
class StoreTemplateAssembler {

	/**
	 * The schemas a store item of each kind may carry.
	 *
	 * @var array<string, array<int, string>>
	 */
	public const KIND_SCHEMAS = [
		'pipeline'          => ['pipeline'],
		'queue-routing'     => ['skill'],
		'catalogue'         => ['product', 'productCategory'],
		'pos-setup'         => ['posRole', 'posTenderType', 'receiptTemplate', 'refundReason'],
		'loyalty-programme' => ['loyaltyProgramme', 'pointsRule'],
		'connector'         => ['berichtenboxTemplate'],
	];

	/**
	 * Keys that identify a stored object and never travel in a template.
	 *
	 * @var array<int, string>
	 */
	private const IDENTITY_KEYS = ['id', 'uuid', '@self', 'organisation', 'owner', 'created', 'updated', 'register', 'schema'];

	/**
	 * The most objects offered per schema.
	 */
	private const OFFER_LIMIT = 200;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface  $objectService    OpenRegister's object service.
	 * @param IAppConfig              $appConfig        Holds the schema id per slug.
	 * @param RegisterResolverService $registerResolver Resolves pipelinq's register id.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly RegisterResolverService $registerResolver,
	) {
	}//end __construct()

	/**
	 * The local objects a template of this kind may carry, by title.
	 *
	 * @param string $kind The store item kind.
	 *
	 * @return array<int, array{schema: string, id: string, title: string}>
	 *
	 * @spec openspec/changes/store-publish-templates/specs/pipelinq-store-surface/spec.md
	 */
	public function publishable(string $kind): array {
		$offered = [];
		foreach ((self::KIND_SCHEMAS[$kind] ?? []) as $slug) {
			foreach ($this->objectsOf(slug: $slug) as $object) {
				$id = (string) ($object['id'] ?? ($object['@self']['id'] ?? ''));
				if ($id === '') {
					continue;
				}

				$offered[] = [
					'schema' => $slug,
					'id'     => $id,
					'title'  => (string) ($object['title'] ?? ($object['name'] ?? $id)),
				];
			}
		}

		return $offered;
	}//end publishable()

	/**
	 * The chosen objects as store item components, without their identity.
	 *
	 * @param string                                         $kind   The store item kind.
	 * @param array<int, array{schema?: mixed, id?: mixed}> $chosen The objects to include.
	 *
	 * @return array<int, array{schema: string, object: array<string, mixed>}>
	 *
	 * @throws InvalidArgumentException When an object is outside the kind or not on this instance.
	 *
	 * @spec openspec/changes/store-publish-templates/specs/pipelinq-store-surface/spec.md
	 */
	public function components(string $kind, array $chosen): array {
		$allowed    = (self::KIND_SCHEMAS[$kind] ?? []);
		$components = [];
		foreach ($chosen as $entry) {
			$slug = (string) ($entry['schema'] ?? '');
			$id   = (string) ($entry['id'] ?? '');
			if (in_array($slug, $allowed, true) === false) {
				throw new InvalidArgumentException('A '.$kind.' template cannot carry a '.$slug.'.');
			}

			$object = $this->objectById(slug: $slug, id: $id);
			if ($object === null) {
				throw new InvalidArgumentException('The chosen '.$slug.' is not on this instance.');
			}

			$components[] = ['schema' => $slug, 'object' => $this->withoutIdentity(object: $object)];
		}

		return $components;
	}//end components()

	/**
	 * The objects of one schema, as arrays.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function objectsOf(string $slug): array {
		$registerId = $this->registerResolver->resolve();
		$schemaId   = $this->appConfig->getValueString(Application::APP_ID, (SchemaSlugMap::SLUG_TO_CONFIG_KEY[$slug] ?? ''), '');
		if ($registerId === '' || $schemaId === '') {
			return [];
		}

		$rows = [];
		$found = $this->objectService->findAll(
			config: ['filters' => ['register' => $registerId, 'schema' => $schemaId], 'limit' => self::OFFER_LIMIT]
		);
		foreach ($found as $object) {
			if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
				$object = $object->jsonSerialize();
			}

			if (is_array($object) === true) {
				$rows[] = $object;
			}
		}

		return $rows;
	}//end objectsOf()

	/**
	 * One object of a schema by its id, or null.
	 *
	 * @param string $slug The schema slug.
	 * @param string $id   The object id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function objectById(string $slug, string $id): ?array {
		foreach ($this->objectsOf(slug: $slug) as $object) {
			if ((string) ($object['id'] ?? ($object['@self']['id'] ?? '')) === $id) {
				return $object;
			}
		}

		return null;
	}//end objectById()

	/**
	 * The object without the keys that identify the stored copy.
	 *
	 * @param array<string, mixed> $object The stored object.
	 *
	 * @return array<string, mixed>
	 */
	private function withoutIdentity(array $object): array {
		foreach (self::IDENTITY_KEYS as $key) {
			unset($object[$key]);
		}

		return $object;
	}//end withoutIdentity()
}//end class
