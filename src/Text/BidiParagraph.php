<?php
/**
 * @package nativepdf
 * @link    https://github.com/Javaabu/nativepdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
namespace NativePdf\Text;

use NativePdf\FrameDecorator\AbstractFrameDecorator;
use NativePdf\FrameDecorator\Block as BlockFrameDecorator;
use NativePdf\FrameDecorator\Text as TextFrameDecorator;

/**
 * Paragraph-level bidirectional analysis for a block container's inline
 * formatting context.
 *
 * The paragraph buffer is assembled from the block's in-flow inline
 * descendants in logical order: text frames contribute their
 * whitespace-collapsed text, atomic inlines an object-replacement
 * character, non-`normal` `unicode-bidi` inline boundaries synthetic
 * directional controls, and `<br>`/block-level children paragraph
 * separators. Arabic text is shaped (joining across frame boundaries,
 * lam-alef ligatures merging across them). The resolved UAX #9 levels are
 * written back by splitting text frames at level-run boundaries; explicit
 * directional formatting characters are stripped from the rendered text.
 *
 * Analysis runs once per document: resolved levels and shaped text stay
 * valid across page re-reflows (all later mutations are level-preserving),
 * so none of this state is cleared by reset().
 *
 * @package nativepdf
 */
final class BidiParagraph
{
    const OBJECT_REPLACEMENT = 0xFFFC;
    const PARAGRAPH_SEPARATOR = 0x2029;

    /**
     * Analyze the inline content of a block container and attach resolved
     * embedding levels to its inline-level frames.
     *
     * @param BlockFrameDecorator $block
     */
    public static function process(BlockFrameDecorator $block): void
    {
        if ($block->bidi_analyzed) {
            return;
        }

        if (!$block->get_nativepdf()->getOptions()->isBidiEnabled()) {
            $block->bidi_analyzed = true;
            return;
        }

        $block->bidi_analyzed = true;

        $entries = [];
        $hasDirectionalContext = false;
        self::collect($block, $entries, $hasDirectionalContext);

        if (count($entries) === 0) {
            return;
        }

        // ---- Assemble the paragraph buffer -----------------------------
        $cps = [];
        $bufEntry = []; // parallel: entry index, or -1 for synthetic chars

        foreach ($entries as $ei => $entry) {
            if ($entry[0] === "text") {
                foreach ($entry[2] as $cp) {
                    $cps[] = $cp;
                    $bufEntry[] = $ei;
                }
            } elseif ($entry[0] === "atomic") {
                $cps[] = self::OBJECT_REPLACEMENT;
                $bufEntry[] = $ei;
            } elseif ($entry[0] === "ctl") {
                $cps[] = $entry[1];
                $bufEntry[] = -1;
            } else { // "sep"
                $cps[] = self::PARAGRAPH_SEPARATOR;
                $bufEntry[] = -1;
            }
        }

        // ---- Base direction --------------------------------------------
        $style = $block->get_style();
        $base = $style->direction === "rtl" ? 1 : 0;

        $node = $block->get_node();
        $plaintext = $style->unicode_bidi === "plaintext";

        if ($plaintext
            || ($node instanceof \DOMElement && strtolower($node->getAttribute("dir")) === "auto")
        ) {
            $base = BidiAnalyzer::paragraphLevel($cps);

            // Direction-dependent layout -- block alignment above all --
            // reads the computed direction, so the detected base has to
            // reach it too. Otherwise the paragraph reorders internally but
            // still starts at the left edge.
            $detected = $base === 1 ? "rtl" : "ltr";

            if ($style->direction !== $detected) {
                $style->set_used("direction", $detected);

                // text-align takes its default from the direction, and it
                // resolves at compute time, so an unset alignment has to be
                // redone against the direction just discovered
                if ($style->get_specified("text_align") === "") {
                    $style->set_used("text_align", $detected === "rtl" ? "right" : "left");
                }
            }
        }

        $block->bidi_base = $base;

        // Fast bail for pure left-to-right content
        if ($base === 0 && !$hasDirectionalContext && !self::needsBidi($cps)) {
            return;
        }

        // ---- Arabic shaping (before analysis and measurement) ----------
        $hasArabic = false;
        foreach ($cps as $cp) {
            if ($cp >= 0x0600 && $cp <= 0x08FF) {
                $hasArabic = true;
                break;
            }
        }

        if ($hasArabic) {
            $shaped = ArabicShaper::shapeCodePoints($cps);
            $newEntryOf = [];
            foreach ($shaped["src"] as $i => $srcIdx) {
                $newEntryOf[$i] = $bufEntry[$srcIdx];
            }
            $cps = $shaped["cps"];
            $bufEntry = $newEntryOf;
        }

        // ---- Resolve levels --------------------------------------------
        $result = self::resolveLevels($cps, $base, $plaintext);
        $levels = $result["levels"];
        $removed = $result["removed"];

        // ---- Write back ------------------------------------------------
        // Group the retained characters per entry
        $perEntry = [];
        foreach ($cps as $i => $cp) {
            $ei = $bufEntry[$i];
            if ($ei < 0 || $removed[$i]) {
                continue;
            }
            $perEntry[$ei][] = [$cp, $levels[$i]];
        }

        foreach ($entries as $ei => $entry) {
            if ($entry[0] === "atomic") {
                $kept = isset($perEntry[$ei]) ? $perEntry[$ei] : [];
                $entry[1]->bidi_level = count($kept) > 0 ? $kept[0][1] : $base;
                continue;
            }

            if ($entry[0] !== "text") {
                continue;
            }

            /** @var TextFrameDecorator $decorator */
            $decorator = $entry[1];
            $kept = isset($perEntry[$ei]) ? $perEntry[$ei] : [];

            // Build level runs
            $runs = [];
            foreach ($kept as [$cp, $level]) {
                $last = count($runs) - 1;
                if ($last >= 0 && $runs[$last][1] === $level) {
                    $runs[$last][0] .= ArabicShaper::encode($cp);
                    $runs[$last][2]++;
                } else {
                    $runs[] = [ArabicShaper::encode($cp), $level, 1];
                }
            }

            // Update the frame text if shaping or control-stripping
            // changed it
            $newText = "";
            foreach ($runs as $run) {
                $newText .= $run[0];
            }

            if ($newText !== $decorator->get_text()) {
                $decorator->set_text($newText);
            }

            if (count($runs) === 0) {
                $decorator->bidi_level = $base;
                continue;
            }

            // Split the frame at level-run boundaries
            $current = $decorator;
            foreach ($runs as $ri => $run) {
                $current->bidi_level = $run[1];

                if ($ri < count($runs) - 1) {
                    $next = $current->split_text($run[2], false);
                    if ($next === null) {
                        break;
                    }
                    $current = $next;
                }
            }
        }
    }

