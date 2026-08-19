<?php
namespace Dompdf\Tests\LayoutTest;

use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\FrameDecorator\AbstractFrameDecorator;
use Dompdf\Options;
use Dompdf\Tests\TestCase;

class BidiTest extends TestCase
{
    /**
     * Render a document and collect the non-empty text frames as
     * [logical text, x, bidi level, visual text], sorted by (y, x).
     */
    private function layoutTextFrames(string $body, ?Options $options = null): array
    {
        $frames = [];

        $dompdf = new Dompdf($options ?? new Options());
        $dompdf->setCallbacks([
            [
                "event" => "begin_frame",
                "f" => function (AbstractFrameDecorator $frame, Canvas $canvas) use (&$frames) {
                    if (!$frame->is_text_node()) {
                        return;
                    }

                    $text = $frame->get_node()->nodeValue;
                    if (trim($text) === "") {
                        return;
                    }

                    [$x, $y] = $frame->get_position();
                    $frames[] = [
                        "text" => $text,
                        "x" => (float)$x,
                        "y" => (float)$y,
                        "level" => $frame->bidi_level,
                        "visual" => $frame->get_visual_text(),
                        "page" => $canvas->get_page_number(),
                    ];
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
        size: 400pt 300pt;
        margin: 10pt;
    }

    body {
        margin: 0;
        font-family: "DejaVu Sans";
        font-size: 12pt;
    }
</style>
</head>
<body>$body</body>
</html>
HTML
        );
        $dompdf->render();

        usort($frames, function ($a, $b) {
            return $a["page"] <=> $b["page"] ?: $a["y"] <=> $b["y"] ?: $a["x"] <=> $b["x"];
        });

        return $frames;
    }

    private function frameByText(array $frames, string $needle): array
    {
        foreach ($frames as $frame) {
            if (strpos($frame["text"], $needle) !== false) {
                return $frame;
            }
        }

        $this->fail("No text frame containing '$needle'");
    }

    public function testMixedDirectionLine(): void
    {
        $frames = $this->layoutTextFrames('<p>hello עברית world</p>');

        $hello = $this->frameByText($frames, "hello");
        $hebrew = $this->frameByText($frames, "עברית");
        $world = $this->frameByText($frames, "world");

        // Logical and visual order coincide for a single RTL run in an LTR
        // paragraph
        $this->assertLessThan($hebrew["x"], $hello["x"]);
        $this->assertLessThan($world["x"], $hebrew["x"]);

        $this->assertSame(0, $hello["level"]);
        $this->assertSame(1, $hebrew["level"]);
        $this->assertSame(0, $world["level"]);

        // The RTL run renders in reverse grapheme order
        $this->assertSame("תירבע", $hebrew["visual"]);
    }

    public function testRtlParagraphRunOrder(): void
    {
        $frames = $this->layoutTextFrames('<p dir="rtl">שלום world</p>');

        $hebrew = $this->frameByText($frames, "שלום");
        $world = $this->frameByText($frames, "world");

        // In an RTL paragraph the Hebrew (first logical) run is rightmost
        $this->assertLessThan($hebrew["x"], $world["x"]);
        $this->assertSame(1, $hebrew["level"]);
        $this->assertSame(2, $world["level"]);
    }

    public function testNumbersInRtlText(): void
    {
        $frames = $this->layoutTextFrames('<p dir="rtl">מספר 123 בדיקה</p>');

        $first = $this->frameByText($frames, "מספר");
        $digits = $this->frameByText($frames, "123");
        $last = $this->frameByText($frames, "בדיקה");

        // Visual right-to-left: מספר, then 123, then בדיקה
        $this->assertLessThan($first["x"], $digits["x"]);
        $this->assertLessThan($digits["x"], $last["x"]);

        // Digits form a level-2 run and are not reversed
        $this->assertSame(2, $digits["level"]);
        $this->assertSame("123", $digits["visual"]);
    }

    public function testBdoOverride(): void
    {
        $frames = $this->layoutTextFrames('<p>abc <bdo dir="rtl">xyz</bdo> def</p>');

        $xyz = $this->frameByText($frames, "xyz");

        $this->assertSame(1, $xyz["level"]);
        $this->assertSame("zyx", $xyz["visual"]);
    }

    public function testArabicShaping(): void
    {
        $frames = $this->layoutTextFrames('<p dir="rtl">السلام عليكم</p>');

        $joined = "";
        foreach ($frames as $frame) {
            $joined .= $frame["text"];
        }

        // The text has been replaced with presentation forms, including the
        // mandatory lam-alef ligature
        $this->assertMatchesRegularExpression('/[\x{FE70}-\x{FEFF}]/u', $joined);
        $this->assertStringContainsString("\u{FEFC}", $joined); // lam-alef final
        // No unshaped Arabic base letters remain
        $this->assertDoesNotMatchRegularExpression('/[\x{0621}-\x{064A}]/u', $joined);
    }

    public function testMultiPageRtl(): void
    {
        $para = str_repeat("שלום עולם זהו מבחן טקסט ארוך בעברית. ", 40);
        $frames = $this->layoutTextFrames("<p dir=\"rtl\" style=\"text-align: justify;\">$para</p>");

        $pages = array_unique(array_column($frames, "page"));
        $this->assertGreaterThan(1, count($pages));

        // All frames analyzed with RTL levels on every page
        foreach ($frames as $frame) {
            $this->assertSame(1, $frame["level"], "Frame '{$frame['text']}' has unexpected level");
        }
    }

    public function testKillSwitch(): void
    {
        $options = new Options();
        $options->setIsBidiEnabled(false);

        $frames = $this->layoutTextFrames('<p>hello עברית world</p>', $options);

        $hebrew = $this->frameByText($frames, "עברית");
        $this->assertNull($hebrew["level"]);
        $this->assertSame($hebrew["text"], $hebrew["visual"]);
    }

    public function testLtrContentUntouched(): void
    {
        $frames = $this->layoutTextFrames('<p>plain left to right text</p>');

        $frame = $this->frameByText($frames, "plain");
        $this->assertNull($frame["level"]);
        $this->assertSame($frame["text"], $frame["visual"]);
    }
}
