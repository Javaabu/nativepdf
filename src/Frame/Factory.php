<?php
/**
 * @package nativepdf
 * @link    https://github.com/Javaabu/nativepdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
namespace NativePdf\Frame;

use NativePdf\NativePdf;
use NativePdf\Exception;
use NativePdf\Frame;
use NativePdf\FrameDecorator\AbstractFrameDecorator;
use NativePdf\FrameDecorator\Page as PageFrameDecorator;
use NativePdf\FrameReflower\Page as PageFrameReflower;
use NativePdf\Positioner\AbstractPositioner;
use DOMXPath;

/**
 * Contains frame decorating logic
 *
 * This class is responsible for assigning the correct {@link AbstractFrameDecorator},
 * {@link AbstractPositioner}, and {@link AbstractFrameReflower} objects to {@link Frame}
 * objects.  This is determined primarily by the Frame's display type, but
 * also by the Frame's node's type (e.g. DomElement vs. #text)
 *
 * @package nativepdf
 */
class Factory
{

    /**
     * Array of positioners for specific frame types
     *
     * @var AbstractPositioner[]
     */
    protected static $_positioners;

    /**
     * Decorate the root Frame
     *
     * @param Frame  $root   The frame to decorate
     * @param NativePdf $nativepdf The nativepdf instance
     *
     * @return PageFrameDecorator
     */
    public static function decorate_root(Frame $root, NativePdf $nativepdf): PageFrameDecorator
    {
        $frame = new PageFrameDecorator($root, $nativepdf);
        $frame->set_reflower(new PageFrameReflower($frame));
        $root->set_decorator($frame);

        return $frame;
    }