    /**
     * Reorder the frames of a completed line box into visual order
     * (L2), reassigning x positions. Runs after justification, so frame
     * widths are final; the operation is a pure permutation of positions.
     *
     * @param BlockFrameDecorator $block
     */
    public static function reorderLines(BlockFrameDecorator $block): void
    {
        $base = is_int($block->bidi_base) ? $block->bidi_base : 0;

        foreach ($block->get_line_boxes() as $line) {
            $frames = [];
            $levels = [];
            $needed = $base % 2 === 1;

            foreach ($line->get_frames() as $f) {
                // Inline element wrappers do not occupy line space
                // themselves and are not part of the line frame list. An
                // outside list marker is anchored to the border edge and
                // stays put; an inside one is inline content and reorders
                // with the rest of the line.
                if (($f instanceof \NativePdf\FrameDecorator\ListBullet
                        || $f instanceof \NativePdf\FrameDecorator\ListBulletImage)
                    && $f->get_parent()->get_style()->list_style_position === "outside"
                ) {
                    continue;
                }

                $level = is_int($f->bidi_level) ? $f->bidi_level : $base;
                $frames[] = $f;
                $levels[] = $level;

                if ($level % 2 === 1) {
                    $needed = true;
                }
            }

            if (!$needed || count($frames) < 1) {
                continue;
            }

            // UAX #9 L1: whitespace at the end of a line resets to the
            // paragraph level. Without it a line that ends in RTL text plus
            // a space -- reachable under white-space: pre-wrap -- carries
            // that space at an odd level and reordering moves it to the
            // front of the visual line.
            for ($i = count($frames) - 1; $i >= 0; $i--) {
                $f = $frames[$i];

                if (!$f->is_text_node() || trim($f->get_node()->nodeValue) !== "") {
                    break;
                }

                $levels[$i] = $base;
            }

            $order = BidiAnalyzer::visualOrder($levels);

            // Reposition sequentially from the leftmost frame edge
            $x = INF;
            foreach ($frames as $f) {
                $x = min($x, (float)$f->get_position("x"));
            }

            foreach ($order as $k) {
                $f = $frames[$k];
                $dx = $x - (float)$f->get_position("x");

                if ($dx != 0) {
                    $f->move($dx, 0);
                }

                $x += $f->get_margin_width();
            }
        }
    }

