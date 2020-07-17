<?php
/**
 * @author @haihv433
 * @copyright Copyright (c) 2020 Goomento (https://store.goomento.com)
 * @package Goomento_CouponErrorMessage
 * @link https://github.com/Goomento/CouponErrorMessage
 */

namespace Goomento\CouponErrorMessage\Controller\Cart;

use Goomento\CouponErrorMessage\Helper\Config;
use Goomento\CouponErrorMessage\Helper\Helper;
use Goomento\CouponErrorMessage\Helper\Logger;
use Magento\Framework\Exception\LocalizedException;
use Magento\Rule\Model\Condition\AbstractCondition;
use Magento\SalesRule\Model\Data\Condition;

/**
 * Class CouponPost
 * @package Goomento\CouponErrorMessage\Plugin\Checkout\Controller\Cart
 */
class CouponPost extends \Magento\Checkout\Controller\Cart\CouponPost
{
    /**
     * @var \Magento\Directory\Model\Currency
     */
    protected $currency;
    /**
     * @var \Magento\SalesRule\Model\RuleFactory
     */
    protected $ruleFactory;
    /**
     * @var \Magento\SalesRule\Api\Data\ConditionInterfaceFactory
     */
    protected $conditionFactory;

    /**
     * CouponPost constructor.
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Framework\Data\Form\FormKey\Validator $formKeyValidator
     * @param \Magento\Checkout\Model\Cart $cart
     * @param \Magento\SalesRule\Model\CouponFactory $couponFactory
     * @param \Magento\Quote\Api\CartRepositoryInterface $quoteRepository
     * @param \Magento\Directory\Model\Currency $currency
     * @param \Magento\SalesRule\Model\RuleFactory $ruleFactory
     * @param \Magento\SalesRule\Api\Data\ConditionInterfaceFactory $conditionFactory
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\Data\Form\FormKey\Validator $formKeyValidator,
        \Magento\Checkout\Model\Cart $cart,
        \Magento\SalesRule\Model\CouponFactory $couponFactory,
        \Magento\Quote\Api\CartRepositoryInterface $quoteRepository,
        \Magento\Directory\Model\Currency $currency,
        \Magento\SalesRule\Model\RuleFactory $ruleFactory,
        \Magento\SalesRule\Api\Data\ConditionInterfaceFactory $conditionFactory
    ) {
        parent::__construct($context, $scopeConfig, $checkoutSession, $storeManager, $formKeyValidator, $cart, $couponFactory, $quoteRepository);
        $this->currency = $currency;
        $this->ruleFactory = $ruleFactory;
        $this->conditionFactory = $conditionFactory;
    }

    /**
     * @return \Magento\Framework\Controller\Result\Redirect
     */
    public function execute()
    {
        if (!Config::staticIsActive()) {
            return parent::execute();
        }

        $couponCode = $this->getRequest()->getParam('remove') == 1
            ? ''
            : trim($this->getRequest()->getParam('coupon_code'));

        $cartQuote = $this->cart->getQuote();
        $oldCouponCode = $cartQuote->getCouponCode();
        $codeLength = strlen($couponCode);
        if (!$codeLength && !strlen($oldCouponCode)) {
            return $this->_goBack();
        }

        try {
            $isCodeLengthValid = $codeLength && $codeLength <= \Magento\Checkout\Helper\Cart::COUPON_CODE_MAX_LENGTH;
            $itemsCount = $cartQuote->getItemsCount();

            if ($itemsCount) {
                $cartQuote->getShippingAddress()->setCollectShippingRates(true);
                $cartQuote->setCouponCode($isCodeLengthValid ? $couponCode : '')->collectTotals();
                $this->quoteRepository->save($cartQuote);
            }

            if ($codeLength) {
                $escaper = $this->_objectManager->get(\Magento\Framework\Escaper::class);
                $coupon = $this->couponFactory->create();
                $coupon->load($couponCode, 'code');

                if (!$itemsCount) {
                    if ($isCodeLengthValid && $coupon->getId()) {
                        $this->_checkoutSession->getQuote()->setCouponCode($couponCode)->save();
                        $this->messageManager->addSuccessMessage(
                            __(
                                'You used coupon code "%1".',
                                $escaper->escapeHtml($couponCode)
                            )
                        );
                    } else {
                        $this->messageManager->addErrorMessage(
                            __(
                                'The coupon code "%1" is not valid.',
                                $escaper->escapeHtml($couponCode)
                            )
                        );
                    }
                } else {
                    if ($isCodeLengthValid && $coupon->getId() && $couponCode == $cartQuote->getCouponCode()) {
                        $this->messageManager->addSuccessMessage(
                            __(
                                'Your coupon was already used.',
                                $escaper->escapeHtml($couponCode)
                            )
                        );
                    } else {
                        /**
                         * Now, we need a reason why this coupon add failed
                         */
                        if ($isCodeLengthValid && $coupon->getId()) {
                            $rule = $this->ruleFactory->create()->load($coupon->getRuleId());
                            if (!$rule->getIsActive()) {
                                throw new LocalizedException(
                                    __(
                                        'Your coupon is inactive.'
                                    )
                                );
                            }
                            /** @var array $websiteIds */
                            $websiteIds = $rule->getWebsiteIds();
                            $currentWebsiteId = $this->_storeManager->getStore()->getWebsiteId();
                            if (!in_array($currentWebsiteId, $websiteIds)) {
                                // Invalid websites
                                $websiteIds = array_flip($websiteIds);
                                $websites = $this->_storeManager->getWebsites();
                                foreach ($websites as $website) {
                                    if (isset($websiteIds[$website->getId()])) {
                                        $websiteIds[$website->getId()] = $website->getName();
                                    }
                                }

                                throw new LocalizedException(
                                    __(
                                        "Your coupon is not valid for this website, allowed websites: %s",
                                        implode(', ', array_values($websiteIds))
                                    )
                                );
                            }

                            $groupIds = $rule->getCustomerGroupIds();
                            if (!in_array($cartQuote->getCustomerGroupId(), $groupIds)) {
                                /** @var \Magento\Customer\Api\GroupRepositoryInterface $customerGroup */
                                $customerGroup = $this->_objectManager->get('\Magento\Customer\Api\GroupRepositoryInterface');
                                foreach ($groupIds as &$groupId) {
                                    try {
                                        $groupId = $customerGroup->getById($groupId)->getCode();
                                    } catch (\Exception $e) {
                                        Logger::staticError($e->getMessage());
                                    }
                                }
                                throw new LocalizedException(
                                    __(
                                        'Your coupon is not valid for your Customer Group. Allowed customer groups: %1.',
                                        implode(', ', array_values($groupIds))
                                    )
                                );
                            }

                            $now = \Zend_Date::now();

                            if ($rule->getFromDate()) {
                                $fromDate = new \Zend_Date($rule->getFromDate());
                                if ($now->isEarlier($fromDate)) {
                                    throw new LocalizedException(
                                        __(
                                            'Your coupon is not valid yet. It will be active on %1.',
                                            $fromDate->toString()
                                        )
                                    );
                                }
                            }

                            if ($rule->getToDate()) {
                                $toDate = new \Zend_Date($rule->getToDate());
                                if ($now->isLater($toDate)) {
                                    throw new LocalizedException(
                                        __(
                                            'Your coupon is no longer valid. It expired on %1.',
                                            $toDate->toString()
                                        )
                                    );
                                }
                            }

                            $isCouponAlreadyUsed = $coupon->getUsageLimit() && $coupon->getTimesUsed() >= $coupon->getUsageLimit();
                            if ($isCouponAlreadyUsed) {
                                throw new LocalizedException(
                                    __(
                                        'Your coupon was already used. It may only be used %1 time(s).',
                                        $coupon->getUsageLimit()
                                    )
                                );
                            }

                            /** @var \Magento\SalesRule\Model\ResourceModel\Coupon\Usage $usage */
                            $usage = $this->_objectManager->get('Magento\SalesRule\Model\ResourceModel\Coupon\Usage');
                            $dataUsage = new \Magento\Framework\DataObject();
                            $usage->loadByCustomerCoupon($dataUsage, $cartQuote->getCustomer()->getId(), $coupon->getId());
                            if ($dataUsage->getCouponId() && $dataUsage->getTimesUsed() >= $coupon->getUsagePerCustomer()) {
                                throw new LocalizedException(
                                    __(
                                        'You have already used your coupon. It may only be used %1 time(s).',
                                        $coupon->getUsagePerCustomer()
                                    )
                                );
                            }

                            if ($cartQuote->getCouponCode() && $cartQuote->getCouponCode()==$couponCode) {
                                $this->messageManager->addSuccessMessage(
                                    __(
                                        'You used coupon code "%1".',
                                        $escaper->escapeHtml($couponCode)
                                    )
                                );
                            } else {
                                $conditionsSerialized = \Zend_Json::decode($rule->getConditionsSerialized());
                                $condition = $this->arrayToConditionDataModel($conditionsSerialized);
                                $messages = $this->validateCondition($condition, $cartQuote);
                                if (!empty($messages)) {
                                    if (!Config::staticConfigGetBool('allowed_show_all')) {
                                        $messages = [array_shift($messages)];
                                    }
                                    $messages = implode('. ', $messages);

                                    throw new LocalizedException(__($messages));
                                }
                                $messages = [];
                                $actionsSerialized = \Zend_Json::decode($rule->getActionsSerialized());
                                $action = $this->arrayToConditionDataModel($actionsSerialized);
                                $messages = $this->validateCondition($action, $cartQuote);
                                if (!empty($messages)) {
                                    if (!Config::staticConfigGetBool('allowed_show_all')) {
                                        $messages = [array_shift($messages)];
                                    }
                                    $messages = implode('. ', $messages);
                                } else {
                                    $messages = [Config::staticConfigGet('on_error_message')];
                                }
                                throw new LocalizedException(__($messages));
                            }
                        } else {
                            $this->messageManager->addErrorMessage(
                                __(
                                    'Coupon code does not exist.',
                                    $escaper->escapeHtml($couponCode)
                                )
                            );
                        }
                    }
                }
            } else {
                $this->messageManager->addSuccessMessage(__('You canceled the coupon code.'));
            }
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('We cannot apply the coupon code.'));
            Logger::staticError($e->getMessage());
        }

