<?php
/**
 * @package dompdf
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
namespace Dompdf\Svg;

use Dompdf\Cpdf;
use Svg\Document;
use Svg\Style;
use Svg\Surface\SurfaceCpdf;
use Svg\Tag\LinearGradient;
use Svg\Tag\RadialGradient;

/**
 * SurfaceCpdf subclass that renders SVG gradient fills as native PDF
 * shadings.
 *
 * php-svg-lib parses gradient elements but drops `fill: url(#id)`
 * references when computing styles (Svg\Style::parseColor returns null).
 * This subclass re-resolves the reference from the raw attributes of the
 * tag being rendered, arms the gradient, substitutes the first stop as a
 * flat fallback fill (so the Shape issues a fill call at all), and replaces
 * the fill operation with clip + `sh` using the shading API in Dompdf\Cpdf.
 *
 * Supported: linear and radial gradients (radialGradient elements are
 * parsed into LinearGradient objects by php-svg-lib; the element name is
 * dispatched on), gradientUnits objectBoundingBox (default, via path
 * bounding-box tracking) and userSpaceOnUse, gradientTransform,
 * href/xlink:href template inheritance, and radial focal points (fx/fy/fr).
 * Fallbacks: gradient strokes and text fills use the first stop color;
 * spreadMethod reflect/repeat behave as pad; stop-opacity is ignored.
 *
 * @package dompdf
 */
class GradientAwareSurface extends SurfaceCpdf
{
    /**
     * @var Document
     */
    protected $doc;

    /**
     * @var Cpdf|null
     */
    protected $pdf;

    /**
     * The gradient definition armed for the current shape, if any.
     *
     * @var LinearGradient|RadialGradient|null
     */
    protected $gradient;

    /**
     * Bounding box of the current path in user space:
     * [minX, minY, maxX, maxY], or null if empty.
     *
     * @var array|null
     */
    protected $bbox;

    /**
     * Current point of the path being built, in user space.
     *
     * @var float[]
     */
    protected $cursor = [0.0, 0.0];

    /**
     * Path-building calls recorded for the current shape, so the path can
     * be re-issued after a gradient fill has consumed it.
     *
     * @var array
     */
    protected $pathOps = [];

    /**
     * The document's source file, for re-scanning definitions.
     *
     * @var string
     */
    protected $file = "";

    /**
     * A fully parsed copy of the document, built on demand to resolve
     * forward references: null before the attempt, false if it failed.
     *
     * @var Document|false|null
     */
    protected $scanned = null;

    /**
     * @param Document  $doc
     * @param Cpdf|null $canvas
     * @param string    $file The document's source file, used to re-scan for
     *                        definitions the streaming parser has not reached
     */
    public function __construct(Document $doc, $canvas = null, string $file = "")
    {
        parent::__construct($doc, $canvas);
        $this->doc = $doc;
        $this->pdf = $canvas instanceof Cpdf ? $canvas : null;
        $this->file = $file;
    }

    /**
     * Look up a definition by id.
     *
     * Svg\Document collects definitions as it streams, so a shape that
     * references a gradient declared later in the same file finds nothing.
     * A forward reference is valid, so on a miss the file is scanned once
     * more in full and the result reused.
     *
     * @param string $id
     * @return mixed
     */
    protected function lookupDef(string $id)
    {
        $def = $this->doc->getDef($id);

        if ($def !== null || $this->file === "") {
            return $def;
        }

        if ($this->scanned === null) {
            $this->scanned = false;

            $document = new Document();

            if (property_exists($document, "allowExternalReferences")) {
                $document->allowExternalReferences = $this->doc->allowExternalReferences;
            }

            $document->loadFile($this->file);

            try {
                $document->render(new DefinitionScanSurface());
                $this->scanned = $document;
            } catch (\Exception $e) {
                // A file that cannot be re-parsed simply yields no extra
                // definitions
            }
        }

        return $this->scanned === false ? null : $this->scanned->getDef($id);
    }

    public function setStyle(Style $style)
    {
        $this->gradient = null;
        $this->bbox = null;
        $this->cursor = [0.0, 0.0];
        $this->pathOps = [];

        if ($this->pdf !== null) {
            $def = $this->resolveGradientDef();

            if ($def !== null) {
                $stops = $this->gradientStops($def);

                if (count($stops) > 0) {
                    $this->gradient = $def;
                    // Make sure the Shape issues a fill call; the first
                    // stop doubles as the flat fallback color
                    $style->fill = [
                        $stops[0][1][0] * 255,
                        $stops[0][1][1] * 255,
                        $stops[0][1][2] * 255,
                    ];
                }
            }
        }

        parent::setStyle($style);
    }

