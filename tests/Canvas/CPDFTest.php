<?php
namespace NativePdf\Tests\Canvas;

use NativePdf\Adapter\CPDF;
use NativePdf\Canvas;
use NativePdf\NativePdf;
use NativePdf\FontMetrics;
use NativePdf\Tests\TestCase;
use DateTime;

class CPDFTest extends TestCase
{
    public function testImage(): void
    {
        $basePath = realpath(__DIR__ . "/..");
        $imagePath = "$basePath/_files/red-dot.png";

        $nativepdf = new NativePdf();
        $canvas = new CPDF([0, 0, 200, 200], "portrait", $nativepdf);
        $canvas->new_page();
        $canvas->image($imagePath, 0, 0, 5, 5);
        $output = $canvas->output();
        $this->assertNotSame("", $output);
    }

    public function testPageScript(): void
    {
        global $called;
        $called = 0;

        $nativepdf = new NativePdf();
        $canvas = new CPDF([0, 0, 200, 200], "portrait", $nativepdf);
        $canvas->new_page();

        $canvas->page_script(function (
            int $pageNumber,
            int $pageCount,
            Canvas $canvas,
            FontMetrics $fontMetrics
        ) use (&$called) {
            $called++;
            $font = $fontMetrics->getFont("Helvetica");
            $canvas->text(40, 20, "Page $pageNumber of $pageCount", $font, 12);
            $canvas->line(200, 0, 0, 200, [0, 0, 0], 1);
        });
        $canvas->page_script('
            global $called;
            $called++;
            $font = $fontMetrics->getFont("Helvetica");
            $pdf->text(20, 0, "Page $PAGE_NUM of $PAGE_COUNT", $font, 12);
        ');

        $output = $canvas->output();

        $this->assertNotSame("", $output);
        $this->assertSame(4, $called);
    }

    public function testPageText(): void
    {
        $nativepdf = new NativePdf();
        $canvas = new CPDF([0, 0, 200, 200], "portrait", $nativepdf);
        $canvas->new_page();

        $font = $nativepdf->getFontMetrics()->getFont("Helvetica");
        $canvas->page_text(60, 40, "Page {PAGE_NUM} of {PAGE_COUNT}", $font, 12);

        $output = $canvas->output();
        $this->assertNotSame("", $output);
    }

    public function testPageLine(): void
    {
        $nativepdf = new NativePdf();
        $canvas = new CPDF([0, 0, 200, 200], "portrait", $nativepdf);
        $canvas->new_page();

        $canvas->page_line(0, 0, 200, 200, [0, 0, 0], 1);

        $output = $canvas->output();
        $this->assertNotSame("", $output);
    }

    public static function fontSupportsCharProvider(): array
    {
        return [
            // Core fonts
            // ASCII and ISO-8859-1
            ["Helvetica", "A", true],
            ["Helvetica", "{", true],
            ["Helvetica", "Æ", true],
            ["Helvetica", "÷", true],

            // Part of Windows-1252, but not ISO-8859-1
            ["Helvetica", "€", true],
            ["Helvetica", "‚", true],
            ["Helvetica", "ƒ", true],
            ["Helvetica", "„", true],
            ["Helvetica", "…", true],
            ["Helvetica", "†", true],
            ["Helvetica", "‡", true],
            ["Helvetica", "ˆ", true],
            ["Helvetica", "‰", true],
            ["Helvetica", "Š", true],
            ["Helvetica", "‹", true],
            ["Helvetica", "Œ", true],
            ["Helvetica", "Ž", true],
            ["Helvetica", "‘", true],
            ["Helvetica", "’", true],
            ["Helvetica", "“", true],
            ["Helvetica", "”", true],
            ["Helvetica", "•", true],
            ["Helvetica", "–", true],
            ["Helvetica", "—", true],
            ["Helvetica", "˜", true],
            ["Helvetica", "™", true],
            ["Helvetica", "š", true],
            ["Helvetica", "›", true],
            ["Helvetica", "œ", true],
            ["Helvetica", "ž", true],
            ["Helvetica", "Ÿ", true],
            ["Helvetica", "ÿ", true],

            // Unicode outside Windows-1252
            ["Helvetica", "Ā", false],
            ["Helvetica", "↦", false],
            ["Helvetica", "∉", false],
            ["Helvetica", "能", false],

            // DejaVu
            ["DejaVu Sans", "A", true],
            ["DejaVu Sans", "{", true],
            ["DejaVu Sans", "Æ", true],
            ["DejaVu Sans", "÷", true],
            ["DejaVu Sans", "Œ", true],
            ["DejaVu Sans", "—", true],
            ["DejaVu Sans", "↦", true],
            ["DejaVu Sans", "∉", true],
            ["DejaVu Sans", "能", false],
        ];
    }

    /**
     * @dataProvider fontSupportsCharProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('fontSupportsCharProvider')]
    public function testFontSupportsChar(string $font, string $char, bool $expected): void
    {
        $nativepdf = new NativePdf();
        $canvas = new CPDF("letter", "portrait", $nativepdf);
        $fontFile = $nativepdf->getFontMetrics()->getFont($font);

        $this->assertSame($expected, $canvas->font_supports_char($fontFile, $char));
    }

    public function testGetXmpMetadata(): void
    {
        $nativepdf = new NativePdf();
        $canvas = new CPDF([0, 0, 200, 200], "portrait", $nativepdf);

        $canvas->get_cpdf()->addInfo('CreationDate', 'aa20250208195048');
        $canvas->get_cpdf()->addInfo('ModDate', 'aa20250208195048Z');
        $canvas->get_cpdf()->addInfo('Producer', 'NativePdf Tests');

        $data = implode("\n", [
            '<?xpacket begin="﻿" id="W5M0MpCehiHzreSzNTczkc9d"?>',
            '<x:xmpmeta xmlns:x="adobe:ns:meta/">',
            '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">',
            '',
            '<rdf:Description xmlns:pdfaid="http://www.aiim.org/pdfa/ns/id/" rdf:about="">',
            '<pdfaid:part>3</pdfaid:part>',
            '<pdfaid:conformance>B</pdfaid:conformance>',
            '</rdf:Description>',
            '',
            '<rdf:Description xmlns:dc="http://purl.org/dc/elements/1.1/" rdf:about="">',
            '</rdf:Description>',
            '<rdf:Description xmlns:pdf="http://ns.adobe.com/pdf/1.3/" rdf:about="">',
            '<pdf:Producer>NativePdf Tests</pdf:Producer>',
            '</rdf:Description>',
            '<rdf:Description xmlns:xmp="http://ns.adobe.com/xap/1.0/" rdf:about="">',
            '<xmp:CreateDate>2025-02-08T19:50:48+00:00</xmp:CreateDate>',
            '<xmp:ModifyDate>2025-02-08T19:50:48+00:00</xmp:ModifyDate>',
            '</rdf:Description>',
            '</rdf:RDF>',
            '</x:xmpmeta>',
            '<?xpacket end="w"?>',
        ]);

        $this->assertSame($data, $canvas->get_cpdf()->getXmpMetadata());
    }

    public function testSetAdditionalXmpRdf(): void
    {
        $nativepdf = new NativePdf();
        $canvas = new CPDF([0, 0, 200, 200], "portrait", $nativepdf);

        $canvas->get_cpdf()->addInfo('CreationDate', 'aa20250208195048');
        $canvas->get_cpdf()->addInfo('ModDate', 'aa20250208195048Z');
        $canvas->get_cpdf()->addInfo('Producer', 'NativePdf Tests');
        $additionalXmpRdf = implode("\n", [
            '',
            '<rdf:Description rdf:about="" xmlns:zf="urn:ferd:pdfa:CrossIndustryDocument:invoice:1p0#">',
            '  <zf:DocumentType>INVOICE</zf:DocumentType>',
            '  <zf:DocumentFileName>ZUGFeRD-invoice.xml</zf:DocumentFileName>',
            '  <zf:Version>1.0</zf:Version>',
            '  <zf:ConformanceLevel>BASIC</zf:ConformanceLevel>',
            '</rdf:Description>',
        ]);

        $canvas->get_cpdf()->setAdditionalXmpRdf($additionalXmpRdf);

        $data = implode("\n", [
            '<?xpacket begin="﻿" id="W5M0MpCehiHzreSzNTczkc9d"?>',
            '<x:xmpmeta xmlns:x="adobe:ns:meta/">',
            '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">',
            '',
            '<rdf:Description xmlns:pdfaid="http://www.aiim.org/pdfa/ns/id/" rdf:about="">',
            '<pdfaid:part>3</pdfaid:part>',
            '<pdfaid:conformance>B</pdfaid:conformance>',
            '</rdf:Description>',
            '',
            '<rdf:Description xmlns:dc="http://purl.org/dc/elements/1.1/" rdf:about="">',
            '</rdf:Description>',
            '<rdf:Description xmlns:pdf="http://ns.adobe.com/pdf/1.3/" rdf:about="">',
            '<pdf:Producer>NativePdf Tests</pdf:Producer>',
            '</rdf:Description>',
            '<rdf:Description xmlns:xmp="http://ns.adobe.com/xap/1.0/" rdf:about="">',
            '<xmp:CreateDate>2025-02-08T19:50:48+00:00</xmp:CreateDate>',
            '<xmp:ModifyDate>2025-02-08T19:50:48+00:00</xmp:ModifyDate>',
            '</rdf:Description>',
            '<rdf:Description rdf:about="" xmlns:zf="urn:ferd:pdfa:CrossIndustryDocument:invoice:1p0#">',
            '  <zf:DocumentType>INVOICE</zf:DocumentType>',
            '  <zf:DocumentFileName>ZUGFeRD-invoice.xml</zf:DocumentFileName>',
            '  <zf:Version>1.0</zf:Version>',
            '  <zf:ConformanceLevel>BASIC</zf:ConformanceLevel>',
            '</rdf:Description>',
            '</rdf:RDF>',
            '</x:xmpmeta>',
            '<?xpacket end="w"?>',
        ]);

        $this->assertSame($data, $canvas->get_cpdf()->getXmpMetadata());
    }
}
