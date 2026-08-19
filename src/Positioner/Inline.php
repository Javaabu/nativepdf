<?php
/**
 * @package nativepdf
 * @link    https://github.com/Javaabu/nativepdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
namespace NativePdf\Positioner;

use NativePdf\FrameDecorator\AbstractFrameDecorator;
use NativePdf\FrameDecorator\Inline as InlineFrameDecorator;
use NativePdf\Exception;
use NativePdf\Helpers;

/**
 * Positions inline frames
 *
 * @package nativepdf
 */
class Inline extends AbstractPositioner
{

    /**
     * @param AbstractFrameDecorator $frame
     * @throws Exception
     */
    function position(AbstractFrameDecorator $frame): void
    {
        // Find our nearest block level parent and access its lines property
        $block = $frame->find_block_parent();
        $cb = $frame->get_containing_block();

        if (!$block) {
            // FIXME: An inline frame without block parent should not be
            // possible, but this can occur currently when the body is styled
            // with `display: inline !important;` or `display: inline-block !important;`
            $frame->set_position($cb["x"], $cb["y"]);
            return;
        }

        $line = $block->get_current_line_box();

        if (!$frame->is_text_node() && !($frame instanceof InlineFrameDecorator)) {
            // Atomic inline boxes and replaced inline elements
            // (inline-block, inline-table, img etc.)
            $width = $frame->get_margin_width();
            $available_width = $cb["w"] - $line->left - $line->w - $line->right;

            if (Helpers::lengthGreater($width, $available_width)) {
                $block->add_line();
                $line = $block->get_current_line_box();
            }
        }

        $frame->set_position($cb["x"] + $line->w, $line->y);
    }
}
