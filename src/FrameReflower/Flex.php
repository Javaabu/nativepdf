<?php
/**
 * @package dompdf
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
namespace Dompdf\FrameReflower;

use Dompdf\FrameDecorator\AbstractFrameDecorator;
use Dompdf\FrameDecorator\Block as BlockFrameDecorator;
use Dompdf\FrameDecorator\Flex as FlexFrameDecorator;
use Dompdf\Helpers;

/**
 * Reflows flex containers
 *
 * Implements the CSS Flexible Box Layout Module Level 1 layout algorithm
 * (https://www.w3.org/TR/css-flexbox-1/#layout-algorithm) within dompdf's
 * architecture: the container computes all item geometry centrally and
 * pushes positions to items (cf. Cellmap for tables), imposing resolved
 * sizes through used style values before reflowing each item.
 *
 * Scope notes: `baseline` alignment is treated as `flex-start`; wrapping is
 * supported for row directions (column containers are laid out as a single
 * line); `wrap-reverse` mirrors the line stacking order.
 *
 * @package dompdf
 */
class Flex extends Block
{
    /**
     * Content height as determined by flex layout, consumed by
     * _calculate_content_height() during height resolution.
     *
     * @var float
     */
    protected $flex_content_height = 0.0;

    /**
     * @param BlockFrameDecorator|null $block
     */
    function reflow(?BlockFrameDecorator $block = null)
    {
        $frame = $this->_frame;
        $page = $frame->get_root();

        // Check if a page break is forced
        $page->check_forced_page_break($frame);

        // Bail if the page is full
        if ($page->is_full()) {
            return;
        }

        // Suppress page breaks inside the container; an overflowing
        // container is moved to the next page as a whole
        $page->flex_reflow_start();

        try {
            $this->reflow_flex($block);
        } finally {
            $page->flex_reflow_end();
        }
    }

    /**
     * @param BlockFrameDecorator|null $block
     */
    protected function reflow_flex(?BlockFrameDecorator $block): void
    {
        /** @var FlexFrameDecorator $frame */
        $frame = $this->_frame;
        $style = $frame->get_style();
        $cb = $frame->get_containing_block();
        $page = $frame->get_root();

        $this->determine_absolute_containing_block();

        // Counters and generated content
        $this->_set_content();

        $frame->normalize();

        // Container main size (width)
        [$width, $margin_left, $margin_right, $left, $right] = $this->_calculate_restricted_width();
        $style->set_used("width", $width);
        $style->set_used("margin_left", $margin_left);
        $style->set_used("margin_right", $margin_right);
        $style->set_used("left", $left);
        $style->set_used("right", $right);

        // Track auto values for the absolute-position re-move below
        $auto_top = $style->top === "auto";
        $auto_margin_top = $style->margin_top === "auto";

        $frame->position();
        [$x, $y] = $frame->get_position();

        // Content box origin
        $offset_left = (float)$style->length_in_pt(
            [$style->margin_left, $style->border_left_width, $style->padding_left],
            $cb["w"]
        );
        $offset_top = (float)$style->length_in_pt(
            [$style->margin_top, $style->border_top_width, $style->padding_top],
            $cb["w"]
        );
        $cb_x = $x + $offset_left;
        $cb_y = $y + $offset_top;

        // Definite inner cross size (height), if the container has one
        $inner_height = null;
        $computed_height = $style->height;
        if ($computed_height !== "auto"
            && !(!isset($cb["h"]) && Helpers::is_percent($computed_height))
        ) {
            $resolved = $style->length_in_pt($computed_height, $cb["h"] ?? 0);
            if ($resolved !== "auto") {
                $inner_height = max(0.0, (float)$resolved - $this->border_box_height_delta($cb["w"]));
            }
        }

        // Flex layout
        $items = $frame->get_flex_items();
        $direction = $style->flex_direction;
        $column = $direction === "column" || $direction === "column-reverse";

        if (count($items) > 0) {
            $this->flex_content_height = $column
                ? $this->layout_column($items, $width, $inner_height, $cb_x, $cb_y)
                : $this->layout_rows($items, $width, $inner_height, $cb_x, $cb_y);
        } else {
            $this->flex_content_height = 0.0;
        }

        // Stop if a page break occurred before the container (it has been
        // reset, including its position)
        if ($page->is_full() && $frame->get_position("x") === null) {
            return;
        }

        // Out-of-flow children are not flex items, but still need layout
        foreach ($frame->get_children() as $child) {
            if (!$child->is_in_flow()) {
                $child->set_containing_block($cb_x, $cb_y, $width, $cb["h"] ?? null);
                $child->reflow(null);
            }
        }

        // Container height
        [$height, $margin_top, $margin_bottom, $top, $bottom] = $this->_calculate_restricted_height();

        $style->set_used("height", $height);
        $style->set_used("margin_top", $margin_top);
        $style->set_used("margin_bottom", $margin_bottom);
        $style->set_used("top", $top);
        $style->set_used("bottom", $bottom);

        if ($frame->is_absolute()) {
            if ($auto_top) {
                $frame->move(0, $top);
            }
            if ($auto_margin_top) {
                $frame->move(0, $margin_top, true);
            }
        }

        // Handle relative positioning
        foreach ($frame->get_children() as $child) {
            $this->position_relative($child);
        }

        if ($block && $frame->is_in_flow()) {
            $block->add_frame_to_line($frame);

            if ($frame->is_block_level()) {
                $block->add_line();
            }
        }
    }

