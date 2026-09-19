<?php

/**
 * Pipelinq PartyOrganisationTreeService.
 *
 * Customer organisations nest: a housing corporation with six regional
 * offices, a holding with subsidiaries. This is the CUSTOMER tree. The
 * internal organisation stays what it is, and the two are not merged here.
 *
 * The object is trivial; the guards are where this goes wrong, so they are
 * what this service is for:
 *
 *   - A CYCLE makes any recursive read hang. Refused on the write, by reading
 *     the proposed parent's materialised path.
 *   - UNBOUNDED DEPTH makes a breadcrumb useless and a query slow. Capped,
 *     with the cap administered rather than fixed, because where that line
 *     sits is a customer's.
 *   - A MOVED NODE has to take its subtree's paths with it in one act, or half
 *     the tree points at a parent that no longer holds it.
 *
 * The path is materialised because the common read is "everything under this
 * afdeling", and walking that recursively per read against an object store is
 * slow enough to matter on a real municipal tree.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/party-fields-and-indicators/spec.md#requirement-organisations-shall-nest-as-a-guarded-tree-carrying-their-own-fields-req-pfi-005
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Nest customer organisations, with the three guards that make it survivable.
 *
 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/party-fields-and-indicators/spec.md#requirement-organisations-shall-nest-as-a-guarded-tree-carrying-their-own-fields-req-pfi-005
 */
class PartyOrganisationTreeService {
	/**
	 * The default depth cap, used when the instance has not set one.
	 *
	 * Six is deep enough for a holding with regional offices and departments,
	 * and shallow enough that a breadcrumb still fits on a line.
	 *
	 * @var int
	 */
	public const DEFAULT_MAX_DEPTH = 6;

	/**
	 * The separator in a materialised path.
	 *
	 * @var string
	 */
	public const PATH_SEPARATOR = '/';

	/**
	 * Upper bound on the rows read while moving a subtree.
	 *
	 * @var int
	 */
	private const SUBTREE_LIMIT = 1000;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config, holding the register, the
	 *   client schema id and the administered depth cap.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The administered depth cap.
	 *
	 * @return int The maximum number of nodes from the root to a leaf.
	 *
	 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/party-fields-and-indicators/spec.md#requirement-organisations-shall-nest-as-a-guarded-tree-carrying-their-own-fields-req-pfi-005
	 */
	public function maxDepth(): int {
		$configured = (int)$this->appConfig->getValueString(
			Application::APP_ID,
			'party_organisation_max_depth',
			(string)self::DEFAULT_MAX_DEPTH
		);

		if ($configured > 0) {
			return $configured;
		}

		return self::DEFAULT_MAX_DEPTH;
	}//end maxDepth()

	/**
	 * The path a node would get under a given parent.
	 *
	 * @param string $nodeId The node's uuid.
	 * @param string $parentPath The parent's materialised path, '' at the root.
	 *
	 * @return string The node's path.
	 */
	public function pathUnder(string $nodeId, string $parentPath): string {
		$parentPath = trim($parentPath, self::PATH_SEPARATOR);

		if ($parentPath === '') {
			return self::PATH_SEPARATOR . $nodeId;
		}

		return self::PATH_SEPARATOR . $parentPath . self::PATH_SEPARATOR . $nodeId;
	}//end pathUnder()

	/**
	 * How many nodes a path holds.
	 *
	 * @param string $path A materialised path.
	 *
	 * @return int The depth.
	 *
	 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/party-fields-and-indicators/spec.md#requirement-organisations-shall-nest-as-a-guarded-tree-carrying-their-own-fields-req-pfi-005
	 */
	public function depthOf(string $path): int {
		$trimmed = trim($path, self::PATH_SEPARATOR);

		if ($trimmed === '') {
			return 0;
		}

		return count(explode(self::PATH_SEPARATOR, $trimmed));
	}//end depthOf()

	/**
	 * Whether a node is on a path, and therefore an ancestor of it.
	 *
	 * @param string $nodeId The node's uuid.
	 * @param string $path A materialised path.
	 *
	 * @return bool True when the node appears in the path.
	 */
	public function isOnPath(string $nodeId, string $path): bool {
		$trimmed = trim($path, self::PATH_SEPARATOR);
		if ($trimmed === '') {
			return false;
		}

		return in_array($nodeId, explode(self::PATH_SEPARATOR, $trimmed), true);
	}//end isOnPath()

