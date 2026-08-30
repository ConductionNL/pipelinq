<?php

/**
 * Test stub for OCA\OpenRegister\Db\ObjectEntity.
 *
 * SIGNATURE PARITY CONTRACT (pipelinq#807)
 * ----------------------------------------
 * This stub stands in for the real entity in the unit suite (tests/bootstrap.php
 * pre-declares it so the same class wins whether or not OpenRegister is
 * installed). Anything this stub declares that production does not — or declares
 * more loosely than production — creates a double the real system can never
 * produce, and the suite goes green on assertions that could never hold.
 *
 * Matched against ConductionNL/openregister@origin/development, lib/Db/ObjectEntity.php:
 *
 *   class ObjectEntity extends Entity implements JsonSerializable   (line 148)
 *   public function getObject(): array                              (line 781)
 *   public function jsonSerialize(): array                          (line 885)
 *
 * NO LONGER ABSENT, as of ADR-084 (openregister#2498) — read this before you
 * "restore parity" by deleting the concrete getters below.
 *
 * The paragraph that follows described the world up to #807, and its reasoning
 * was right for that world. It is now stale in its conclusion: production's
 * ObjectEntity declares getUuid(), getRegister(), getSchema(), getOrganisation()
 * and getOwner() as REAL methods, because it implements
 * OCA\OpenRegister\Contract\ObjectEntityInterface and magic does not satisfy an
 * interface — PHP counts only declared methods. So `method_exists()` is now TRUE
 * in production for all five, and a stub that omits them is the double the real
 * system can never produce. The parity argument has not weakened; it points the
 * other way.
 *
 * The `@method` tags below are kept because production keeps its own: removing
 * them there surfaces 214 phpstan findings.
 *
 * --- historical, pre-ADR-084 ---
 * DELIBERATELY ABSENT: getUuid() / getSchema() / getRegister() / getId() and
 * their setters. Production declared them ONLY as `@method` docblock tags
 * (lib/Db/ObjectEntity.php lines 61-72) and served them through
 * `OCP\AppFramework\Db\Entity::__call()`. They were therefore NOT real methods:
 *
 *   | accessor        | method_exists | is_callable | property_exists |
 *   |-----------------|---------------|-------------|-----------------|
 *   | getObject()     | true          | true        | true            |
 *   | getSchema()     | **false**     | true        | true            |
 *   | getUuid()       | **false**     | true        | true            |
 *   | getNoSuchThing()| false         | **true**    | false           |
 *
 * (measured on a live Nextcloud 34 against the real class).
 *
 * The previous revision of this stub declared getUuid() and getSchema() as
 * CONCRETE methods "so unit tests can mock them with onlyMethods()". That
 * inverted the exact predicate under test: `method_exists($entity, 'getSchema')`
 * was TRUE in the suite and FALSE in production, so three permanently-dead
 * listeners (POS loyalty, expense→AP, Berichtenbox) and four silently-lost
 * object ids stayed green through 2200 tests. Tests must build a real entity
 * with the magic setters, not mock accessors production does not declare.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use JsonSerializable;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCP\AppFramework\Db\Entity;

/**
 * Stub for ObjectEntity; mirrors the production class's real member set.
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getRegister()
 * @method void setRegister(?string $register)
 * @method string|null getSchema()
 * @method void setSchema(?string $schema)
 * @method void setObject(?array $object)
 */
class ObjectEntity extends Entity implements JsonSerializable, ObjectEntityInterface {

	/**
	 * The @method annotations above document Entity's magic accessors, but magic
	 * does not satisfy an interface — PHP only counts DECLARED methods. So every
	 * contract getter is spelled out here, including getUuid() and getSchema(),
	 * which the annotations alone would have left missing.
	 *
	 * @return ?string
	 */
	public function getUuid(): ?string {
		return $this->uuid ?? null;
	}

	/**
	 * @return ?string
	 */
	public function getRegister(): ?string {
		return $this->register ?? null;
	}

	/**
	 * @return ?string
	 */
	public function getSchema(): ?string {
		return $this->schema ?? null;
	}

	/**
	 * @return ?string
	 */
	public function getOrganisation(): ?string {
		return $this->organisation ?? null;
	}

	/**
	 * @return ?string
	 */
	public function getOwner(): ?string {
		return $this->owner ?? null;
	}

	/**
	 * Unique identifier for the object.
	 *
	 * @var string|null
	 */
	protected ?string $uuid = null;

	/**
	 * Register the object belongs to.
	 *
	 * @var string|null
	 */
	protected ?string $register = null;

	/**
	 * Schema the object belongs to.
	 *
	 * @var string|null
	 */
	protected ?string $schema = null;

	/**
	 * Organisation the object belongs to.
	 *
	 * @var string|null
	 */
	protected ?string $organisation = null;

	/**
	 * Owner of the object.
	 *
	 * @var string|null
	 */
	protected ?string $owner = null;

	/**
	 * Object data stored as an array.
	 *
	 * @var array<string,mixed>|null
	 */
	protected ?array $object = null;

	/**
	 * Register the field types, as the production entity does.
	 */
	public function __construct() {
		$this->addType('uuid', 'string');
		$this->addType('register', 'string');
		$this->addType('schema', 'string');
		$this->addType('organisation', 'string');
		$this->addType('owner', 'string');
		$this->addType('object', 'json');

	}//end __construct()

	/**
	 * Return the object data with 'id' injected from the UUID.
	 *
	 * Mirrors production (lib/Db/ObjectEntity.php:781): the id is prepended via
	 * array_merge, so a payload that carries its own 'id' wins.
	 *
	 * @return array<string,mixed>
	 */
	public function getObject(): array {
		return array_merge(['id' => $this->uuid], ($this->object ?? []));
	}//end getObject()

	/**
	 * Return a JSON-serialisable representation of the entity.
	 *
	 * Mirrors the shape production emits: the payload, plus an '@self' metadata
	 * envelope, plus a top-level 'id' when the UUID is known.
	 *
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		$object = ($this->object ?? []);
		$object['@self'] = [
			'id' => $this->uuid,
			'name' => $this->uuid,
			'register' => $this->register,
			'schema' => $this->schema,
		];

		if ($this->uuid !== null) {
			$object['id'] = $this->uuid;
		}

		return $object;
	}//end jsonSerialize()

}//end class