    /**
     * The content height determined by flex layout.
     *
     * @return float
     */
    protected function _calculate_content_height(): float
    {
        return $this->flex_content_height;
    }

    /**
     * Row-direction layout: partition items into lines, lay out each line,
     * then distribute the lines along the cross axis per align-content.
     *
     * @param AbstractFrameDecorator[] $items
     * @param float                    $container_main Container content width
     * @param float|null               $definite_cross Definite content height
     * @param float                    $cb_x
     * @param float                    $cb_y
     *
     * @return float The natural content height
     */
    protected function layout_rows(
        array $items,
        float $container_main,
        ?float $definite_cross,
        float $cb_x,
        float $cb_y
    ): float {
        $style = $this->_frame->get_style();
        $page = $this->_frame->get_root();

        $gap = (float)$style->length_in_pt($style->column_gap, $container_main);
        $row_gap = (float)$style->length_in_pt($style->row_gap, $definite_cross ?? 0);
        $wrap = $style->flex_wrap !== "nowrap";
        $wrap_reverse = $style->flex_wrap === "wrap-reverse";

        $metrics = [];
        foreach ($items as $item) {
            $metrics[] = $this->item_metrics($item, $container_main);
        }

        // ---- Partition into flex lines (§9.3) --------------------------
        $lines = [];

        if (!$wrap) {
            $lines[] = array_keys($metrics);
        } else {
            $line = [];
            $used = 0.0;

            foreach ($metrics as $i => $m) {
                $needed = $m["hyp"] + (count($line) > 0 ? $gap : 0.0);

                if (count($line) > 0 && $used + $needed > $container_main + 0.01) {
                    $lines[] = $line;
                    $line = [];
                    $used = 0.0;
                    $needed = $m["hyp"];
                }

                $line[] = $i;
                $used += $needed;
            }

            if (count($line) > 0) {
                $lines[] = $line;
            }
        }

        // A single line in a nowrap container spans a definite cross size
        $single_definite = !$wrap && count($lines) === 1 && $definite_cross !== null
            ? $definite_cross
            : null;

        // ---- Lay out each line at its natural position -----------------
        $line_offsets = [];
        $line_crosses = [];
        $cursor_y = 0.0;

        foreach ($lines as $line) {
            $line_offsets[] = $cursor_y;
            $cross = $this->layout_row_line(
                $line,
                $metrics,
                $items,
                $container_main,
                $single_definite,
                $cb_x,
                $cb_y + $cursor_y,
                $gap
            );

            // A page break through the no-valid-break fallback resets the
            // container; abandon layout
            if ($page->is_full() && $this->_frame->get_position("x") === null) {
                return 0.0;
            }

            $line_crosses[] = $cross;
            $cursor_y += $cross + $row_gap;
        }

        $natural_total = $cursor_y - (count($lines) > 0 ? $row_gap : 0.0);

        // ---- align-content (§8.4) --------------------------------------
        $container_cross = $definite_cross ?? $natural_total;
        $free = $container_cross - $natural_total;
        $n_lines = count($lines);

        $align = $style->align_content;
        if ($align === "start") {
            $align = "flex-start";
        } elseif ($align === "end") {
            $align = "flex-end";
        }

        if ($free < 0) {
            if ($align === "space-between" || $align === "stretch") {
                $align = "flex-start";
            } elseif ($align === "space-around" || $align === "space-evenly") {
                $align = "center";
            }
        }

        $leading = 0.0;
        $between = 0.0;
        $extra_cross = 0.0;

        switch ($align) {
            case "flex-end":
                $leading = $free;
                break;
            case "center":
                $leading = $free / 2;
                break;
            case "space-between":
                $between = $n_lines > 1 ? $free / ($n_lines - 1) : 0.0;
                break;
            case "space-around":
                $between = $free / $n_lines;
                $leading = $between / 2;
                break;
            case "space-evenly":
                $between = $free / ($n_lines + 1);
                $leading = $between;
                break;
            case "stretch":
                $extra_cross = $free > 0 ? $free / $n_lines : 0.0;
                break;
            case "flex-start":
            default:
                break;
        }

        // Final line offsets and cross sizes
        $final_offsets = [];
        $final_crosses = [];
        $offset = $leading;

        foreach ($lines as $li => $line) {
            $final_crosses[$li] = $line_crosses[$li] + $extra_cross;
            $final_offsets[$li] = $offset;
            $offset += $final_crosses[$li] + $row_gap + $between;
        }

        if ($wrap_reverse) {
            // Mirror the line stacking within the container cross size
            foreach ($lines as $li => $line) {
                $final_offsets[$li] = $container_cross - $final_offsets[$li] - $final_crosses[$li];
            }
        }

        // ---- Shift lines and align items within them -------------------
        foreach ($lines as $li => $line) {
            $dy = $final_offsets[$li] - $line_offsets[$li];

            foreach ($line as $i) {
                if ($dy != 0) {
                    $items[$i]->move(0, $dy);
                }

                $this->align_item_cross($items[$i], $metrics[$i], $final_crosses[$li], $container_main);
            }
        }

        return $natural_total;
    }