    /**
     * Decorate a Frame
     *
     * @param Frame      $frame  The frame to decorate
     * @param NativePdf     $nativepdf The nativepdf instance
     * @param Frame|null $root   The root of the frame
     *
     * @throws Exception
     * @return AbstractFrameDecorator|null
     * FIXME: this is admittedly a little smelly...
     */
    public static function decorate_frame(Frame $frame, NativePdf $nativepdf, ?Frame $root = null): ?AbstractFrameDecorator
    {
        $style = $frame->get_style();
        $display = $style->display;

        // Flex items: every in-flow element child of a flex container is a
        // flex item with a blockified display
        // https://www.w3.org/TR/css-flexbox-1/#flex-items
        $parent = $frame->get_parent();
        $is_flex_item = $parent !== null
            && !$frame->is_text_node()
            && $style->is_in_flow()
            && $parent->get_style() !== null
            && in_array($parent->get_style()->display, ["flex", "inline-flex"], true);

        if ($is_flex_item) {
            // https://www.w3.org/TR/css-display-3/#blockify
            static $blockify = [
                "inline" => "block",
                "inline-block" => "block",
                "inline-table" => "table",
                "inline-flex" => "flex",
                "table-row-group" => "block",
                "table-header-group" => "block",
                "table-footer-group" => "block",
                "table-row" => "block",
                "table-cell" => "block",
                "table-column" => "block",
                "table-column-group" => "block",
                "table-caption" => "block",
            ];

            if (isset($blockify[$display])) {
                $style->set_prop("display", $blockify[$display]);
                $display = $style->display;
            }

            // Floats do not apply to flex items
            if ($style->float !== "none") {
                $style->set_prop("float", "none");
            }
        }

        switch ($display) {

            case "block":
                $positioner = "Block";
                $decorator = "Block";
                $reflower = "Block";
                break;

            case "inline-block":
                $positioner = "Inline";
                $decorator = "Block";
                $reflower = "Block";
                break;

            case "inline":
                $positioner = "Inline";
                if ($frame->is_text_node()) {
                    $decorator = "Text";
                    $reflower = "Text";
                } else {
                    $decorator = "Inline";
                    $reflower = "Inline";
                }
                break;

            case "table":
                $positioner = "Block";
                $decorator = "Table";
                $reflower = "Table";
                break;

            case "inline-table":
                $positioner = "Inline";
                $decorator = "Table";
                $reflower = "Table";
                break;

            case "table-row-group":
            case "table-header-group":
            case "table-footer-group":
                $positioner = "NullPositioner";
                $decorator = "TableRowGroup";
                $reflower = "TableRowGroup";
                break;

            case "table-row":
                $positioner = "NullPositioner";
                $decorator = "TableRow";
                $reflower = "TableRow";
                break;

            case "table-cell":
                $positioner = "TableCell";
                $decorator = "TableCell";
                $reflower = "TableCell";
                break;

            case "list-item":
                $positioner = "Block";
                $decorator = "Block";
                $reflower = "Block";
                break;

            case "flex":
                $positioner = "Block";
                $decorator = "Flex";
                $reflower = "Flex";
                break;

            case "inline-flex":
                $positioner = "Inline";
                $decorator = "Flex";
                $reflower = "Flex";
                break;

            case "-nativepdf-list-bullet":
                if ($style->list_style_position === "inside") {
                    $positioner = "Inline";
                } else {
                    $positioner = "ListBullet";
                }

                if ($style->list_style_image !== "none") {
                    $decorator = "ListBulletImage";
                } else {
                    $decorator = "ListBullet";
                }

                $reflower = "ListBullet";
                break;

            case "-nativepdf-image":
                $positioner = "Inline";
                $decorator = "Image";
                $reflower = "Image";
                break;

            case "-nativepdf-br":
                $positioner = "Inline";
                $decorator = "Inline";
                $reflower = "Inline";
                break;

            default:
            case "none":
                if ($style->_nativepdf_keep !== "yes") {
                    // Remove the node and the frame
                    $frame->get_parent()->remove_child($frame);
                    return null;
                }

                $positioner = "NullPositioner";
                $decorator = "NullFrameDecorator";
                $reflower = "NullFrameReflower";
                break;
        }

        // Handle CSS position
        $position = $style->position;

        if ($position === "absolute") {
            $positioner = "Absolute";
        } elseif ($position === "fixed") {
            $positioner = "Fixed";
        }

        $node = $frame->get_node();

        // Handle nodeName
        if ($node->nodeName === "img") {
            $style->set_prop("display", "-nativepdf-image");
            $decorator = "Image";
            $reflower = "Image";
        }

        // Flex items are positioned by their container
        if ($is_flex_item && $style->display !== "none") {
            $positioner = "FlexItem";
        }

        $decorator  = "NativePdf\\FrameDecorator\\$decorator";
        $reflower   = "NativePdf\\FrameReflower\\$reflower";

        /** @var AbstractFrameDecorator $deco */
        $deco = new $decorator($frame, $nativepdf);

        $deco->set_positioner(self::getPositionerInstance($positioner));
        $deco->set_reflower(new $reflower($deco, $nativepdf->getFontMetrics()));

        if ($root) {
            $deco->set_root($root);
        }

        if ($display === "list-item") {
            // Insert a list-bullet frame
            $xml = $nativepdf->getDom();
            $bullet_node = $xml->createElement("bullet"); // arbitrary choice
            $b_f = new Frame($bullet_node);

            $node = $frame->get_node();
            $parent_node = $node->parentNode;
            if ($parent_node && $parent_node instanceof \DOMElement) {
                if (!$parent_node->hasAttribute("nativepdf-children-count")) {
                    $xpath = new DOMXPath($xml);
                    $count = $xpath->query("li", $parent_node)->length;
                    $parent_node->setAttribute("nativepdf-children-count", $count);
                }

                if (is_numeric($node->getAttribute("value"))) {
                    $index = intval($node->getAttribute("value"));
                } else {
                    if (!$parent_node->hasAttribute("nativepdf-counter")) {
                        $index = ($parent_node->hasAttribute("start") ? $parent_node->getAttribute("start") : 1);
                    } else {
                        $index = (int)$parent_node->getAttribute("nativepdf-counter") + 1;
                    }
                }

                $parent_node->setAttribute("nativepdf-counter", $index);
                $bullet_node->setAttribute("nativepdf-counter", $index);
            }

            $new_style = $nativepdf->getCss()->create_style();
            $new_style->set_prop("display", "-nativepdf-list-bullet");
            $new_style->inherit($style);
            $b_f->set_style($new_style);

            $deco->prepend_child(Factory::decorate_frame($b_f, $nativepdf, $root));
        }

        return $deco;
    }

    /**
     * Creates Positioners
     *
     * @param string $type Type of positioner to use
     *
     * @return AbstractPositioner
     */
    public static function getPositionerInstance(string $type): AbstractPositioner
    {
        if (!isset(self::$_positioners[$type])) {
            $class = '\\NativePdf\\Positioner\\'.$type;
            self::$_positioners[$type] = new $class();
        }
        return self::$_positioners[$type];
    }
}
