<?php

/**
 * Pipelinq TemplateRenderer.
 *
 * Renders Berichtenbox message templates. Templates use a safe, self-contained
 * `{{variable}}` substitution syntax (no external template engine, so no extra
 * runtime dependency). Variable values are HTML-escaped before substitution to
 * prevent body injection. The rendered body is validated as well-formed XHTML
 * (BBK 1.7 appendix B) and the subject is truncated to 200 characters.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#REQ-OUTBOUND-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DOMDocument;
use OCA\Pipelinq\Exception\TemplateRenderException;

/**
 * Renders and validates Berichtenbox message templates.
 *
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#REQ-OUTBOUND-001
 */
class TemplateRenderer
{
    /**
     * Maximum subject length per BBK 1.7.
     *
     * @var int
     */
    public const MAX_SUBJECT_LEN = 200;

    /**
     * Render a template with the given variables.
     *
     * @param array<string, string> $template  The template ('subject', 'body').
     * @param array<string, mixed>  $variables The substitution variables.
     *
     * @return array{subject: string, body: string} The rendered subject and body.
     *
     * @throws TemplateRenderException When the body fails XHTML validation.
     */
    public function render(array $template, array $variables): array
    {
        $subjectTemplate = (string) ($template['subject'] ?? '');
        $bodyTemplate    = (string) ($template['body'] ?? '');

        $subject = $this->substitute(template: $subjectTemplate, variables: $variables);
        $subject = $this->truncateSubject(subject: $subject);

        $body = $this->substitute(template: $bodyTemplate, variables: $variables);
        $this->validateXhtml(body: $body);

        return [
            'subject' => $subject,
            'body'    => $body,
        ];
    }//end render()

    /**
     * Substitute `{{variable}}` placeholders, HTML-escaping each value.
     *
     * Unknown placeholders render as an empty string (Mustache-compatible).
     *
     * @param string               $template  The template string.
     * @param array<string, mixed> $variables The substitution variables.
     *
     * @return string The rendered string.
     */
    private function substitute(string $template, array $variables): string
    {
        return preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
            static function (array $match) use ($variables): string {
                $name = $match[1];
                if (array_key_exists($name, $variables) === false || $variables[$name] === null) {
                    return '';
                }

                return htmlspecialchars((string) $variables[$name], (ENT_QUOTES | ENT_XHTML), 'UTF-8');
            },
            $template
        ) ?? '';
    }//end substitute()

    /**
     * Truncate a subject to the BBK 1.7 limit.
     *
     * @param string $subject The subject.
     *
     * @return string The truncated subject.
     */
    private function truncateSubject(string $subject): string
    {
        if (mb_strlen($subject) <= self::MAX_SUBJECT_LEN) {
            return $subject;
        }

        return mb_substr($subject, 0, self::MAX_SUBJECT_LEN);
    }//end truncateSubject()

    /**
     * Validate that a rendered body is well-formed XHTML.
     *
     * @param string $body The rendered body.
     *
     * @return void
     *
     * @throws TemplateRenderException When the body is not well-formed.
     */
    private function validateXhtml(string $body): void
    {
        if (trim($body) === '') {
            throw new TemplateRenderException(message: 'Rendered body is empty.');
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $doc = new DOMDocument();
        // Wrap in a root element so fragments without a single root still parse,
        // and disable network entity loading (XXE-safe).
        $loaded = $doc->loadXML('<root>'.$this->coerceToXml(body: $body).'</root>', (LIBXML_NONET | LIBXML_NOENT));

        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false || empty($errors) === false) {
            throw new TemplateRenderException(message: 'Rendered body is not well-formed XHTML.');
        }
    }//end validateXhtml()

    /**
     * Coerce common HTML void elements into XML-self-closing form for parsing.
     *
     * @param string $body The body.
     *
     * @return string The XML-coercible body.
     */
    private function coerceToXml(string $body): string
    {
        $void = ['br', 'hr', 'img', 'input', 'meta', 'link'];
        foreach ($void as $tag) {
            $body = preg_replace('/<'.$tag.'([^>\/]*)>/i', '<'.$tag.'$1/>', $body) ?? $body;
        }

        return $body;
    }//end coerceToXml()
}//end class
