<?php

/**
 * Test stub for OpenRegister's LanguageService.
 *
 * Mirrors the request-scoped language-negotiation surface that
 * ScheduledTaskService::applyAcceptLanguage() consumes; OpenRegister is not a
 * test-time dependency. Resolved via the `OCA\OpenRegister\ => tests/Stubs/`
 * autoload-dev mapping, and read (never analysed) by psalm's <extraFiles> and
 * phpstan's scanDirectories. Without it, psalm reported:
 *
 *   UndefinedClass: Class, interface or enum named
 *   OCA\OpenRegister\Service\LanguageService does not exist
 *
 * @category Test
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Minimal LanguageService stub.
 *
 * The method surface mirrors the real class one-for-one. A stub that is
 * narrower than the class it stands in for silently changes what static
 * analysis can see, so keep the two in step.
 */
class LanguageService {
	/**
	 * The preferred language code resolved from the request.
	 *
	 * @var string
	 */
	private string $preferredLanguage = 'nl';

	/**
	 * The accepted languages in priority order.
	 *
	 * @var array<int, string>
	 */
	private array $acceptedLanguages = [];

	/**
	 * Whether every translation variant should be returned.
	 *
	 * @var bool
	 */
	private bool $returnAll = false;

	/**
	 * Whether a fallback language was used.
	 *
	 * @var bool
	 */
	private bool $fallbackUsed = false;

	/**
	 * Where the resolved language came from: query, header or default.
	 *
	 * @var string
	 */
	private string $requestedLanguageSource = 'default';

	/**
	 * Optional BCP-47 write target language.
	 *
	 * @var string|null
	 */
	private ?string $targetLanguage = null;

	/**
	 * Set the preferred language.
	 *
	 * @param string $language The BCP 47 language code.
	 *
	 * @return void
	 */
	public function setPreferredLanguage(string $language): void {
		$this->preferredLanguage = $language;
	}//end setPreferredLanguage()

	/**
	 * Get the preferred language.
	 *
	 * @return string The BCP 47 language code.
	 */
	public function getPreferredLanguage(): string {
		return $this->preferredLanguage;
	}//end getPreferredLanguage()

	/**
	 * Set the accepted languages in priority order.
	 *
	 * @param array<int, string> $languages The BCP 47 language codes.
	 *
	 * @return void
	 */
	public function setAcceptedLanguages(array $languages): void {
		$this->acceptedLanguages = $languages;
	}//end setAcceptedLanguages()

	/**
	 * Get the accepted languages in priority order.
	 *
	 * @return array<int, string> The BCP 47 language codes.
	 */
	public function getAcceptedLanguages(): array {
		return $this->acceptedLanguages;
	}//end getAcceptedLanguages()

	/**
	 * Set whether all translation variants should be returned.
	 *
	 * @param bool $returnAll True to return every variant.
	 *
	 * @return void
	 */
	public function setReturnAllTranslations(bool $returnAll): void {
		$this->returnAll = $returnAll;
	}//end setReturnAllTranslations()

	/**
	 * Whether all translation variants should be returned.
	 *
	 * @return bool True when every variant is requested.
	 */
	public function shouldReturnAllTranslations(): bool {
		return $this->returnAll;
	}//end shouldReturnAllTranslations()

	/**
	 * Record that a fallback language was used.
	 *
	 * @param bool $fallback True when a fallback was used.
	 *
	 * @return void
	 */
	public function setFallbackUsed(bool $fallback): void {
		$this->fallbackUsed = $fallback;
	}//end setFallbackUsed()

	/**
	 * Whether a fallback language was used.
	 *
	 * @return bool True when a fallback was used.
	 */
	public function isFallbackUsed(): bool {
		return $this->fallbackUsed;
	}//end isFallbackUsed()

	/**
	 * Record where the resolved language came from.
	 *
	 * @param string $source One of `query`, `header` or `default`.
	 *
	 * @return void
	 */
	public function setRequestedLanguageSource(string $source): void {
		$this->requestedLanguageSource = $source;
	}//end setRequestedLanguageSource()

	/**
	 * Where the resolved language came from.
	 *
	 * @return string One of `query`, `header` or `default`.
	 */
	public function getRequestedLanguageSource(): string {
		return $this->requestedLanguageSource;
	}//end getRequestedLanguageSource()

	/**
	 * Set the BCP-47 write target language.
	 *
	 * @param string|null $language The target language, or null.
	 *
	 * @return void
	 */
	public function setTargetLanguage(?string $language): void {
		$this->targetLanguage = $language;
	}//end setTargetLanguage()

	/**
	 * Get the BCP-47 write target language.
	 *
	 * @return string|null The target language, or null.
	 */
	public function getTargetLanguage(): ?string {
		return $this->targetLanguage;
	}//end getTargetLanguage()

	/**
	 * Resolve which of a register's languages to serve.
	 *
	 * @param array<int, string> $registerLanguages The register's languages.
	 *
	 * @return string The resolved BCP 47 language code.
	 */
	public function resolveLanguageForRegister(array $registerLanguages): string {
		if (in_array($this->preferredLanguage, $registerLanguages, true) === true) {
			return $this->preferredLanguage;
		}

		return (string)($registerLanguages[0] ?? $this->preferredLanguage);
	}//end resolveLanguageForRegister()
}//end class
