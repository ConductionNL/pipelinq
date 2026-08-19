<?php

/**
 * Pipelinq ReceiptService.
 *
 * Renders a server-authoritative POS receipt from a persisted posTransaction:
 * a plain-text / HTML body for print and email, an ESC/POS thermal byte
 * stream, and (for transactions >= EUR 100) a legal invoice variant with the
 * full BTW breakdown and Dutch compliance metadata. Reuses the
 * invoiceBreakdown / taxBreakdown already computed and persisted by
 * PosTransactionService — it never re-derives tax from the client.
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
 * @spec openspec/specs/pos-receipt-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IL10N;

/**
 * Pure receipt rendering + ESC/POS generation service.
 *
 * Deliberately template-injection-safe: templates are a small, fixed set of
 * `{{ placeholder }}` tokens that are substituted from a server-built context.
 * There is no expression evaluation, no filesystem / URL access and no PHP
 * callback surface, so a malicious template body cannot execute code, read
 * files or trigger SSRF. Unknown placeholders render as an empty string.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)     The public surface is the
 *  render core (renderText / renderHtml / renderEscPos), the invoice-mode
 *  decision (isInvoiceTransaction / invoiceThreshold), the placeholder context
 *  builder and the company-details accessor — each single-purpose and
 *  individually unit-tested; collapsing them would hide tested seams.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The receipt layout is one
 *  cohesive concern composed of many small, single-purpose section builders
 *  (header / body / summary / tax / footer rows + ESC/POS framing); the
 *  aggregate complexity reflects the breadth of receipt formatting, not tangled
 *  logic, and splitting it across classes would scatter one rendering concern.
 *
 * @spec openspec/specs/pos-receipt-engine/spec.md
 */
class ReceiptService
{
    /**
     * Grand-total threshold (inclusive) at or above which a transaction MUST
     * render as a legal invoice with full BTW breakdown (Dutch VAT rule).
     *
     * @var float
     */
    public const INVOICE_THRESHOLD_EUR = 100.0;

    /**
     * Default thermal printer line width in characters.
     *
     * @var int
     */
    public const DEFAULT_LAYOUT_WIDTH = 42;

    /**
     * ESC/POS: initialise / reset printer (ESC @).
     *
     * @var string
     */
    private const ESC_INIT = "\x1B\x40";

    /**
     * ESC/POS: select bold on (ESC E 1).
     *
     * @var string
     */
    private const ESC_BOLD_ON = "\x1B\x45\x01";

    /**
     * ESC/POS: select bold off (ESC E 0).
     *
     * @var string
     */
    private const ESC_BOLD_OFF = "\x1B\x45\x00";

    /**
     * ESC/POS: select centre alignment (ESC a 1).
     *
     * @var string
     */
    private const ESC_ALIGN_CENTER = "\x1B\x61\x01";

    /**
     * ESC/POS: select left alignment (ESC a 0).
     *
     * @var string
     */
    private const ESC_ALIGN_LEFT = "\x1B\x61\x00";

    /**
     * ESC/POS: full paper cut (GS V 0).
     *
     * @var string
     */
    private const ESC_FULL_CUT = "\x1D\x56\x00";

    /**
     * ESC/POS: select code page CP858 (ESC t 19) — Western European incl. €.
     *
     * @var string
     */
    private const ESC_CODEPAGE_CP858 = "\x1B\x74\x13";

    /**
     * Constructor.
     *
     * @param IAppConfig $appConfig The app config (company details, defaults).
     * @param IL10N      $l10n      The localization service.
     */
    public function __construct(
        private IAppConfig $appConfig,
        private IL10N $l10n,
    ) {
    }//end __construct()

    /**
     * Whether a transaction must be rendered as a legal invoice.
     *
     * Decided server-side from the persisted grand total only; a client cannot
     * downgrade a high-value sale to a simple receipt.
     *
     * @param array<string, mixed> $transaction The persisted transaction.
     *
     * @return bool Whether the legal-invoice variant is required.
     *
     * @spec openspec/specs/pos-receipt-engine/spec.md
     */
    public function isInvoiceTransaction(array $transaction): bool
    {
        $total = (float) ($transaction['total'] ?? 0);

        return $total >= self::INVOICE_THRESHOLD_EUR;
    }//end isInvoiceTransaction()

    /**
     * The invoice threshold in euros.
     *
     * @return float The threshold.
     *
     * @spec openspec/specs/pos-receipt-engine/spec.md
     */
    public function invoiceThreshold(): float
    {
        return self::INVOICE_THRESHOLD_EUR;
    }//end invoiceThreshold()

