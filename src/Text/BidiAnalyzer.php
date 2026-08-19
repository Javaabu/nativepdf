<?php
/**
 * @package dompdf
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
namespace Dompdf\Text;

/**
 * Implementation of the Unicode Bidirectional Algorithm (UAX #9),
 * conformant per the official BidiTest/BidiCharacterTest suites: rules
 * P2-P3, X1-X10 (including isolates), W1-W7, N0-N2, and I1-I2 produce the
 * resolved embedding levels; L1 and L2 are provided for per-line
 * application.
 *
 * https://www.unicode.org/reports/tr9/
 *
 * @package dompdf
 */
final class BidiAnalyzer
{
    const MAX_DEPTH = 125;

    const LRE = 0x202A;
    const RLE = 0x202B;
    const PDF = 0x202C;
    const LRO = 0x202D;
    const RLO = 0x202E;
    const LRI = 0x2066;
    const RLI = 0x2067;
    const FSI = 0x2068;
    const PDI = 0x2069;

    /**
     * Convert a UTF-8 string to an array of code points.
     *
     * @param string $text
     * @return int[]
     */
    public static function toCodePoints(string $text): array
    {
        $cps = [];
        $len = strlen($text);
        $i = 0;

        while ($i < $len) {
            $b0 = ord($text[$i]);

            if ($b0 < 0x80) {
                $cps[] = $b0;
                $i += 1;
            } elseif (($b0 & 0xE0) === 0xC0 && $i + 1 < $len) {
                $cps[] = (($b0 & 0x1F) << 6) | (ord($text[$i + 1]) & 0x3F);
                $i += 2;
            } elseif (($b0 & 0xF0) === 0xE0 && $i + 2 < $len) {
                $cps[] = (($b0 & 0x0F) << 12) | ((ord($text[$i + 1]) & 0x3F) << 6)
                    | (ord($text[$i + 2]) & 0x3F);
                $i += 3;
            } elseif (($b0 & 0xF8) === 0xF0 && $i + 3 < $len) {
                $cps[] = (($b0 & 0x07) << 18) | ((ord($text[$i + 1]) & 0x3F) << 12)
                    | ((ord($text[$i + 2]) & 0x3F) << 6) | (ord($text[$i + 3]) & 0x3F);
                $i += 4;
            } else {
                $cps[] = 0xFFFD;
                $i += 1;
            }
        }

        return $cps;
    }

    /**
     * Fast scan for content that requires bidirectional processing: strong
     * right-to-left characters, Arabic numbers, or bidi formatting
     * characters.
     *
     * @param string $text
     * @return bool
     */
    public static function hasBidiContent(string $text): bool
    {
        // Pure ASCII cannot contain RTL content
        if (!preg_match('/[\x{0590}-\x{08FF}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FB1D}-\x{FDFF}\x{FE70}-\x{FEFF}\x{10800}-\x{10FFF}\x{1E800}-\x{1EFFF}]/u', $text)) {
            return false;
        }

        return true;
    }

    /**
     * Determine the paragraph embedding level per P2/P3.
     *
     * @param int[]      $cps
     * @param array|null $classes Optional precomputed classes
     * @return int 0 or 1
     */
    public static function paragraphLevel(array $cps, ?array $classes = null): int
    {
        if ($classes === null) {
            $classes = array_map([UnicodeData::class, "bidiClass"], $cps);
        }

        $isolate = 0;

        for ($i = 0; $i < count($classes); $i++) {
            $t = $classes[$i];

            if ($t === "LRI" || $t === "RLI" || $t === "FSI") {
                $isolate++;
            } elseif ($t === "PDI") {
                if ($isolate > 0) {
                    $isolate--;
                }
            } elseif ($isolate === 0) {
                if ($t === "L") {
                    return 0;
                }
                if ($t === "R" || $t === "AL") {
                    return 1;
                }
            }
        }

        return 0;
    }

    /**
     * Compute the resolved embedding levels of a paragraph.
     *
     * @param int[] $cps            The paragraph's code points
     * @param int   $paragraphLevel The paragraph embedding level
     *
     * @return array ["levels" => int[], "removed" => bool[]] where removed
     *         marks the X9 formatting characters that do not render
     */
    public static function computeLevels(array $cps, int $paragraphLevel): array
    {
        $classes = array_map([UnicodeData::class, "bidiClass"], $cps);

        return self::computeLevelsFromClasses($classes, $paragraphLevel, $cps);
    }

