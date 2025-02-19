<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Sitemap\Model\ResourceModel\Catalog;

use Magento\CatalogUrlRewrite\Model\CategoryUrlRewriteGenerator;
use Magento\Framework\App\ObjectManager;

/**
 * Sitemap resource catalog collection model
 *
 * @api
 * @since 100.0.2
 */
class Category extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb
{
    /**
     * Collection Zend Db select
     *
     * @var \Magento\Framework\DB\Select
     */
    protected $_select;

    /**
     * Attribute cache
     *
     * @var array
     */
    protected $_attributesCache = [];

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $_storeManager;

    /**
     * @var \Magento\Catalog\Model\ResourceModel\Category
     */
    protected $_categoryResource;

    /**
     * @var \Magento\Framework\EntityManager\MetadataPool
     * @since 100.1.0
     */
    protected $metadataPool;

    /**
     * @var CategorySelectBuilder
     */
    private $categorySelectBuilder;

    /**
     * @param \Magento\Framework\Model\ResourceModel\Db\Context $context
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Catalog\Model\ResourceModel\Category $categoryResource
     * @param \Magento\Framework\EntityManager\MetadataPool $metadataPool
     * @param string $connectionName
     * @param CategorySelectBuilder|null $categorySelectBuilder
     */
    public function __construct(
        \Magento\Framework\Model\ResourceModel\Db\Context $context,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Catalog\Model\ResourceModel\Category $categoryResource,
        \Magento\Framework\EntityManager\MetadataPool $metadataPool,
        $connectionName = null,
        ?CategorySelectBuilder $categorySelectBuilder = null
    ) {
        $this->_storeManager = $storeManager;
        $this->_categoryResource = $categoryResource;
        parent::__construct($context, $connectionName);
        $this->metadataPool = $metadataPool;
        $this->categorySelectBuilder = $categorySelectBuilder ??
            ObjectManager::getInstance()->get(CategorySelectBuilder::class);
    }

    /**
     * Initialize catalog category entity resource model
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init('catalog_category_entity', 'entity_id');
    }

    /**
     * Get category collection array
     *
     * @param null|string|bool|int|\Magento\Store\Model\Store $storeId
     * @return array|bool
     */
    public function getCollection($storeId)
    {
        $categories = [];

        /* @var $store \Magento\Store\Model\Store */
        $store = $this->_storeManager->getStore($storeId);

        if (!$store) {
            return false;
        }

        $connection = $this->getConnection();

        $this->_select = $connection->select()->from(
            $this->getMainTable()
        )->where(
            $this->getIdFieldName() . '=?',
            $store->getRootCategoryId()
        );
        $categoryRow = $connection->fetchRow($this->_select);

        if (!$categoryRow) {
            return false;
        }

        $this->_select = $this->categorySelectBuilder->execute(
            $this->getMainTable(),
            $this->getIdFieldName(),
            $store,
            $categoryRow['path']
        );

        $this->_addFilter($storeId, 'is_active', 1);

        $query = $connection->query($this->_select);

        while ($row = $query->fetch()) {
            $category = $this->_prepareCategory($row);
            $categories[$category->getId()] = $category;
        }

        return $categories;
    }

    /**
     * Prepare category
     *
     * @param array $categoryRow
     * @return \Magento\Framework\DataObject
     */
    protected function _prepareCategory(array $categoryRow)
    {
        $category = new \Magento\Framework\DataObject();
        $category->setId($categoryRow[$this->getIdFieldName()]);
        $categoryUrl = !empty($categoryRow['url']) ? $categoryRow['url'] : 'catalog/category/view/id/' .
            $category->getId();
        $category->setUrl($categoryUrl);
        $category->setUpdatedAt($categoryRow['updated_at']);
        return $category;
    }

    /**
     * Add attribute to filter
     *
     * @param int $storeId
     * @param string $attributeCode
     * @param mixed $value
     * @param string $type
     * @return \Magento\Framework\DB\Select|bool
     */
    protected function _addFilter($storeId, $attributeCode, $value, $type = '=')
    {
        $meta = $this->metadataPool->getMetadata(\Magento\Catalog\Api\Data\CategoryInterface::class);
        $linkField = $meta->getLinkField();

        if (!$this->_select instanceof \Magento\Framework\DB\Select) {
            return false;
        }

        if (!isset($this->_attributesCache[$attributeCode])) {
            $attribute = $this->_categoryResource->getAttribute($attributeCode);

            $this->_attributesCache[$attributeCode] = [
                'entity_type_id' => $attribute->getEntityTypeId(),
                'attribute_id' => $attribute->getId(),
                'table' => $attribute->getBackend()->getTable(),
                'is_global' => $attribute->getIsGlobal(),
                'backend_type' => $attribute->getBackendType(),
            ];
        }
        $attribute = $this->_attributesCache[$attributeCode];

        switch ($type) {
            case '=':
                $conditionRule = '=?';
                break;
            case 'in':
                $conditionRule = ' IN(?)';
                break;
            default:
                return false;
        }

        if ($attribute['backend_type'] == 'static') {
            $this->_select->where('e.' . $attributeCode . $conditionRule, $value);
        } else {
            $this->_select->join(
                ['t1_' . $attributeCode => $attribute['table']],
                'e.' . $linkField . ' = t1_' . $attributeCode . '.' . $linkField .
                ' AND t1_' . $attributeCode . '.store_id = 0',
                []
            )->where(
                't1_' . $attributeCode . '.attribute_id=?',
                $attribute['attribute_id']
            );

            if ($attribute['is_global']) {
                $this->_select->where('t1_' . $attributeCode . '.value' . $conditionRule, $value);
            } else {
                $ifCase = $this->getConnection()->getCheckSql(
                    't2_' . $attributeCode . '.value_id > 0',
                    't2_' . $attributeCode . '.value',
                    't1_' . $attributeCode . '.value'
                );
                $this->_select->joinLeft(
                    ['t2_' . $attributeCode => $attribute['table']],
                    $this->getConnection()->quoteInto(
                        't1_' .
                        $attributeCode .
                        '.' . $linkField . ' = t2_' .
                        $attributeCode .
                        '.' . $linkField . ' AND t1_' .
                        $attributeCode .
                        '.attribute_id = t2_' .
                        $attributeCode .
                        '.attribute_id AND t2_' .
                        $attributeCode .
                        '.store_id=?',
                        $storeId
                    ),
                    []
                )->where(
                    '(' . $ifCase . ')' . $conditionRule,
                    $value
                );
            }
        }

        return $this->_select;
    }
}
