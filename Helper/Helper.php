<?php
/**
 * @author @haihv433
 * @copyright Copyright (c) 2020 Goomento (https://store.goomento.com)
 * @package Goomento_CouponErrorMessage
 * @link https://github.com/Goomento/CouponErrorMessage
 */

namespace Goomento\CouponErrorMessage\Helper;


/**
 * Class Helper
 * @package Goomento\CouponErrorMessage\Helper
 */
class Helper extends \Goomento\Base\Helper\AbstractHelper
{
    /**
     * @param $type
     * @param bool $revert
     * @return string
     */
    public static function getOperatorMessage($type, $revert = false)
    {
        $data = [
            '==' => __('must be'),
            '!=' => __('must not be'),
            '>=' => __('must be equal or greater than'),
            '<=' => __('must be equal or less than'),
            '>' => __('must be greater than'),
            '<' => __('must be less than'),
            '{}' => __('must contain'),
            '!{}' => __('must not contain'),
            '()' => __('must be one of'),
            '!()' => __('must not be one of')
        ];

        return $data[$type] ?? '';
    }
}