    /**
     * Compute resolved levels from explicit character classes; $cps is only
     * required for bracket-pair resolution (N0) and may be null when code
     * points are unavailable.
     *
     * @param string[]   $classes
     * @param int        $paragraphLevel
     * @param int[]|null $cps
     *
     * @return array ["levels" => int[], "removed" => bool[]]
     */
    public static function computeLevelsFromClasses(array $classes, int $paragraphLevel, ?array $cps = null): array
    {
        $n = count($classes);
        $levels = array_fill(0, $n, $paragraphLevel);
        $removed = array_fill(0, $n, false);
        $orig = $classes;

        if ($n === 0) {
            return ["levels" => [], "removed" => []];
        }

        // ---- BD9: matching PDI for each isolate initiator --------------
        $matchingPDI = array_fill(0, $n, -1);
        $matchingInitiator = array_fill(0, $n, -1);

        for ($i = 0; $i < $n; $i++) {
            $t = $classes[$i];
            if ($t !== "LRI" && $t !== "RLI" && $t !== "FSI") {
                continue;
            }

            $depth = 1;
            for ($j = $i + 1; $j < $n; $j++) {
                $u = $classes[$j];
                if ($u === "LRI" || $u === "RLI" || $u === "FSI") {
                    $depth++;
                } elseif ($u === "PDI") {
                    $depth--;
                    if ($depth === 0) {
                        $matchingPDI[$i] = $j;
                        $matchingInitiator[$j] = $i;
                        break;
                    }
                }
            }

            if ($matchingPDI[$i] === -1) {
                $matchingPDI[$i] = $n;
            }
        }

        // ---- X1-X8: explicit levels and directions ---------------------
        $stack = [[$paragraphLevel, "N", false]];
        $overflowIsolate = 0;
        $overflowEmbedding = 0;
        $validIsolate = 0;

        $top = function () use (&$stack) {
            return $stack[count($stack) - 1];
        };

        for ($i = 0; $i < $n; $i++) {
            $t = $classes[$i];

            switch ($t) {
                case "RLE":
                case "LRE":
                case "RLO":
                case "LRO":
                    // X2-X5
                    $levels[$i] = $top()[0];
                    $rtl = $t === "RLE" || $t === "RLO";
                    $newLevel = $rtl
                        ? ($top()[0] + ($top()[0] % 2 === 1 ? 2 : 1))
                        : ($top()[0] + ($top()[0] % 2 === 1 ? 1 : 2));

                    if ($newLevel <= self::MAX_DEPTH
                        && $overflowIsolate === 0 && $overflowEmbedding === 0
                    ) {
                        $override = $t === "RLO" ? "R" : ($t === "LRO" ? "L" : "N");
                        $stack[] = [$newLevel, $override, false];
                    } elseif ($overflowIsolate === 0) {
                        $overflowEmbedding++;
                    }
                    break;

                case "FSI":
                case "LRI":
                case "RLI":
                    // X5a-X5c
                    if ($t === "FSI") {
                        // First strong of the isolated content decides
                        $end = $matchingPDI[$i];
                        $rtl = self::paragraphLevel(
                            [],
                            array_slice($classes, $i + 1, $end - $i - 1)
                        ) === 1;
                    } else {
                        $rtl = $t === "RLI";
                    }

                    $levels[$i] = $top()[0];
                    if ($top()[1] !== "N") {
                        $classes[$i] = $top()[1];
                    }

                    $newLevel = $rtl
                        ? ($top()[0] + ($top()[0] % 2 === 1 ? 2 : 1))
                        : ($top()[0] + ($top()[0] % 2 === 1 ? 1 : 2));

                    if ($newLevel <= self::MAX_DEPTH
                        && $overflowIsolate === 0 && $overflowEmbedding === 0
                    ) {
                        $validIsolate++;
                        $stack[] = [$newLevel, "N", true];
                    } else {
                        $overflowIsolate++;
                    }
                    break;

                case "PDI":
                    // X6a
                    if ($overflowIsolate > 0) {
                        $overflowIsolate--;
                    } elseif ($validIsolate > 0) {
                        $overflowEmbedding = 0;

                        while (!$top()[2]) {
                            array_pop($stack);
                        }
                        array_pop($stack);
                        $validIsolate--;
                    }

                    $levels[$i] = $top()[0];
                    if ($top()[1] !== "N") {
                        $classes[$i] = $top()[1];
                    }
                    break;

                case "PDF":
                    // X7
                    $levels[$i] = $top()[0];

                    if ($overflowIsolate > 0) {
                        // no-op
                    } elseif ($overflowEmbedding > 0) {
                        $overflowEmbedding--;
                    } elseif (!$top()[2] && count($stack) >= 2) {
                        array_pop($stack);
                    }
                    break;

                case "B":
                    // X8
                    $levels[$i] = $paragraphLevel;
                    break;

                case "BN":
                    $levels[$i] = $top()[0];
                    break;

                default:
                    // X6
                    $levels[$i] = $top()[0];
                    if ($top()[1] !== "N") {
                        $classes[$i] = $top()[1];
                    }
                    break;
            }
        }

        // ---- X9: remove explicit formatting characters -----------------
        for ($i = 0; $i < $n; $i++) {
            $t = $orig[$i];
            if ($t === "RLE" || $t === "LRE" || $t === "RLO" || $t === "LRO"
                || $t === "PDF" || $t === "BN"
            ) {
                $removed[$i] = true;
            }
        }

        // Indices of the retained characters
        $retained = [];
        for ($i = 0; $i < $n; $i++) {
            if (!$removed[$i]) {
                $retained[] = $i;
            }
        }

        if (count($retained) === 0) {
            return ["levels" => $levels, "removed" => $removed];
        }

        // ---- X10: isolating run sequences ------------------------------
        // Level runs over the retained characters
        $runs = [];
        $runStart = 0;

        for ($k = 1; $k <= count($retained); $k++) {
            if ($k === count($retained)
                || $levels[$retained[$k]] !== $levels[$retained[$runStart]]
            ) {
                $runs[] = array_slice($retained, $runStart, $k - $runStart);
                $runStart = $k;
            }
        }

        // Group runs into isolating run sequences (BD13)
        $runOfIndex = [];
        foreach ($runs as $ri => $run) {
            foreach ($run as $idx) {
                $runOfIndex[$idx] = $ri;
            }
        }

        $usedRun = array_fill(0, count($runs), false);
        $sequences = [];

        foreach ($runs as $ri => $run) {
            if ($usedRun[$ri]) {
                continue;
            }

            // A run starting with a PDI that has a matching initiator is
            // attached to the initiator's sequence
            $first = $run[0];
            if ($classes[$first] === "PDI" && $matchingInitiator[$first] !== -1) {
                continue;
            }

            $seq = [];
            $currentRun = $ri;

            while (true) {
                $usedRun[$currentRun] = true;
                $seq = array_merge($seq, $runs[$currentRun]);

                $last = $runs[$currentRun][count($runs[$currentRun]) - 1];
                $lastClass = $classes[$last];

                if (($lastClass === "LRI" || $lastClass === "RLI" || $lastClass === "FSI")
                    && $matchingPDI[$last] !== $n && $matchingPDI[$last] !== -1
                ) {
                    $pdi = $matchingPDI[$last];
                    if (isset($runOfIndex[$pdi]) && !$usedRun[$runOfIndex[$pdi]]) {
                        $currentRun = $runOfIndex[$pdi];
                        continue;
                    }
                }

                break;
            }

            $sequences[] = $seq;
        }

        // ---- Resolve each isolating run sequence -----------------------
        // sos/eos are determined from the levels as assigned by the X
        // rules, before I1/I2 modify them
        $xLevels = $levels;

        foreach ($sequences as $seq) {
            $seqLevel = $xLevels[$seq[0]];

            // sos: compare with the level of the closest preceding retained
            // character (skipping removed), or the paragraph level
            $firstIdx = $seq[0];
            $prevLevel = $paragraphLevel;
            $pos = array_search($firstIdx, $retained, true);
            if ($pos > 0) {
                $prevLevel = $xLevels[$retained[$pos - 1]];
            }
            $sos = max($seqLevel, $prevLevel) % 2 === 0 ? "L" : "R";

            // eos: similarly with the following character; sequences ending
            // with an unmatched isolate initiator use the paragraph level
            $lastIdx = $seq[count($seq) - 1];
            $lastClass = $classes[$lastIdx];
            $nextLevel = $paragraphLevel;

            if (($lastClass === "LRI" || $lastClass === "RLI" || $lastClass === "FSI")
                && $matchingPDI[$lastIdx] === $n
            ) {
                $nextLevel = $paragraphLevel;
            } else {
                $pos = array_search($lastIdx, $retained, true);
                if ($pos !== false && $pos < count($retained) - 1) {
                    $nextLevel = $xLevels[$retained[$pos + 1]];
                }
            }
            $eos = max($seqLevel, $nextLevel) % 2 === 0 ? "L" : "R";

            self::resolveWeakTypes($classes, $seq, $sos);
            if ($cps !== null) {
                self::resolveBrackets($classes, $seq, $cps, $seqLevel, $sos);
            }
            self::resolveNeutralTypes($classes, $seq, $seqLevel, $sos, $eos);
            self::resolveImplicitLevels($classes, $levels, $seq, $seqLevel);
        }

        return ["levels" => $levels, "removed" => $removed];
    }

