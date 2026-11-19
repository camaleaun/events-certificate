<?php
/**
 * @package dompdf
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
namespace EventsCertificate\Vendor\Dompdf\FrameReflower;

use EventsCertificate\Vendor\Dompdf\Frame;
use EventsCertificate\Vendor\Dompdf\FrameDecorator\Block as BlockFrameDecorator;

/**
 * Dummy reflower
 *
 * @package dompdf
 */
class NullFrameReflower extends AbstractFrameReflower
{

    /**
     * NullFrameReflower constructor.
     * @param Frame $frame
     */
    function __construct(Frame $frame)
    {
        parent::__construct($frame);
    }

    /**
     * @param BlockFrameDecorator|null $block
     */
    function reflow(?BlockFrameDecorator $block = null)
    {
        return;
    }

}
