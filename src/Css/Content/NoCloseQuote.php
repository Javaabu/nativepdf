<?php
namespace NativePdf\Css\Content;

final class NoCloseQuote extends ContentPart
{
    public function __toString(): string
    {
        return "no-close-quote";
    }
}
