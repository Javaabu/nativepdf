<?php
namespace NativePdf\Tests;

use DOMDocument;
use NativePdf\Adapter\CPDF;
use NativePdf\Canvas;
use NativePdf\Css\Stylesheet;
use NativePdf\NativePdf;
use NativePdf\FontMetrics;
use NativePdf\Frame;
use NativePdf\Frame\FrameTree;
use NativePdf\Options;
use NativePdf\Tests\TestCase;

class NativePdfTest extends TestCase
{
    public function testConstructor()
    {
        $nativepdf = new NativePdf();
        $this->assertInstanceOf(CPDF::class, $nativepdf->getCanvas());
        $this->assertSame("", $nativepdf->getProtocol());
        $this->assertSame("", $nativepdf->getBaseHost());
        $this->assertSame("", $nativepdf->getBasePath());
        $this->assertIsArray($nativepdf->getCallbacks());
        $this->assertInstanceOf(Stylesheet::class, $nativepdf->getCss());
        $this->assertNull($nativepdf->getDom());
        $this->assertInstanceOf(Options::class, $nativepdf->getOptions());
        $this->assertFalse($nativepdf->getQuirksmode());
        $this->assertNull($nativepdf->getTree());
    }

    public function testSetters()
    {
        $nativepdf = new NativePdf();
        $nativepdf->setBaseHost('test1');
        $nativepdf->setBasePath('test2');
        $nativepdf->setCallbacks(['test' => ['event' => 'test', 'f' => function () {}]]);
        $nativepdf->setCss(new Stylesheet($nativepdf));
        $nativepdf->setDom(new DOMDocument());
        $nativepdf->setHttpContext(fopen(__DIR__ . "/_files/jamaica.jpg", 'r'));
        $nativepdf->setOptions(new Options());
        $nativepdf->setProtocol('test3');
        $nativepdf->setTree(new FrameTree($nativepdf->getDom()));

        $this->assertEquals('test1', $nativepdf->getBaseHost());
        $this->assertEquals('test2', $nativepdf->getBasePath());
        $this->assertCount(1, $nativepdf->getCallbacks());
        $this->assertInstanceOf(Stylesheet::class, $nativepdf->getCss());
        $this->assertInstanceOf(DOMDocument::class, $nativepdf->getDom());
        $this->assertIsResource($nativepdf->getHttpContext());
        $this->assertInstanceOf(Options::class, $nativepdf->getOptions());
        $this->assertEquals('test3', $nativepdf->getProtocol());
        $this->assertInstanceOf(FrameTree::class, $nativepdf->getTree());

        $nativepdf = new NativePdf();
        $nativepdf->setHttpContext(['ssl' => ['verify_peer' => false]]);
        $this->assertIsResource($nativepdf->getHttpContext());
    }

    public static function loadHtmlProvider(): array
    {
        $textContent = "Some – Unicode";
        $document = function (string $encoding, string $head = "") use ($textContent) {
            $html = "<html><head>$head</head><body><strong>$textContent</strong></body></html>";
            return $encoding !== "UTF-8"
                ? mb_convert_encoding($html, $encoding, "UTF-8")
                : $html;
        };
        $metaCharset = function (string $charset) {
            return "<meta charset='$charset'>";
        };
        $metaContent1 = function (string $charset) {
            return "<meta http-equiv='Content-Type' content='text/html; charset=$charset'>";
        };
        $metaContent2 = function (string $charset) {
            return "<meta content='text/html; charset=$charset' http-equiv='Content-Type'>";
        };

        return [
            // Without encoding parameter
            "utf-8 no encoding" => [
                $document("UTF-8"),
                null,
                $textContent
            ],
            "utf-8 meta no encoding" => [
                $document("UTF-8", $metaCharset("UTF-8")),
                null,
                $textContent
            ],
            "windows-1252 meta no encoding 1" => [
                $document("Windows-1252", $metaCharset("Windows-1252")),
                null,
                $textContent
            ],
            "windows-1252 meta no encoding 2" => [
                $document("Windows-1252", $metaContent1("Windows-1252")),
                null,
                $textContent
            ],
            "windows-1252 meta no encoding 3" => [
                $document("Windows-1252", $metaContent2("Windows-1252")),
                null,
                $textContent
            ],

            // With encoding parameter
            "utf-8 with encoding" => [
                $document("UTF-8"),
                "UTF-8",
                $textContent
            ],
            "windows-1252 with encoding" => [
                $document("Windows-1252"),
                "Windows-1252",
                $textContent
            ],
            // Verify that passed encoding takes precedence
            "windows-1252 meta mismatch with encoding" => [
                $document("Windows-1252", $metaCharset("UTF-8")),
                "Windows-1252",
                $textContent
            ],
            "utf-16 meta with encoding" => [
                $document("UTF-16", $metaCharset("UTF-16")),
                "UTF-16",
                $textContent
            ],

            // With BOM
            "utf-8 bom" => [
                "\xEF\xBB\xBF" . $document("UTF-8"),
                null,
                $textContent
            ],
            "utf-16be bom" => [
                "\xFE\xFF" . $document("UTF-16BE", $metaCharset("UTF-16")),
                null,
                $textContent
            ],
            "utf-16le bom" => [
                "\xFF\xFE" . $document("UTF-16LE", $metaCharset("UTF-16")),
                null,
                $textContent
            ],
            // Verify that BOM takes precedence
            "utf-8 bom with encoding mismatch" => [
                "\xEF\xBB\xBF" . $document("UTF-8"),
                "Windows-1252",
                $textContent
            ],
            "utf-16le bom with encoding mismatch" => [
                "\xFF\xFE" . $document("UTF-16LE", $metaCharset("UTF-16")),
                "UTF-8",
                $textContent
            ],
        ];
    }

