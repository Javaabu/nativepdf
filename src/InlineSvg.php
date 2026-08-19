<?php
/**
 * @package dompdf
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
namespace Dompdf;

use DOMDocument;
use DOMElement;
use Dompdf\Helpers;

/**
 * Converts inline `<svg>` elements into `<img>` elements with an SVG data
 * URI, so they travel through the regular image pipeline (validation via
 * Image\Cache, intrinsic sizing, vector rendering via the canvas).
 *
 * The conversion must run on a DOM that still has attribute-case fidelity
 * (e.g. `viewBox`). In Dompdf::loadHtml that means after the Masterminds
 * HTML5 parse and before the DOMDocument::loadHTML normalization round-trip,
 * which is namespace-blind and lowercases attribute and tag names.
 *
 * Known limitations (documented): page CSS does not cascade into the SVG's
 * internals; presentation attributes, the SVG's own `style` attributes and
 * `<style>` elements inside the SVG are honored (via php-svg-lib). Links and
 * text inside the SVG are not part of the page's text flow.
 */
class InlineSvg
{
    const SVG_NS = "http://www.w3.org/2000/svg";

    /**
     * Marks the <img> that replaced an inline <svg>, so that `svg` type
     * selectors still match it during style resolution.
     */
    const CONVERTED_ATTR = "data-dompdf-inline-svg";

    /**
     * The CSS default object size for replaced elements, in CSS px.
     */
    const DEFAULT_WIDTH = 300;
    const DEFAULT_HEIGHT = 150;

    /**
     * Replace each top-level `<svg>` element in the document with an
     * equivalent `<img>` element carrying the SVG as a data URI.
     *
     * @param DOMDocument $dom
     */
    public static function convert(
        DOMDocument $dom,
        string $protocol = "",
        string $baseHost = "",
        string $basePath = ""
    ): void {
        $svgs = [];

        // Namespace-blind parses (Masterminds with disable_html_ns) yield
        // null-namespace elements; namespace-aware DOMs use the SVG
        // namespace. Handle both.
        foreach ($dom->getElementsByTagName("svg") as $node) {
            $svgs[] = $node;
        }
        foreach ($dom->getElementsByTagNameNS(self::SVG_NS, "svg") as $node) {
            $svgs[] = $node;
        }

        // Skip nested <svg> elements and deduplicate
        $topLevel = [];
        foreach ($svgs as $node) {
            if (in_array($node, $topLevel, true)) {
                continue;
            }

            $parent = $node->parentNode;
            $nested = false;
            while ($parent instanceof DOMElement) {
                if (self::isSvgElement($parent)) {
                    $nested = true;
                    break;
                }
                $parent = $parent->parentNode;
            }

            if (!$nested) {
                $topLevel[] = $node;
            }
        }

        foreach ($topLevel as $node) {
            if ($node->parentNode === null) {
                continue;
            }

            self::absolutizeReferences($node, $protocol, $baseHost, $basePath);

            $img = self::createReplacement($dom, $node);
            $node->parentNode->replaceChild($img, $node);
        }
    }

    /**
     * Rewrite relative resource references inside the SVG to absolute ones.
     *
     * Encoding the SVG as a `data:` URI destroys the base the references
     * were written against, so a valid `<image href="photo.png">` would
     * afterwards resolve against the `data:` scheme and fail. Resolution is
     * confined to the document's own directory: a relative reference names a
     * sibling resource, not an arbitrary path on disk.
     *
     * @param DOMElement $svg
     * @param string     $protocol
     * @param string     $baseHost
     * @param string     $basePath
     */
    protected static function absolutizeReferences(
        DOMElement $svg,
        string $protocol,
        string $baseHost,
        string $basePath
    ): void {
        if ($basePath === "") {
            return;
        }

        $root = realpath($basePath);
        $stack = [$svg];

        while (count($stack) > 0) {
            $node = array_pop($stack);

            foreach ($node->childNodes as $child) {
                if ($child instanceof DOMElement) {
                    $stack[] = $child;
                }
            }

            foreach (["href", "xlink:href"] as $attr) {
                if (!$node->hasAttribute($attr)) {
                    continue;
                }

                $value = trim($node->getAttribute($attr));

                // Fragments address the SVG's own contents
                if ($value === "" || $value[0] === "#") {
                    continue;
                }

                $resolved = Helpers::build_url($protocol, $baseHost, $basePath, $value);

                if ($resolved === null) {
                    continue;
                }

                if (strpos($resolved, "file://") === 0) {
                    $target = realpath(substr($resolved, 7));

                    if ($target === false || $root === false
                        || strpos($target, $root . DIRECTORY_SEPARATOR) !== 0
                    ) {
                        continue;
                    }
                }

                $node->setAttribute($attr, $resolved);
            }
        }
    }

