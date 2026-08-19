<?php
/**
 * @package dompdf
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
namespace Dompdf\Positioner;

use Dompdf\FrameDecorator\AbstractFrameDecorator;
use Dompdf\FrameDecorator\Flex as FlexFrameDecorator;

/**
 * Positions flex items
 *
 * Flex items are positioned at the coordinates computed by the parent flex
 * container's layout algorithm (push model, cf. Positioner\TableCell).
 *
 * @package dompdf
 */
class FlexItem extends AbstractPositioner
{

    function position(AbstractFrameDecorator $frame): void
    {
        $flex = FlexFrameDecorator::find_parent_flex($frame);
        $pos = $flex !== null ? $flex->get_item_position($frame) : null;

        if ($pos !== null) {
            $frame->set_position($pos[0], $pos[1]);
        } else {
            // Not laid out by the container (yet); fall back to the
            // containing-block origin
            $cb = $frame->get_containing_block();
            $frame->set_position($cb["x"], $cb["y"]);
        }
    }
}
