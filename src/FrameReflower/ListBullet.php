<?php
/**
 * @package nativepdf
 * @link    https://github.com/Javaabu/nativepdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
namespace NativePdf\FrameReflower;

use NativePdf\FrameDecorator\Block as BlockFrameDecorator;
use NativePdf\FrameDecorator\ListBullet as ListBulletFrameDecorator;

/**
 * Reflows list bullets
 *
 * @package nativepdf
 */
class ListBullet extends AbstractFrameReflower
{

    /**
     * ListBullet constructor.
     * @param ListBulletFrameDecorator $frame
     */
    function __construct(ListBulletFrameDecorator $frame)
    {
        parent::__construct($frame);
    }

    /**
     * @param BlockFrameDecorator|null $block
     */
    function reflow(?BlockFrameDecorator $block = null)
    {
        if ($block === null) {
            return;
        }

        /** @var ListBulletFrameDecorator */
        $frame = $this->_frame;
        $style = $frame->get_style();

        $style->set_used("width", $frame->get_width());
        $frame->position();

        if ($style->list_style_position === "inside") {
            $block->add_frame_to_line($frame);
        } else {
            $block->add_dangling_marker($frame);
        }
    }
}
