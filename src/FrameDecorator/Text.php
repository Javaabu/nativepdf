<?php
/**
 * @package dompdf
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
namespace Dompdf\FrameDecorator;

use Dompdf\Dompdf;
use Dompdf\Frame;
use Dompdf\Exception;

/**
 * Decorates Frame objects for text layout
 *
 * @package dompdf
 */
class Text extends AbstractFrameDecorator
{
    /**
     * @var float
     */
    protected $text_spacing;

    /**
     * @var string|null
     */
    protected $mapped_font;

    /**
     * Saves trailing whitespace trimmed after a line break, so it can be
     * restored when needed.
     *
     * @var string|null
     */
    protected $trailingWs = null;

    /**
     * Text constructor.
     * @param Frame $frame
     * @param Dompdf $dompdf
     * @throws Exception
     */
    function __construct(Frame $frame, Dompdf $dompdf)
    {
        if (!$frame->is_text_node()) {
            throw new Exception("Text_Decorator can only be applied to #text nodes.");
        }

        parent::__construct($frame, $dompdf);
        $this->text_spacing = 0.0;
    }

    /**
     * Trim trailing white space from the frame text.
     */
    public function trim_trailing_ws(): void
    {
        $frame = $this->_frame;
        $text = $this->get_text();
        $trailing = mb_substr($text, -1, null, "UTF-8");

        // White space is always collapsed to the standard space character
        // currently, so only handle that for now
        if ($trailing === " ") {
            $this->trailingWs = $trailing;
            $this->set_text(mb_substr($text, 0, -1, "UTF-8"));
            $this->recalculate_width();
        }
    }

    function reset()
    {
        parent::reset();
        $this->text_spacing = 0.0;
        $this->mapped_font = null;

        // Restore trimmed trailing white space, as the frame will go through
        // another reflow and line breaks might be different after a split
        if ($this->trailingWs !== null) {
            $text = $this->get_text();
            $this->set_text($text . $this->trailingWs);
            $this->trailingWs = null;
        }
    }

    // Accessor methods

    /**
     * @return float
     */
    public function get_text_spacing(): float
    {
        return $this->text_spacing;
    }

    /**
     * The text as it is to be drawn: frames on an odd (right-to-left)
     * embedding level render their text in reverse grapheme order with
     * mirrored characters substituted (UAX #9 rules L2/L4). Measurement
     * continues to use get_text(); per-character width sums are
     * order-independent.
     *
     * @return string
     */
    public function get_visual_text(): string
    {
        $text = $this->get_text();
        $rtl = $this->bidi_level !== null && $this->bidi_level % 2 === 1;

        // Mark placement does not depend on the embedding level: a mark the
        // font draws ahead of its base has to be emitted ahead of it either
        // way. A left-to-right run only reaches that case through an override
        // (`<bdo>`, `unicode-bidi: bidi-override`), so skip the grapheme walk
        // unless the text actually carries right-to-left script characters.
        if (!$rtl && !preg_match(self::RTL_BLOCK_PATTERN, $text)) {
            return $text;
        }

        if (!preg_match_all('/\X/u', $text, $matches)) {
            return $text;
        }

        $clusters = $rtl ? array_reverse($matches[0]) : $matches[0];

        foreach ($clusters as &$cluster) {
            $cluster = self::marksBeforeBase($cluster);
            // Mirror single-character clusters (brackets etc.)
            if ($rtl && strlen($cluster) <= 4) {
                $cps = \Dompdf\Text\BidiAnalyzer::toCodePoints($cluster);
                if (count($cps) === 1) {
                    $mirror = \Dompdf\Text\UnicodeData::mirror($cps[0]);
                    if ($mirror !== null) {
                        $cluster = \Dompdf\Text\ArabicShaper::encode($mirror);
                    }
                }
            }
        }
        unset($cluster);

        return implode("", $clusters);
    }

    /**
     * Blocks whose non-spacing marks are drawn ahead of their base. Every
     * block from Hebrew (U+0590) through Arabic Extended-A (U+08FF) belongs to
     * a right-to-left script, as do the listed presentation-form and
     * supplementary-plane ranges.
     */
    /**
     * Matches any character of a right-to-left script, i.e. the ranges below.
     */
    private const RTL_BLOCK_PATTERN = '/[\x{0590}-\x{08FF}\x{FB1D}-\x{FDFF}\x{FE70}-\x{FEFF}'
        . '\x{10D00}-\x{10D3F}\x{10EC0}-\x{10EFF}\x{10F30}-\x{10F6F}\x{1E900}-\x{1E95F}]/u';