    /**
     * Lay out one row-direction flex line: resolve flexible lengths (§9.7),
     * distribute auto margins and justify-content offsets, impose sizes,
     * and reflow the items. Cross alignment is applied by the caller.
     *
     * @param int[]                    $line    Indices into $metrics/$items
     * @param array[]                  $metrics
     * @param AbstractFrameDecorator[] $items
     * @param float                    $container_main
     * @param float|null               $definite_cross Definite line cross size
     * @param float                    $cb_x
     * @param float                    $line_y
     * @param float                    $gap
     *
     * @return float The cross size of the line
     */
    protected function layout_row_line(
        array $line,
        array &$metrics,
        array $items,
        float $container_main,
        ?float $definite_cross,
        float $cb_x,
        float $line_y,
        float $gap
    ): float {
        $style = $this->_frame->get_style();
        $n = count($line);
        $gaps_total = $gap * max(0, $n - 1);

        $this->resolve_flexible_lengths($metrics, $line, $container_main, $gaps_total);

        // ---- Main-axis auto margins (§8.1) -----------------------------
        $free = $container_main - $gaps_total;
        foreach ($line as $i) {
            $free -= $metrics[$i]["target"];
        }

        $auto_margin_count = 0;
        foreach ($line as $i) {
            $auto_margin_count += ($metrics[$i]["ml_auto"] ? 1 : 0) + ($metrics[$i]["mr_auto"] ? 1 : 0);
        }

        if ($free > 0 && $auto_margin_count > 0) {
            $share = $free / $auto_margin_count;
            foreach ($line as $i) {
                if ($metrics[$i]["ml_auto"]) {
                    $metrics[$i]["ml"] += $share;
                    $metrics[$i]["target"] += $share;
                }
                if ($metrics[$i]["mr_auto"]) {
                    $metrics[$i]["mr"] += $share;
                    $metrics[$i]["target"] += $share;
                }
            }
            $free = 0.0;
        }

        // ---- justify-content (§8.2) ------------------------------------
        // In a horizontal writing mode the row main axis follows the inline
        // direction: `direction: rtl` puts main-start at the right edge, and
        // `row-reverse` inverts whichever edge that is.
        // https://www.w3.org/TR/css-flexbox-1/#flex-direction-property
        $axis_reversed = $style->flex_direction === "row-reverse";
        $reversed = $axis_reversed !== ($style->direction === "rtl");
        [$leading, $between] = $this->justify_offsets(
            $style->justify_content,
            $free,
            $n,
            $reversed,
            $axis_reversed
        );

        // ---- Impose sizes, position, and reflow ------------------------
        // Offsets are computed along the main axis from the main-start
        // edge; under row-reverse the main-start edge is the right edge
        $flex = $this->_frame;
        $page = $flex->get_root();
        $cursor = $leading;
        $line_cross = $definite_cross !== null ? max(0.0, $definite_cross) : 0.0;

        foreach ($line as $i) {
            $item = $items[$i];
            $m = $metrics[$i];

            $this->impose_main_size($item, $m);

            $x = $reversed
                ? $cb_x + $container_main - $cursor - $m["target"]
                : $cb_x + $cursor;

            $item->set_containing_block($cb_x, $line_y, $container_main, $definite_cross);
            $flex->set_item_position($item, $x, $line_y);
            $item->reflow(null);

            // A page break may still occur through the no-valid-break
            // fallback, pushing (and resetting) this container as a whole
            if ($page->is_full() && $flex->get_position("x") === null) {
                return $line_cross;
            }

            $metrics[$i]["cross"] = $item->get_margin_height();

            if ($definite_cross === null) {
                $line_cross = max($line_cross, $metrics[$i]["cross"]);
            }

            $cursor += $m["target"] + $gap + $between;
        }

        return $line_cross;
    }

