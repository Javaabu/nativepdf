<?php
/**
 * @package nativepdf
 * @link    https://github.com/Javaabu/nativepdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
namespace NativePdf\FrameDecorator;

use NativePdf\NativePdf;
use NativePdf\Frame;

/**
 * Dummy decorator
 *
 * @package nativepdf
 */
class NullFrameDecorator extends AbstractFrameDecorator
{
    /**
     * NullFrameDecorator constructor.
     * @param Frame $frame
     * @param NativePdf $nativepdf
     */
    function __construct(Frame $frame, NativePdf $nativepdf)
    {
        parent::__construct($frame, $nativepdf);
        $style = $this->_frame->get_style();
        $style->width = 0;
        $style->height = 0;
        $style->margin = 0;
        $style->padding = 0;
    }
}