    public function fill()
    {
        if ($this->gradient !== null) {
            $this->fillGradient();
            return;
        }

        parent::fill();
    }

    public function fillStroke(bool $close = false)
    {
        // php-svg-lib routes a gradient-filled shape that also has an
        // ordinary stroke through here rather than fill(), so the gradient
        // has to be handled on this path too
        if ($this->gradient !== null) {
            if ($close) {
                $this->closePath();
            }

            $ops = $this->pathOps;
            $this->fillGradient();

            // The fill consumed the path; re-issue it for the stroke, which
            // SVG paints on top of the fill
            $this->pathOps = $ops;
            $this->replayPath();
            parent::stroke(false);
            return;
        }

        parent::fillStroke($close);
    }

    // ---- Path bounding-box tracking ------------------------------------

    /**
     * @param float $x
     * @param float $y
     */
    protected function extendBBox($x, $y): void
    {
        if ($this->bbox === null) {
            $this->bbox = [(float)$x, (float)$y, (float)$x, (float)$y];
            return;
        }

        $this->bbox[0] = min($this->bbox[0], (float)$x);
        $this->bbox[1] = min($this->bbox[1], (float)$y);
        $this->bbox[2] = max($this->bbox[2], (float)$x);
        $this->bbox[3] = max($this->bbox[3], (float)$y);
    }

    /**
     * Extend the bounding box over a cubic Bezier segment.
     *
     * Control points are generally off the curve, so taking them as bounds
     * overstates the box. The real extrema are the endpoints plus any root
     * of the derivative inside the segment.
     *
     * @param float $x0
     * @param float $y0
     * @param float $x1
     * @param float $y1
     * @param float $x2
     * @param float $y2
     * @param float $x3
     * @param float $y3
     */
    protected function extendBBoxCubic($x0, $y0, $x1, $y1, $x2, $y2, $x3, $y3): void
    {
        $this->extendBBox($x0, $y0);
        $this->extendBBox($x3, $y3);

        $axes = [[$x0, $x1, $x2, $x3], [$y0, $y1, $y2, $y3]];

        foreach ($axes as $axis => $p) {
            // B'(t)/3 = at^2 + bt + c over the first differences
            $d1 = $p[1] - $p[0];
            $d2 = $p[2] - $p[1];
            $d3 = $p[3] - $p[2];

            $a = $d1 - 2 * $d2 + $d3;
            $b = 2 * ($d2 - $d1);
            $c = $d1;

            foreach ($this->quadraticRoots($a, $b, $c) as $t) {
                $u = 1 - $t;
                $value = $u * $u * $u * $p[0]
                    + 3 * $u * $u * $t * $p[1]
                    + 3 * $u * $t * $t * $p[2]
                    + $t * $t * $t * $p[3];

                if ($axis === 0) {
                    $this->extendBBox($value, $y0);
                } else {
                    $this->extendBBox($x0, $value);
                }
            }
        }
    }

    /**
     * Extend the bounding box over a quadratic Bezier segment.
     *
     * @param float $x0
     * @param float $y0
     * @param float $x1
     * @param float $y1
     * @param float $x2
     * @param float $y2
     */
    protected function extendBBoxQuadratic($x0, $y0, $x1, $y1, $x2, $y2): void
    {
        $this->extendBBox($x0, $y0);
        $this->extendBBox($x2, $y2);

        $axes = [[$x0, $x1, $x2], [$y0, $y1, $y2]];

        foreach ($axes as $axis => $p) {
            $denominator = $p[0] - 2 * $p[1] + $p[2];

            if (abs($denominator) < 1e-12) {
                continue;
            }

            $t = ($p[0] - $p[1]) / $denominator;

            if ($t <= 0.0 || $t >= 1.0) {
                continue;
            }

            $u = 1 - $t;
            $value = $u * $u * $p[0] + 2 * $u * $t * $p[1] + $t * $t * $p[2];

            if ($axis === 0) {
                $this->extendBBox($value, $y0);
            } else {
                $this->extendBBox($x0, $value);
            }
        }
    }

