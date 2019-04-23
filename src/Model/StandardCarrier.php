<?php

namespace Cream\RedJePakketje\Model;

use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Shipping\Model\Rate\Result;
use Magento\Quote\Model\Quote\Item as QuoteItemModel;

class StandardCarrier extends AbstractCarrier
{
    /**
     * Constants for use in multiple functions
     */
    const SHIPPING_METHOD = 'shipping';

    /**
     * @var string
     */
    protected $_code = 'redjepakketje_standard';

    /**
     * @var array
     */
    protected $quoteItems;

    /**
     * Determines whether or not a shipping method can be shown for the request.
     *
     * @param string $carrier
     * @param RateRequest $request
     * @return boolean
     */
    protected function canShowMethod($carrier, RateRequest $request)
    {
        if ($this->getConfigData('active') &&
            !$this->redJePakketjeHelper->getIsPostcodeExcluded($carrier, $request->getDestPostcode()) &&
            (
                !$this->redJePakketjeHelper->getIsHiddenForBackorders($carrier) ||
                $this->isInStock()
            )
        ) {
            return true;
        }

        return false;
    }

    /**
     * Collects shipping rates.
     *
     * @param RateRequest $request
     * @return Result|boolean
     */
    public function collectRates(RateRequest $request)
    {
        $this->quoteItems = $request->getAllItems();

        $carrier = $this->_code;
        $shippingMethod = self::SHIPPING_METHOD;

        if (!$this->canShowMethod($carrier, $request)) {
            return false;
        }

        /** @var Result $result */
        $result = $this->_rateFactory->create();

        $shippingPrice = $this->getShippingPrice($carrier);

        if ($shippingPrice !== false) {
            $resultMethod = $this->createResultMethod($carrier, $shippingMethod, $shippingPrice);

            if ($request->getFreeShipping()) {
                $resultMethod->setPrice('0.00');
            }

            $result->append($resultMethod);
        }

        return $result;
    }

    /**
     * Get the title for the given shipping method
     *
     * @param string $carrier
     * @return string
     */
    protected function getTitle($carrier)
    {
        if ($this->redJePakketjeHelper->getIsBeforeCutoff($carrier)) {
            $title = $this->getConfigData('title_before_cutoff');
        } else {
            $title = $this->getConfigData('title_after_cutoff');
        }

        if ($this->redJePakketjeHelper->getIsWeekendDay($carrier)) {
            $title = $this->getConfigData('title_weekend');
        }

        if ($this->redJePakketjeHelper->getIsHoliday($carrier)) {
            $title = $this->getConfigData('title_holiday');
        }

        $title = $this->redJePakketjeHelper->replaceVariables($carrier, $title);

        return $title ?: '';
    }

    /**
     * Get the description for the given shipping method
     *
     * @param string $carrier
     * @return string
     */
    protected function getDescription($carrier)
    {
        if ($this->redJePakketjeHelper->getIsBeforeCutoff($carrier)) {
            $description = $this->getConfigData('description_before_cutoff');
        } else {
            $description = $this->getConfigData('description_after_cutoff');
        }

        if ($this->redJePakketjeHelper->getIsWeekendDay($carrier)) {
            $description = $this->getConfigData('description_weekend');
        }

        if ($this->redJePakketjeHelper->getIsHoliday($carrier)) {
            $description = $this->getConfigData('description_holiday');
        }

        $description = $this->redJePakketjeHelper->replaceVariables($carrier, $description);

        return $description ?: '';
    }

    /**
     * Get the price for the given shipping method
     *
     * @param string $carrier
     * @return float
     */
    protected function getShippingPrice($carrier)
    {
        if ($this->redJePakketjeHelper->getIsBeforeCutoff($carrier)) {
            $shippingPrice = $this->getConfigData('price_before_cutoff');
        } else {
            $shippingPrice = $this->getConfigData('price_after_cutoff');
        }

        return $shippingPrice ?: 0;
    }

    /**
     * Check if all products in the quote are in stock
     *
     * @return bool
     */
    protected function isInStock()
    {
        /**
         * @var QuoteItemModel $quoteItem
         */
        foreach ($this->quoteItems as $quoteItem) {
            $product = $quoteItem->getProduct();
            $productStockItem = $product->getExtensionAttributes()->getStockItem();

            if (!$productStockItem) {
                continue;
            }

            $quantityInStock = $productStockItem->getQty() - $quoteItem->getQty();

            if ($quantityInStock < 0) {
                return false;
            }
        }

        return true;
    }

    protected function _doShipmentRequest(\Magento\Framework\DataObject $request)
    {
        // TODO: Implement _doShipmentRequest() method.
    }
}