    /**
     * Column-direction layout: items are laid out in a single vertical
     * line. Cross (width) sizing and item reflow happen first to measure
     * natural main sizes; flexible lengths are then resolved and applied
     * through used heights and vertical offsets, without a second reflow.
     *
     * @param AbstractFrameDecorator[] $items
     * @param float                    $container_cross Container content width
     * @param float|null               $definite_main   Definite content height
     * @param float                    $cb_x
     * @param float                    $cb_y
     *
     * @return float The natural content height
     */
    protected function layout_column(
        array $items,
        float $container_cross,
        ?float $definite_main,
        float $cb_x,
        float $cb_y
    ): float {
        $style = $this->_frame->get_style();
        $flex = $this->_frame;
        $page = $flex->get_root();

        $gap = (float)$style->length_in_pt($style->row_gap, $definite_main ?? 0);
        $reversed = $style->flex_direction === "column-reverse";
        $align_items = $style->align_items;

        // ---- Cross sizing + measuring reflow ---------------------------
        $metrics = [];
        $cursor_y = 0.0;

        foreach ($items as $idx => $item) {
            $istyle = $item->get_style();
            $reflower = $item->get_reflower();

            $ml_auto = $istyle->margin_left === "auto";
            $mr_auto = $istyle->margin_right === "auto";
            $ml = $ml_auto ? 0.0 : (float)$istyle->length_in_pt($istyle->margin_left, $container_cross);
            $mr = $mr_auto ? 0.0 : (float)$istyle->length_in_pt($istyle->margin_right, $container_cross);
            $bp = (float)$istyle->length_in_pt([
                $istyle->border_left_width,
                $istyle->padding_left,
                $istyle->padding_right,
                $istyle->border_right_width
            ], $container_cross);

            $border_box = $istyle->box_sizing === "border-box" && !$item->is_table();
            $width_auto = $istyle->width === "auto";

            $alignment = $istyle->align_self !== "auto" ? $istyle->align_self : $align_items;
            $stretch = $alignment === "stretch" && $width_auto && !$ml_auto && !$mr_auto;

            $m = [
                "ml" => $ml,
                "mr" => $mr,
                "ml_auto" => $ml_auto,
                "mr_auto" => $mr_auto,
                "bp" => $bp,
                "border_box" => $border_box,
                "grow" => (float)$istyle->flex_grow,
                "shrink" => (float)$istyle->flex_shrink,
            ];

            // Main-axis (vertical) metrics that must be captured before
            // reflow, while computed values are still visible
            $mt_auto = $istyle->margin_top === "auto";
            $mb_auto = $istyle->margin_bottom === "auto";
            $mt = $mt_auto ? 0.0 : (float)$istyle->length_in_pt($istyle->margin_top, $container_cross);
            $mb = $mb_auto ? 0.0 : (float)$istyle->length_in_pt($istyle->margin_bottom, $container_cross);
            $height_computed = $istyle->height;
            $min_height_auto = $istyle->min_height === "auto";

            // Impose the cross size: stretched items fill the container,
            // others take their max-content width (clamped)
            if ($width_auto) {
                if ($stretch) {
                    $outer = $container_cross;
                } else {
                    [, $max_content] = $item->get_min_max_width();
                    $outer = min($max_content, $container_cross);
                }

                $target_width = $border_box
                    ? max(0.0, $outer - $ml - $mr)
                    : max(0.0, $outer - $ml - $mr - $bp);
                $istyle->set_used("width", $target_width);
            }

            $istyle->set_used("margin_left", $ml);
            $istyle->set_used("margin_right", $mr);

            $item->set_containing_block($cb_x, $cb_y, $container_cross, $definite_main);
            $flex->set_item_position($item, $cb_x, $cb_y + $cursor_y);
            $item->reflow(null);

            if ($page->is_full() && $flex->get_position("x") === null) {
                return 0.0;
            }

            $natural = $item->get_margin_height();
            $m["cross"] = $item->get_margin_width();
            $m["natural_y"] = $cursor_y;
            $m["natural"] = $natural;
            $m["mt_auto"] = $mt_auto;
            $m["mb_auto"] = $mb_auto;

            $bp_v = (float)$istyle->length_in_pt([
                $istyle->border_top_width,
                $istyle->padding_top,
                $istyle->padding_bottom,
                $istyle->border_bottom_width
            ], $container_cross);

            // Delta between the outer main size and the content height
            $outer_extra = $bp_v + $mt + $mb;
            $m["outer_extra"] = $outer_extra;

            // Flex base size: flex-basis, else the height property, else
            // the measured content
            $basis = $istyle->flex_basis;
            if ($basis === "auto") {
                $basis = $height_computed;
            }

            if ($basis === "auto" || $basis === "content"
                || ($definite_main === null && Helpers::is_percent($basis))
            ) {
                $base = $natural;
            } else {
                $resolved = max(0.0, (float)$istyle->length_in_pt($basis, $definite_main ?? 0));
                $base = $border_box
                    ? max($resolved, $bp_v) + $mt + $mb
                    : $resolved + $outer_extra;
            }

            // Automatic minimum: content height, unless min-height set;
            // replaced items may shrink freely
            if (!$min_height_auto) {
                $min = $reflower->resolve_min_height($definite_main, $container_cross) + $outer_extra;
            } elseif ($reflower instanceof Image) {
                $min = 0.0;
            } else {
                $min = $natural;
            }

            $max_height = $reflower->resolve_max_height($definite_main, $container_cross);
            $max = $max_height === INF ? INF : $max_height + $outer_extra;

            if ($max < $min) {
                $max = $min;
            }

            $m["base"] = $base;
            $m["inner_base"] = max(0.0, $base - $outer_extra);
            $m["min"] = $min;
            $m["max"] = $max;
            $m["hyp"] = Helpers::clamp($base, $min, $max);
            $m["frozen"] = false;
            $m["target"] = 0.0;
            $m["violation"] = 0.0;

            $metrics[$idx] = $m;
            $cursor_y += $natural + $gap;
        }

        $natural_total = $cursor_y - (count($items) > 0 ? $gap : 0.0);
        $n = count($items);
        $gaps_total = $gap * max(0, $n - 1);

        // ---- Resolve flexible lengths along the main (vertical) axis ---
        $container_main = $definite_main ?? $natural_total;
        $this->resolve_flexible_lengths($metrics, array_keys($metrics), $container_main, $gaps_total);

        // ---- Auto main margins + justify-content -----------------------
        $free = $container_main - $gaps_total;
        foreach ($metrics as $m) {
            $free -= $m["target"];
        }

        $auto_margin_count = 0;
        foreach ($metrics as $m) {
            $auto_margin_count += ($m["mt_auto"] ? 1 : 0) + ($m["mb_auto"] ? 1 : 0);
        }

        $top_shares = array_fill(0, $n, 0.0);
        $auto_totals = array_fill(0, $n, 0.0);

        if ($free > 0 && $auto_margin_count > 0) {
            $share = $free / $auto_margin_count;
            foreach ($metrics as $i => $m) {
                if ($m["mt_auto"]) {
                    $top_shares[$i] += $share;
                    $auto_totals[$i] += $share;
                    $metrics[$i]["target"] += $share;
                }
                if ($m["mb_auto"]) {
                    $auto_totals[$i] += $share;
                    $metrics[$i]["target"] += $share;
                }
            }
            $free = 0.0;
        }

        [$leading, $between] = $this->justify_offsets($style->justify_content, $free, $n, $reversed);

        // ---- Apply main sizes and offsets ------------------------------
        $cursor = $leading;
        $final_total = $gaps_total;

        foreach ($metrics as $i => $m) {
            $item = $items[$i];

            $y_offset = $reversed
                ? $container_main - $cursor - $m["target"]
                : $cursor;

            $dy = ($y_offset + $top_shares[$i]) - $m["natural_y"];

            if ($dy != 0) {
                $item->move(0, $dy);
            }

            // Apply a changed main size through the used height (content
            // semantics; no re-reflow: growing shows more background,
            // shrinking overflows)
            $inner_target = max(0.0, $m["target"] - $m["outer_extra"] - $auto_totals[$i]);
            $natural_inner = max(0.0, $m["natural"] - $m["outer_extra"]);

            if (abs($inner_target - $natural_inner) > 0.01) {
                $item->get_style()->set_used("height", $inner_target);
            }

            // Cross (horizontal) alignment
            $this->align_item_cross_horizontal($item, $metrics[$i], $container_cross);

            $cursor += $m["target"] + $gap + $between;
            $final_total += $m["target"];
        }

        return $final_total;
    }