    /**
     * Real roots of at^2 + bt + c that lie strictly inside (0, 1).
     *
     * @param float $a
     * @param float $b
     * @param float $c
     *
     * @return float[]
     */
    protected function quadraticRoots($a, $b, $c): array
    {
        $roots = [];

        if (abs($a) < 1e-12) {
            if (abs($b) >= 1e-12) {
                $roots[] = -$c / $b;
            }
        } else {
            $discriminant = $b * $b - 4 * $a * $c;

            if ($discriminant >= 0) {
                $root = sqrt($discriminant);
                $roots[] = (-$b + $root) / (2 * $a);
                $roots[] = (-$b - $root) / (2 * $a);
            }
        }

        $inside = [];

        foreach ($roots as $t) {
            if ($t > 0.0 && $t < 1.0) {
                $inside[] = $t;
            }
        }

        return $inside;
    }

    /**
     * Record a path-building call so it can be re-issued.
     *
     * @param string $method
     * @param array  $args
     */
    protected function recordPath(string $method, array $args): void
    {
        $this->pathOps[] = [$method, $args];
    }

    /**
     * Re-issue the recorded path. Painting a path consumes it, so a shape
     * that both fills with a gradient and strokes has to emit it twice.
     */
    protected function replayPath(): void
    {
        foreach ($this->pathOps as $op) {
            list($method, $a) = $op;

            switch ($method) {
                case "moveTo":
                    parent::moveTo($a[0], $a[1]);
                    break;
                case "lineTo":
                    parent::lineTo($a[0], $a[1]);
                    break;
                case "quadraticCurveTo":
                    parent::quadraticCurveTo($a[0], $a[1], $a[2], $a[3]);
                    break;
                case "bezierCurveTo":
                    parent::bezierCurveTo($a[0], $a[1], $a[2], $a[3], $a[4], $a[5]);
                    break;
                case "rect":
                    parent::rect($a[0], $a[1], $a[2], $a[3], $a[4], $a[5]);
                    break;
                case "circle":
                    parent::circle($a[0], $a[1], $a[2]);
                    break;
                case "ellipse":
                    parent::ellipse($a[0], $a[1], $a[2], $a[3], $a[4], $a[5], $a[6], $a[7]);
                    break;
                case "closePath":
                    parent::closePath();
                    break;
            }
        }
    }

    public function moveTo($x, $y)
    {
        $this->extendBBox($x, $y);
        $this->cursor = [(float)$x, (float)$y];
        $this->recordPath("moveTo", [$x, $y]);
        parent::moveTo($x, $y);
    }

    public function lineTo($x, $y)
    {
        $this->extendBBox($x, $y);
        $this->cursor = [(float)$x, (float)$y];
        $this->recordPath("lineTo", [$x, $y]);
        parent::lineTo($x, $y);
    }

    public function quadraticCurveTo($cpx, $cpy, $x, $y)
    {
        list($x0, $y0) = $this->cursor;
        $this->extendBBoxQuadratic($x0, $y0, (float)$cpx, (float)$cpy, (float)$x, (float)$y);
        $this->cursor = [(float)$x, (float)$y];
        $this->recordPath("quadraticCurveTo", [$cpx, $cpy, $x, $y]);
        parent::quadraticCurveTo($cpx, $cpy, $x, $y);
    }

    public function bezierCurveTo($cp1x, $cp1y, $cp2x, $cp2y, $x, $y)
    {
        list($x0, $y0) = $this->cursor;
        $this->extendBBoxCubic(
            $x0,
            $y0,
            (float)$cp1x,
            (float)$cp1y,
            (float)$cp2x,
            (float)$cp2y,
            (float)$x,
            (float)$y
        );
        $this->cursor = [(float)$x, (float)$y];
        $this->recordPath("bezierCurveTo", [$cp1x, $cp1y, $cp2x, $cp2y, $x, $y]);
        parent::bezierCurveTo($cp1x, $cp1y, $cp2x, $cp2y, $x, $y);
    }

    public function rect($x, $y, $w, $h, $rx = 0, $ry = 0)
    {
        $this->extendBBox($x, $y);
        $this->extendBBox($x + $w, $y + $h);
        $this->cursor = [(float)$x, (float)$y];
        $this->recordPath("rect", [$x, $y, $w, $h, $rx, $ry]);
        parent::rect($x, $y, $w, $h, $rx, $ry);
    }