    /**
     * W1-W7.
     *
     * @param string[] $classes
     * @param int[]    $seq
     * @param string   $sos
     */
    private static function resolveWeakTypes(array &$classes, array $seq, string $sos): void
    {
        $count = count($seq);

        // W1: NSM takes the class of the previous character; ON after
        // isolate initiators and PDI
        $prev = $sos;
        foreach ($seq as $idx) {
            $t = $classes[$idx];

            if ($t === "NSM") {
                $classes[$idx] = ($prev === "LRI" || $prev === "RLI" || $prev === "FSI" || $prev === "PDI")
                    ? "ON"
                    : $prev;
            }

            $prev = $t === "NSM" ? $classes[$idx] : $t;
        }

        // W2: EN with a preceding strong AL becomes AN
        $strong = $sos;
        foreach ($seq as $idx) {
            $t = $classes[$idx];

            if ($t === "EN" && $strong === "AL") {
                $classes[$idx] = "AN";
            }

            if ($t === "L" || $t === "R" || $t === "AL") {
                $strong = $t;
            }
        }

        // W3: AL becomes R
        foreach ($seq as $idx) {
            if ($classes[$idx] === "AL") {
                $classes[$idx] = "R";
            }
        }

        // W4: single ES between EN pair -> EN; single CS between a pair of
        // the same numeric type -> that type
        for ($k = 1; $k < $count - 1; $k++) {
            $t = $classes[$seq[$k]];
            $before = $classes[$seq[$k - 1]];
            $after = $classes[$seq[$k + 1]];

            if ($t === "ES" && $before === "EN" && $after === "EN") {
                $classes[$seq[$k]] = "EN";
            } elseif ($t === "CS"
                && (($before === "EN" && $after === "EN") || ($before === "AN" && $after === "AN"))
            ) {
                $classes[$seq[$k]] = $before;
            }
        }

        // W5: runs of ET adjacent to EN become EN
        for ($k = 0; $k < $count; $k++) {
            if ($classes[$seq[$k]] !== "ET") {
                continue;
            }

            $runEnd = $k;
            while ($runEnd < $count && $classes[$seq[$runEnd]] === "ET") {
                $runEnd++;
            }

            $beforeEN = $k > 0 && $classes[$seq[$k - 1]] === "EN";
            $afterEN = $runEnd < $count && $classes[$seq[$runEnd]] === "EN";

            if ($beforeEN || $afterEN) {
                for ($m = $k; $m < $runEnd; $m++) {
                    $classes[$seq[$m]] = "EN";
                }
            }

            $k = $runEnd - 1;
        }

        // W6: remaining separators and terminators become ON
        foreach ($seq as $idx) {
            $t = $classes[$idx];
            if ($t === "ET" || $t === "ES" || $t === "CS") {
                $classes[$idx] = "ON";
            }
        }

        // W7: EN with a preceding strong L becomes L
        $strong = $sos;
        foreach ($seq as $idx) {
            $t = $classes[$idx];

            if ($t === "EN" && $strong === "L") {
                $classes[$idx] = "L";
            }

            if ($t === "L" || $t === "R") {
                $strong = $t;
            }
        }
    }

