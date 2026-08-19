<?php
namespace NativePdf\Tests\LayoutTest;

use DOMElement;
use NativePdf\Canvas;
use NativePdf\NativePdf;
use NativePdf\FrameDecorator\AbstractFrameDecorator;
use NativePdf\Options;
use NativePdf\Tests\TestCase;

class PageTest extends TestCase
{
    public static function pageBreakProvider(): array
    {
        return [
            // TODO: Heredocs can be nicely indented starting with PHP 7.3
            "one page" => [
                <<<HTML
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
        background-color: rgb(0, 0, 0, 0.05);
    }

    .box {
        height: 400pt;
        background-color: lightblue;
    }
</style>
</head>
<body><div class="box"></div></body>
</html>
HTML
,
                1,
                ["box" => 1]
            ],
            "two pages" => [
                <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    @page {
        size: 400pt 400pt;
        margin: 0;
    }

    @page :first {
        margin-bottom: 100pt;
    }

    body {
        background-color: rgb(0, 0, 0, 0.05);
    }

    .box {
        height: 400pt;
        background-color: lightblue;
    }
</style>
</head>
<body><div class="box"></div></body>
</html>
HTML
,
                2,
                ["box" => 2]
            ],
        ];
    }

    /**
     * @dataProvider pageBreakProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('pageBreakProvider')]
    public function testPageBreak(
        string $html,
        int $pageCount,
        array $expectedPages
    ): void {
        $elementPages = [];

        $options = new Options();

        // Use callback to inspect frame tree
        $nativepdf = new NativePdf($options);
        $nativepdf->setCallbacks([
            [
                "event" => "begin_frame",
                "f" => function (AbstractFrameDecorator $frame, Canvas $canvas) use ($expectedPages, &$elementPages) {
                    $node = $frame->get_node();

                    if (!($node instanceof DOMElement)) {
                        return;
                    }

                    $class = $node->getAttribute("class");

                    if (isset($expectedPages[$class])) {
                        $elementPages[$class] = $canvas->get_page_number();
                    }
                }
            ]
        ]);

        $nativepdf->loadHtml($html);
        $nativepdf->render();

        $this->assertSame($pageCount, $nativepdf->getCanvas()->get_page_count());

        foreach ($expectedPages as $class => $pageNumber) {
            $this->assertSame($pageNumber, $elementPages[$class] ?? 0);
        }
    }
}
