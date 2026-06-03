<?php

/**
 * Pipelinq ContrastRatioCalculator.
 *
 * Computes the WCAG 2.x relative-luminance contrast ratio between two sRGB hex
 * colours and tests it against the AA 4.5:1 minimum for normal text. Used to
 * reject inaccessible tenant brand colours at save time so a portal can never
 * ship below WCAG 2.2 AA by misconfiguration (REQ-009).
 *
 * @category Util
 * @package  OCA\Pipelinq\Util
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/customer-portal/specs.md#REQ-009
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Util;

use InvalidArgumentException;

/**
 * WCAG contrast-ratio helper.
 *
 * @SuppressWarnings(PHPMD.ShortVariable) The single-letter names (r/g/b channels,
 *  l1/l2 luminances, rl/gl/bl linearised channels) are the canonical WCAG/sRGB
 *  formula symbols; longer names would obscure the maths.
 */
class ContrastRatioCalculator
{
    /**
     * The WCAG AA minimum contrast ratio for normal text.
     *
     * @var float
     */
    public const AA_MINIMUM = 4.5;

    /**
     * Compute the contrast ratio between two hex colours.
     *
     * @param string $color1 The first hex colour (e.g. #21468B).
     * @param string $color2 The second hex colour (e.g. #FFFFFF).
     *
     * @return float The contrast ratio (1.0 .. 21.0), rounded to 2 decimals.
     *
     * @throws \InvalidArgumentException When a colour is not a valid hex string.
     */
    public function calculate(string $color1, string $color2): float
    {
        $l1 = $this->relativeLuminance(hex: $color1);
        $l2 = $this->relativeLuminance(hex: $color2);

        $lighter = max($l1, $l2);
        $darker  = min($l1, $l2);

        return round((($lighter + 0.05) / ($darker + 0.05)), 2);
    }//end calculate()

    /**
     * Whether a ratio meets the WCAG AA minimum.
     *
     * @param float $ratio The contrast ratio.
     *
     * @return bool True when ratio >= 4.5.
     */
    public function meetsAaStandard(float $ratio): bool
    {
        return $ratio >= self::AA_MINIMUM;
    }//end meetsAaStandard()

    /**
     * Whether two colours together meet WCAG AA.
     *
     * @param string $color1 The first hex colour.
     * @param string $color2 The second hex colour.
     *
     * @return bool True when their contrast ratio meets AA.
     */
    public function colorsMeetAa(string $color1, string $color2): bool
    {
        return $this->meetsAaStandard(ratio: $this->calculate(color1: $color1, color2: $color2));
    }//end colorsMeetAa()

    /**
     * Compute the WCAG relative luminance of an sRGB hex colour.
     *
     * @param string $hex The hex colour.
     *
     * @return float The relative luminance (0.0 .. 1.0).
     *
     * @throws \InvalidArgumentException When the colour is invalid.
     */
    private function relativeLuminance(string $hex): float
    {
        [$r, $g, $b] = $this->toRgb(hex: $hex);

        $rl = $this->linearise(channel: $r / 255);
        $gl = $this->linearise(channel: $g / 255);
        $bl = $this->linearise(channel: $b / 255);

        return ((0.2126 * $rl) + (0.7152 * $gl) + (0.0722 * $bl));
    }//end relativeLuminance()

    /**
     * Linearise a gamma-encoded sRGB channel (WCAG formula).
     *
     * @param float $channel The 0..1 channel value.
     *
     * @return float The linearised value.
     */
    private function linearise(float $channel): float
    {
        if ($channel <= 0.03928) {
            return ($channel / 12.92);
        }

        return ((($channel + 0.055) / 1.055) ** 2.4);
    }//end linearise()

    /**
     * Parse a hex colour into [r, g, b] integer components.
     *
     * Accepts #RGB and #RRGGBB, with or without the leading '#'.
     *
     * @param string $hex The hex colour.
     *
     * @return array{0: int, 1: int, 2: int} The RGB components.
     *
     * @throws \InvalidArgumentException When the colour is invalid.
     */
    private function toRgb(string $hex): array
    {
        $value = ltrim(trim($hex), '#');

        if (preg_match('/^[0-9a-fA-F]{3}$/', $value) === 1) {
            $value = $value[0].$value[0].$value[1].$value[1].$value[2].$value[2];
        }

        if (preg_match('/^[0-9a-fA-F]{6}$/', $value) !== 1) {
            throw new InvalidArgumentException('Invalid hex colour: '.$hex);
        }

        return [
            (int) hexdec(substr($value, 0, 2)),
            (int) hexdec(substr($value, 2, 2)),
            (int) hexdec(substr($value, 4, 2)),
        ];
    }//end toRgb()
}//end class
