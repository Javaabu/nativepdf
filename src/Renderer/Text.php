<?php
/**
 * @package dompdf
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
namespace Dompdf\Renderer;

use Dompdf\Adapter\CPDF;
use Dompdf\Frame;

/**
 * Renders text frames
 *
 * @package dompdf
 */
class Text extends AbstractRenderer
{
    /** Thickness of underline. Screen: 0.08, print: better less, e.g. 0.04 */
    const DECO_THICKNESS = 0.02;

    /**
     * Upper bound on the number of segments used to draw a `wavy`
     * decoration, past which a plain line is drawn instead.
     */
    const MAX_DECO_SEGMENTS = 2000;

    //Tweaking if $base and $descent are not accurate.
    //Check method_exists( $this->_canvas, "get_cpdf" )
    //- For cpdf these can and must stay 0, because font metrics are used directly.
    //- For other renderers, if different values are wanted, separate the parameter sets.
    //  But $size and $size-$height seem to be accurate enough

    /** Relative to bottom of text, as fraction of height */
    const UNDERLINE_OFFSET = 0.0;

    /** Relative to top of text */
    const OVERLINE_OFFSET = 0.0;

    /** Relative to centre of text. */
    const LINETHROUGH_OFFSET = 0.0;

    /** How far to extend lines past either end, in pt */
    const DECO_EXTENSION = 0.0;

    /**
     * @param \Dompdf\FrameDecorator\Text $frame
     */
    function render(Frame $frame)
    {
        $style = $frame->get_style();
        $text = $frame->get_visual_text();

        if ($text === "") {
            return;
        }

        $this->_set_opacity($frame->get_opacity($style->opacity));

        [$x, $y] = $frame->get_position();
        $cb = $frame->get_containing_block();

        $ml = $style->margin_left;
        $pl = $style->padding_left;
        $bl = $style->border_left_width;
        $x += (float) $style->length_in_pt([$ml, $pl, $bl], $cb["w"]);

        $font = $style->font_family;
        $size = $style->font_size;
        $frame_font_size = $frame->get_dompdf()->getFontMetrics()->getFontHeight($font, $size);
        $word_spacing = $frame->get_text_spacing() + $style->word_spacing;
        $letter_spacing = $style->letter_spacing;
        $width = (float) $style->width;

        /*$text = str_replace(
          array("{PAGE_NUM}"),
          array($this->_canvas->get_page_number()),
          $text
        );*/

        $this->_canvas->text($x, $y, $text,
            $font, $size,
            $style->color, $word_spacing, $letter_spacing);

        $line = $frame->get_containing_line();

        // FIXME Instead of using the tallest frame to position,
        // the decoration, the text should be well placed
        if (false && $line->tallest_frame) {
            $base_frame = $line->tallest_frame;
            $style = $base_frame->get_style();
            $size = $style->font_size;
        }

        $line_thickness = $size * self::DECO_THICKNESS;
        $underline_offset = $size * self::UNDERLINE_OFFSET;
        $overline_offset = $size * self::OVERLINE_OFFSET;
        $linethrough_offset = $size * self::LINETHROUGH_OFFSET;
        $underline_position = -0.08;

        if ($this->_canvas instanceof CPDF) {
            $cpdf_font = $this->_canvas->get_cpdf()->fonts[$style->font_family];

            if (isset($cpdf_font["UnderlinePosition"])) {
                $underline_position = $cpdf_font["UnderlinePosition"] / 1000;
            }

            if (isset($cpdf_font["UnderlineThickness"])) {
                $line_thickness = $size * ($cpdf_font["UnderlineThickness"] / 1000);
            }
        }

        $descent = $size * $underline_position;
        $base = $frame_font_size;

        // Handle text decoration:
        // http://www.w3.org/TR/CSS21/text.html#propdef-text-decoration

        // Draw all applicable text-decorations.  Start with the root and work our way down.
        $p = $frame;
        $stack = [];
        while ($p = $p->get_parent()) {
            $stack[] = $p;
        }

        while (isset($stack[0])) {
            $f = array_pop($stack);
            $deco_style = $f->get_style();
            $lines = $deco_style->text_decoration_line;

            if ($lines === "none" || $lines === "") {
                continue;
            }

            $deco_color = $deco_style->text_decoration_color;
            if ($deco_color === "currentcolor" || $deco_color === "") {
                $deco_color = $deco_style->color;
            } else {
                $deco_color = \Dompdf\Css\Color::parse($deco_color);
            }

            $deco_line_style = $deco_style->text_decoration_style;

            foreach (explode(" ", $lines) as $text_deco) {
                $deco_y = $y; //$line->y;

                switch ($text_deco) {
                    default:
                        continue 2;

                    case "underline":
                        $deco_y += $base - $descent + $underline_offset + $line_thickness / 2;
                        break;

                    case "overline":
                        $deco_y += $overline_offset + $line_thickness / 2;
                        break;

                    case "line-through":
                        $deco_y += $base * 0.7 + $linethrough_offset;
                        break;
                }

                $x1 = $x - self::DECO_EXTENSION;
                $x2 = $x + $width + self::DECO_EXTENSION;

                $this->render_decoration_line($x1, $x2, $deco_y, $deco_color, $line_thickness, $deco_line_style);
            }
        }

        $options = $this->_dompdf->getOptions();

        if ($options->getDebugLayout() && $options->getDebugLayoutLines()) {
            $fontMetrics = $this->_dompdf->getFontMetrics();
            $textWidth = $fontMetrics->getTextWidth($text, $font, $size, $word_spacing, $letter_spacing);
            $this->debugLayout([$x, $y, $textWidth, $frame_font_size], "orange", [0.5, 0.5]);
        }
    }