        return $this->_goBack();
    }

    /**
     * Convert recursive array into condition data model
     *
     * @param array $input
     * @return AbstractCondition|Condition
     */
    protected function arrayToConditionDataModel(array $input)
    {
        /** @var \Magento\Rule\Model\Condition\AbstractCondition $conditionDataModel */
        $conditionDataModel = $this->_objectManager->create($input['type']);
        foreach ($input as $key => $value) {
            switch ($key) {
                case 'type':
                    $conditionDataModel->setType($value);
                    break;
                case 'attribute':
                    $conditionDataModel->setAttribute($value);
                    break;
                case 'operator':
                    $conditionDataModel->setOperator($value);
                    break;
                case 'value':
                    $conditionDataModel->setValue($value);
                    break;
                case 'aggregator':
                    $conditionDataModel->setAggregator($value);
                    break;
                case 'conditions':
                    $conditions = [];
                    foreach ($value as $condition) {
                        $conditions[] = $this->arrayToConditionDataModel($condition);
                    }
                    $conditionDataModel->setConditions($conditions);
                    break;
                default:
            }
        }
        return $conditionDataModel;
    }

    /**
     * @param $condition
     * @param $entity
     * @return array|bool
     */
    protected function validateCondition($condition, $entity)
    {
        if (!$condition->getConditions()) {
            return true;
        }

        $all = $condition->getAggregator() === 'all';
        $true = (bool)$condition->getValue();
        $validated = false;
        $messages = [];
        foreach ($condition->getConditions() as $cond) {
            try {
                /** @var \Magento\Rule\Model\Condition\AbstractCondition $cond */
                if ($cond->getConditions()) {
                    $message = $this->validateCondition($cond, $entity);
                    $messages = array_merge($messages, $message);
                } else {
                    if ($entity instanceof \Magento\Framework\Model\AbstractModel) {
                        $attributeValue = $entity->getData($cond->getAttribute());
                        $validated = $cond->validateAttribute($attributeValue);
                        if (!$validated) {
                            $value = $cond->getValueParsed();
                            $option = $cond->getOperatorForValidate();
                            $compareText = Helper::getOperatorMessage($option);
                            $attrName = $cond->getAttributeName();
                            $message = "{$attrName} {$compareText} {$value}";
                            $messages[] = $message;
                        }
                    }
                    if ($all && $validated !== $true) {
                        return $messages;
                    } elseif (!$all && $validated === $true) {
                        return [];
                    }
                }
            } catch (\Exception $e) {
                Logger::staticError($e->getMessage());
            }
        }

        return $messages;
    }
}