    /**
     * The intrinsic aspect ratio an SVG's viewBox establishes, as
     * [width, height], or null when it declares none usable.
     *
     * @param DOMElement $svg
     * @return float[]|null
     */
    protected static function viewBoxRatio(DOMElement $svg): ?array
    {
        if (!$svg->hasAttribute("viewBox")) {
            return null;
        }

        $parts = preg_split('/[\s,]+/', trim($svg->getAttribute("viewBox")), -1, PREG_SPLIT_NO_EMPTY);

        if ($parts === false || count($parts) !== 4) {
            return null;
        }

        $width = (float) $parts[2];
        $height = (float) $parts[3];

        return $width > 0 && $height > 0 ? [$width, $height] : null;
    }

    /**
     * @param DOMElement $node
     * @return bool
     */
    protected static function isSvgElement(DOMElement $node): bool
    {
        return $node->localName === "svg"
            && ($node->namespaceURI === null || $node->namespaceURI === self::SVG_NS);
    }

    /**
     * @param DOMDocument $dom
     * @param DOMElement  $svg
     *
     * @return DOMElement
     */
    protected static function createReplacement(DOMDocument $dom, DOMElement $svg): DOMElement
    {
        // Only dimensions the author actually wrote may travel to the <img>,
        // where they act as presentational hints and pin the used size. A
        // default injected below belongs to the SVG's own intrinsic size, so
        // that CSS sizing one axis still derives the other from the ratio.
        $authored = [
            "width" => $svg->hasAttribute("width"),
            "height" => $svg->hasAttribute("height"),
        ];

        // A viewBox establishes a coordinate system and an aspect ratio, not
        // intrinsic dimensions, so an SVG carrying only a viewBox still needs
        // a concrete default size. Per the CSS default sizing algorithm that
        // is the largest rectangle with the intrinsic ratio that fits inside
        // the 300x150 default object size -- which preserves the ratio.
        // https://www.w3.org/TR/css-images-3/#default-sizing
        if (!$svg->hasAttribute("width") && !$svg->hasAttribute("height")) {
            $ratio = self::viewBoxRatio($svg);

            if ($ratio === null) {
                $svg->setAttribute("width", "300");
                $svg->setAttribute("height", "150");
            } else {
                $scale = min(self::DEFAULT_WIDTH / $ratio[0], self::DEFAULT_HEIGHT / $ratio[1]);
                $svg->setAttribute("width", (string) round($ratio[0] * $scale, 4));
                $svg->setAttribute("height", (string) round($ratio[1] * $scale, 4));
            }
        } elseif (self::viewBoxRatio($svg) === null) {
            // Without a ratio the remaining axis cannot be derived, so it
            // falls back to the default object size too
            if (!$svg->hasAttribute("width")) {
                $svg->setAttribute("width", (string) self::DEFAULT_WIDTH);
            }

            if (!$svg->hasAttribute("height")) {
                $svg->setAttribute("height", (string) self::DEFAULT_HEIGHT);
            }
        }

        if (!$svg->hasAttribute("xmlns") && $svg->namespaceURI === null) {
            $svg->setAttribute("xmlns", self::SVG_NS);
        }

        $xml = $dom->saveXML($svg);

        // Undeclared xlink prefixes break strict XML consumers
        if (strpos($xml, "xlink:") !== false && strpos($xml, "xmlns:xlink") === false) {
            $svg->setAttribute("xmlns:xlink", "http://www.w3.org/1999/xlink");
            $xml = $dom->saveXML($svg);
        }

        $img = $dom->createElement("img");
        $img->setAttribute("src", "data:image/svg+xml;base64," . base64_encode($xml));
        $img->setAttribute(self::CONVERTED_ATTR, "1");

        foreach (["id", "class", "alt", "title"] as $attr) {
            if ($svg->hasAttribute($attr)) {
                $img->setAttribute($attr, $svg->getAttribute($attr));
            }
        }

        // Numeric width/height attributes map to the img attributes; other
        // values (percentages, units) become CSS so they keep their meaning
        $css = "";
        foreach (["width", "height"] as $attr) {
            if (!$authored[$attr]) {
                continue;
            }

            $value = trim($svg->getAttribute($attr));

            if (preg_match('/^\d*\.?\d+(px)?$/', $value)) {
                $img->setAttribute($attr, rtrim($value, "px"));
            } elseif ($value !== "") {
                $css .= $attr . ": " . $value . "; ";
            }
        }

        $style = trim($css . ($svg->hasAttribute("style") ? $svg->getAttribute("style") : ""));
        if ($style !== "") {
            $img->setAttribute("style", $style);
        }

        return $img;
    }
}