    /**
     * Render a transaction to a plain-text receipt body.
     *
     * For transactions >= EUR 100 this auto-selects the legal-invoice layout
     * (BTW breakdown + compliance footer) regardless of the supplied template's
     * isInvoiceMode flag, so a simple template can never under-render a sale
     * that legally requires a formal invoice (REQ-PRE-004 scenario 3).
     *
     * @param array<string, mixed> $transaction The persisted transaction.
     * @param array<string, mixed> $template    The selected receipt template (optional).
     *
     * @return string The rendered plain-text receipt.
     *
     * @spec openspec/specs/pos-receipt-engine/spec.md
     */
    public function renderText(array $transaction, array $template=[]): string
    {
        $width   = $this->layoutWidth(template: $template, transaction: $transaction);
        $invoice = $this->renderAsInvoice(transaction: $transaction, template: $template);
        $context = $this->buildContext(transaction: $transaction);

        $lines = array_merge(
            $this->headerLines(context: $context, width: $width, invoice: $invoice),
            $this->bodyLines(context: $context, width: $width),
            $this->summaryLines(context: $context, width: $width, invoice: $invoice),
            $this->footerLines(context: $context, width: $width, invoice: $invoice)
        );

        return implode("\n", $lines)."\n";
    }//end renderText()

    /**
     * Whether to render the legal-invoice variant.
     *
     * True when the sale is >= EUR 100 (forced) or the template opts in.
     *
     * @param array<string, mixed> $transaction The transaction.
     * @param array<string, mixed> $template    The template.
     *
     * @return bool Whether to render as an invoice.
     */
    private function renderAsInvoice(array $transaction, array $template): bool
    {
        if ($this->isInvoiceTransaction(transaction: $transaction) === true) {
            return true;
        }

        return ($template['isInvoiceMode'] ?? false) === true;
    }//end renderAsInvoice()

    /**
     * Build the header lines (company, title, invoice no., reference, date).
     *
     * @param array<string, mixed> $context The render context.
     * @param int                  $width   The layout width.
     * @param bool                 $invoice Whether this is an invoice.
     *
     * @return array<int, string> The header lines.
     */
    private function headerLines(array $context, int $width, bool $invoice): array
    {
        $lines   = [];
        $lines[] = $this->center(text: $context['company']['name'], width: $width);
        if ($context['company']['address'] !== '') {
            $lines[] = $this->center(text: $context['company']['address'], width: $width);
        }

        if ($context['company']['phone'] !== '') {
            $lines[] = $this->center(text: 'Tel: '.$context['company']['phone'], width: $width);
        }

        $lines[] = str_repeat('=', $width);
        $lines[] = $this->center(text: $this->headingLabel(invoice: $invoice), width: $width);

        if ($invoice === true && $context['invoiceNumber'] !== '') {
            $lines[] = $this->center(
                text: $this->l10n->t('Invoice no.').' '.$context['invoiceNumber'],
                width: $width
            );
        }

        $lines[] = $context['reference'];
        $lines[] = $context['date'];
        $lines[] = str_repeat('-', $width);

        return $lines;
    }//end headerLines()

    /**
     * Build the line-item rows.
     *
     * @param array<string, mixed> $context The render context.
     * @param int                  $width   The layout width.
     *
     * @return array<int, string> The line rows.
     */
    private function bodyLines(array $context, int $width): array
    {
        $lines = [];
        foreach ($context['lines'] as $line) {
            $lines[] = $this->lineRow(line: $line, width: $width);
        }

        return $lines;
    }//end bodyLines()

    /**
     * Build the totals / BTW summary lines.
     *
     * @param array<string, mixed> $context The render context.
     * @param int                  $width   The layout width.
     * @param bool                 $invoice Whether this is an invoice.
     *
     * @return array<int, string> The summary lines.
     */
    private function summaryLines(array $context, int $width, bool $invoice): array
    {
        $lines   = [];
        $lines[] = str_repeat('-', $width);
        $lines[] = $this->amountRow(label: $this->l10n->t('Subtotal'), amount: $context['subtotal'], width: $width);
        if ($context['discountTotal'] > 0) {
            $lines[] = $this->amountRow(
                label: $this->l10n->t('Discount'),
                amount: -$context['discountTotal'],
                width: $width
            );
        }

        foreach ($this->taxLines(context: $context, width: $width, invoice: $invoice) as $taxLine) {
            $lines[] = $taxLine;
        }

        $lines[] = str_repeat('=', $width);
        $lines[] = $this->amountRow(label: $this->l10n->t('Total'), amount: $context['total'], width: $width);
        $lines[] = str_repeat('=', $width);

        return $lines;
    }//end summaryLines()