    public function circle($x, $y, $radius)
    {
        $this->extendBBox($x - $radius, $y - $radius);
        $this->extendBBox($x + $radius, $y + $radius);
        $this->cursor = [(float)$x, (float)$y];
        $this->recordPath("circle", [$x, $y, $radius]);
        parent::circle($x, $y, $radius);
    }

    public function ellipse($x, $y, $radiusX, $radiusY, $rotation, $startAngle, $endAngle, $anticlockwise)
    {
        $this->extendBBox($x - $radiusX, $y - $radiusY);
        $this->extendBBox($x + $radiusX, $y + $radiusY);
        $this->cursor = [(float)$x, (float)$y];
        $this->recordPath("ellipse", [$x, $y, $radiusX, $radiusY, $rotation, $startAngle, $endAngle, $anticlockwise]);
        parent::ellipse($x, $y, $radiusX, $radiusY, $rotation, $startAngle, $endAngle, $anticlockwise);
    }

    public function closePath()
    {
        $this->recordPath("closePath", []);
        parent::closePath();
    }

    // ---- Gradient resolution -------------------------------------------

    /**
     * Resolve a gradient definition referenced by the raw fill of the tag
     * currently being rendered.
     *
     * @return LinearGradient|RadialGradient|null
     */
    protected function resolveGradientDef()
    {
        $stack = $this->doc->getStack();
        if (count($stack) === 0) {
            return null;
        }

        // `fill` is an inherited property, so walk out through the open
        // groups until one specifies it
        $raw = null;

        for ($i = count($stack) - 1; $i >= 0; $i--) {
            $raw = $this->rawFill($stack[$i]);

            if ($raw !== null && $raw !== "") {
                break;
            }
        }

        if ($raw === null || !preg_match('/url\(\s*[\'"]?#([^)\'"\s]+)/', $raw, $m)) {
            return null;
        }

        $def = $this->lookupDef($m[1]);

        // php-svg-lib constructs LinearGradient for BOTH gradient elements
        // (its RadialGradient class is an empty stub); the element name is
        // preserved in $tagName, which is what fillGradient() dispatches on
        return ($def instanceof LinearGradient || $def instanceof RadialGradient)
            ? $def
            : null;
    }

    /**
     * The raw `fill` value specified on a tag, before php-svg-lib's colour
     * parsing discards `url(#...)` references. Follows the same cascade the
     * library applies: inline style, then presentation attribute, then a
     * matching rule from the SVG's own `<style>` elements.
     *
     * @param \Svg\Tag\AbstractTag $tag
     * @return string|null
     */
    protected function rawFill($tag)
    {
        $attrs = $tag->getAttributes();

        if (isset($attrs["style"])
            && preg_match('/(?:^|;)\s*fill\s*:\s*([^;]+)/i', $attrs["style"], $m)
        ) {
            return trim($m[1]);
        }

        if (isset($attrs["fill"])) {
            return trim($attrs["fill"]);
        }

        return $this->styleSheetFill($tag, $attrs);
    }

    /**
     * The `fill` declared for a tag by the SVG's `<style>` elements.
     *
     * php-svg-lib matches only bare type selectors and single class
     * selectors, so this recognises exactly the same forms.
     *
     * @param \Svg\Tag\AbstractTag $tag
     * @param array                $attrs
     *
     * @return string|null
     */
    protected function styleSheetFill($tag, array $attrs)
    {
        $sheets = $this->doc->getStyleSheets();

        if (count($sheets) === 0) {
            return null;
        }

        $classes = isset($attrs["class"])
            ? preg_split('/\s+/', trim($attrs["class"]))
            : [];
        $format = \Sabberworm\CSS\OutputFormat::createCompact();
        $fill = null;

        foreach ($sheets as $sheet) {
            foreach ($sheet->getAllDeclarationBlocks() as $declaration) {
                $matched = false;

                foreach ($declaration->getSelectors() as $selector) {
                    $text = $selector->getSelector();

                    if ($text === $tag->tagName
                        || (isset($text[0]) && $text[0] === "."
                            && in_array(substr($text, 1), $classes, true))
                    ) {
                        $matched = true;
                        break;
                    }
                }

                if (!$matched) {
                    continue;
                }

                foreach ($declaration->getRules() as $rule) {
                    if ($rule->getRule() !== "fill") {
                        continue;
                    }

                    $value = $rule->getValue();
                    // Later blocks win, matching source-order cascade
                    $fill = $value instanceof \Sabberworm\CSS\Value\Value
                        ? $value->render($format) . ""
                        : $value . "";
                }
            }
        }

        return $fill;
    }

