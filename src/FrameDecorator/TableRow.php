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
 * Decorates Frames for table row layout
 *
 * @package nativepdf
 */
class TableRow extends AbstractFrameDecorator
{
    /**
     * TableRow constructor.
     * @param Frame $frame
     * @param NativePdf $nativepdf
     */
    function __construct(Frame $frame, NativePdf $nativepdf)
    {
        parent::__construct($frame, $nativepdf);
    }
}
