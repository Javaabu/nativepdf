<?php
/**
 * @package nativepdf
 * @link    https://github.com/Javaabu/nativepdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
namespace NativePdf\FrameDecorator;

use NativePdf\Frame;
use NativePdf\Frame\Factory;

/**
 * Decorates frames for flex container layout
 *
 * The flex container computes item geometry centrally (cf. Cellmap for
 * tables); items read their positions back via Positioner\FlexItem.
 *
 * @package nativepdf
 */
class Flex extends Block
{
    /**
     * Resolved item positions (margin-box origin), keyed by frame id.
     *
     * @var array<int, array{0: float, 1: float}>
     */
    protected $item_positions = [];

    /**
     * Whether anonymous-item normalization has been performed. The resulting
     * tree structure persists across page reflows, so this is intentionally
     * not cleared by reset().
     *
     * @var bool
     */
    protected $normalized = false;

    /**
     * @param AbstractFrameDecorator $frame
     * @param float                  $x
     * @param float                  $y
     */
    public function set_item_position(AbstractFrameDecorator $frame, float $x, float $y): void
    {
        $this->item_positions[$frame->get_id()] = [$x, $y];
    }

    /**
     * @param AbstractFrameDecorator $frame
     *
     * @return array|null [x, y], or null if the container has not positioned
     *         the frame
     */
    public function get_item_position(AbstractFrameDecorator $frame): ?array
    {
        $id = $frame->get_id();
        return isset($this->item_positions[$id]) ? $this->item_positions[$id] : null;
    }

    /**
     * The in-flow children of the container, stable-sorted by their `order`
     * property (document order breaks ties).
     *
     * https://www.w3.org/TR/css-flexbox-1/#order-property
     *
     * @return AbstractFrameDecorator[]
     */
    public function get_flex_items(): array
    {
        $indexed = [];
        $i = 0;

        foreach ($this->get_children() as $child) {
            if ($child->is_in_flow()) {
                $indexed[] = [$child->get_style()->order, $i++, $child];
            }
        }

        usort($indexed, function ($a, $b) {
            return $a[0] <=> $b[0] ?: $a[1] <=> $b[1];
        });

        return array_map(function ($entry) {
            return $entry[2];
        }, $indexed);
    }

    /**
     * Flexbox requires painting to follow order-modified document order, not
     * just layout, so that `order` also decides which of two overlapping
     * items is on top.
     *
     * https://www.w3.org/TR/css-flexbox-1/#painting
     *
     * @return iterable<AbstractFrameDecorator>
     */
    public function get_children_in_paint_order(): iterable
    {
        $indexed = [];
        $i = 0;

        foreach ($this->get_children() as $child) {
            // `order` only reorders items; anything out of flow keeps its
            // document position
            $order = $child->is_in_flow() ? $child->get_style()->order : 0;
            $indexed[] = [$order, $i++, $child];
        }

        usort($indexed, function ($a, $b) {
            return $a[0] <=> $b[0] ?: $a[1] <=> $b[1];
        });

        return array_map(function ($entry) {
            return $entry[2];
        }, $indexed);
    }

    /**
     * Locate the flex container a flex item belongs to.
     *
     * @param Frame $frame
     *
     * @return Flex|null
     */
    public static function find_parent_flex(Frame $frame): ?Flex
    {
        $f = $frame->get_parent();

        while ($f !== null && !($f instanceof Flex)) {
            $f = $f->get_parent();
        }

        return $f;
    }

    function reset()
    {
        parent::reset();
        $this->item_positions = [];
    }

    /**
     * Restructure the children so that each contiguous run of inline-level
     * content is wrapped in an anonymous block-level flex item. Element
     * children have already been blockified into their own items during
     * decoration (see Frame\Factory). White-space-only text between items is
     * removed.
     *
     * https://www.w3.org/TR/css-flexbox-1/#flex-items
     */
    public function normalize(): void
    {
        if ($this->normalized) {
            return;
        }
        $this->normalized = true;

        // Only match collapsible white space (cf. FrameReflower\Text)
        $wsPattern = '/^[^\S\xA0\x{202F}\x{2007}]*$/u';

        $isItemLevelOrNull = function ($f) {
            return $f === null
                || (!$f->is_text_node()
                    && !in_array($f->get_style()->display, ["inline", "-nativepdf-br"], true));
        };

        $children = iterator_to_array($this->get_children());
        $run = [];

        $flush = function () use (&$run) {
            if (count($run) === 0) {
                return;
            }

            $wrapper = $this->create_anonymous_child("nativepdf-flex-item", "block");
            $this->insert_child_before($wrapper, $run[0]);
            $wrapper->set_positioner(Factory::getPositionerInstance("FlexItem"));

            foreach ($run as $f) {
                $wrapper->append_child($f);
            }

            $run = [];
        };

        foreach ($children as $child) {
            $display = $child->get_style()->display;
            $isInline = $child->is_text_node()
                || in_array($display, ["inline", "-nativepdf-br"], true);

            if (!$isInline || !$child->is_in_flow()) {
                $flush();
                continue;
            }

            // Drop white-space-only text directly between items
            if ($child instanceof Text
                && !$child->is_pre()
                && preg_match($wsPattern, $child->get_text())
                && $isItemLevelOrNull($child->get_prev_sibling())
                && $isItemLevelOrNull($child->get_next_sibling())
            ) {
                $this->remove_child($child);
                continue;
            }

            $run[] = $child;
        }

        $flush();
    }
}
