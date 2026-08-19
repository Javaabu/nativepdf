<?php
namespace Dompdf\Tests\LayoutTest;

use DOMElement;
use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\FrameDecorator\AbstractFrameDecorator;
use Dompdf\Helpers;
use Dompdf\Options;
use Dompdf\Tests\TestCase;

class ViewportUnitsTest extends TestCase
{
    public function testViewportUnitsAndCh(): void
    {
        $geo = [];

        $dompdf = new Dompdf(new Options());
        $dompdf->setPaper([0, 0, 500, 400]);
        $dompdf->setCallbacks([
            [
                "event" => "begin_frame",
                "f" => function (AbstractFrameDecorator $frame, Canvas $canvas) use (&$geo) {
                    $node = $frame->get_node();

                    if ($node instanceof DOMElement && $node->getAttribute("class") !== "") {
                        $geo[$node->getAttribute("class")] = [
                            $frame->get_margin_width(),
                            $frame->get_margin_height()
                        ];
                    }
                }
            ]
        ]);

        $dompdf->loadHtml(<<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 0; }
    body { margin: 0; }
    .a { width: 50vw; height: 25vh; }
    .b { width: 10vmin; height: 10vmax; }
    .c { width: 20ch; height: 10pt; font-size: 12pt; font-family: "DejaVu Sans"; }
</style>
</head>
<body>
<div class="a"></div>
<div class="b"></div>
<div class="c"></div>
</body>
</html>
HTML
        );
        $dompdf->render();

        // Page is 500x400pt
        $this->assertTrue(Helpers::lengthEqual(250.0, $geo["a"][0]), "50vw: {$geo['a'][0]}");
        $this->assertTrue(Helpers::lengthEqual(100.0, $geo["a"][1]), "25vh: {$geo['a'][1]}");
        $this->assertTrue(Helpers::lengthEqual(40.0, $geo["b"][0]), "10vmin: {$geo['b'][0]}");
        $this->assertTrue(Helpers::lengthEqual(50.0, $geo["b"][1]), "10vmax: {$geo['b'][1]}");

        // 20ch = 20 x the advance width of "0" in DejaVu Sans at 12pt
        $zero = $dompdf->getFontMetrics()->getTextWidth(
            "0",
            $dompdf->getFontMetrics()->getFont("DejaVu Sans"),
            12.0
        );
        $this->assertTrue(
            Helpers::lengthEqual(20 * $zero, $geo["c"][0]),
            "20ch: {$geo['c'][0]} vs " . (20 * $zero)
        );
    }
}
