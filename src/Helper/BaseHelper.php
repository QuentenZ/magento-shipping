<?php

namespace RedJePakketje\Shipping\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\Config\ScopeConfigInterface;
use RedJePakketje\Shipping\Model\Config\Source\Weekdays;

class BaseHelper extends AbstractHelper
{
    const TYPE_DEBUG = 0;
    const TYPE_ERROR = 1;

    /**
     * @var ProductMetadataInterface
     */
    private $productMetaData;

    /**
     * @var TimezoneInterface
     */
    private $timezone;

    /**
     * @var JsonSerializer
     */
    private $serializer;

    /**
     * @var BackendUrl
     */
    private $backendUrl;

    /**
     * @param Context $context
     * @param ProductMetadataInterface $productMetadata
     * @param TimezoneInterface $timezone
     * @param JsonSerializer $serializer
     * @param BackendUrl $backendUrl
     */
    public function __construct(
        Context $context,
        ProductMetadataInterface $productMetadata,
        TimezoneInterface $timezone,
        JsonSerializer $serializer,
        BackendUrl $backendUrl
    ) {
        parent::__construct($context);

        $this->productMetaData = $productMetadata;
        $this->timezone = $timezone;
        $this->serializer = $serializer;
        $this->backendUrl = $backendUrl;
    }

    /**
     * Get the configuration for the given path (and optionally for a given scope)
     *
     * @param string $path
     * @param string $scopeType
     * @param mixed|null $scopeCode
     * @return mixed
     */
    public function getConfiguration($path, $scopeType = ScopeConfigInterface::SCOPE_TYPE_DEFAULT, $scopeCode = null)
    {
        return $this->scopeConfig->getValue($path, $scopeType, $scopeCode);
    }

    /**
     * Get the date in the given format for the current store's timezone
     *
     * @param null $format
     * @param string $modify
     * @return string
     */
    public function getTimezoneDate($format = null, $modify = null)
    {
        $date = $this->timezone->date();

        if ($modify) {
            $date->modify($modify);
        }

        return $date->format($format);
    }

    /**
     * Check if the auto generation of labels is enabled
     *
     * @return bool
     */
    public function getIsAutoGenerateEnabled()
    {
        return $this->getConfiguration("redjepakketje_label_configuration/general/auto_generate");
    }

    /**
     * Check if the current store's time is before the configured cut-off time
     *
     * @param string $carrier
     * @return bool
     */
    public function getIsBeforeCutoff($carrier)
    {
        $now = $this->getTimezoneDate('His');
        $cutoff = $this->getConfiguration(sprintf("carriers/%s/cutoff_time", $carrier));
        $cutoff = str_replace(',', '', $cutoff);

        return $cutoff > $now;
    }

    /**
     * Check if today is a configured weekend day
     *
     * @param string $carrier
     * @return bool
     */
    public function getIsWeekendDay($carrier)
    {
        $dayOfTheWeek = $this->getTimezoneDate('w');
        $weekendDays = $this->getConfiguration(sprintf("carriers/%s/weekend_days", $carrier));
        $weekendDays = explode(',', $weekendDays);

        if (!in_array($dayOfTheWeek, $weekendDays) && !$this->getIsBeforeCutoff($carrier)) {
            // Check if the next day is a weekend day
            $dayOfTheWeek = $this->getTimezoneDate('w', '+1day');
        }

        return in_array($dayOfTheWeek, $weekendDays);
    }