    /**
     * Build the per-rate (invoice) or aggregate (receipt) BTW lines.
     *
     * @param array<string, mixed> $context The render context.
     * @param int                  $width   The layout width.
     * @param bool                 $invoice Whether this is an invoice.
     *
     * @return array<int, string> The tax lines.
     */
    private function taxLines(array $context, int $width, bool $invoice): array
    {
        if ($invoice === false) {
            return [$this->amountRow(label: $this->l10n->t('VAT'), amount: $context['totalTax'], width: $width)];
        }

        $lines = [];
        foreach ($context['btwLines'] as $btw) {
            $lines[] = $this->amountRow(label: $btw['label'], amount: $btw['tax'], width: $width);
        }

        return $lines;
    }//end taxLines()

    /**
     * Build the footer lines (compliance footer for invoices, thanks otherwise).
     *
     * @param array<string, mixed> $context The render context.
     * @param int                  $width   The layout width.
     * @param bool                 $invoice Whether this is an invoice.
     *
     * @return array<int, string> The footer lines.
     */
    private function footerLines(array $context, int $width, bool $invoice): array
    {
        if ($invoice === false) {
            return ['', $this->center(text: $this->l10n->t('Thank you for your purchase'), width: $width)];
        }

        return array_merge([''], $this->complianceFooter(context: $context));
    }//end footerLines()

    /**
     * The localized heading label for a receipt or invoice.
     *
     * @param bool $invoice Whether this is an invoice.
     *
     * @return string The heading label.
     */
    private function headingLabel(bool $invoice): string
    {
        if ($invoice === true) {
            return $this->l10n->t('INVOICE');
        }

        return $this->l10n->t('RECEIPT');
    }//end headingLabel()

    /**
     * Render a transaction to an HTML receipt body (for email / preview).
     *
     * @param array<string, mixed> $transaction The persisted transaction.
     * @param array<string, mixed> $template    The selected receipt template.
     *
     * @return string The HTML body (escaped content inside a <pre> block).
     *
     * @spec openspec/specs/pos-receipt-engine/spec.md
     */
    public function renderHtml(array $transaction, array $template=[]): string
    {
        $text = $this->renderText(transaction: $transaction, template: $template);

        // The receipt is monospace, fixed-width content. Escaping it and wrapping
        // in a <pre> block keeps the layout intact and makes injection of markup
        // through transaction / company fields impossible.
        $escaped = htmlspecialchars($text, (ENT_QUOTES | ENT_HTML5), 'UTF-8');

        return '<pre style="font-family:monospace;font-size:13px;line-height:1.35;'
            .'white-space:pre-wrap;margin:0;">'.$escaped.'</pre>';
    }//end renderHtml()

    /**
     * Render a transaction to an ESC/POS thermal byte stream.
     *
     * Produces the actual command stream a thermal printer consumes: init,
     * code-page select, centred bold header, the rendered receipt body, feed
     * and a full cut. Pure and deterministic — the bytes are unit-tested; live
     * spooling to a device is environment-dependent and handled by the caller.
     *
     * @param array<string, mixed> $transaction The persisted transaction.
     * @param array<string, mixed> $template    The selected receipt template.
     *
     * @return string The raw ESC/POS byte stream.
     *
     * @spec openspec/specs/pos-receipt-engine/spec.md
     */
    public function renderEscPos(array $transaction, array $template=[]): string
    {
        $invoice = $this->renderAsInvoice(transaction: $transaction, template: $template);
        $body    = $this->renderText(transaction: $transaction, template: $template);
        $heading = $this->headingLabel(invoice: $invoice);

        // The header lines are already part of the rendered text body; here we
        // wrap the whole body in printer control codes and emphasise the title.
        $out  = self::ESC_INIT;
        $out .= self::ESC_CODEPAGE_CP858;
        $out .= self::ESC_ALIGN_CENTER.self::ESC_BOLD_ON;
        $out .= $this->encodeForPrinter(text: $heading)."\n";
        $out .= self::ESC_BOLD_OFF.self::ESC_ALIGN_LEFT;
        $out .= $this->encodeForPrinter(text: $body);
        $out .= "\n\n\n";
        $out .= self::ESC_FULL_CUT;

        return $out;
    }//end renderEscPos()