    /**
     * N0: paired brackets (BD16).
     *
     * @param string[] $classes
     * @param int[]    $seq
     * @param int[]    $cps
     * @param int      $seqLevel
     * @param string   $sos
     */
    private static function resolveBrackets(array &$classes, array $seq, array $cps, int $seqLevel, string $sos): void
    {
        $embedding = $seqLevel % 2 === 0 ? "L" : "R";
        $opposite = $embedding === "L" ? "R" : "L";

        // Canonical equivalence for bracket matching
        $canonical = function (int $cp): int {
            if ($cp === 0x2329) {
                return 0x3008;
            }
            if ($cp === 0x232A) {
                return 0x3009;
            }
            return $cp;
        };

        // BD16: identify bracket pairs with a stack of at most 63 openers
        $stack = [];
        $pairs = [];

        foreach ($seq as $pos => $idx) {
            if ($classes[$idx] !== "ON") {
                continue;
            }

            $bracket = UnicodeData::bracket($cps[$idx]);
            if ($bracket === null) {
                continue;
            }

            if ($bracket[1] === "o") {
                if (count($stack) >= 63) {
                    break;
                }
                // Store the expected (canonicalized) closing bracket
                $stack[] = [$canonical($bracket[0]), $pos];
            } else {
                $closing = $canonical($cps[$idx]);
                for ($s = count($stack) - 1; $s >= 0; $s--) {
                    if ($stack[$s][0] === $closing) {
                        $pairs[] = [$stack[$s][1], $pos];
                        $stack = array_slice($stack, 0, $s);
                        break;
                    }
                }
            }
        }

        usort($pairs, function ($a, $b) {
            return $a[0] <=> $b[0];
        });

        $strongClass = function (string $t): ?string {
            if ($t === "L") {
                return "L";
            }
            if ($t === "R" || $t === "EN" || $t === "AN") {
                return "R";
            }
            return null;
        };

        foreach ($pairs as [$openPos, $closePos]) {
            // Find strong types inside the pair
            $foundEmbedding = false;
            $foundOpposite = false;

            for ($k = $openPos + 1; $k < $closePos; $k++) {
                $strong = $strongClass($classes[$seq[$k]]);
                if ($strong === $embedding) {
                    $foundEmbedding = true;
                } elseif ($strong === $opposite) {
                    $foundOpposite = true;
                }
            }

            if ($foundEmbedding) {
                $newClass = $embedding;
            } elseif ($foundOpposite) {
                // Check the preceding context
                $context = $sos;
                for ($k = $openPos - 1; $k >= 0; $k--) {
                    $strong = $strongClass($classes[$seq[$k]]);
                    if ($strong !== null) {
                        $context = $strong;
                        break;
                    }
                }

                $newClass = $context === $opposite ? $opposite : $embedding;
            } else {
                continue;
            }

            $classes[$seq[$openPos]] = $newClass;
            $classes[$seq[$closePos]] = $newClass;

            // NSMs following a changed bracket copy its class
            foreach ([$openPos, $closePos] as $bracketPos) {
                for ($k = $bracketPos + 1; $k < count($seq); $k++) {
                    $cp = $cps[$seq[$k]];
                    if (UnicodeData::bidiClass($cp) === "NSM") {
                        $classes[$seq[$k]] = $newClass;
                    } else {
                        break;
                    }
                }
            }
        }
    }

