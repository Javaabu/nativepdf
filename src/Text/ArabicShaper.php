<?php
/**
 * @package dompdf
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
namespace Dompdf\Text;

/**
 * Contextual Arabic shaping using the Unicode presentation forms
 * (U+FB50-U+FDFF, U+FE70-U+FEFF): joining-type-driven selection of
 * isolated/final/initial/medial forms plus the mandatory lam-alef
 * ligatures.
 *
 * The replacement approach is limited to glyphs that exist as Unicode code
 * points (dompdf's PDF text layer addresses glyphs by code point); fonts
 * providing shaping only through OpenType GSUB tables are not supported.
 * Transparent characters (diacritics, ZWJ/ZWNJ) are preserved; shaping is
 * idempotent, as presentation forms themselves have no further forms.
 *
 * @package dompdf
 */
final class ArabicShaper
{
    /**
     * Whether the text contains characters in the Arabic blocks that may
     * require shaping.
     *
     * @param string $text
     * @return bool
     */
    public static function isArabic(string $text): bool
    {
        return preg_match('/[\x{0600}-\x{08FF}]/u', $text) === 1;
    }

    /**
     * Shape a UTF-8 string. The output may be shorter than the input
     * (lam-alef ligatures merge two letters into one glyph).
     *
     * @param string $text
     * @return string
     */
    public static function shape(string $text): string
    {
        $cps = BidiAnalyzer::toCodePoints($text);
        $shaped = self::shapeCodePoints($cps);

        $out = "";
        foreach ($shaped["cps"] as $cp) {
            $out .= self::encode($cp);
        }

        return $out;
    }

    /**
     * Shape an array of code points.
     *
     * @param int[] $cps
     * @return array ["cps" => int[], "src" => int[]] where src maps each
     *         output code point to the index of its source code point
     *         (ligatures map to their first component)
     */
    public static function shapeCodePoints(array $cps): array
    {
        $n = count($cps);
        $types = [];

        foreach ($cps as $cp) {
            $types[] = UnicodeData::joiningType($cp);
        }

        // Neighbor lookups skipping transparent characters
        $prevIndex = function (int $i) use ($types): int {
            for ($j = $i - 1; $j >= 0; $j--) {
                if ($types[$j] !== "T") {
                    return $j;
                }
            }
            return -1;
        };
        $nextIndex = function (int $i) use ($types, $n): int {
            for ($j = $i + 1; $j < $n; $j++) {
                if ($types[$j] !== "T") {
                    return $j;
                }
            }
            return -1;
        };

        // Whether the character at $i joins towards the following character
        // (i.e. can connect on its left side in visual RTL terms)
        $joinsForward = function (int $i) use ($types): bool {
            if ($i < 0) {
                return false;
            }
            $t = $types[$i];
            return $t === "D" || $t === "L" || $t === "C";
        };
        // Whether the character at $i joins towards the preceding character
        $joinsBackward = function (int $i) use ($types): bool {
            if ($i < 0) {
                return false;
            }
            $t = $types[$i];
            return $t === "D" || $t === "R" || $t === "C";
        };

        $out = [];
        $src = [];

        for ($i = 0; $i < $n; $i++) {
            $cp = $cps[$i];
            $type = $types[$i];

            if ($type === "T" || $type === "U" || $type === "C") {
                $out[] = $cp;
                $src[] = $i;
                continue;
            }

            $prev = $prevIndex($i);
            $next = $nextIndex($i);

            $joinPrev = $joinsForward($prev);

            // Lam-alef ligature: lam followed by an alef variant
            if ($cp === 0x0644 && $next !== -1) {
                $ligature = UnicodeData::lamAlefLigature($cps[$next]);

                if ($ligature !== null) {
                    // Final ligature when the lam connects to the previous
                    // character, isolated otherwise
                    $form = $joinPrev ? $ligature[1] : $ligature[0];

                    if ($form !== null) {
                        $out[] = $form;
                        $src[] = $i;

                        // Preserve transparent characters between the two,
                        // then skip the consumed alef
                        for ($j = $i + 1; $j < $next; $j++) {
                            $out[] = $cps[$j];
                            $src[] = $j;
                        }
                        $i = $next;
                        continue;
                    }
                }
            }

            $forms = UnicodeData::arabicForms($cp);

            if ($forms === null) {
                $out[] = $cp;
                $src[] = $i;
                continue;
            }

            $joinNext = $joinsBackward($next);

            if ($type === "D") {
                if ($joinPrev && $joinNext) {
                    $slot = 3; // medial
                } elseif ($joinPrev) {
                    $slot = 1; // final
                } elseif ($joinNext) {
                    $slot = 2; // initial
                } else {
                    $slot = 0; // isolated
                }
            } elseif ($type === "R") {
                $slot = $joinPrev ? 1 : 0;
            } elseif ($type === "L") {
                $slot = $joinNext ? 2 : 0;
            } else {
                $slot = 0;
            }

            // Fall back towards the isolated form, then the base character
            $form = $forms[$slot];
            if ($form === null && $slot === 3) {
                $form = $forms[1];
            }
            if ($form === null) {
                $form = $forms[0];
            }

            $out[] = $form !== null ? $form : $cp;
            $src[] = $i;
        }

        return ["cps" => $out, "src" => $src];
    }

    /**
     * Encode a code point as UTF-8.
     *
     * @param int $cp
     * @return string
     */
    public static function encode(int $cp): string
    {
        if ($cp < 0x80) {
            return chr($cp);
        }
        if ($cp < 0x800) {
            return chr(0xC0 | ($cp >> 6)) . chr(0x80 | ($cp & 0x3F));
        }
        if ($cp < 0x10000) {
            return chr(0xE0 | ($cp >> 12)) . chr(0x80 | (($cp >> 6) & 0x3F))
                . chr(0x80 | ($cp & 0x3F));
        }

        return chr(0xF0 | ($cp >> 18)) . chr(0x80 | (($cp >> 12) & 0x3F))
            . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
    }
}
