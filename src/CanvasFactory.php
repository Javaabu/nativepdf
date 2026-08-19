<?php
/**
 * @package nativepdf
 * @link    https://github.com/Javaabu/nativepdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
namespace NativePdf;

/**
 * Create canvas instances
 *
 * The canvas factory creates canvas instances based on the
 * availability of rendering backends and config options.
 *
 * @package nativepdf
 */
class CanvasFactory
{
    /**
     * Constructor is private: this is a static class
     */
    private function __construct()
    {
    }

    /**
     * @param NativePdf         $nativepdf
     * @param string|float[] $paper
     * @param string         $orientation
     * @param string|null    $class
     *
     * @return Canvas
     */
    static function get_instance(NativePdf $nativepdf, $paper, string $orientation, ?string $class = null)
    {
        $backend = strtolower($nativepdf->getOptions()->getPdfBackend());

        if (isset($class) && class_exists($class, false)) {
            $class .= "_Adapter";
        } else {
            if (($backend === "auto" || $backend === "pdflib") &&
                class_exists("PDFLib", false)
            ) {
                $class = "NativePdf\\Adapter\\PDFLib";
            }

            else {
                if (class_exists($backend, false)) {
                    $class = $backend;
                } elseif ($backend === "gd" && extension_loaded('gd')) {
                    $class = "NativePdf\\Adapter\\GD";
                } else {
                    $class = "NativePdf\\Adapter\\CPDF";
                }
            }
        }

        $instance = new $class($paper, $orientation, $nativepdf);

        $class_interfaces = class_implements($class, false);
        if (!$class_interfaces || !in_array("NativePdf\\Canvas", $class_interfaces)) {
            $class = "NativePdf\\Adapter\\CPDF";
            $instance = new $class($paper, $orientation, $nativepdf);
        }

        return $instance;
    }
}
