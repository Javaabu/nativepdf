<?php
namespace NativePdf\Tests\LayoutTest;

use NativePdf\Canvas;
use NativePdf\NativePdf;
use NativePdf\FrameDecorator\AbstractFrameDecorator;
use NativePdf\Options;
use NativePdf\Tests\TestCase;

class BidiTest extends TestCase
{
    /**
     * Render a document and collect the non-empty text frames as
     * [logical text, x, bidi level, visual text], sorted by (y, x).
     */
    private function layoutTextFrames(string $body, ?Options $options = null): array
    {
        $frames = [];

        $nativepdf = new NativePdf($options ?? new Options());
        $nativepdf->setCallbacks([
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

        $nativepdf->loadHtml(<<<HTML
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
        $nativepdf->render();

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

    public function testCombiningMarksPrecedeTheirBase(): void
    {
        // Thaana: each consonant carries a non-spacing vowel mark (fili).
        // U+0789 U+07A8 U+0787 U+07A6 U+078B U+07AA, "miadhu".
        $frames = $this->layoutTextFrames('<p dir="rtl">މިއަދު</p>');

        $frame = $frames[0];

        $this->assertSame(1, $frame["level"]);

        // The canvas paints glyphs left to right and a non-spacing mark has no
        // advance, so every mark must be emitted ahead of the consonant it sits
        // on; emitted after, it would land over the next consonant instead.
        $this->assertSame("ުދައިމ", $frame["visual"]);
    }

    public function testMarksOfLtrScriptsKeepTheirPlace(): void
    {
        // U+0301 and the variation selector U+FE0F are non-spacing marks too,
        // but their glyphs are drawn to the left of the origin (U+0301 starts
        // at -655 font units in DejaVu Sans), so moving them ahead of the base
        // would detach them from it.
        $frames = $this->layoutTextFrames(
            "<p dir=\"rtl\">\u{05D0}\u{0301}</p><p dir=\"rtl\">\u{2764}\u{FE0F}</p>"
        );

        $this->assertSame("\u{05D0}\u{0301}", $frames[0]["visual"]);
        $this->assertSame("\u{2764}\u{FE0F}", $frames[1]["visual"]);
    }

    public function testHebrewAndArabicMarksPrecedeTheirBase(): void
    {
        // Marks of right-to-left scripts do sit to the right of their origin
        // (hebrew patah, arabic fatha), so these move ahead of the base.
        $frames = $this->layoutTextFrames(
            "<p dir=\"rtl\">\u{05D0}\u{05B7}</p><p dir=\"rtl\">\u{0628}\u{064E}</p>"
        );

        $this->assertSame("\u{05B7}\u{05D0}", $frames[0]["visual"]);
        // The base has been replaced with its arabic presentation form
        $this->assertSame("\u{064E}\u{FE8F}", $frames[1]["visual"]);
    }

    public function testMarksStayOnTheirBaseUnderAnLtrOverride(): void
    {
        // <bdo dir="ltr"> puts the run on an even level and displays the
        // characters in logical order. Mark placement does not depend on the
        // level though: the fili still has to be emitted ahead of the
        // consonant it sits on, which is also what a shaper produces when it
        // is handed thaana with a left-to-right direction.
        $frames = $this->layoutTextFrames(
            "<p dir=\"rtl\"><bdo dir=\"ltr\">\u{0789}\u{07AA}\u{0788}\u{07A6}</bdo></p>"
        );

        $this->assertSame(2, $frames[0]["level"]);
        $this->assertSame("\u{07AA}\u{0789}\u{07A6}\u{0788}", $frames[0]["visual"]);
    }

    public function testJustifiedRtlLastLineIsAlignedToTheRight(): void
    {
        // CSS 2.1 16.2: the last line of a justified block is aligned to the
        // start edge, which is the right edge under `direction: rtl`.
        $frames = $this->layoutTextFrames(
            '<p style="direction:rtl; text-align:justify; margin:0;">'
            . 'שלום עולם זהו מבחן של טקסט ארוך בעברית כדי לבדוק יישור מלא של שורות טקסט. סוף</p>'
        );

        $this->assertGreaterThan(1, count($frames), "the paragraph should wrap");

        $first = $frames[0];
        $last = $frames[count($frames) - 1];

        // A justified line starts at the left edge; a short last line pushed to
        // the right edge has to start further right.
        $this->assertGreaterThan($first["x"], $last["x"]);
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