    /**
     * Resolve the flexible lengths of one line per §9.7. Reads and writes
     * the metrics entries referenced by $line: uses base/inner_base, min,
     * max, hyp, grow, shrink; produces target.
     *
     * @param array[] $metrics
     * @param int[]   $line
     * @param float   $inner_size Container inner main size
     * @param float   $gaps_total
     */
    protected function resolve_flexible_lengths(array &$metrics, array $line, float $inner_size, float $gaps_total): void
    {
        $sum_hyp = $gaps_total;
        foreach ($line as $i) {
            $sum_hyp += $metrics[$i]["hyp"];
        }

        $grow = $sum_hyp < $inner_size;

        // Freeze inflexible items at their hypothetical main size
        foreach ($line as $i) {
            $factor = $grow ? $metrics[$i]["grow"] : $metrics[$i]["shrink"];
            $inflexible = $factor == 0
                || ($grow && $metrics[$i]["base"] > $metrics[$i]["hyp"])
                || (!$grow && $metrics[$i]["base"] < $metrics[$i]["hyp"]);

            $metrics[$i]["frozen"] = $inflexible;
            $metrics[$i]["target"] = $metrics[$i]["hyp"];
        }

        // Free space: frozen items contribute their target size, unfrozen
        // ones their flex base size
        $free_space = function () use (&$metrics, $line, $inner_size, $gaps_total) {
            $sum = $gaps_total;
            foreach ($line as $i) {
                $sum += $metrics[$i]["frozen"] ? $metrics[$i]["target"] : $metrics[$i]["base"];
            }
            return $inner_size - $sum;
        };

        $initial_free = $free_space();

        while (true) {
            $unfrozen = [];
            foreach ($line as $i) {
                if (!$metrics[$i]["frozen"]) {
                    $unfrozen[] = $i;
                }
            }

            if (count($unfrozen) === 0) {
                break;
            }

            $remaining = $free_space();

            $sum_factors = 0.0;
            foreach ($unfrozen as $i) {
                $sum_factors += $grow ? $metrics[$i]["grow"] : $metrics[$i]["shrink"];
            }

            if ($sum_factors < 1 && abs($initial_free * $sum_factors) < abs($remaining)) {
                $remaining = $initial_free * $sum_factors;
            }

            // Distribute free space proportional to the flex factors
            if ($remaining != 0 && $sum_factors > 0) {
                if ($grow) {
                    foreach ($unfrozen as $i) {
                        $ratio = $metrics[$i]["grow"] / $sum_factors;
                        $metrics[$i]["target"] = $metrics[$i]["base"] + $remaining * $ratio;
                    }
                } else {
                    $sum_scaled = 0.0;
                    foreach ($unfrozen as $i) {
                        $sum_scaled += $metrics[$i]["shrink"] * $metrics[$i]["inner_base"];
                    }

                    foreach ($unfrozen as $i) {
                        $ratio = $sum_scaled > 0
                            ? ($metrics[$i]["shrink"] * $metrics[$i]["inner_base"]) / $sum_scaled
                            : 1 / count($unfrozen);
                        $metrics[$i]["target"] = $metrics[$i]["base"] - abs($remaining) * $ratio;
                    }
                }
            } else {
                foreach ($unfrozen as $i) {
                    $metrics[$i]["target"] = $metrics[$i]["base"];
                }
            }

            // Fix min/max violations
            $total_violation = 0.0;
            foreach ($unfrozen as $i) {
                $clamped = Helpers::clamp($metrics[$i]["target"], $metrics[$i]["min"], $metrics[$i]["max"]);
                $metrics[$i]["violation"] = $clamped - $metrics[$i]["target"];
                $total_violation += $metrics[$i]["violation"];
                $metrics[$i]["target"] = $clamped;
            }

            // Freeze over-flexed items
            foreach ($unfrozen as $i) {
                if ($total_violation > 0) {
                    if ($metrics[$i]["violation"] > 0) {
                        $metrics[$i]["frozen"] = true;
                    }
                } elseif ($total_violation < 0) {
                    if ($metrics[$i]["violation"] < 0) {
                        $metrics[$i]["frozen"] = true;
                    }
                } else {
                    $metrics[$i]["frozen"] = true;
                }
            }
        }
    }

