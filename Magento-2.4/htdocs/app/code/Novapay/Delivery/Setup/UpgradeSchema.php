<?php
namespace Novapay\Delivery\Setup;

use Magento\Framework\Setup\UpgradeSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Novapay\Payment\SDK\Schema\Schema;

/**
 * @codeCoverageIgnore
 */
class UpgradeSchema implements UpgradeSchemaInterface
{

    /**
     * {@inheritdoc}
     */
    public function upgrade(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $setup->startSetup();
        if (version_compare($context->getVersion(), '1.0.0', '>=')) {
            $this->addToQuote($setup);
            $this->addToOrder($setup);
        }
        $setup->endSetup();
        // remove on uninstall?
        // $setup->getConnection()->dropColumn($setup->getTable('quote'), 'warehouse_ref');
    }

    /**
     * Adds columns to quote table.
     *
     * @param SchemaSetupInterface $installer
     * 
     * @return void
     */
    protected function addToQuote(SchemaSetupInterface $installer)
    {
        $installer->getConnection()->addColumn(
            $installer->getTable('quote'),
            'warehouse_ref',
            [
                'type' => 'text',
                'nullable' => false,
                'comment' => 'Warehouse Reference ID',
            ]
        );

        $installer->getConnection()->addColumn(
            $installer->getTable('quote'),
            'warehouse_title',
            [
                'type' => 'text',
                'nullable' => false,
                'comment' => 'Warehouse Title',
            ]
        );

        $installer->getConnection()->addColumn(
            $installer->getTable('quote'),
            'city_ref',
            [
                'type' => 'text',
                'nullable' => false,
                'comment' => 'City Reference ID',
            ]
        );

        $installer->getConnection()->addColumn(
            $installer->getTable('quote'),
            'city_title',
            [
                'type' => 'text',
                'nullable' => false,
                'comment' => 'City Title',
            ]
        );
    }
    
    protected function addToOrder(SchemaSetupInterface $installer)
    {
        $installer->getConnection()->addColumn(
            $installer->getTable('sales_order'),
            'warehouse_ref',
            [
                'type' => 'text',
                'nullable' => false,
                'comment' => 'Warehouse Reference ID',
            ]
        );

        $installer->getConnection()->addColumn(
            $installer->getTable('sales_order'),
            'warehouse_title',
            [
                'type' => 'text',
                'nullable' => false,
                'comment' => 'Warehouse Title',
            ]
        );

        $installer->getConnection()->addColumn(
            $installer->getTable('sales_order'),
            'city_ref',
            [
                'type' => 'text',
                'nullable' => false,
                'comment' => 'City Reference ID',
            ]
        );

        $installer->getConnection()->addColumn(
            $installer->getTable('sales_order'),
            'city_title',
            [
                'type' => 'text',
                'nullable' => false,
                'comment' => 'City Title',
            ]
        );

        // only Warehouse title is needed in the orders list!
        $installer->getConnection()->addColumn(
            $installer->getTable('sales_order_grid'),
            'warehouse_ref',
            [
                'type' => Table::TYPE_TEXT,
                'nullable' => false,
                'comment' => 'Warehouse Reference ID',
            ]
        );

        $installer->getConnection()->addColumn(
            $installer->getTable('sales_order_grid'),
            'warehouse_title',
            [
                'type' => Table::TYPE_TEXT,
                'nullable' => false,
                'comment' => 'Warehouse Title',
            ]
        );

        $installer->getConnection()->addColumn(
            $installer->getTable('sales_order_grid'),
            'city_ref',
            [
                'type' => Table::TYPE_TEXT,
                'nullable' => false,
                'comment' => 'City Reference ID',
            ]
        );

        $installer->getConnection()->addColumn(
            $installer->getTable('sales_order_grid'),
            'city_title',
            [
                'type' => Table::TYPE_TEXT,
                'nullable' => false,
                'comment' => 'City Title',
            ]
        );
    }
}