	/**
	 * Give an organisation a parent, moving its subtree with it.
	 *
	 * @param string $nodeId The organisation to move.
	 * @param string|null $parentId The new parent, or null to make it a root.
	 *
	 * @return array<string, mixed> `status` plus either `moved` or `error`.
	 *
	 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/party-fields-and-indicators/spec.md#requirement-organisations-shall-nest-as-a-guarded-tree-carrying-their-own-fields-req-pfi-005
	 */
	public function setParent(string $nodeId, ?string $parentId): array {
		$nodeId = trim($nodeId);
		$parentId = trim((string)$parentId);

		if ($nodeId === '') {
			return ['status' => 400, 'error' => 'An organisation is required.'];
		}

		if ($nodeId === $parentId) {
			return ['status' => 409, 'error' => 'An organisation cannot be its own parent.'];
		}

		$node = $this->read(id: $nodeId);
		if ($node === null) {
			return ['status' => 404, 'error' => 'That organisation could not be read.'];
		}

		$resolved = $this->parentPathFor(nodeId: $nodeId, parentId: $parentId);
		if (array_key_exists('error', $resolved) === true) {
			return $resolved;
		}

		$newPath = $this->pathUnder(nodeId: $nodeId, parentPath: (string)$resolved['path']);
		$oldPath = (string)($node['organisationPath'] ?? $this->pathUnder(nodeId: $nodeId, parentPath: ''));

		$descendants = $this->descendantsOf(path: $oldPath);

		$deepest = $this->deepestAfter(newPath: $newPath, oldPath: $oldPath, descendants: $descendants);
		if ($deepest > $this->maxDepth()) {
			return [
				'status' => 409,
				'error' => "That move would nest the organisation {$deepest} levels deep, past the cap of {$this->maxDepth()}.",
			];
		}

		$writes = $this->movePlan(
			node: $node,
			nodeId: $nodeId,
			parentId: $parentId,
			newPath: $newPath,
			oldPath: $oldPath,
			descendants: $descendants,
		);

		foreach ($writes as $uuid => $object) {
			if ($this->write(uuid: (string)$uuid, object: $object) === false) {
				return ['status' => 500, 'error' => 'The move could not be saved in full.'];
			}
		}

		return ['status' => 200, 'moved' => count($writes), 'path' => $newPath];
	}//end setParent()

	/**
	 * The proposed parent's path, or the refusal reading it produced.
	 *
	 * @param string $nodeId The organisation being moved.
	 * @param string $parentId The proposed parent, or an empty string for a root.
	 *
	 * @return array<string, mixed> Either `path`, or `status` plus `error`.
	 *
	 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/party-fields-and-indicators/spec.md#requirement-organisations-shall-nest-as-a-guarded-tree-carrying-their-own-fields-req-pfi-005
	 */
	private function parentPathFor(string $nodeId, string $parentId): array {
		if ($parentId === '') {
			return ['path' => ''];
		}

		$parent = $this->read(id: $parentId);
		if ($parent === null) {
			return ['status' => 404, 'error' => 'That parent organisation could not be read.'];
		}

		$parentPath = (string)($parent['organisationPath'] ?? $this->pathUnder(nodeId: $parentId, parentPath: ''));

		// The cycle guard: a parent that already sits UNDER this node would
		// close the loop. Reading the proposed parent's path answers that in
		// one comparison, without walking anything.
		if ($this->isOnPath(nodeId: $nodeId, path: $parentPath) === true) {
			return [
				'status' => 409,
				'error' => 'That parent already sits under this organisation, so the move would close a cycle.',
			];
		}

		return ['path' => $parentPath];
	}//end parentPathFor()

	/**
	 * How deep the deepest node sits once the subtree has moved.
	 *
	 * @param string $newPath The node's path after the move.
	 * @param string $oldPath The node's path before it.
	 * @param array<int, array<string, mixed>> $descendants The subtree.
	 *
	 * @return int The deepest level the move reaches.
	 */
	private function deepestAfter(string $newPath, string $oldPath, array $descendants): int {
		$newDepth = $this->depthOf(path: $newPath);
		$oldDepth = $this->depthOf(path: $oldPath);
		$deepest = $newDepth;

		foreach ($descendants as $descendant) {
			$relative = ($this->depthOf(path: (string)($descendant['organisationPath'] ?? '')) - $oldDepth);
			$deepest = max($deepest, ($newDepth + $relative));
		}

		return $deepest;
	}//end deepestAfter()