    /**
     * Build the safe placeholder context from a persisted transaction.
     *
     * Reuses the server-computed invoiceBreakdown (falling back to taxBreakdown)
     * for the BTW lines — tax is never recomputed here. All monetary fields come
     * straight from the persisted, server-authoritative transaction.
     *
     * @param array<string, mixed> $transaction The persisted transaction.
     *
     * @return array<string, mixed> The render context.
     *
     * @spec openspec/specs/pos-receipt-engine/spec.md
     */
    public function buildContext(array $transaction): array
    {
        $btwRows  = $transaction['invoiceBreakdown'] ?? $transaction['taxBreakdown'] ?? [];
        $btwLines = [];
        if (is_array($btwRows) === true) {
            foreach ($btwRows as $row) {
                if (is_array($row) === false) {
                    continue;
                }

                $rate  = (float) ($row['rate'] ?? 0);
                $label = (string) ($row['description'] ?? '');
                if ($label === '') {
                    $label = rtrim(rtrim((string) $rate, '0'), '.').'% '.$this->l10n->t('VAT');
                }

                $btwLines[] = [
                    'rate'  => $rate,
                    'base'  => (float) ($row['base'] ?? 0),
                    'tax'   => (float) ($row['tax'] ?? 0),
                    'label' => $label,
                ];
            }//end foreach
        }//end if

        $lines = [];
        foreach (($transaction['lines'] ?? []) as $line) {
            if (is_array($line) === false) {
                continue;
            }

            $lines[] = [
                'description' => (string) ($line['description'] ?? ''),
                'quantity'    => (float) ($line['quantity'] ?? 0),
                'lineTotal'   => (float) ($line['lineTotal'] ?? 0),
            ];
        }

        return [
            'reference'     => (string) ($transaction['reference'] ?? ($transaction['id'] ?? '')),
            'date'          => $this->formatDate(value: (string) ($transaction['confirmedAt'] ?? $transaction['createdAt'] ?? '')),
            'subtotal'      => (float) ($transaction['subtotal'] ?? 0),
            'discountTotal' => (float) ($transaction['discountTotal'] ?? 0),
            'totalTax'      => (float) ($transaction['totalTax'] ?? 0),
            'total'         => (float) ($transaction['total'] ?? 0),
            'btwLines'      => $btwLines,
            'lines'         => $lines,
            'company'       => $this->companyDetails(),
            'invoiceNumber' => (string) ($transaction['invoiceNumber'] ?? ''),
        ];
    }//end buildContext()

    /**
     * The configured company details for the receipt header / footer.
     *
     * @return array{name: string, address: string, phone: string, vatId: string, kvk: string}
     *  The company details.
     *
     * @spec openspec/specs/pos-receipt-engine/spec.md
     */
    public function companyDetails(): array
    {
        return [
            'name'    => $this->appConfig->getValueString(Application::APP_ID, 'receipt_company_name', 'Conduction B.V.'),
            'address' => $this->appConfig->getValueString(Application::APP_ID, 'receipt_company_address', ''),
            'phone'   => $this->appConfig->getValueString(Application::APP_ID, 'receipt_company_phone', ''),
            'vatId'   => $this->appConfig->getValueString(Application::APP_ID, 'receipt_company_vat', ''),
            'kvk'     => $this->appConfig->getValueString(Application::APP_ID, 'receipt_company_kvk', ''),
        ];
    }//end companyDetails()

    /**
     * Resolve the layout width from the template, transaction or default.
     *
     * @param array<string, mixed> $template    The receipt template.
     * @param array<string, mixed> $transaction The transaction.
     *
     * @return int The line width in characters.
     */
    private function layoutWidth(array $template, array $transaction): int
    {
        $width = (int) ($template['layoutWidth'] ?? 0);
        if ($width < 20) {
            $width = self::DEFAULT_LAYOUT_WIDTH;
        }

        // Legal invoices use a wider 80-column layout for formal formatting.
        if ($this->isInvoiceTransaction(transaction: $transaction) === true
            && ($template['layoutWidth'] ?? 0) === 0
        ) {
            $width = 80;
        }

        return min(120, $width);
    }//end layoutWidth()