    /**
     * @dataProvider loadHtmlProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('loadHtmlProvider')]
    public function testLoadHtml(
        string $html,
        ?string $encoding,
        string $expectedText
    ): void {
        $nativepdf = new NativePdf();
        $nativepdf->loadHtml($html, $encoding);

        $this->assertSame($expectedText, $nativepdf->getDom()->textContent);
    }

    public function testRender()
    {
        $nativepdf = new NativePdf();
        $nativepdf->loadHtml('<html><body><strong>Hello</strong></body></html>');
        $nativepdf->render();

        $this->assertEquals('', $nativepdf->getDom()->textContent);
    }

    public static function callbacksProvider(): array
    {
        return [
            ["begin_page_reflow", 1],
            ["begin_frame", 3],
            ["end_frame", 3],
            ["begin_page_render", 1],
            ["end_page_render", 1]
        ];
    }

    /**
     * @dataProvider callbacksProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('callbacksProvider')]
    public function testCallbacks(string $event, int $numCalls): void
    {
        $called = 0;

        $nativepdf = new NativePdf();
        $nativepdf->setCallbacks([
            [
                "event" => $event,
                "f" => function ($frame, $canvas, $fontMetrics) use (&$called) {
                    $this->assertInstanceOf(Frame::class, $frame);
                    $this->assertInstanceOf(Canvas::class, $canvas);
                    $this->assertInstanceOf(FontMetrics::class, $fontMetrics);
                    $called++;
                }
            ]
        ]);

        $nativepdf->loadHtml("<html><body><p>Some text</p></body></html>");
        $nativepdf->render();

        $this->assertSame($numCalls, $called);
    }

    public function testEndDocumentCallback(): void
    {
        $called = 0;

        $nativepdf = new NativePdf();
        $nativepdf->setCallbacks([
            [
                "event" => "end_document",
                "f" => function ($pageNumber, $pageCount, $canvas, $fontMetrics) use (&$called) {
                    $called++;
                    $this->assertSame($called, $pageNumber);
                    $this->assertSame(2, $pageCount);
                    $this->assertInstanceOf(Canvas::class, $canvas);
                    $this->assertInstanceOf(FontMetrics::class, $fontMetrics);
                }
            ]
        ]);

        $nativepdf->loadHtml("<html><body><p>Page 1</p><p style='page-break-before: always;'>Page 2</p></body></html>");
        $nativepdf->render();

        $this->assertSame(2, $called);
    }

    public static function customCanvasProvider(): array
    {
        return [
            ["A4", "portrait", true, "auto"],
            ["A5", "landscape", true, "A5 landscape"],
            ["A5", "landscape", false, "A5 landscape"],
            [[0, 0, 300, 400], "portrait", true, "300pt 400pt"]
        ];
    }

    /**
     * Test that a custom canvas is not replaced on render if its size matches
     * the desired paper size.
     *
     * @dataProvider customCanvasProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('customCanvasProvider')]
    public function testCustomCanvas(
        $size,
        string $orientation,
        bool $setPaper,
        string $cssSize
    ): void {
        $options = new Options();
        $options->setDefaultPaperSize("Letter");

        $nativepdf = new NativePdf($options);

        if ($setPaper) {
            $nativepdf->setPaper($size, $orientation);
        }

        $c1 = new CPDF($size, $orientation, $nativepdf);
        $nativepdf->setCanvas($c1);
        $nativepdf->loadHtml("<html><head><style>@page { size: $cssSize; }</style></head><body></body></html>");
        $nativepdf->render();
        $c2 = $nativepdf->getCanvas();

        $this->assertSame($c1, $c2);
    }

    public function testSpaceAtStartOfSecondInlineTag()
    {
        $text_frame_contents = [];

        $nativepdf = new NativePdf();

        // Use a callback to inspect the frame tree; otherwise FrameReflower\Page::reflow()
        // will dispose of it before nativepdf->render finishes
        $nativepdf->setCallbacks(['test' => [
            'event' => 'end_page_render',
            'f' => function (Frame $frame) use (&$text_frame_contents) {
                foreach ($frame->get_children() as $child) {
                    foreach ($child->get_children() as $grandchild) {
                        $text_frame_contents[] = $grandchild->get_text();
                    }
                }
            }
        ]]);

        $nativepdf->loadHtml('<html><body><span>one</span><span> - two</span></body></html>');
        $nativepdf->render();

        $this->assertEquals("one", $text_frame_contents[0]);
        $this->assertEquals(" - two", $text_frame_contents[1]);
    }
}
