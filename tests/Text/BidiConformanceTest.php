<?php
namespace NativePdf\Tests\Text;

use NativePdf\Tests\TestCase;
use NativePdf\Text\BidiAnalyzer;

/**
 * Runs the complete official UAX #9 conformance suites (BidiTest.txt and
 * BidiCharacterTest.txt, fetched by bin/generate-unicode-data.php).
 *
 * @group conformance
 */
class BidiConformanceTest extends TestCase
{
    private static function readGz(string $file): array
    {
        $path = __DIR__ . "/../_files/unicode/" . $file;
        if (!is_file($path)) {
            self::fail("Missing $path; run bin/generate-unicode-data.php");
        }

        return explode("\n", gzdecode(file_get_contents($path)));
    }

    /**
     * @param array  $result   From computeLevels*
     * @param int[]  $cps      Code points (or class list for applyL1)
     * @param int    $paragraphLevel
     * @param array  $classes  Original classes
     * @return array [levelString[], orderString[]]
     */
    private static function finish(array $result, int $paragraphLevel, array $classes): array
    {
        $levels = BidiAnalyzer::applyL1($result["levels"], [], $paragraphLevel, $classes);
        $removed = $result["removed"];

        $levelStrings = [];
        foreach ($levels as $i => $level) {
            $levelStrings[] = $removed[$i] ? "x" : (string)$level;
        }

        $order = BidiAnalyzer::visualOrder($levels, $removed);

        return [$levelStrings, array_map("strval", $order)];
    }

    public function testBidiTest(): void
    {
        $lines = self::readGz("BidiTest.txt.gz");

        $expectedLevels = [];
        $expectedOrder = [];
        $cases = 0;
        $failures = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === "" || $line[0] === "#") {
                continue;
            }

            if (strncmp($line, "@Levels:", 8) === 0) {
                $spec = trim(substr($line, 8));
                $expectedLevels = $spec === "" ? [] : preg_split('/\s+/', $spec);
                continue;
            }

            if (strncmp($line, "@Reorder:", 9) === 0) {
                $spec = trim(substr($line, 9));
                $expectedOrder = $spec === "" ? [] : preg_split('/\s+/', $spec);
                continue;
            }

            [$classSpec, $bitset] = array_map("trim", explode(";", $line));
            $classes = preg_split('/\s+/', $classSpec);
            $bitset = (int)$bitset;

            $directions = [];
            if ($bitset & 1) {
                $directions[] = BidiAnalyzer::paragraphLevel([], $classes);
            }
            if ($bitset & 2) {
                $directions[] = 0;
            }
            if ($bitset & 4) {
                $directions[] = 1;
            }

            foreach (array_unique($directions) as $paragraphLevel) {
                $cases++;

                $result = BidiAnalyzer::computeLevelsFromClasses($classes, $paragraphLevel);
                [$levelStrings, $order] = self::finish($result, $paragraphLevel, $classes);

                if ($levelStrings !== $expectedLevels || $order !== $expectedOrder) {
                    if (count($failures) < 10) {
                        $failures[] = sprintf(
                            "[%s] pl=%d\n  levels got [%s] want [%s]\n  order got [%s] want [%s]",
                            $classSpec,
                            $paragraphLevel,
                            implode(" ", $levelStrings),
                            implode(" ", $expectedLevels),
                            implode(" ", $order),
                            implode(" ", $expectedOrder)
                        );
                    } else {
                        $failures[] = "";
                    }
                }
            }
        }

        $this->assertGreaterThan(100000, $cases, "BidiTest.txt did not parse");
        $this->assertSame(
            0,
            count($failures),
            count($failures) . " of $cases BidiTest cases failed. First failures:\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }

    public function testBidiCharacterTest(): void
    {
        $lines = self::readGz("BidiCharacterTest.txt.gz");

        $cases = 0;
        $failures = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === "" || $line[0] === "#") {
                continue;
            }

            $fields = explode(";", $line);
            if (count($fields) < 5) {
                continue;
            }

            $cps = array_map("hexdec", preg_split('/\s+/', trim($fields[0])));
            $direction = (int)$fields[1];
            $expectedParagraphLevel = (int)$fields[2];
            $levelSpec = trim($fields[3]);
            $expectedLevels = $levelSpec === "" ? [] : preg_split('/\s+/', $levelSpec);
            $orderSpec = trim($fields[4]);
            $expectedOrder = $orderSpec === "" ? [] : preg_split('/\s+/', $orderSpec);

            $paragraphLevel = $direction === 2
                ? BidiAnalyzer::paragraphLevel($cps)
                : $direction;

            $cases++;

            $result = BidiAnalyzer::computeLevels($cps, $paragraphLevel);
            $levels = BidiAnalyzer::applyL1($result["levels"], $cps, $paragraphLevel);
            $removed = $result["removed"];

            $levelStrings = [];
            foreach ($levels as $i => $level) {
                $levelStrings[] = $removed[$i] ? "x" : (string)$level;
            }

            $order = array_map("strval", BidiAnalyzer::visualOrder($levels, $removed));

            if ($paragraphLevel !== $expectedParagraphLevel
                || $levelStrings !== $expectedLevels
                || $order !== $expectedOrder
            ) {
                if (count($failures) < 10) {
                    $failures[] = sprintf(
                        "[%s] dir=%d\n  pl got %d want %d\n  levels got [%s] want [%s]\n  order got [%s] want [%s]",
                        $fields[0],
                        $direction,
                        $paragraphLevel,
                        $expectedParagraphLevel,
                        implode(" ", $levelStrings),
                        implode(" ", $expectedLevels),
                        implode(" ", $order),
                        implode(" ", $expectedOrder)
                    );
                } else {
                    $failures[] = "";
                }
            }
        }

        $this->assertGreaterThan(50000, $cases, "BidiCharacterTest.txt did not parse");
        $this->assertSame(
            0,
            count($failures),
            count($failures) . " of $cases BidiCharacterTest cases failed. First failures:\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }
}
