<?php
namespace Dompdf\Tests\LayoutTest;

use DOMElement;
use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\FrameDecorator\AbstractFrameDecorator;
use Dompdf\Helpers;
use Dompdf\Options;
use Dompdf\Tests\TestCase;

class FlexTest extends TestCase
{
    /**
     * Each case: [body, expectations], where expectations maps a class name
     * to [x, y, margin-box width, margin-box height]; null entries are not
     * asserted. Geometry derived from css-flexbox-1.
     */
    public static function flexLayoutProvider(): array
    {
        return [
            "grow 1:2 with basis 0" => [
                '<div style="display:flex; width:300pt;">
                    <div class="a" style="flex:1; height:10pt;">x</div>
                    <div class="b" style="flex:2; height:10pt;">y</div></div>',
                ["a" => [0, 0, 100, 10], "b" => [100, 0, 200, 10]]
            ],
            "equal shrink" => [
                '<div style="display:flex; width:300pt;">
                    <div class="a" style="flex: 0 1 200pt; height:10pt;"></div>
                    <div class="b" style="flex: 0 1 200pt; height:10pt;"></div></div>',
                ["a" => [0, 0, 150, 10], "b" => [150, 0, 150, 10]]
            ],
            "shrink with min-width freeze" => [
                '<div style="display:flex; width:300pt;">
                    <div class="a" style="flex: 0 1 200pt; min-width:180pt; height:10pt;"></div>
                    <div class="b" style="flex: 0 1 200pt; height:10pt;"></div></div>',
                ["a" => [0, 0, 180, 10], "b" => [180, 0, 120, 10]]
            ],
            "grow with max-width freeze" => [
                '<div style="display:flex; width:300pt;">
                    <div class="a" style="flex:1; max-width:80pt; height:10pt;"></div>
                    <div class="b" style="flex:1; height:10pt;"></div></div>',
                ["a" => [0, 0, 80, 10], "b" => [80, 0, 220, 10]]
            ],
            "justify-content center" => [
                '<div style="display:flex; width:300pt; justify-content:center;">
                    <div class="a" style="width:50pt; height:10pt;"></div>
                    <div class="b" style="width:50pt; height:10pt;"></div></div>',
                ["a" => [100, 0, 50, 10], "b" => [150, 0, 50, 10]]
            ],
            "space-between with gap" => [
                '<div style="display:flex; width:300pt; justify-content:space-between; gap:10pt;">
                    <div class="a" style="width:50pt; height:10pt;"></div>
                    <div class="b" style="width:50pt; height:10pt;"></div>
                    <div class="c" style="width:50pt; height:10pt;"></div></div>',
                ["a" => [0, 0, 50, 10], "b" => [125, 0, 50, 10], "c" => [250, 0, 50, 10]]
            ],
            "auto main margin absorbs free space" => [
                '<div style="display:flex; width:300pt; justify-content:center;">
                    <div class="a" style="width:50pt; height:10pt; margin-left:auto;"></div>
                    <div class="b" style="width:50pt; height:10pt;"></div></div>',
                ["a" => [0, 0, 250, 10], "b" => [250, 0, 50, 10]]
            ],
            "order" => [
                '<div style="display:flex; width:300pt;">
                    <div class="a" style="width:50pt; height:10pt; order:2;"></div>
                    <div class="b" style="width:50pt; height:10pt; order:1;"></div></div>',
                ["b" => [0, 0, 50, 10], "a" => [50, 0, 50, 10]]
            ],
            "row-reverse packs at main start" => [
                '<div style="display:flex; flex-direction:row-reverse; width:300pt;">
                    <div class="a" style="width:50pt; height:10pt;"></div>
                    <div class="b" style="width:50pt; height:10pt;"></div></div>',
                ["a" => [250, 0, 50, 10], "b" => [200, 0, 50, 10]]
            ],
            "align-items center" => [
                '<div style="display:flex; width:300pt; height:100pt; align-items:center;">
                    <div class="a" style="width:50pt; height:40pt;"></div></div>',
                ["a" => [0, 30, 50, 40]]
            ],
            "stretch fills definite height" => [
                '<div style="display:flex; width:300pt; height:100pt;">
                    <div class="a" style="width:50pt;">x</div></div>',
                ["a" => [0, 0, 50, 100]]
            ],
            "inline-flex shrink-to-fit" => [
                '<div style="width:300pt;"><div class="f" style="display:inline-flex; gap:10pt;">
                    <div class="a" style="width:40pt; height:10pt;"></div>
                    <div class="b" style="width:40pt; height:10pt;"></div></div></div>',
                ["f" => [0, null, 90, 10]]
            ],
            "nested flex" => [
                '<div style="display:flex; width:300pt;">
                    <div class="outer" style="flex:1; display:flex; height:20pt;">
                        <div class="x" style="flex:1;"></div>
                        <div class="y" style="flex:2;"></div>
                    </div></div>',
                ["outer" => [0, 0, 300, 20], "x" => [0, 0, 100, null], "y" => [100, 0, 200, null]]
            ],
            "image item with definite basis keeps ratio" => [
                '<div style="display:flex; width:300pt;">
                    <img class="img" src="../_files/jamaica.jpg" style="flex: 0 0 100pt;"></div>',
                ["img" => [0, 0, 100, 75]]
            ],
            "percentage basis" => [
                '<div style="display:flex; width:400pt;">
                    <div class="a" style="flex: 0 0 25%; height:10pt;"></div>
                    <div class="b" style="flex:1; height:10pt;"></div></div>',
                ["a" => [0, 0, 100, 10], "b" => [100, 0, 300, 10]]
            ],
            "border-box basis" => [
                '<div style="display:flex; width:300pt;">
                    <div class="a" style="flex: 0 0 100pt; box-sizing:border-box; padding:10pt; height:30pt;"></div>
                    <div class="b" style="flex:1; height:10pt;"></div></div>',
                ["a" => [0, 0, 100, 30], "b" => [100, 0, 200, 10]]
            ],
            "span and text become separate items" => [
                '<div style="display:flex; width:300pt;">
                    <span class="a" style="width:60pt; height:10pt;"></span>
                    <span class="b" style="width:60pt; height:10pt;"></span></div>',
                ["a" => [0, 0, 60, 10], "b" => [60, 0, 60, 10]]
            ],
            "wrap basic" => [
                '<div style="display:flex; flex-wrap:wrap; width:400pt;">
                    <div class="a" style="flex: 0 0 150pt; height:20pt;"></div>
                    <div class="b" style="flex: 0 0 150pt; height:20pt;"></div>
                    <div class="c" style="flex: 0 0 150pt; height:20pt;"></div></div>',
                ["a" => [0, 0, 150, 20], "b" => [150, 0, 150, 20], "c" => [0, 20, 150, 20]]
            ],
            "wrap with row and column gaps" => [
                '<div style="display:flex; flex-wrap:wrap; width:400pt; gap: 10pt 20pt;">
                    <div class="a" style="flex: 0 0 200pt; height:20pt;"></div>
                    <div class="b" style="flex: 0 0 200pt; height:20pt;"></div></div>',
                ["a" => [0, 0, 200, 20], "b" => [0, 30, 200, 20]]
            ],
            "wrap with per-line grow" => [
                '<div style="display:flex; flex-wrap:wrap; width:400pt;">
                    <div class="a" style="flex: 1 0 150pt; height:20pt;"></div>
                    <div class="b" style="flex: 1 0 150pt; height:20pt;"></div>
                    <div class="c" style="flex: 1 0 150pt; height:20pt;"></div></div>',
                ["a" => [0, 0, 200, 20], "b" => [200, 0, 200, 20], "c" => [0, 20, 400, 20]]
            ],
            "align-content center" => [
                '<div style="display:flex; flex-wrap:wrap; width:400pt; height:100pt; align-content:center;">
                    <div class="a" style="flex: 0 0 250pt; height:20pt;"></div>
                    <div class="b" style="flex: 0 0 250pt; height:20pt;"></div></div>',
                ["a" => [0, 30, 250, 20], "b" => [0, 50, 250, 20]]
            ],
            "align-content space-between" => [
                '<div style="display:flex; flex-wrap:wrap; width:400pt; height:100pt; align-content:space-between;">
                    <div class="a" style="flex: 0 0 250pt; height:20pt;"></div>
                    <div class="b" style="flex: 0 0 250pt; height:20pt;"></div></div>',
                ["a" => [0, 0, 250, 20], "b" => [0, 80, 250, 20]]
            ],
            "wrap-reverse mirrors line stacking" => [
                '<div style="display:flex; flex-wrap:wrap-reverse; width:400pt;">
                    <div class="a" style="flex: 0 0 250pt; height:20pt;"></div>
                    <div class="b" style="flex: 0 0 250pt; height:30pt;"></div></div>',
                ["a" => [0, 30, 250, 20], "b" => [0, 0, 250, 30]]
            ],
            "column stacks and stretches" => [
                '<div style="display:flex; flex-direction:column; width:300pt;">
                    <div class="a" style="height:30pt;">x</div>
                    <div class="b" style="height:40pt;">y</div></div>',
                ["a" => [0, 0, 300, 30], "b" => [0, 30, 300, 40]]
            ],
            "column grow 1:3" => [
                '<div style="display:flex; flex-direction:column; width:300pt; height:200pt;">
                    <div class="a" style="flex:1;">x</div>
                    <div class="b" style="flex:3;">y</div></div>',
                ["a" => [0, 0, 300, 50], "b" => [0, 50, 300, 150]]
            ],
            "column justify-content center" => [
                '<div style="display:flex; flex-direction:column; width:300pt; height:200pt; justify-content:center;">
                    <div class="a" style="height:40pt;">x</div></div>',
                ["a" => [0, 80, 300, 40]]
            ],
            "column-reverse" => [
                '<div style="display:flex; flex-direction:column-reverse; width:300pt; height:100pt;">
                    <div class="a" style="height:20pt;">x</div>
                    <div class="b" style="height:30pt;">y</div></div>',
                ["a" => [0, 80, 300, 20], "b" => [0, 50, 300, 30]]
            ],
            "column align-items center" => [
                '<div style="display:flex; flex-direction:column; width:300pt; align-items:center;">
                    <div class="a" style="width:100pt; height:20pt;"></div></div>',
                ["a" => [100, 0, 100, 20]]
            ],
            "column row-gap" => [
                '<div style="display:flex; flex-direction:column; width:300pt; row-gap:15pt;">
                    <div class="a" style="height:20pt;">x</div>
                    <div class="b" style="height:20pt;">y</div></div>',
                ["a" => [0, 0, 300, 20], "b" => [0, 35, 300, 20]]
            ],
            "column shrink" => [
                '<div style="display:flex; flex-direction:column; width:300pt; height:100pt;">
                    <div class="a" style="flex: 1 1 80pt;"></div>
                    <div class="b" style="flex: 1 1 80pt;"></div></div>',
                ["a" => [0, 0, 300, 50], "b" => [0, 50, 300, 50]]
            ],
            "absolute child is not an item" => [
                '<div style="display:flex; width:300pt; height: 50pt; position:relative;">
                    <div class="abs" style="position:absolute; left:20pt; top:20pt; width:30pt; height:10pt;"></div>
                    <div class="a" style="flex:1; height:10pt;"></div></div>',
                ["a" => [0, 0, 300, 10], "abs" => [20, 20, 30, 10]]
            ],
        ];
    }

