<?php
namespace NativePdf\Tests\LayoutTest;

use NativePdf\NativePdf;
use NativePdf\FrameDecorator\AbstractFrameDecorator;
use NativePdf\Helpers;
use NativePdf\Options;
use NativePdf\Tests\TestCase;

class BoxSizingTest extends TestCase
{
    public static function boxSizingProvider(): array
    {
        return [
            "border-box width" => [
                '<div class="target" style="width: 100pt; padding: 10pt; border: 5pt solid black; box-sizing: border-box;">x</div>',
                100.0, 70.0, null, null
            ],
            "content-box width" => [
                '<div class="target" style="width: 100pt; padding: 10pt; border: 5pt solid black; box-sizing: content-box;">x</div>',
                130.0, 100.0, null, null
            ],
            "border-box percentage width" => [
                '<div class="target" style="width: 50%; padding: 10pt; border: 5pt solid black; box-sizing: border-box;">x</div>',
                200.0, 170.0, null, null
            ],
            "border-box min-width" => [
                '<div class="target" style="float: left; min-width: 200pt; padding: 10pt; border: 5pt solid black; box-sizing: border-box;">x</div>',
                200.0, 170.0, null, null
            ],
            "border-box max-width" => [
                '<div class="target" style="width: 300pt; max-width: 100pt; padding: 10pt; border: 5pt solid black; box-sizing: border-box;">x</div>',
                100.0, 70.0, null, null
            ],
            "border-box height" => [
                '<div class="target" style="height: 100pt; padding: 10pt; border: 5pt solid black; box-sizing: border-box;">x</div>',
                null, null, 100.0, 70.0
            ],
            "content-box height" => [
                '<div class="target" style="height: 100pt; padding: 10pt; border: 5pt solid black; box-sizing: content-box;">x</div>',
                null, null, 130.0, 100.0
            ],
            "border-box min-height" => [
                '<div class="target" style="height: 10pt; min-height: 100pt; padding: 10pt; border: 5pt solid black; box-sizing: border-box;">x</div>',
                null, null, 100.0, 70.0
            ],
            "border-box image" => [
                '<img class="target" src="../_files/jamaica.jpg" style="width: 100pt; padding: 10pt; border: 5pt solid black; box-sizing: border-box;">',
                100.0, 70.0, null, 52.5
            ],
            "border-box fixed width inside shrink-to-fit" => [
                '<div style="float: left;"><div class="target" style="width: 100pt; padding: 10pt; border: 5pt solid black; box-sizing: border-box;">x</div></div>',
                100.0, 70.0, null, null
            ],
        ];
    }

    /**
     * @dataProvider boxSizingProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('boxSizingProvider')]
    public function testBoxSizing(
        string $body,
        ?float $expectedMarginWidth,
        ?float $expectedContentWidth,
        ?float $expectedMarginHeight,
        ?float $expectedContentHeight
    ): void {
        $marginWidth = null;
        $marginHeight = null;
        $contentWidth = null;
        $contentHeight = null;

        $options = new Options();

        // Use callback to inspect frame tree
        $nativepdf = new NativePdf($options);
        $nativepdf->setBasePath(__DIR__);
        $nativepdf->setCallbacks([
            [
                "event" => "begin_frame",
                "f" => function (AbstractFrameDecorator $frame) use (
                    &$marginWidth,
                    &$marginHeight,
                    &$contentWidth,
                    &$contentHeight
                ) {
                    $node = $frame->get_node();

                    if ($node instanceof \DOMElement
                        && $node->getAttribute("class") === "target"
                    ) {
                        $marginWidth = $frame->get_margin_width();
                        $marginHeight = $frame->get_margin_height();
                        [, , $contentWidth, $contentHeight] = $frame->get_content_box();
                    }
                }
            ]
        ]);

        $nativepdf->loadHtml(<<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    @page {
        size: 400pt 300pt;
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
        $nativepdf->render();

        $checks = [
            "margin width" => [$expectedMarginWidth, $marginWidth],
            "content width" => [$expectedContentWidth, $contentWidth],
            "margin height" => [$expectedMarginHeight, $marginHeight],
            "content height" => [$expectedContentHeight, $contentHeight],
        ];

        foreach ($checks as $label => [$expected, $actual]) {
            if ($expected === null) {
                continue;
            }

            $this->assertTrue(
                Helpers::lengthEqual($expected, $actual),
                "Failed asserting that $label $actual is equal to $expected."
            );
        }
    }
}