    /**
     * Leading and between offsets for a justify-content keyword.
     *
     * @param string $justify
     * @param float  $free
     * @param int    $n
     * @param bool      $reversed      Whether main-start is the far edge
     * @param bool|null $axis_reversed Whether the flex axis runs against the
     *                                 writing direction; defaults to $reversed
     *
     * @return array [leading, between]
     */
    protected function justify_offsets(
        string $justify,
        float $free,
        int $n,
        bool $reversed,
        ?bool $axis_reversed = null
    ): array {
        // `start`/`end` pack towards the writing-direction start/end. That
        // follows the reversal of the flex axis relative to the writing
        // direction, which differs from the absolute main-start edge once
        // `direction: rtl` is in play.
        if ($axis_reversed === null) {
            $axis_reversed = $reversed;
        }

        if ($justify === "start") {
            $justify = $axis_reversed ? "flex-end" : "flex-start";
        } elseif ($justify === "end") {
            $justify = $axis_reversed ? "flex-start" : "flex-end";
        }

        // Fallback alignments for negative free space
        if ($free < 0) {
            if ($justify === "space-between") {
                $justify = "flex-start";
            } elseif ($justify === "space-around" || $justify === "space-evenly") {
                $justify = "center";
            }
        }

        $leading = 0.0;
        $between = 0.0;

        switch ($justify) {
            case "flex-end":
                $leading = $free;
                break;
            case "center":
                $leading = $free / 2;
                break;
            case "space-between":
                $between = $n > 1 ? $free / ($n - 1) : 0.0;
                break;
            case "space-around":
                $between = $free / $n;
                $leading = $between / 2;
                break;
            case "space-evenly":
                $between = $free / ($n + 1);
                $leading = $between;
                break;
            case "flex-start":
            default:
                break;
        }

        return [$leading, $between];
    }

