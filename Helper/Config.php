<?php
/**
 * @author @haihv433
 * @copyright Copyright (c) 2020 Goomento (https://store.goomento.com)
 * @package Goomento_CouponErrorMessage
 * @link https://github.com/Goomento/CouponErrorMessage
 */

namespace Goomento\CouponErrorMessage\Helper;

use Magento\Framework\App\Helper\Context;

/**
 * Class Config
 * @package Goomento\CouponErrorMessage\Helper
 */
class Config extends \Goomento\Base\Helper\AbstractConfig
{
    /**
     * Config constructor.
     * @param Context $context
     */
    public function __construct(Context $context)
    {
        parent::__construct($context, ['goomento_coupon_error_message', 'general']);
    }
}