    /**
     * Build the Dutch legal-invoice compliance footer lines.
     *
     * @param array<string, mixed> $context The render context.
     *
     * @return array<int, string> The footer lines.
     *
     * @spec openspec/specs/pos-receipt-engine/spec.md
     */
    private function complianceFooter(array $context): array
    {
        $company = $context['company'];
        $footer  = [];

        if ($company['vatId'] !== '') {
            $footer[] = $this->l10n->t('VAT no.').': '.$company['vatId'];
        }

        if ($company['kvk'] !== '') {
            $footer[] = $this->l10n->t('Chamber of Commerce no.').': '.$company['kvk'];
        }

        if ($context['invoiceNumber'] !== '') {
            $footer[] = $this->l10n->t('Invoice no.').': '.$context['invoiceNumber'];
        }

        $footer[] = $this->l10n->t('Invoice date').': '.$context['date'];
        $footer[] = $this->l10n->t('Transaction reference').': '.$context['reference'];

        return $footer;
    }//end complianceFooter()

    /**
     * Render a single line item row.
     *
     * @param array<string, mixed> $line  The line context.
     * @param int                  $width The layout width.
     *
     * @return string The row.
     */
    private function lineRow(array $line, int $width): string
    {
        $qty   = rtrim(rtrim(number_format((float) $line['quantity'], 2, '.', ''), '0'), '.');
        $label = (string) $line['description'].' x'.$qty;
        $price = $this->money(value: (float) $line['lineTotal']);

        return $this->amountRow(label: $label, amount: (float) $line['lineTotal'], width: $width, prefix: '', display: $price);
    }//end lineRow()

    /**
     * Render a label + right-aligned euro amount on a single line.
     *
     * @param string      $label   The left label.
     * @param float       $amount  The amount.
     * @param int         $width   The layout width.
     * @param string      $prefix  An optional label prefix.
     * @param string|null $display An optional pre-formatted amount string.
     *
     * @return string The row.
     */
    private function amountRow(string $label, float $amount, int $width, string $prefix='', ?string $display=null): string
    {
        $right = $display ?? ('€'.$this->money(value: $amount));
        $left  = $prefix.$label;
        $space = ($width - mb_strlen($left) - mb_strlen($right));
        if ($space < 1) {
            $space = 1;
        }

        return $left.str_repeat(' ', $space).$right;
    }//end amountRow()

    /**
     * Centre a string within the layout width.
     *
     * @param string $text  The text.
     * @param int    $width The width.
     *
     * @return string The centred text.
     */
    private function center(string $text, int $width): string
    {
        $len = mb_strlen($text);
        if ($len >= $width) {
            return mb_substr($text, 0, $width);
        }

        $pad = (int) floor((($width - $len) / 2));

        return str_repeat(' ', $pad).$text;
    }//end center()

    /**
     * Format a euro amount to two decimals.
     *
     * @param float $value The value.
     *
     * @return string The formatted amount.
     */
    private function money(float $value): string
    {
        return number_format(round($value, 2), 2, ',', '.');
    }//end money()

    /**
     * Format an ISO timestamp for display, falling back to the raw value.
     *
     * @param string $value The ISO timestamp.
     *
     * @return string The display date.
     */
    private function formatDate(string $value): string
    {
        $timestamp = false;
        if ($value !== '') {
            $timestamp = strtotime($value);
        }

        if ($timestamp === false) {
            if ($value !== '') {
                return $value;
            }

            $timestamp = (new DateTimeImmutable())->getTimestamp();
        }

        return date('d-m-Y H:i', $timestamp);
    }//end formatDate()

    /**
     * Transcode UTF-8 receipt text to the CP858 thermal code page.
     *
     * The ESC/POS stream selects CP858 (Western European incl. €), so the body
     * is best-effort transcoded with iconv. When iconv is unavailable or the
     * conversion fails the original UTF-8 bytes are kept — the printer command
     * structure (the unit-tested contract) does not depend on the transcoding.
     * iconv warnings are caught locally so no error-control operator is needed.
     *
     * @param string $text The UTF-8 text.
     *
     * @return string The CP858-encoded (or original) text.
     */
    private function encodeForPrinter(string $text): string
    {
        if (function_exists('iconv') === false) {
            return $text;
        }

        set_error_handler(static fn (): bool => true);
        try {
            $encoded = iconv('UTF-8', 'CP858//TRANSLIT', $text);
        } finally {
            restore_error_handler();
        }

        if ($encoded === false) {
            return $text;
        }

        return $encoded;
    }//end encodeForPrinter()
}//end class