    /**
     * N1-N2.
     *
     * @param string[] $classes
     * @param int[]    $seq
     * @param int      $seqLevel
     * @param string   $sos
     * @param string   $eos
     */
    private static function resolveNeutralTypes(array &$classes, array $seq, int $seqLevel, string $sos, string $eos): void
    {
        $count = count($seq);
        $embedding = $seqLevel % 2 === 0 ? "L" : "R";

        $isNI = function (string $t): bool {
            return $t === "B" || $t === "S" || $t === "WS" || $t === "ON"
                || $t === "FSI" || $t === "LRI" || $t === "RLI" || $t === "PDI";
        };

        // Strong context for N1: EN and AN count as R
        $strongOf = function (string $t): ?string {
            if ($t === "L") {
                return "L";
            }
            if ($t === "R" || $t === "EN" || $t === "AN") {
                return "R";
            }
            return null;
        };

        for ($k = 0; $k < $count; $k++) {
            if (!$isNI($classes[$seq[$k]])) {
                continue;
            }

            $runEnd = $k;
            while ($runEnd < $count && $isNI($classes[$seq[$runEnd]])) {
                $runEnd++;
            }

            $before = $k > 0 ? $strongOf($classes[$seq[$k - 1]]) : $sos;
            $after = $runEnd < $count ? $strongOf($classes[$seq[$runEnd]]) : $eos;

            if ($before !== null && $before === $after) {
                // N1
                $resolved = $before;
            } else {
                // N2
                $resolved = $embedding;
            }

            for ($m = $k; $m < $runEnd; $m++) {
                $classes[$seq[$m]] = $resolved;
            }

            $k = $runEnd - 1;
        }
    }