    /**
     * Gather the §9.2/§9.3 metrics for one row-direction item: margins, box
     * deltas, flex base size, hypothetical main size, and min/max clamps.
     * All main sizes are outer (margin-box) sizes unless noted.
     *
     * @param AbstractFrameDecorator $item
     * @param float                  $container_main
     *
     * @return array
     */
    protected function item_metrics(AbstractFrameDecorator $item, float $container_main): array
    {
        $style = $item->get_style();
        $reflower = $item->get_reflower();

        $ml_auto = $style->margin_left === "auto";
        $mr_auto = $style->margin_right === "auto";
        $ml = $ml_auto ? 0.0 : (float)$style->length_in_pt($style->margin_left, $container_main);
        $mr = $mr_auto ? 0.0 : (float)$style->length_in_pt($style->margin_right, $container_main);

        $bp = (float)$style->length_in_pt([
            $style->border_left_width,
            $style->padding_left,
            $style->padding_right,
            $style->border_right_width
        ], $container_main);

        // Tables interpret a specified width with border-box semantics of
        // their own, and impose_main_size() relies on that, so the flex base
        // must not add border and padding on top for them either
        $border_box = $style->box_sizing === "border-box" || $item->is_table();

        // The delta between the outer (margin-box) main size and the value
        // that a specified width-type property refers to
        $box_delta = $border_box ? $ml + $mr : $ml + $mr + $bp;

        // Flex base size (§9.2.3): flex-basis, falling back to the width
        // property, falling back to max-content
        $basis = $style->flex_basis;
        if ($basis === "auto") {
            $basis = $style->width;
        }

        if ($basis === "auto" || $basis === "content") {
            [, $max_content] = $item->get_min_max_width();
            $base = $max_content; // outer size, margins included
        } else {
            $resolved = (float)$style->length_in_pt($basis, $container_main);
            $base = max(0.0, $resolved) + $box_delta;
            if ($border_box) {
                // A border-box basis includes border and padding; make sure
                // it cannot go below them
                $base = max($base, $ml + $mr + $bp);
            }
        }

        // Automatic minimum size (§4.5): min-content, unless min-width is
        // specified. Replaced items get an automatic minimum of 0 — their
        // min-content size is the intrinsic size, which would prevent any
        // shrinking below it (browsers squish images in flex rows)
        [$min_content] = $item->get_min_max_width();

        if ($style->min_width !== "auto") {
            $min = $reflower->resolve_min_width($container_main) + $ml + $mr + $bp;
        } elseif ($reflower instanceof Image) {
            $min = 0.0;
        } else {
            $min = $min_content;
        }

        $max_width = $reflower->resolve_max_width($container_main);
        $max = $max_width === INF ? INF : $max_width + $ml + $mr + $bp;

        if ($max < $min) {
            $max = $min;
        }

        // Auto-ness must be captured before the item is reflowed: after
        // reflow, used values mask the computed `auto`
        return [
            "height_auto" => $style->height === "auto",
            "mt_auto" => $style->margin_top === "auto",
            "mb_auto" => $style->margin_bottom === "auto",
            "border_box" => $border_box,
            "grow" => (float)$style->flex_grow,
            "shrink" => (float)$style->flex_shrink,
            "ml" => $ml,
            "mr" => $mr,
            "ml_auto" => $ml_auto,
            "mr_auto" => $mr_auto,
            "bp" => $bp,
            "base" => $base,
            "inner_base" => max(0.0, $base - $ml - $mr - $bp),
            "min" => $min,
            "max" => $max,
            "hyp" => Helpers::clamp($base, $min, $max),
            "frozen" => false,
            "target" => 0.0,
            "violation" => 0.0,
            "cross" => 0.0,
        ];
    }

    /**
     * Impose the resolved outer main size and margins on an item as used
     * values, converted per the semantics of the item's reflower type.
     *
     * @param AbstractFrameDecorator $item
     * @param array                  $m The item's metrics
     */
    protected function impose_main_size(AbstractFrameDecorator $item, array $m): void
    {
        $style = $item->get_style();
        $reflower = $item->get_reflower();

        $style->set_used("margin_left", $m["ml"]);
        $style->set_used("margin_right", $m["mr"]);

        if ($reflower instanceof Table) {
            // Tables subtract their own border, padding, and spacing delta
            // from the specified width (legacy border-box-like behavior)
            $style->set_used("width", max(0.0, $m["target"] - $m["ml"] - $m["mr"]));
        } else {
            // The used width must be expressed in the item's own box-sizing
            // semantics, as the item's reflow applies the border-box
            // adjustment to it again: border-box items receive the
            // border-box width, content-box items the content-box width
            $bp = $m["border_box"] ? 0.0 : $m["bp"];
            $style->set_used("width", max(0.0, $m["target"] - $m["ml"] - $m["mr"] - $bp));
        }
    }

