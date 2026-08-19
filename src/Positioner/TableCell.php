<?php
/**
 * @package nativepdf
 * @link    https://github.com/Javaabu/nativepdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
namespace NativePdf\Positioner;

use NativePdf\Exception;
use NativePdf\FrameDecorator\AbstractFrameDecorator;
use NativePdf\FrameDecorator\Table;

/**
 * Positions table cells
 *
 * @package nativepdf
 */
class TableCell extends AbstractPositioner
{

    /**
     * @param AbstractFrameDecorator $frame
     */
    function position(AbstractFrameDecorator $frame): void
    {
        $table = Table::find_parent_table($frame);
        if ($table === null) {
            throw new Exception("Parent table not found for table cell");
        }
        $cellmap = $table->get_cellmap();
        $frame->set_position($cellmap->get_frame_position($frame));
    }
}