    /**
     * I1-I2.
     *
     * @param string[] $classes
     * @param int[]    $levels
     * @param int[]    $seq
     * @param int      $seqLevel
     */
    private static function resolveImplicitLevels(array $classes, array &$levels, array $seq, int $seqLevel): void
    {
        foreach ($seq as $idx) {
            $t = $classes[$idx];

            if ($seqLevel % 2 === 0) {
                // I1
                if ($t === "R") {
                    $levels[$idx] = $seqLevel + 1;
                } elseif ($t === "AN" || $t === "EN") {
                    $levels[$idx] = $seqLevel + 2;
                }
            } else {
                // I2
                if ($t === "L" || $t === "AN" || $t === "EN") {
                    $levels[$idx] = $seqLevel + 1;
                }
            }
        }
    }

    /**
     * L1: on a line, segment separators, paragraph separators, and any
     * contiguous sequence of whitespace/isolate-formatting characters
     * preceding them or at the end of the line reset to the paragraph
     * level. Uses the ORIGINAL character classes.
     *
     * @param int[]         $levels          Resolved levels for the line
     * @param int[]         $cps             The line's code points
     * @param int           $paragraphLevel
     * @param string[]|null $classes         Optional precomputed original classes
     *
     * @return int[] Adjusted levels
     */
    public static function applyL1(array $levels, array $cps, int $paragraphLevel, ?array $classes = null): array
    {
        if ($classes === null) {
            $classes = array_map([UnicodeData::class, "bidiClass"], $cps);
        }

        $n = count($classes);
        $resettable = function (string $t): bool {
            return $t === "WS" || $t === "FSI" || $t === "LRI" || $t === "RLI" || $t === "PDI"
                || $t === "RLE" || $t === "LRE" || $t === "RLO" || $t === "LRO"
                || $t === "PDF" || $t === "BN";
        };

        $lastNonWs = -1;

        for ($i = 0; $i < $n; $i++) {
            $t = $classes[$i];

            if ($t === "S" || $t === "B") {
                $levels[$i] = $paragraphLevel;

                // Reset the preceding whitespace run
                for ($j = $i - 1; $j > $lastNonWs; $j--) {
                    $levels[$j] = $paragraphLevel;
                }
                $lastNonWs = $i;
            } elseif (!$resettable($t)) {
                $lastNonWs = $i;
            }
        }

        // Trailing whitespace
        for ($i = $n - 1; $i > $lastNonWs; $i--) {
            $levels[$i] = $paragraphLevel;
        }

        return $levels;
    }

    /**
     * L2: compute the visual order of a line as a permutation of the
     * indices of the non-removed characters.
     *
     * @param int[]       $levels
     * @param bool[]|null $removed
     *
     * @return int[] Indices in visual order
     */
    public static function visualOrder(array $levels, ?array $removed = null): array
    {
        $indices = [];
        $lineLevels = [];

        foreach ($levels as $i => $level) {
            if ($removed === null || !$removed[$i]) {
                $indices[] = $i;
                $lineLevels[] = $level;
            }
        }

        $count = count($indices);
        if ($count === 0) {
            return [];
        }

        $max = max($lineLevels);
        $minOdd = PHP_INT_MAX;
        foreach ($lineLevels as $level) {
            if ($level % 2 === 1 && $level < $minOdd) {
                $minOdd = $level;
            }
        }

        if ($minOdd === PHP_INT_MAX) {
            return $indices;
        }

        for ($level = $max; $level >= $minOdd; $level--) {
            for ($k = 0; $k < $count; $k++) {
                if ($lineLevels[$k] < $level) {
                    continue;
                }

                $runEnd = $k;
                while ($runEnd < $count && $lineLevels[$runEnd] >= $level) {
                    $runEnd++;
                }

                // Reverse indices in [k, runEnd)
                $slice = array_reverse(array_slice($indices, $k, $runEnd - $k));
                array_splice($indices, $k, $runEnd - $k, $slice);
                $slice = array_reverse(array_slice($lineLevels, $k, $runEnd - $k));
                array_splice($lineLevels, $k, $runEnd - $k, $slice);

                $k = $runEnd - 1;
            }
        }

        return $indices;
    }
}
