<?php
namespace Dompdf\Tests;

use DOMDocument;
use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\FrameDecorator\AbstractFrameDecorator;
use Dompdf\InlineSvg;
use Dompdf\Options;
use Masterminds\HTML5;

class InlineSvgTest extends TestCase
{
    private function convertHtml(string $html): DOMDocument
    {
        $html5 = new HTML5(["encoding" => "UTF-8", "disable_html_ns" => true]);
        $dom = $html5->loadHTML($html);
        InlineSvg::convert($dom);

        return $dom;
    }

    private function decodeSrc(\DOMElement $img): string
    {
        $src = $img->getAttribute("src");
        $this->assertStringStartsWith("data:image/svg+xml;base64,", $src);

        return base64_decode(substr($src, strlen("data:image/svg+xml;base64,")));
    }

    public function testCasePreservationAndNamespace(): void
    {
        $dom = $this->convertHtml(
            '<html><body><svg width="100" height="50" viewBox="0 0 200 100" preserveAspectRatio="xMidYMid meet">'
            . '<defs><linearGradient id="g"><stop offset="0" stop-color="red"/></linearGradient></defs>'
            . '<rect width="200" height="100" fill="url(#g)"/></svg></body></html>'
        );

        $this->assertSame(0, $dom->getElementsByTagName("svg")->length);
        $imgs = $dom->getElementsByTagName("img");
        $this->assertSame(1, $imgs->length);

        $xml = $this->decodeSrc($imgs->item(0));
        $this->assertStringContainsString('viewBox="0 0 200 100"', $xml);
        $this->assertStringContainsString('preserveAspectRatio="xMidYMid meet"', $xml);
        $this->assertStringContainsString('<linearGradient', $xml);
        $this->assertStringContainsString('xmlns="http://www.w3.org/2000/svg"', $xml);
    }

    public function testDefaultDimensionsInjected(): void
    {
        $dom = $this->convertHtml('<html><body><svg><rect width="10" height="10"/></svg></body></html>');

        $img = $dom->getElementsByTagName("img")->item(0);
        $xml = $this->decodeSrc($img);

        // The default size is the SVG's intrinsic size, not a presentational
        // hint on the <img>, so that CSS can still size it freely
        $this->assertStringContainsString('width="300"', $xml);
        $this->assertStringContainsString('height="150"', $xml);
        $this->assertFalse($img->hasAttribute("width"));
        $this->assertFalse($img->hasAttribute("height"));
    }

    public function testViewBoxOnlyKeepsIntrinsicRatio(): void
    {
        $dom = $this->convertHtml('<html><body><svg viewBox="0 0 40 20"><rect width="10" height="10"/></svg></body></html>');

        $img = $dom->getElementsByTagName("img")->item(0);
        $xml = $this->decodeSrc($img);

        // A viewBox carries a ratio but no intrinsic size, so the default
        // object size is scaled to that ratio and applied to the SVG itself.
        // The <img> stays unsized, so CSS sizing one axis still derives the
        // other from the ratio.
        $this->assertStringContainsString('width="300"', $xml);
        $this->assertStringContainsString('height="150"', $xml);
        $this->assertFalse($img->hasAttribute("width"));
        $this->assertFalse($img->hasAttribute("height"));
    }

    public function testAttributeCarryOver(): void
    {
        $dom = $this->convertHtml(
            '<html><body><svg id="chart" class="a b" title="hello" style="border: 1pt solid red"'
            . ' width="50%" height="40"><rect width="10" height="10"/></svg></body></html>'
        );

        /** @var \DOMElement $img */
        $img = $dom->getElementsByTagName("img")->item(0);

        $this->assertSame("chart", $img->getAttribute("id"));
        $this->assertSame("a b", $img->getAttribute("class"));
        $this->assertSame("hello", $img->getAttribute("title"));
        // Numeric height becomes an attribute; percentage width becomes CSS
        $this->assertSame("40", $img->getAttribute("height"));
        $this->assertFalse($img->hasAttribute("width"));
        $this->assertStringContainsString("width: 50%;", $img->getAttribute("style"));
        $this->assertStringContainsString("border: 1pt solid red", $img->getAttribute("style"));
    }

    public function testNestedSvgProducesSingleImage(): void
    {
        $dom = $this->convertHtml(
            '<html><body><svg width="100" height="100">'
            . '<svg x="10" y="10" width="50" height="50"><rect width="10" height="10"/></svg>'
            . '</svg></body></html>'
        );

        $this->assertSame(1, $dom->getElementsByTagName("img")->length);
        $xml = $this->decodeSrc($dom->getElementsByTagName("img")->item(0));
        $this->assertSame(2, substr_count($xml, "<svg"));
    }

    public function testXlinkNamespaceDeclared(): void
    {
        $dom = $this->convertHtml(
            '<html><body><svg width="10" height="10">'
            . '<use xlink:href="#x"/><rect id="x" width="10" height="10"/></svg></body></html>'
        );

        $xml = $this->decodeSrc($dom->getElementsByTagName("img")->item(0));
        $this->assertStringContainsString('xmlns:xlink="http://www.w3.org/1999/xlink"', $xml);
    }

    public function testDocumentWithoutSvgUnchanged(): void
    {
        $dom = $this->convertHtml('<html><body><p>hello</p></body></html>');

        $this->assertSame(0, $dom->getElementsByTagName("img")->length);
        $this->assertSame(1, $dom->getElementsByTagName("p")->length);
    }

    public function testEndToEndRenderNoTextLeak(): void
    {
        $textFrames = [];
        $imageFrames = 0;

        $dompdf = new Dompdf(new Options());
        $dompdf->setCallbacks([
            [
                "event" => "begin_frame",
                "f" => function (AbstractFrameDecorator $frame, Canvas $canvas) use (&$textFrames, &$imageFrames) {
                    if ($frame->is_text_node()) {
                        $text = trim($frame->get_node()->nodeValue);
                        if ($text !== "") {
                            $textFrames[] = $text;
                        }
                    }
                    if ($frame->get_node()->nodeName === "img") {
                        $imageFrames++;
                    }
                }
            ]
        ]);

        $dompdf->loadHtml(
            '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>'
            . '<p>before</p>'
            . '<svg width="100" height="50" viewBox="0 0 100 50">'
            . '<style>rect { stroke: black; }</style>'
            . '<rect width="100" height="50" fill="#ff0000"/>'
            . '<text x="10" y="30">LEAKED</text>'
            . '</svg>'
            . '<p>after</p>'
            . '</body></html>'
        );
        $dompdf->render();

        $this->assertSame(1, $imageFrames);
        $this->assertContains("before", $textFrames);
        $this->assertContains("after", $textFrames);
        $this->assertNotContains("LEAKED", $textFrames);
        $this->assertNotContains("rect { stroke: black; }", $textFrames);
        $this->assertNotSame("", $dompdf->output());
    }
}
