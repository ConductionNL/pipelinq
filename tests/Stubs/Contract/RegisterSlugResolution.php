<?php

/**
 * The answer to "which slug does this register answer to here?".
 *
 * STUB. Mirrors OpenRegister's published `RegisterSlugResolution` so the unit
 * suite can construct one without the real app on the autoload path. See
 * tests/Stubs/Contract/ObjectServiceInterface.php for why these stubs exist at
 * all: a leaf app cannot mock what it cannot load.
 *
 * @license   EUPL-1.2
 * @copyright Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Contract;

/**
 * A resolved register slug, or an explicit absence.
 *
 * `isResolved()` reads the SLUG and not the state on purpose, matching the
 * real class: a caller that branches on the state alone and then falls back to
 * the canonical slug reinstates the defect this type exists to prevent, because
 * a read with a slug the instance does not carry returns zero rows, and zero
 * rows is what a healthy empty register returns too.
 */
final class RegisterSlugResolution {

	public const RESOLVED = 'resolved';

	public const ABSENT = 'absent';

	public const AMBIGUOUS = 'ambiguous';

	/**
	 * Constructor.
	 *
	 * @param string       $canonical  The canonical (current) register slug.
	 * @param string|null  $slug       The slug to use, or null when absent.
	 * @param string       $state      One of RESOLVED, ABSENT, AMBIGUOUS.
	 * @param list<string> $candidates The slugs that were considered.
	 * @param list<string> $matched    The slugs that matched.
	 */
	public function __construct(
		public readonly string $canonical,
		public readonly ?string $slug,
		public readonly string $state,
		public readonly array $candidates,
		public readonly array $matched,
	) {
	}//end __construct()

	/**
	 * Whether a usable slug was found.
	 *
	 * @return bool True when there is a slug to read with.
	 */
	public function isResolved(): bool {
		return $this->slug !== null;
	}//end isResolved()

	/**
	 * Whether the register is genuinely not on this instance.
	 *
	 * @return bool True when absent.
	 */
	public function isAbsent(): bool {
		return $this->state === self::ABSENT;
	}//end isAbsent()

	/**
	 * Whether more than one candidate matched.
	 *
	 * @return bool True when ambiguous.
	 */
	public function isAmbiguous(): bool {
		return $this->state === self::AMBIGUOUS;
	}//end isAmbiguous()
}//end class