    /**
     * Draw one decoration line in the given text-decoration-style.
     *
     * @param float $x1
     * @param float $x2
     * @param float $y
     * @param mixed $color
     * @param float $thickness
     * @param string $style `solid`, `double`, `dotted`, `dashed`, or `wavy`
     */
    protected function render_decoration_line(float $x1, float $x2, float $y, $color, float $thickness, string $style): void
    {
        switch ($style) {
            case "double":
                // Two lines separated by a gap of one thickness
                $this->_canvas->line($x1, $y - $thickness, $x2, $y - $thickness, $color, $thickness);
                $this->_canvas->line($x1, $y + $thickness, $x2, $y + $thickness, $color, $thickness);
                break;

            case "dotted":
                $this->_canvas->line($x1, $y, $x2, $y, $color, $thickness, [$thickness, $thickness], "round");
                break;

            case "dashed":
                $this->_canvas->line($x1, $y, $x2, $y, $color, $thickness, [3 * $thickness]);
                break;

            case "wavy":
                // Zigzag with an amplitude and half-period scaled to the
                // line thickness
                $amp = $thickness * 1.5;
                $step = $thickness * 3;

                // A non-positive step never advances the cursor, and a
                // step small enough relative to the run would take an
                // unbounded number of segments to cover it. Both are
                // reachable with a tiny or zero font size combined with
                // letter spacing, and neither is visually distinguishable
                // from a plain line, so draw one instead.
                if (!($step > 0) || ($x2 - $x1) / $step > self::MAX_DECO_SEGMENTS) {
                    $this->_canvas->line($x1, $y, $x2, $y, $color, $thickness);
                    break;
                }

                $up = true;
                $cx = $x1;

                while ($cx < $x2) {
                    $nx = min($cx + $step, $x2);
                    $y1 = $up ? $y + $amp : $y - $amp;
                    $y2 = $up ? $y - $amp : $y + $amp;
                    $this->_canvas->line($cx, $y1, $nx, $y2, $color, $thickness);
                    $cx = $nx;
                    $up = !$up;
                }
                break;

            case "solid":
            default:
                $this->_canvas->line($x1, $y, $x2, $y, $color, $thickness);
                break;
        }
    }
}
