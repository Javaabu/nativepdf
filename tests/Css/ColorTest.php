<?php
namespace NativePdf\Tests\Css;

use NativePdf\Css\Color;
use NativePdf\Tests\TestCase;

class ColorTest extends TestCase
{
    public static function validColorProvider(): array
    {
        return [
            // Color names
            ["red", [1, 0, 0, 1.0]],
            ["lime", [0, 1, 0, 1.0]],
            ["blue", [0, 0, 1, 1.0]],

            // Hex notation
            ["#f00", [1, 0, 0, 1.0]],
            ["#f003", [1, 0, 0, 0.2]],
            ["#ff0000", [1, 0, 0, 1.0]],
            ["#ff000033", [1, 0, 0, 0.2]],
            ["#FFFFFF00", [1, 1, 1, 0.0]],

            // Functional rgb syntax (space-separated)
            ["rgb(255 0 0)", [1, 0, 0, 1.0]],
            ["rgb(255 0 0/0.2)", [1, 0, 0, 0.2]],
            ["rgb( 255 0 0 / 0.2 )", [1, 0, 0, 0.2]],
            ["rgb(100% 0% 0% / 20%)", [1, 0, 0, 0.2]],
            ["rgba(255 0 0)", [1, 0, 0, 1.0]],
            ["rgba(255 0 0/0.2)", [1, 0, 0, 0.2]],

            // Functional rgb syntax (comma-separated)
            ["rgb(255, 0, 0)", [1, 0, 0, 1.0]],
            ["rgb(255, 0, 0, 0.2)", [1, 0, 0, 0.2]],
            ["rgb( 255,0,0,0.2 )", [1, 0, 0, 0.2]],
            ["rgb(100%, 0%, 0%, 20%)", [1, 0, 0, 0.2]],
            ["rgba(255, 0, 0)", [1, 0, 0, 1.0]],
            ["rgba(255, 0, 0, 0.2)", [1, 0, 0, 0.2]],

            // Functional hsl syntax
            // https://www.w3.org/TR/css-color-4/#the-hsl-notation
            ["hsl(0, 100%, 50%)", [1, 0, 0, 1.0]],
            ["hsl(120 100% 50%)", [0, 1, 0, 1.0]],
            ["hsl(240deg 100% 50%)", [0, 0, 1, 1.0]],
            ["hsl(0.5turn 100% 50%)", [0, 1, 1, 1.0]],
            ["hsl(120, 100%, 50%, 0.2)", [0, 1, 0, 0.2]],
            ["hsla(120 100% 50% / 20%)", [0, 1, 0, 0.2]],
            ["hsl(480, 100%, 50%)", [0, 1, 0, 1.0]], // hue wraps
            ["hsl(0, 0%, 100%)", [1, 1, 1, 1.0]],

            // Functional hwb syntax
            // https://www.w3.org/TR/css-color-4/#the-hwb-notation
            ["hwb(0 0% 0%)", [1, 0, 0, 1.0]],
            ["hwb(0 100% 0%)", [1, 1, 1, 1.0]],
            ["hwb(0 0% 100%)", [0, 0, 0, 1.0]],
            ["hwb(120 0% 0% / 0.2)", [0, 1, 0, 0.2]],
        ];
    }

    /**
     * @dataProvider validColorProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('validColorProvider')]
    public function testParseColor(string $value, array $expected): void
    {
        $color = Color::parse($value);

        if (!is_array($color)) {
            $this->fail("Failed to parse valid color declaration");
        }

        [$r, $g, $b] = $color;
        $alpha = $color["alpha"];

        $this->assertEquals($expected, [$r, $g, $b, $alpha]);
    }
}