    /**
     * @param LinearGradient|RadialGradient $def
     * @return bool
     */
    protected function isRadial($def): bool
    {
        return strtolower((string) $def->tagName) === "radialgradient";
    }

    /**
     * Follow the href/xlink:href template chain of a gradient definition.
     *
     * @param LinearGradient|RadialGradient $def
     * @return array The definitions of the chain, starting with $def
     */
    protected function gradientChain($def): array
    {
        $chain = [$def];
        $seen = [spl_object_hash($def) => true];
        $current = $def;

        for ($depth = 0; $depth < 10; $depth++) {
            $attrs = $current->getAttributes();
            $href = null;

            foreach (["href", "xlink:href"] as $key) {
                if (isset($attrs[$key]) && $attrs[$key] !== "") {
                    $href = $attrs[$key];
                    break;
                }
            }

            if ($href === null || $href[0] !== "#") {
                break;
            }

            $next = $this->lookupDef(substr($href, 1));

            if (!($next instanceof LinearGradient || $next instanceof RadialGradient)
                || isset($seen[spl_object_hash($next)])
            ) {
                break;
            }

            $chain[] = $next;
            $seen[spl_object_hash($next)] = true;
            $current = $next;
        }

        return $chain;
    }

    /**
     * The gradient's attributes with href-template inheritance applied
     * (nearest definition wins).
     *
     * @param LinearGradient|RadialGradient $def
     * @return array
     */
    protected function resolveGradientAttributes($def): array
    {
        $merged = [];

        foreach ($this->gradientChain($def) as $link) {
            foreach ($link->getAttributes() as $name => $value) {
                if (!isset($merged[$name])) {
                    $merged[$name] = $value;
                }
            }
        }

        return $merged;
    }

    /**
     * The gradient's stops, inherited through the href chain when the
     * definition itself has none.
     *
     * @param LinearGradient|RadialGradient $def
     * @return array List of [offset (0-1), [r, g, b] (0-1)]
     */
    protected function gradientStops($def): array
    {
        foreach ($this->gradientChain($def) as $link) {
            $out = [];

            foreach ($link->getStops() as $stop) {
                if (!is_array($stop->color)) {
                    continue;
                }

                $offset = $stop->offset;
                if (is_string($offset) && substr(trim($offset), -1) === "%") {
                    $offset = (float)$offset / 100;
                }
                $offset = max(0.0, min(1.0, (float)$offset));

                $out[] = [$offset, [
                    (float)$stop->color[0] / 255,
                    (float)$stop->color[1] / 255,
                    (float)$stop->color[2] / 255,
                ]];
            }

            if (count($out) > 0) {
                return $out;
            }
        }

        return [];
    }

    /**
     * Replace the pending fill of the current path with a clipped shading.
     * The surrounding save/restore pair emitted by Svg\Tag\Shape scopes the
     * clip and the gradient-space transforms to this shape.
     */
    protected function fillGradient(): void
    {
        $def = $this->gradient;
        $this->gradient = null;
        $pdf = $this->pdf;

        // Scope the clip as well as the transform, so a following stroke is
        // not confined to the filled area
        $pdf->save();
        $pdf->clip();

        $attrs = $this->resolveGradientAttributes($def);
        $stops = $this->gradientStops($def);

        $units = strtolower(isset($attrs["gradientunits"]) ? $attrs["gradientunits"] : "objectboundingbox");
        $objectBB = $units !== "userspaceonuse";

        if ($objectBB) {
            // Gradient coordinates are fractions of the shape's bounding
            // box: map the unit square onto it
            $bbox = $this->bbox !== null
                ? $this->bbox
                : [0.0, 0.0, (float)$this->doc->getWidth(), (float)$this->doc->getHeight()];

            $bw = max($bbox[2] - $bbox[0], 0.001);
            $bh = max($bbox[3] - $bbox[1], 0.001);
            $pdf->transform([$bw, 0, 0, $bh, $bbox[0], $bbox[1]]);

            $refX = 1.0;
            $refY = 1.0;
            $refR = 1.0;
        } else {
            $refX = (float)$this->doc->getWidth();
            $refY = (float)$this->doc->getHeight();
            $refR = sqrt(($refX * $refX + $refY * $refY) / 2);
        }

        if (isset($attrs["gradienttransform"])) {
            foreach ($this->parseTransform($attrs["gradienttransform"]) as $matrix) {
                $pdf->transform($matrix);
            }
        }

        if ($this->isRadial($def)) {
            $cx = $this->gradientCoord($attrs, "cx", "50%", $refX);
            $cy = $this->gradientCoord($attrs, "cy", "50%", $refY);
            $r = $this->gradientCoord($attrs, "r", "50%", $refR);
            $fx = isset($attrs["fx"]) ? $this->gradientCoord($attrs, "fx", "50%", $refX) : $cx;
            $fy = isset($attrs["fy"]) ? $this->gradientCoord($attrs, "fy", "50%", $refY) : $cy;
            $fr = $this->gradientCoord($attrs, "fr", "0", $refR);

            $name = $pdf->addRadialGradient($fx, $fy, $fr, $cx, $cy, $r, $stops);
        } else {
            $x1 = $this->gradientCoord($attrs, "x1", "0%", $refX);
            $y1 = $this->gradientCoord($attrs, "y1", "0%", $refY);
            $x2 = $this->gradientCoord($attrs, "x2", "100%", $refX);
            $y2 = $this->gradientCoord($attrs, "y2", "0%", $refY);

            $name = $pdf->addLinearGradient($x1, $y1, $x2, $y2, $stops);
        }

        $pdf->applyShading($name);
        $pdf->restore();
    }

