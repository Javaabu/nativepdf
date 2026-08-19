<?php
namespace NativePdf\Tests\Helpers;

use NativePdf\Css\Style;
use NativePdf\Css\Stylesheet;
use NativePdf\NativePdf;
use Mockery\MockInterface;

class MockHelper
{
    /**
     * @param $properties
     * @return MockInterface | Style
     */
    public static function getStyleMock($properties)
    {
        // initialize static properties
        // For now we cannot mock methods in a constructor
        // https://github.com/mockery/mockery/issues/534
        // $style = \Mockery::mock(Style::class, [new Stylesheet(new NativePdf())]);

        new Style(new Stylesheet(new NativePdf()));
        $style = \Mockery::mock(Style::class);

        foreach ($properties as $property => $value) {
            $style->$property = $value;
        }

        return $style;
    }
}