    /**
     * Align an item within the line's cross axis (row direction): auto
     * cross margins first, then align-self/align-items. `baseline` is
     * treated as `flex-start`.
     *
     * @param AbstractFrameDecorator $item
     * @param array                  $m
     * @param float                  $line_cross
     * @param float                  $container_main
     */
    protected function align_item_cross(AbstractFrameDecorator $item, array $m, float $line_cross, float $container_main): void
    {
        $item_style = $item->get_style();
        $free = $line_cross - $m["cross"];

        // Auto cross margins absorb positive free space (§8.1)
        if (($m["mt_auto"] || $m["mb_auto"]) && $free > 0) {
            if ($m["mt_auto"] && $m["mb_auto"]) {
                $item->move(0, $free / 2);
            } elseif ($m["mt_auto"]) {
                $item->move(0, $free);
            }
            return;
        }

        $alignment = $item_style->align_self;
        if ($alignment === "auto") {
            $alignment = $this->_frame->get_style()->align_items;
        }

        switch ($alignment) {
            case "stretch":
                // Only items with an automatic cross size stretch
                if ($m["height_auto"]) {
                    $bp_v = (float)$item_style->length_in_pt([
                        $item_style->margin_top,
                        $item_style->border_top_width,
                        $item_style->padding_top,
                        $item_style->padding_bottom,
                        $item_style->border_bottom_width,
                        $item_style->margin_bottom
                    ], $container_main);

                    $stretched = max(0.0, $line_cross - $bp_v);
                    $reflower = $item->get_reflower();
                    $min_h = $reflower->resolve_min_height(null, null);
                    $max_h = $reflower->resolve_max_height(null, null);
                    $stretched = Helpers::clamp($stretched, $min_h, $max_h);

                    // Stretch resolves the used cross size to the line's
                    // cross size clamped by min/max, in both directions.
                    // A line shorter than the item's content still applies:
                    // the content overflows rather than growing the item.
                    $item_style->set_used("height", $stretched);
                }
                break;

            case "center":
                $item->move(0, $free / 2);
                break;

            case "flex-end":
            case "end":
                $item->move(0, $free);
                break;

            case "flex-start":
            case "start":
            case "baseline":
            default:
                break;
        }
    }

    /**
     * Align an item horizontally within a column container. Stretch is
     * handled through the width imposition during layout.
     *
     * @param AbstractFrameDecorator $item
     * @param array                  $m
     * @param float                  $container_cross
     */
    protected function align_item_cross_horizontal(AbstractFrameDecorator $item, array $m, float $container_cross): void
    {
        $item_style = $item->get_style();
        $free = $container_cross - $m["cross"];

        // Auto cross margins absorb positive free space
        if (($m["ml_auto"] || $m["mr_auto"]) && $free > 0) {
            if ($m["ml_auto"] && $m["mr_auto"]) {
                $item->move($free / 2, 0);
            } elseif ($m["ml_auto"]) {
                $item->move($free, 0);
            }
            return;
        }

        $alignment = $item_style->align_self;
        if ($alignment === "auto") {
            $alignment = $this->_frame->get_style()->align_items;
        }

        switch ($alignment) {
            case "center":
                $item->move($free / 2, 0);
                break;

            case "flex-end":
            case "end":
                $item->move($free, 0);
                break;

            default:
                break;
        }
    }

    /**
     * Intrinsic widths of the flex container's content (§9.9). Used both by
     * the inherited `get_min_max_content_width()` (which applies fixed-width
     * and min/max clamping on top) and by shrink-to-fit sizing.
     *
     * @return array
     */
    public function get_min_max_child_width(): array
    {
        if (!is_null($this->_min_max_child_cache)) {
            return $this->_min_max_child_cache;
        }

        /** @var FlexFrameDecorator $frame */
        $frame = $this->_frame;
        $frame->normalize();

        $style = $frame->get_style();
        $items = $frame->get_flex_items();
        $n = count($items);

        // Percentage gaps resolve to 0 while the container size is unknown
        $gap = (float)$style->length_in_pt($style->column_gap, 0);
        $row = $style->flex_direction === "row" || $style->flex_direction === "row-reverse";
        $wrap = $style->flex_wrap !== "nowrap";
        $gaps_total = $row ? $gap * max(0, $n - 1) : 0.0;

        $min = 0.0;
        $max = $gaps_total;

        foreach ($items as $item) {
            [$item_min, $item_max] = $item->get_min_max_width();

            if ($row) {
                $min = $wrap ? max($min, $item_min) : $min + $item_min;
                $max += $item_max;
            } else {
                $min = max($min, $item_min);
                $max = max($max, $item_max);
            }
        }

        if ($row && !$wrap) {
            $min += $gaps_total;
        }

        return $this->_min_max_child_cache = [$min, $max];
    }
}
