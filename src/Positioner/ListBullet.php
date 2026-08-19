<?php
/**
 * @package nativepdf
 * @link    https://github.com/Javaabu/nativepdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
namespace NativePdf\Positioner;

use NativePdf\FrameDecorator\AbstractFrameDecorator;
use NativePdf\FrameDecorator\ListBullet as ListBulletFrameDecorator;

/**
 * Positions list bullets
 *
 * @package nativepdf
 */
class ListBullet extends AbstractPositioner
{
    /**
     * @param ListBulletFrameDecorator $frame
     */
    function position(AbstractFrameDecorator $frame): void
    {
        // List markers are positioned to the left of the border edge of
        // their parent element; to the right of it for right-to-left lists
        $parent = $frame->get_parent();
        $style = $parent->get_style();
        $cbw = $parent->get_containing_block("w");

        if ($style->direction === "rtl") {
            $margin_right = (float) $style->length_in_pt($style->margin_right, $cbw);
            $border_edge = $parent->get_position("x") + $parent->get_margin_width() - $margin_right;

            // This includes the marker indentation
            $x = $border_edge;
        } else {
            $margin_left = (float) $style->length_in_pt($style->margin_left, $cbw);
            $border_edge = $parent->get_position("x") + $margin_left;

            // This includes the marker indentation
            $x = $border_edge - $frame->get_margin_width();
        }

        // The marker is later vertically aligned with the corresponding line
        // box and its vertical position is fine-tuned in the renderer
        $p = $frame->find_block_parent();
        $y = $p->get_current_line_box()->y;

        $frame->set_position($x, $y);
    }
}