    /**
     * Check if today is a configured holiday
     *
     * @param string $carrier
     * @return bool
     */
    public function getIsHoliday($carrier)
    {
        $date = $this->getTimezoneDate('m-d');
        $holidays = $this->getConfiguration(sprintf("carriers/%s/holidays", $carrier)) ?? '[]';
        $holidays = $this->serializer->unserialize($holidays);

        foreach ($holidays as $holiday) {
            // Strip the holiday date of the year (not important)
            if(isset($holiday['date']) && substr($holiday['date'], 5, 5) === $date) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if today is a configured pickup day
     *
     * @param string $carrier
     * @return bool
     */
    public function getIsPickupDay($carrier)
    {
        $dayOfTheWeek = $this->getTimezoneDate('w');
        $pickupDays = $this->getConfiguration(sprintf("carriers/%s/pickup_days", $carrier));
        $pickupDays = explode(',', $pickupDays);

        return in_array($dayOfTheWeek, $pickupDays);
    }

    /**
     * Check if the given postcode is excluded
     *
     * @param string $carrier
     * @param string $postcode
     * @return bool
     */
    public function getIsPostcodeExcluded($carrier, $postcode)
    {
        if ($excludedPostcodes = $this->getConfiguration(sprintf("carriers/%s/excluded_postcodes", $carrier))) {
            $excludedPostcodes = explode(',', $excludedPostcodes);

            if (empty($excludedPostcodes) || !in_array($postcode, $excludedPostcodes)) {
                return false;
            }

            return true;
        }

        return false;
    }

    /**
     * Check if the shipping method should be hidden for backorders
     *
     * @param string $carrier
     * @return bool
     */
    public function getIsHiddenForBackorders($carrier)
    {
        return $this->getConfiguration(sprintf("carriers/%s/hide_for_backorders", $carrier));
    }

    /**
     * Get the configured product type for the given shipping method
     *
     * @param string $carrier
     * @return string
     */
    public function getProductType($carrier)
    {
        // @TODO: Make this configurable
        return 'sameday_parcel_standard';
    }

    /**
     * Get the configured product options for the given shipping method
     *
     * @param string $carrier
     * @return array
     */
    public function getProductOptions($carrier)
    {
        // @TODO: Make this configurable
        return [];
    }

    /**
     * Replace variables with a configured value
     *
     * @param string $carrier
     * @param string $string
     * @return string
     */
    public function replaceVariables($carrier, $string)
    {
        if (strpos($string, 'cutoff') !== false) {
            if ($cutoffTime = $this->getConfiguration(sprintf("carriers/%s/cutoff_time", $carrier))) {
                $cutoffTime = str_replace(',', ':', $cutoffTime);

                $string = str_replace('%cutoff', $cutoffTime, $string);
            }
        }

        if (strpos($string, 'weekend_days') !== false) {
            if ($weekendDays = $this->getConfiguration(sprintf("carriers/%s/weekend_days", $carrier))) {
                foreach (explode(',', $weekendDays) as $weekendDay) {
                    $weekendDays = str_replace($weekendDay, Weekdays::getLabelForOption($weekendDay), $weekendDays);
                }

                $weekendDays = str_replace(',', ', ', $weekendDays);

                $string = str_replace('%weekend_days', $weekendDays, $string);
            }
        }

        if (strpos($string, 'holiday') !== false) {
            if ($holidays = $this->getConfiguration(sprintf("carriers/%s/holidays", $carrier))) {
                $date = $this->getTimezoneDate('m-d');
                $holidays = $this->serializer->unserialize($holidays);

                if ($this->getIsHoliday($carrier)) {
                    foreach ($holidays as $holiday) {
                        if(isset($holiday['date']) && substr($holiday['date'], 5, 5) === $date) {
                            $string = str_replace('%holiday', $holiday['description'], $string);
                            break;
                        }
                    }
                }
            }
        }

        return $string;
    }

    /**
     * Get a backend url for the given path with the given params
     *
     * @param string $path
     * @param array $params
     * @return string
     */
    public function getBackendUrl($path, $params)
    {
        return $this->backendUrl->getUrl($path, $params);
    }

    /**
     * Get the current Magento version
     *
     * @return string
     */
    public function getMagentoVersion()
    {
        return $this->productMetaData->getVersion();
    }

    /**
     * Log the given message
     *
     * @param string $type
     * @param string $message
     * @param string $file
     * @param int $line
     */
    public function log($type, $message, $file, $line)
    {
        if ($type === self::TYPE_DEBUG) {
            $debugMessage = sprintf("Debugging in %s on on line %s: %s", $message, $file, $line);
            $this->_logger->debug($debugMessage);
        } else {
            $errorMessage = sprintf("An error occured in %s on line %s: %s", $file, $line, $message);
            $this->_logger->error($errorMessage);
        }
    }
}
