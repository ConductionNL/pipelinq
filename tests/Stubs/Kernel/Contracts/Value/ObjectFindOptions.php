<?php

/**
 * Test stub for OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions.
 *
 * Mirrors OpenRegister's value object: the read options find() and
 * findSilent() take since openregister #9116. Same constructor, same
 * defaults, so a test double reads the options a caller built exactly as
 * OpenRegister would.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Stubs\Kernel\Contracts\Value
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Value;

use OCA\OpenRegister\Kernel\Contracts\RegisterInterface;
use OCA\OpenRegister\Kernel\Contracts\SchemaInterface;

if (class_exists(ObjectFindOptions::class) === false) {
	/**
	 * Read options for a single-object find.
	 */
	final class ObjectFindOptions {
		/**
		 * Constructor.
		 *
		 * @param RegisterInterface|string|int|null $register The register, or its id, uuid or slug.
		 * @param SchemaInterface|string|int|null   $schema   The schema, or its id, uuid or slug.
		 * @param array<int|string, mixed>|null     $extend   Properties to extend.
		 * @param boolean                           $files    Whether to include files.
		 * @param boolean                           $render   Whether to render the object.
		 * @param boolean                           $audit    Whether to record the read in the audit trail.
		 */
		public function __construct(
			public readonly RegisterInterface|string|int|null $register = null,
			public readonly SchemaInterface|string|int|null $schema = null,
			public readonly ?array $extend = [],
			public readonly bool $files = false,
			public readonly bool $render = true,
			public readonly bool $audit = true,
		) {
		}//end __construct()
	}//end class
}//end if