    /**
     * Collect the paragraph participants from the block's in-flow inline
     * content.
     *
     * @param AbstractFrameDecorator $frame
     * @param array                  $entries
     * @param bool                   $hasDirectionalContext
     */
    private static function collect(AbstractFrameDecorator $frame, array &$entries, bool &$hasDirectionalContext): void
    {
        foreach ($frame->get_children() as $child) {
            if (!$child->is_in_flow()) {
                continue;
            }

            if ($child->is_text_node()) {
                if ($child instanceof TextFrameDecorator) {
                    $reflower = $child->get_reflower();
                    $collapsed = $reflower->pre_process_text($child->get_text());

                    if ($collapsed !== $child->get_text()) {
                        $child->set_text($collapsed);
                    }

                    $entries[] = ["text", $child, BidiAnalyzer::toCodePoints($collapsed)];
                }
                continue;
            }

            $display = $child->get_style()->display;

            if ($display === "-nativepdf-br") {
                $entries[] = ["sep"];
                continue;
            }

            if ($display === "inline") {
                $style = $child->get_style();
                [$open, $close] = self::boundaryControls($child);

                if (count($open) > 0) {
                    $hasDirectionalContext = true;
                    foreach ($open as $cp) {
                        $entries[] = ["ctl", $cp];
                    }
                }

                self::collect($child, $entries, $hasDirectionalContext);

                foreach ($close as $cp) {
                    $entries[] = ["ctl", $cp];
                }
                continue;
            }

            if (in_array($display, ["inline-block", "inline-table", "inline-flex", "-nativepdf-image"], true)) {
                $entries[] = ["atomic", $child];
                continue;
            }

            // Block-level children divide the inline content like
            // paragraph separators; they analyze their own content
            $entries[] = ["sep"];
        }
    }

    /**
     * Resolve embedding levels for the block's buffer.
     *
     * `unicode-bidi: plaintext` makes every bidi paragraph -- the runs
     * separated by a hard break -- determine its own first-strong base
     * direction, rather than sharing one base across the whole block.
     *
     * https://www.w3.org/TR/css-writing-modes-3/#valdef-unicode-bidi-plaintext
     *
     * @param int[] $cps
     * @param int   $base
     * @param bool  $perParagraph
     *
     * @return array ["levels" => int[], "removed" => bool[]]
     */
    private static function resolveLevels(array $cps, int $base, bool $perParagraph): array
    {
        if (!$perParagraph) {
            return BidiAnalyzer::computeLevels($cps, $base);
        }

        $levels = [];
        $removed = [];
        $segment = [];

        $flush = function (array $segment) use (&$levels, &$removed) {
            if (count($segment) === 0) {
                return;
            }

            $resolved = BidiAnalyzer::computeLevels($segment, BidiAnalyzer::paragraphLevel($segment));

            foreach ($resolved["levels"] as $level) {
                $levels[] = $level;
            }

            foreach ($resolved["removed"] as $flag) {
                $removed[] = $flag;
            }
        };

        foreach ($cps as $cp) {
            $segment[] = $cp;

            if ($cp === self::PARAGRAPH_SEPARATOR) {
                $flush($segment);
                $segment = [];
            }
        }

        $flush($segment);

        return ["levels" => $levels, "removed" => $removed];
    }

    /**
     * The synthetic directional control characters wrapping an inline
     * element, per its computed unicode-bidi and direction.
     *
     * @param AbstractFrameDecorator $frame
     * @return array [openCps[], closeCps[]]
     */
    private static function boundaryControls(AbstractFrameDecorator $frame): array
    {
        $style = $frame->get_style();
        $ub = $style->unicode_bidi;

        if ($ub === "normal" || $ub === "") {
            return [[], []];
        }

        $node = $frame->get_node();
        $rtl = $style->direction === "rtl";

        // dir="auto" isolates with first-strong detection, and so does a
        // <bdi> that states no direction of its own: that is the whole
        // point of the element
        // https://html.spec.whatwg.org/multipage/text-level-semantics.html#the-bdi-element
        if ($node instanceof \DOMElement
            && (strtolower($node->getAttribute("dir")) === "auto"
                || (strtolower($node->nodeName) === "bdi" && !$node->hasAttribute("dir")))
        ) {
            return [[BidiAnalyzer::FSI], [BidiAnalyzer::PDI]];
        }

        switch ($ub) {
            case "embed":
                return [[$rtl ? BidiAnalyzer::RLE : BidiAnalyzer::LRE], [BidiAnalyzer::PDF]];
            case "bidi-override":
                return [[$rtl ? BidiAnalyzer::RLO : BidiAnalyzer::LRO], [BidiAnalyzer::PDF]];
            case "isolate":
                return [[$rtl ? BidiAnalyzer::RLI : BidiAnalyzer::LRI], [BidiAnalyzer::PDI]];
            case "isolate-override":
                return [
                    [$rtl ? BidiAnalyzer::RLI : BidiAnalyzer::LRI, $rtl ? BidiAnalyzer::RLO : BidiAnalyzer::LRO],
                    [BidiAnalyzer::PDF, BidiAnalyzer::PDI]
                ];
            case "plaintext":
                return [[BidiAnalyzer::FSI], [BidiAnalyzer::PDI]];
        }

        return [[], []];
    }

    /**
     * Whether the buffer contains content that changes under bidirectional
     * processing in a left-to-right paragraph.
     *
     * @param int[] $cps
     * @return bool
     */
    private static function needsBidi(array $cps): bool
    {
        foreach ($cps as $cp) {
            // Cheap ASCII short-circuit
            if ($cp < 0x0590) {
                continue;
            }

            $class = UnicodeData::bidiClass($cp);

            if ($class === "R" || $class === "AL" || $class === "AN"
                || $class === "RLE" || $class === "RLO" || $class === "RLI"
            ) {
                return true;
            }
        }

        return false;
    }
}
