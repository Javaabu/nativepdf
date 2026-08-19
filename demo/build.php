<?php
/**
 * Renders every page in demo/pages to a PDF in demo/out.
 *
 * Usage: php demo/build.php [name ...]   (no arguments renders all pages)
 */

require __DIR__ . '/../vendor/autoload.php';

use NativePdf\NativePdf;
use NativePdf\Options;

$pagesDir = __DIR__ . '/pages';
$outDir   = __DIR__ . '/out';
$cacheDir = __DIR__ . '/.cache';

@mkdir($outDir, 0777, true);
@mkdir($cacheDir, 0777, true);

$wanted = array_slice($argv, 1);
$sources = glob($pagesDir . '/*.html');
sort($sources);

foreach ($sources as $source) {
    $name = basename($source, '.html');

    if ($wanted && !in_array($name, $wanted, true)) {
        continue;
    }

    $options = new Options();
    $options->setChroot([__DIR__]);
    $options->setFontDir($cacheDir);
    $options->setFontCache($cacheDir);
    $options->setIsRemoteEnabled(false);
    $options->setDefaultPaperSize('A4');

    $nativepdf = new NativePdf($options);
    $nativepdf->loadHtmlFile($source);
    $nativepdf->render();

    // nativepdf has no counter(pages), so the "page x of y" footer is drawn on
    // the canvas, where {PAGE_NUM} and {PAGE_COUNT} are substituted per page.
    $canvas = $nativepdf->getCanvas();
    $font   = $nativepdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
    $width  = $canvas->get_width();
    $height = $canvas->get_height();
    $canvas->page_text($width - 150, $height - 32, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 7.5, [0.45, 0.5, 0.58], 0, 0, 0);
    $canvas->page_text(40, $height - 32, 'NativePdf demo  ·  ' . $name, $font, 7.5, [0.45, 0.5, 0.58]);

    $target = $outDir . '/' . $name . '.pdf';
    file_put_contents($target, $nativepdf->output());

    printf("%-26s %s\n", $name . '.pdf', number_format(filesize($target) / 1024, 1) . ' KB');
}
