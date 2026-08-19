<?php
/**
 * @package nativepdf
 * @link    https://github.com/Javaabu/nativepdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
namespace NativePdf;

/**
 * Embeds Javascript into the PDF document
 *
 * @package nativepdf
 */
class JavascriptEmbedder
{

    /**
     * @var NativePdf
     */
    protected $_nativepdf;

    /**
     * JavascriptEmbedder constructor.
     *
     * @param NativePdf $nativepdf
     */
    public function __construct(NativePdf $nativepdf)
    {
        $this->_nativepdf = $nativepdf;
    }

    /**
     * @param $script
     */
    public function insert($script)
    {
        $this->_nativepdf->getCanvas()->javascript($script);
    }

    /**
     * @param Frame $frame
     */
    public function render(Frame $frame)
    {
        if (!$this->_nativepdf->getOptions()->getIsJavascriptEnabled()) {
            return;
        }

        $this->insert($frame->get_node()->nodeValue);
    }
}
