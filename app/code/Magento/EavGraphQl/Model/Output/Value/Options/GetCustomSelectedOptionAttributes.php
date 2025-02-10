<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\EavGraphQl\Model\Output\Value\Options;

use Magento\Eav\Model\AttributeRepository;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Custom attribute value provider for customer
 */
class GetCustomSelectedOptionAttributes implements GetAttributeSelectedOptionInterface
{
    private AttributeRepository $attributeRepository;
    private StoreManagerInterface $storeManager;
    private array $optionsCache = [];

    public function __construct(
        AttributeRepository $attributeRepository,
        StoreManagerInterface $storeManager
    ) {
        $this->attributeRepository = $attributeRepository;
        $this->storeManager = $storeManager;
    }

    /**
     * @inheritDoc
     */
    public function execute(string $entity, string $code, string $value): ?array
    {
        \Magento\Framework\Profiler::start('Magento_EavGraphQl::GetCustomSelectedOptionAttributes::execute');
        $result = [];
        $selectedValues = explode(',', $value);
        $options = $this->getAttributeOptions($entity, $code);
        foreach ($selectedValues as $selectedValue) {
            if (isset($options[$selectedValue])) {
                $result[] = [
                    'value' => $selectedValue,
                    'label' => $options[$selectedValue],
                ];
            }
        }
        \Magento\Framework\Profiler::stop('Magento_EavGraphQl::GetCustomSelectedOptionAttributes::execute');

        return $result;
    }

    /**
     * Get cached attribute options
     *
     * @param string $entity
     * @param string $code
     * @return array
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    private function getAttributeOptions(string $entity, string $code): array
    {
        \Magento\Framework\Profiler::start(
            'Magento_EavGraphQl::GetCustomSelectedOptionAttributes::getAttributeOptions'
        );
        $attribute = $this->attributeRepository->get($entity, $code);
        $storeId = $attribute->getStoreId();
        if ($storeId === null) {
            $storeId = $this->storeManager->getStore()->getId();
        }

        if (!isset($this->optionsCache[$entity][$storeId][$code])) {
            $options = $attribute->getOptions();
            $optionsLabel = [];
            foreach ($options as $option) {
                $optionsLabel[$option->getValue()] = $option->getLabel();
            }
            $this->optionsCache[$entity][$storeId][$code] = $optionsLabel;
        }
        \Magento\Framework\Profiler::stop(
            'Magento_EavGraphQl::GetCustomSelectedOptionAttributes::getAttributeOptions'
        );

        return $this->optionsCache[$entity][$storeId][$code];
    }
}
