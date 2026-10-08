<?php

/**
 * The published probe for "which slug does this register answer to here?".
 *
 * STUB. Mirrors OpenRegister's published `RegisterSlugResolverInterface` so the
 * unit suite can implement it without the real app on the autoload path.
 *
 * Register slugs live in `openregister_registers`, so no consuming app can
 * answer the question without reaching into OpenRegister's storage. That is why
 * the interface is published rather than copied, and why this file is a mirror
 * of it rather than an invention.
 *
 * @license   EUPL-1.2
 * @copyright Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Contract;

/**
 * Resolves a register by any of the slugs it has answered to.
 */
interface RegisterSlugResolverInterface {

	/**
	 * Which slug this instance's copy of a register actually answers to.
	 *
	 * @param string       $canonical  The canonical (current) register slug.
	 * @param list<string> $candidates Explicit candidates, newest first. Empty
	 *                                 means "use the declared alias list".
	 *
	 * @return RegisterSlugResolution The slug to use, or an explicit absence.
	 */
	public function resolve(string $canonical, array $candidates=[]): RegisterSlugResolution;

	/**
	 * The resolved slug, or null when the register is not here.
	 *
	 * @param string       $canonical  The canonical register slug.
	 * @param list<string> $candidates Explicit candidates, newest first.
	 *
	 * @return string|null The slug, or null.
	 */
	public function slugOrNull(string $canonical, array $candidates=[]): ?string;
}//end interface
