<?php
namespace Dompdf\Tests\Text;

use Dompdf\Tests\TestCase;
use Dompdf\Text\ArabicShaper;
use Dompdf\Text\BidiAnalyzer;

class ArabicShaperTest extends TestCase
{
    private static function cps(string $text): array
    {
        return array_map(function ($cp) {
            return sprintf("%04X", $cp);
        }, BidiAnalyzer::toCodePoints($text));
    }

    private static function fromHex(array $hex): string
    {
        $out = "";
        foreach ($hex as $h) {
            $out .= ArabicShaper::encode(hexdec($h));
        }
        return $out;
    }

    public function testContextualForms(): void
    {
        // muhammad: meem haa meem dal -> initial, medial, medial, final
        $input = self::fromHex(["0645", "062D", "0645", "062F"]);
        $this->assertSame(
            ["FEE3", "FEA4", "FEE4", "FEAA"],
            self::cps(ArabicShaper::shape($input))
        );
    }

    public function testLamAlefLigature(): void
    {
        // salam: seen lam alef meem -> seen initial, lam-alef final, meem isolated
        $input = self::fromHex(["0633", "0644", "0627", "0645"]);
        $this->assertSame(
            ["FEB3", "FEFC", "FEE1"],
            self::cps(ArabicShaper::shape($input))
        );
    }

    public function testIsolatedLamAlef(): void
    {
        // lam alef alone -> isolated ligature
        $input = self::fromHex(["0644", "0627"]);
        $this->assertSame(["FEFB"], self::cps(ArabicShaper::shape($input)));
    }

    public function testRightJoiningBreaksConnection(): void
    {
        // alef (R) does not join forward: alef beh -> alef isolated, beh isolated
        $input = self::fromHex(["0627", "0628"]);
        $this->assertSame(
            ["FE8D", "FE8F"],
            self::cps(ArabicShaper::shape($input))
        );
    }

    public function testZwnjBreaksJoining(): void
    {
        // beh ZWNJ beh: both isolated
        $input = self::fromHex(["0628", "200C", "0628"]);
        $this->assertSame(
            ["FE8F", "200C", "FE8F"],
            self::cps(ArabicShaper::shape($input))
        );
    }

    public function testDiacriticsAreTransparent(): void
    {
        // beh fatha beh: diacritic preserved, letters join through it
        $input = self::fromHex(["0628", "064E", "0628"]);
        $this->assertSame(
            ["FE91", "064E", "FE90"],
            self::cps(ArabicShaper::shape($input))
        );
    }

    public function testMixedWithLatin(): void
    {
        $input = "abc " . self::fromHex(["0628", "0628"]) . " xyz";
        $shaped = ArabicShaper::shape($input);

        $this->assertStringStartsWith("abc ", $shaped);
        $this->assertStringEndsWith(" xyz", $shaped);
        $this->assertSame(
            ["FE91", "FE90"],
            array_values(array_filter(self::cps($shaped), function ($h) {
                return hexdec($h) >= 0xFE70;
            }))
        );
    }

    public function testIdempotence(): void
    {
        $input = self::fromHex(["0633", "0644", "0627", "0645", "0020", "0645", "062D", "0645", "062F"]);
        $once = ArabicShaper::shape($input);
        $twice = ArabicShaper::shape($once);

        $this->assertSame($once, $twice);
    }

    public function testIsArabic(): void
    {
        $this->assertTrue(ArabicShaper::isArabic(self::fromHex(["0628"])));
        $this->assertFalse(ArabicShaper::isArabic("hello world"));
        $this->assertFalse(ArabicShaper::isArabic("שלום")); // Hebrew needs no shaping
    }
}
