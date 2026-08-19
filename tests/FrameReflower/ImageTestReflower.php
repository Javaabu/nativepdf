<?php
namespace NativePdf\Tests\FrameReflower;

use NativePdf\FrameReflower\Image;

class ImageTestReflower extends Image
{
    public function resolve_dimensions(): void
    {
        parent::resolve_dimensions();
    }
}