    /**
     * @dataProvider flexLayoutProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('flexLayoutProvider')]
    public function testFlexLayout(string $body, array $expectations): void
    {
        $geo = [];

        $options = new Options();
        $dompdf = new Dompdf($options);
        $dompdf->setBasePath(__DIR__);
        $dompdf->setCallbacks([
            [
                "event" => "begin_frame",
                "f" => function (AbstractFrameDecorator $frame, Canvas $canvas) use (&$geo) {
                    $node = $frame->get_node();

                    if ($node instanceof DOMElement && $node->getAttribute("class") !== "") {
                        [$x, $y] = $frame->get_position();
                        $geo[$node->getAttribute("class")] = [
                            (float)$x,
                            (float)$y,
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
    @page {
        size: 400pt 400pt;
        margin: 0;
    }

    body {
        margin: 0;
    }
</style>
</head>
<body>$body</body>
</html>
HTML
        );
        $dompdf->render();

        $labels = ["x", "y", "width", "height"];

        foreach ($expectations as $class => $expected) {
            $this->assertArrayHasKey($class, $geo, "Frame .$class was not laid out.");

            foreach ($expected as $i => $value) {
                if ($value === null) {
                    continue;
                }

                $this->assertTrue(
                    Helpers::lengthEqual((float)$value, $geo[$class][$i]),
                    "Failed asserting that .$class {$labels[$i]} {$geo[$class][$i]} is equal to $value."
                );
            }
        }
    }

    public function testFlexContainerMovesToNextPageAsWhole(): void
    {
        $pages = [];

        $dompdf = new Dompdf(new Options());
        $dompdf->setCallbacks([
            [
                "event" => "begin_frame",
                "f" => function (AbstractFrameDecorator $frame, Canvas $canvas) use (&$pages) {
                    $node = $frame->get_node();

                    if ($node instanceof DOMElement && $node->getAttribute("class") !== "") {
                        $pages[$node->getAttribute("class")] = $canvas->get_page_number();
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
    @page {
        size: 400pt 400pt;
        margin: 0;
    }

    body {
        margin: 0;
    }
</style>
</head>
<body>
<div class="tall" style="height: 350pt;"></div>
<div class="flex" style="display: flex;">
    <div class="a" style="flex: 1;"><p>one</p><p>two</p><p>three</p></div>
    <div class="b" style="flex: 1; height: 100pt;"></div>
</div>
</body>
</html>
HTML
        );
        $dompdf->render();

        $this->assertSame(2, $dompdf->getCanvas()->get_page_count());
        $this->assertSame(1, $pages["tall"]);
        $this->assertSame(2, $pages["flex"]);
        $this->assertSame(2, $pages["a"]);
        $this->assertSame(2, $pages["b"]);
    }

    public function testOversizedFlexContainerTerminates(): void
    {
        $dompdf = new Dompdf(new Options());
        $dompdf->loadHtml(<<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    @page {
        size: 400pt 400pt;
        margin: 0;
    }
</style>
</head>
<body>
<div style="display: flex;">
    <div style="flex: 1; height: 600pt;">tall item</div>
</div>
<p>after</p>
</body>
</html>
HTML
        );
        $dompdf->render();

        // The container overflows the page; rendering must terminate and
        // the following content must appear
        $this->assertGreaterThanOrEqual(2, $dompdf->getCanvas()->get_page_count());
        $this->assertNotSame("", $dompdf->output());
    }
}
