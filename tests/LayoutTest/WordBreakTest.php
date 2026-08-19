<?php
namespace Dompdf\Tests\LayoutTest;

use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\FrameDecorator\AbstractFrameDecorator;
use Dompdf\Options;
use Dompdf\Tests\TestCase;

class WordBreakTest extends TestCase
{
    /**
     * Render a narrow container and collect the text of the laid-out text
     * frames in (y, x) order.
     */
    private function textFrames(string $style, string $content): array
    {
        $frames = [];

        $dompdf = new Dompdf(new Options());
        $dompdf->setCallbacks([
            [
                "event" => "begin_frame",
                "f" => function (AbstractFrameDecorator $frame, Canvas $canvas) use (&$frames) {
                    if ($frame->is_text_node() && trim($frame->get_node()->nodeValue) !== "") {
                        [$x, $y] = $frame->get_position();
                        $frames[] = [(float)$y, (float)$x, $frame->get_node()->nodeValue];
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
    @page { size: 200pt 200pt; margin: 0; }
    body { margin: 0; font-size: 12pt; }
    div { width: 60pt; $style }
</style>
</head>
<body><div>$content</div></body>
</html>
HTML
        );
        $dompdf->render();

        usort($frames, function ($a, $b) {
            return $a[0] <=> $b[0] ?: $a[1] <=> $b[1];
        });

        return array_map(function ($f) {
            return $f[2];
        }, $frames);
    }

    public function testBreakAllBreaksWithinWords(): void
    {
        $content = "xy abcdefghijklmnop";

        // Without break-all, the long word moves to its own line whole and
        // overflows
        $normal = $this->textFrames("", $content);
        $this->assertContains("abcdefghijklmnop", $normal);

        // With break-all, the long word is broken mid-word: no frame
        // contains the whole word
        $breakAll = $this->textFrames("word-break: break-all;", $content);
        $this->assertNotContains("abcdefghijklmnop", $breakAll);
    }

    public function testBreakAllFillsPartialLines(): void
    {
        // Unlike overflow-wrap: anywhere, break-all breaks a word that
        // PARTIALLY fits after other content on the line, instead of
        // wrapping it whole
        $content = "xy abcdefghijklmnop";

        $anywhere = $this->textFrames("overflow-wrap: anywhere;", $content);
        $breakAll = $this->textFrames("word-break: break-all;", $content);

        // anywhere: only "xy" on line 1 (emergency breaking applies to
        // words alone on a line), then the word fills subsequent lines
        $this->assertSame("xy", rtrim($anywhere[0]));

        // break-all: line 1 already contains a fragment of the word
        $this->assertStringStartsWith("xy a", $breakAll[0]);
        $this->assertGreaterThan(mb_strlen("xy "), mb_strlen($breakAll[0]));
    }
}