    /**
     * Resolve a gradient coordinate attribute; percentages are relative to
     * $ref (the unit square for objectBoundingBox, the viewport for
     * userSpaceOnUse).
     *
     * @param array  $attrs
     * @param string $name
     * @param string $default
     * @param float  $ref
     *
     * @return float
     */
    protected function gradientCoord(array $attrs, string $name, string $default, float $ref): float
    {
        $v = isset($attrs[$name]) ? trim((string)$attrs[$name]) : $default;

        if (substr($v, -1) === "%") {
            return (float)$v / 100 * $ref;
        }

        return (float)$v;
    }

    /**
     * Parse an SVG transform list into a sequence of PDF transform
     * matrices to apply in order.
     *
     * @param string $transform
     * @return array List of [a, b, c, d, e, f]
     */
    protected function parseTransform(string $transform): array
    {
        $matrices = [];

        if (!preg_match_all('/(matrix|translate|scale|rotate|skewX|skewY)\s*\(([^)]*)\)/i', $transform, $matches, PREG_SET_ORDER)) {
            return $matrices;
        }

        foreach ($matches as $m) {
            $name = strtolower($m[1]);
            $args = preg_split('/[\s,]+/', trim($m[2]));
            $args = array_map("floatval", array_filter($args, "strlen"));

            switch ($name) {
                case "matrix":
                    if (count($args) === 6) {
                        $matrices[] = $args;
                    }
                    break;

                case "translate":
                    $tx = isset($args[0]) ? $args[0] : 0.0;
                    $ty = isset($args[1]) ? $args[1] : 0.0;
                    $matrices[] = [1, 0, 0, 1, $tx, $ty];
                    break;

                case "scale":
                    $sx = isset($args[0]) ? $args[0] : 1.0;
                    $sy = isset($args[1]) ? $args[1] : $sx;
                    $matrices[] = [$sx, 0, 0, $sy, 0, 0];
                    break;

                case "rotate":
                    $a = deg2rad(isset($args[0]) ? $args[0] : 0.0);
                    $cos = cos($a);
                    $sin = sin($a);

                    if (isset($args[1]) || isset($args[2])) {
                        $cx = isset($args[1]) ? $args[1] : 0.0;
                        $cy = isset($args[2]) ? $args[2] : 0.0;
                        $matrices[] = [1, 0, 0, 1, $cx, $cy];
                        $matrices[] = [$cos, $sin, -$sin, $cos, 0, 0];
                        $matrices[] = [1, 0, 0, 1, -$cx, -$cy];
                    } else {
                        $matrices[] = [$cos, $sin, -$sin, $cos, 0, 0];
                    }
                    break;

                case "skewx":
                    $matrices[] = [1, 0, tan(deg2rad(isset($args[0]) ? $args[0] : 0.0)), 1, 0, 0];
                    break;

                case "skewy":
                    $matrices[] = [1, tan(deg2rad(isset($args[0]) ? $args[0] : 0.0)), 0, 1, 0, 0];
                    break;
            }
        }

        return $matrices;
    }
}