	/**
	 * Every write the move needs, keyed by uuid.
	 *
	 * One act: the node and every descendant, or nothing. A descendant left
	 * pointing at a path its parent no longer holds is invisible until
	 * somebody reads the subtree and finds half of it.
	 *
	 * @param array<string, mixed> $node The organisation being moved.
	 * @param string $nodeId Its uuid.
	 * @param string $parentId The new parent, or an empty string for a root.
	 * @param string $newPath The node's path after the move.
	 * @param string $oldPath The node's path before it.
	 * @param array<int, array<string, mixed>> $descendants The subtree.
	 *
	 * @return array<string, array<string, mixed>> The objects to write.
	 *
	 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/party-fields-and-indicators/spec.md#requirement-organisations-shall-nest-as-a-guarded-tree-carrying-their-own-fields-req-pfi-005
	 */
	private function movePlan(
		array $node,
		string $nodeId,
		string $parentId,
		string $newPath,
		string $oldPath,
		array $descendants,
	): array {
		$newParent = null;
		if ($parentId !== '') {
			$newParent = $parentId;
		}

		$writes = [$nodeId => array_merge($node, [
			'parentOrganisation' => $newParent,
			'organisationPath' => $newPath,
		])];

		foreach ($descendants as $descendant) {
			$descendantId = (string)($descendant['id'] ?? $descendant['uuid'] ?? '');
			if ($descendantId === '') {
				continue;
			}

			$writes[$descendantId] = array_merge($descendant, [
				'organisationPath' => $newPath . substr((string)($descendant['organisationPath'] ?? ''), strlen($oldPath)),
			]);
		}

		return $writes;
	}//end movePlan()

	/**
	 * Every organisation under a path, excluding the node that owns it.
	 *
	 * @param string $path The materialised path of the subtree root.
	 *
	 * @return array<int, array<string, mixed>> The descendants.
	 */
	public function descendantsOf(string $path): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'client_schema', '');
		if ($register === '' || $schema === '' || trim($path, self::PATH_SEPARATOR) === '') {
			return [];
		}

		try {
			$rows = $this->objectService->findAll(
				[
					'filters' => ['register' => $register, 'schema' => $schema],
					'limit' => self::SUBTREE_LIMIT,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'PartyOrganisationTreeService: the subtree read failed',
				['path' => $path, 'exception' => $e->getMessage()]
			);

			return [];
		}

		$prefix = rtrim($path, self::PATH_SEPARATOR) . self::PATH_SEPARATOR;
		$descendants = [];
		foreach ($rows as $row) {
			$data = $this->toArray(row: $row);
			if ($data === null) {
				continue;
			}

			$rowPath = (string)($data['organisationPath'] ?? '');
			if ($rowPath !== '' && str_starts_with($rowPath, $prefix) === true) {
				$descendants[] = $data;
			}
		}

		return $descendants;
	}//end descendantsOf()

	/**
	 * Read one organisation as a plain array.
	 *
	 * @param string $id The organisation's uuid.
	 *
	 * @return array<string, mixed>|null The row, or null.
	 */
	private function read(string $id): ?array {
		try {
			$entity = $this->objectService->find(id: $id);
		} catch (Throwable $e) {
			$this->logger->debug(
				'PartyOrganisationTreeService: could not read the organisation',
				['uuid' => $id, 'exception' => $e->getMessage()]
			);

			return null;
		}

		if ($entity === null) {
			return null;
		}

		$data = $entity->jsonSerialize();

		if (is_array($data) === false) {
			return null;
		}

		return $data;
	}//end read()

	/**
	 * Save one organisation.
	 *
	 * @param string $uuid The organisation's uuid.
	 * @param array<string, mixed> $object The object to save.
	 *
	 * @return bool True when the write landed.
	 */
	private function write(string $uuid, array $object): bool {
		try {
			$this->objectService->saveObject(
				object: $object,
				register: $this->appConfig->getValueString(Application::APP_ID, 'register', ''),
				schema: $this->appConfig->getValueString(Application::APP_ID, 'client_schema', ''),
				uuid: $uuid,
			);

			return true;
		} catch (Throwable $e) {
			$this->logger->error(
				'PartyOrganisationTreeService: could not save the organisation',
				['uuid' => $uuid, 'exception' => $e->getMessage()]
			);

			return false;
		}
	}//end write()

	/**
	 * Coerce an OpenRegister row into a plain array.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed>|null The row data, or null.
	 */
	private function toArray(mixed $row): ?array {
		if (is_array($row) === true) {
			return $row;
		}

		if (($row instanceof \JsonSerializable) === true) {
			$data = $row->jsonSerialize();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return null;
	}//end toArray()
}//end class