    private const RTL_MARK_RANGES = [
        [0x00590, 0x008FF], // Hebrew, Arabic, Syriac, Thaana, NKo, Samaritan, Mandaic
        [0x0FB1D, 0x0FDFF], // Hebrew and Arabic presentation forms A
        [0x0FE70, 0x0FEFF], // Arabic presentation forms B
        [0x10D00, 0x10D3F], // Hanifi Rohingya
        [0x10EC0, 0x10EFF], // Arabic Extended-C
        [0x10F30, 0x10F6F], // Sogdian
        [0x1E900, 0x1E95F], // Adlam
    ];

    /**
     * UAX #9 L3: after L2 the combining marks of a right-to-left cluster
     * precede their base, and the ordering is only reversed back if the
     * rendering engine expects marks to follow the base. The canvas draws
     * glyphs left to right using each glyph's own bearings, so which order it
     * expects depends on how the font draws the mark:
     *
     *  - Marks of right-to-left scripts sit to the right of their origin
     *    (Faruma's thaana abafili starts at +343 font units, DejaVu's arabic
     *    fatha at +220, Noto's hebrew patah at +52), so they have to be
     *    painted at the base's origin, i.e. emitted before it. Emitted after,
     *    the base has already advanced the pen and the mark lands on the next
     *    letter.
     *  - Marks shared with left-to-right scripts sit to the left of their
     *    origin (DejaVu's U+0301 starts at -655, U+0308 at -809), so they are
     *    already correct where they are and must not be moved.
     *
     * Anything that is not a mark of a right-to-left script - generic
     * diacritics, variation selectors such as U+FE0F - keeps its position.
     *
     * @param string $cluster
     * @return string
     */
    private static function marksBeforeBase(string $cluster): string
    {
        if (strlen($cluster) <= 1) {
            return $cluster;
        }

        $chars = preg_split('//u', $cluster, -1, PREG_SPLIT_NO_EMPTY);

        if ($chars === false || count($chars) < 2) {
            return $cluster;
        }

        $leading = [];
        $rest = [];

        foreach ($chars as $char) {
            if (self::isPreBaseMark($char)) {
                $leading[] = $char;
            } else {
                $rest[] = $char;
            }
        }

        if ($leading === []) {
            return $cluster;
        }

        return implode("", array_reverse($leading)) . implode("", $rest);
    }

