<?php
/**
 * @package nativepdf
 * @link    https://github.com/Javaabu/nativepdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
namespace NativePdf\FrameDecorator;

use NativePdf\NativePdf;
use NativePdf\Frame;
use NativePdf\Helpers;
use NativePdf\Image\Cache;

/**
 * Decorates frames for image layout and rendering
 *
 * @package nativepdf
 */
class Image extends AbstractFrameDecorator
{

    /**
     * The path to the image file (note that remote images are
     * downloaded locally to Options:tempDir).
     *
     * @var string
     */
    protected $_image_url;

    /**
     * The image's file error message
     *
     * @var string
     */
    protected $_image_msg;

    /**
     * Class constructor
     *
     * @param Frame $frame the frame to decorate
     * @param NativePdf $nativepdf the document's nativepdf object (required to resolve relative & remote urls)
     */
    function __construct(Frame $frame, NativePdf $nativepdf)
    {
        parent::__construct($frame, $nativepdf);

        $node = $frame->get_node();
        $url = $node->getAttribute("src");

        $debug_png = $nativepdf->getOptions()->getDebugPng();
        if ($debug_png) {
            print '[__construct ' . $url . ']';
        }

        list($this->_image_url, /*$type*/, $this->_image_msg) = Cache::resolve_url(
            $url,
            $nativepdf->getProtocol(),
            $nativepdf->getBaseHost(),
            $nativepdf->getBasePath(),
            $nativepdf->getOptions()
        );

        if (Cache::is_broken($this->_image_url) && ($alt = $node->getAttribute("alt")) !== "") {
            $fontMetrics = $nativepdf->getFontMetrics();
            $style = $frame->get_style();
            $font = $style->font_family;
            $size = $style->font_size;
            $word_spacing = $style->word_spacing;
            $letter_spacing = $style->letter_spacing;

            $style->width = $fontMetrics->getTextWidth($alt, $font, $size, $word_spacing, $letter_spacing);
            $style->height = $fontMetrics->getFontHeight($font, $size);
        }
    }

    /**
     * Get the intrinsic pixel dimensions of the image.
     *
     * @return array Width and height as `float|int`.
     */
    public function get_intrinsic_dimensions(): array
    {
        [$width, $height] = Helpers::nativepdf_getimagesize($this->_image_url, $this->_nativepdf->getHttpContext());

        return [$width, $height];
    }

    /**
     * Resample the given pixel length according to dpi.
     *
     * @param float|int $length
     * @return float
     */
    public function resample($length): float
    {
        $dpi = $this->_nativepdf->getOptions()->getDpi();
        return ($length * 72) / $dpi;
    }

    /**
     * Return the image's url
     *
     * @return string The url of this image
     */
    function get_image_url()
    {
        return $this->_image_url;
    }

    /**
     * Return the image's error message
     *
     * @return string The image's error message
     */
    function get_image_msg()
    {
        return $this->_image_msg;
    }

}
