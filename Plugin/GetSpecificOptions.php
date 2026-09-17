<?php
declare(strict_types = 1);

namespace Blackbird\EavOptimize\Plugin;

use Blackbird\EavOptimize\Model\Config;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\Source\Table;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\StoreManagerInterface;

class GetSpecificOptions
{
    /**
     * EAV cache id
     */
    public const ATTRIBUTE_OPTION_TEXT = 'ATTRIBUTE_OPTION_TEXT';

    /**
     * @var EavConfig
     */
    protected EavConfig $eavConfig;

    /**
     * Specific Options array memoization
     *
     * @var array
     */
    protected array $_specificOptions = [];

    /**
     * @var Json
     */
    protected Json $json;

    /**
     * @var Config
     */
    protected Config $config;

    /**
     * @var StoreManagerInterface
     */
    protected StoreManagerInterface $storeManager;

    public function __construct(
        EavConfig $eavConfig,
        Config $config,
        Json $json,
        StoreManagerInterface $storeManager
    ) {
        $this->eavConfig = $eavConfig;
        $this->config    = $config;
        $this->json      = $json;
        $this->storeManager = $storeManager;
    }

    /**
     * @param Table        $subject
     * @param callable     $proceed
     * @param array|string $ids
     * @param bool         $withEmpty
     *
     * @return array
     */
    public function aroundGetSpecificOptions(Table $subject, callable $proceed, $ids, $withEmpty): array
    {
        $storeId          = $subject->getAttribute()->getStoreId();
        $attributeId      = $subject->getAttribute()->getId();
        $optionsIdsToLoad = [];
        $options          = [];

        $optionIds = is_array($ids) ? $ids : [$ids];

        //Get option from runtime memory or try to load
        foreach ($optionIds as $optionId) {
            if (
                $optionId === null ||
                !isset($this->_specificOptions[$storeId][$attributeId][$optionId])
            ) {
                $optionsIdsToLoad[] = $optionId;
            } else {
                $options[] = $this->_specificOptions[$storeId][$attributeId][$optionId];
            }
        }

        if (!empty($optionsIdsToLoad)) {
            //Call parent method to force loading options, the save in EAV cache
            $cacheKey = $this->getCacheKey($attributeId,$storeId, $optionsIdsToLoad);

            if ($this->isCacheOptionEnable() && ($loadedOptionsCache = $this->eavConfig->getCache()->load(
                    $cacheKey))) {
                $loadedOptions = $this->json->unserialize($loadedOptionsCache);
            } else {
                $loadedOptions = $proceed(
                    $ids,
                    false);

                //save result in cache
                if ($this->isCacheOptionEnable()) {
                    $this->saveLoadedOptionsToCache(
                        $loadedOptions,
                        $cacheKey);
                }
            }

            //Merge loaded options for already loaded options
            array_walk(
                $loadedOptions,
                function ($loadedOption) use ($storeId, $attributeId, &$options)
                {
                    $this->_specificOptions[$storeId][$attributeId][$loadedOption['value']] = $loadedOption;
                    $options[]                                                              = $loadedOption;
                });
        }

        //Add empty options if required
        if ($withEmpty) {
            $options = $this->addEmptyOption($options);
        }

        return $options;
    }

    /**
     * Add an empty option to the array
     *
     * @param array $options
     *
     * @return array
     */
    private function addEmptyOption(array $options): array
    {
        array_unshift(
            $options,
            ['label' => ' ', 'value' => '']);

        return $options;
    }

    /**
     * @return bool
     */
    private function isCacheOptionEnable(): bool
    {
        return $this->eavConfig->isCacheEnabled() && $this->config->getCacheOptionValues();
    }

    /**
     * @param mixed $attributeId
     * @param       $storeId
     * @param array $optionsIdsToLoad
     *
     * @return string
     */
    protected function getCacheKey(mixed $attributeId, $storeId, array $optionsIdsToLoad): string
    {
        if ($storeId === null) {
            try {
                $storeId = $this->storeManager->getStore()->getId();
            } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                $storeId = 0;
            }
        }

        return implode(
            '-',
            [
                self::ATTRIBUTE_OPTION_TEXT,
                $attributeId,
                $storeId,
                implode(
                    ',',
                    $optionsIdsToLoad)
            ]);
    }

    /**
     * @param        $loadedOptions
     * @param string $cacheKey
     *
     * @return void
     */
    protected function saveLoadedOptionsToCache($loadedOptions, string $cacheKey): void
    {
        $this->eavConfig->getCache()->save(
            $this->json->serialize($loadedOptions),
            $cacheKey,
            [
                \Magento\Eav\Model\Cache\Type::CACHE_TAG,
                \Magento\Eav\Model\Entity\Attribute::CACHE_TAG
            ]
        );
    }
}