    /**
     * Whether the character is a non-spacing mark of a right-to-left script,
     * which the font draws to the right of its origin.
     *
     * @param string $char A single character
     * @return bool
     */
    private static function isPreBaseMark(string $char): bool
    {
        if (!preg_match('/^\p{Mn}$/u', $char)) {
            return false;
        }

        $cps = \Dompdf\Text\BidiAnalyzer::toCodePoints($char);

        if (count($cps) !== 1) {
            return false;
        }

        $cp = $cps[0];

        foreach (self::RTL_MARK_RANGES as [$from, $to]) {
            if ($cp >= $from && $cp <= $to) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string
     */
    function get_text()
    {
        // FIXME: this should be in a child class (and is incorrect)
//    if ( $this->_frame->get_style()->content !== "normal" ) {
//      $this->_frame->get_node()->data = $this->_frame->get_style()->content;
//      $this->_frame->get_style()->content = "normal";
//    }

//      Helpers::pre_r("---");
//      $style = $this->_frame->get_style();
//      var_dump($text = $this->_frame->get_node()->data);
//      var_dump($asc = utf8_decode($text));
//      for ($i = 0; $i < strlen($asc); $i++)
//        Helpers::pre_r("$i: " . $asc[$i] . " - " . ord($asc[$i]));
//      Helpers::pre_r("width: " . $this->_dompdf->getFontMetrics()->getTextWidth($text, $style->font_family, $style->font_size));

        return $this->_frame->get_node()->data;
    }

    //........................................................................

    /**
     * Vertical padding, border, and margin do not apply when determining the
     * height for inline frames.
     *
     * http://www.w3.org/TR/CSS21/visudet.html#inline-non-replaced
     *
     * The vertical padding, border and margin of an inline, non-replaced box
     * start at the top and bottom of the content area, not the
     * 'line-height'. But only the 'line-height' is used to calculate the
     * height of the line box.
     *
     * @return float
     */
    public function get_margin_height(): float
    {
        // This function is also called in add_frame_to_line() and is used to
        // determine the line height
        $style = $this->get_style();
        $font = $style->font_family;
        $size = $style->font_size;
        $fontHeight = $this->_dompdf->getFontMetrics()->getFontHeight($font, $size);

        return ($style->line_height / ($size > 0 ? $size : 1)) * $fontHeight;
    }

    public function get_padding_box(): array
    {
        $style = $this->_frame->get_style();
        $pb = $this->_frame->get_padding_box();
        $pb[3] = $pb["h"] = (float) $style->length_in_pt($style->height);
        return $pb;
    }

    /**
     * @param float $spacing
     */
    public function set_text_spacing(float $spacing): void
    {
        $this->text_spacing = $spacing;
        $this->recalculate_width();
    }

    /**
     * Recalculate the text width
     *
     * @return float
     */
    public function recalculate_width(): float
    {
        $fontMetrics = $this->_dompdf->getFontMetrics();
        $style = $this->get_style();
        $text = $this->get_text();
        $font = $style->font_family;
        $size = $style->font_size;
        $word_spacing = $this->text_spacing + $style->word_spacing;
        $letter_spacing = $style->letter_spacing;
        $text_width = $fontMetrics->getTextWidth($text, $font, $size, $word_spacing, $letter_spacing);

        $style->set_used("width", $text_width);
        return $text_width;
    }

    // Text manipulation methods

    /**
     * Split the text in this frame at the offset specified.  The remaining
     * text is added as a sibling frame following this one and is returned.
     *
     * @param int  $offset
     * @param bool $split_parent Whether to split parent inline frames.
     *
     * @return Text|null
     */
    function split_text(int $offset, bool $split_parent = true): ?self
    {
        if ($offset === 0) {
            return null;
        }

        $split = $this->_frame->get_node()->splitText($offset);
        if ($split === false) {
            return null;
        }

        /** @var Text */
        $deco = $this->copy($split);
        $style = $this->_frame->get_style();
        $split_style = $deco->get_style();

        if ($this->mapped_font !== null) {
            $split_style->set_used("font_family", $this->mapped_font);
            $deco->mapped_font = $this->mapped_font;
        }

        // Both halves of a split stay within the same bidi level run
        $deco->bidi_level = $this->bidi_level;

        // Clear decoration widths at the split point. They might have been
        // copied from the parent frame during inline reflow
        $style->margin_right = 0.0;
        $style->padding_right = 0.0;
        $style->border_right_width = 0.0;

        $split_style->margin_left = 0.0;
        $split_style->padding_left = 0.0;
        $split_style->border_left_width = 0.0;

        $p = $this->get_parent();
        $p->insert_child_after($deco, $this, false);

        if ($split_parent && $p instanceof Inline) {
            $p->split($deco);
        }

        return $deco;
    }

    /**
     * @param int $offset
     * @param int $count
     */
    function delete_text($offset, $count)
    {
        $this->_frame->get_node()->deleteData($offset, $count);
    }

    /**
     * @param string $text
     */
    function set_text($text)
    {
        $this->_frame->get_node()->data = $text;
    }

    /**
     * Determines the optimal font that applies to the frame and splits
     * the frame where the optimal font changes.
     */
    function apply_font_mapping(): void
    {
        if ($this->mapped_font !== null) {
            return;
        }

        $fontMetrics = $this->_dompdf->getFontMetrics();
        $style = $this->get_style();
        $families = $style->get_font_family_computed();
        $subtype = $fontMetrics->getType($style->font_weight . ' ' . $style->font_style);
        $charMapping = $fontMetrics->mapTextToFonts($this->get_text(), $families, $subtype, 1);

        if (isset($charMapping[0])) {
            if ($charMapping[0]["length"] !== 0) {
                $this->split_text($charMapping[0]["length"], false);
            }
            $mapped_font = $charMapping[0]["font"];
            if ($mapped_font !== null) {
                $style->set_used("font_family", $mapped_font);
                $this->mapped_font = $mapped_font;
            }
        }
    }
}
