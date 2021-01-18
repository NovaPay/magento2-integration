<?php
namespace Novapay\Delivery\Setup;

use Magento\Framework\Setup\InstallSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\DB\Ddl\Table;
/**
 * @codeCoverageIgnore
 */
class InstallSchema implements InstallSchemaInterface
{
    /**
     * {@inheritDoc}
     */
    public function install(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $installer = $setup;
        $installer->startSetup();

        $connection = $installer->getConnection();

        $table = $setup->getConnection()->newTable(
            $setup->getTable('novapay_delivery')
        )->addColumn(
            'id',
            Table::TYPE_INTEGER,
            null,
            [
                'identity' => true,
                'unsigned' => true,
                'nullable' => false,
                'primary' => true],
            'ID'
        )->addColumn(
            'name',
            Table::TYPE_TEXT,
            255,
            [],
            'Name'
        )->addColumn(
            'firstname',
            Table::TYPE_TEXT,
            255,
            [],
            'FirstName'
        )->addColumn(
            'created_at',
            Table::TYPE_TIMESTAMP,
            null,
            [
                'nullable' => false,
                'default' => Table::TIMESTAMP_INIT],
            'Created date'
        )->addColumn(
            'updated_at',
            Table::TYPE_TIMESTAMP,
            null,
            [
                'nullable' => false,
                'default' => Table::TIMESTAMP_INIT],
            'Updated date'
        )->addIndex(
            $setup->getIdxName('tablename', ['id']),
            ['id']
        )->setComment(
            'table related comments'
        );
        $connection->createTable($table);

        if ($connection->tableColumnExists('quote', 'warehouse_ref') === false) {
            $connection->addColumn(
                $installer->getTable('quote'),
                'warehouse_ref',
                [
                    'type' => Table::TYPE_TEXT,
                    'nullable' => false,
                    'comment' => 'Warehouse Reference ID',
                ]
            );
        }

        $this->addToQuote($installer);
        $this->addToOrder($installer);
        $this->addToOrderGrid($installer);
        $installer->endSetup();
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
        $connection = $installer->getConnection();
        if ($connection->tableColumnExists('quote', 'warehouse_ref') === false) {
            $connection->addColumn(
                $installer->getTable('quote'),
                'warehouse_ref',
                [
                    'type' => Table::TYPE_TEXT,
                    'nullable' => false,
                    'comment' => 'Warehouse Reference ID',
                ]
            );
        }

        if ($connection->tableColumnExists('quote', 'warehouse_title') === false) {
            $connection->addColumn(
                $installer->getTable('quote'),
                'warehouse_title',
                [
                    'type' => Table::TYPE_TEXT,
                    'nullable' => false,
                    'comment' => 'Warehouse Title',
                ]
            );
        }

        if ($connection->tableColumnExists('quote', 'city_ref') === false) {
            $connection->addColumn(
                $installer->getTable('quote'),
                'city_ref',
                [
                    'type' => Table::TYPE_TEXT,
                    'nullable' => false,
                    'comment' => 'City Reference ID',
                ]
            );
        }

        if ($connection->tableColumnExists('quote', 'city_title') === false) {
            $connection->addColumn(
                $installer->getTable('quote'),
                'city_title',
                [
                    'type' => Table::TYPE_TEXT,
                    'nullable' => false,
                    'comment' => 'City Title',
                ]
            );
        }
    }
    
    protected function addToOrder(SchemaSetupInterface $installer)
    {
        $installer->getConnection()->addColumn(
            $installer->getTable('sales_order'),
            'warehouse_ref',
            [
                'type' => Table::TYPE_TEXT,
                'nullable' => false,
                'comment' => 'Warehouse Reference ID',
            ]
        );
        $installer->getConnection()->addColumn(
            $installer->getTable('sales_order'),
            'warehouse_title',
            [
                'type' => Table::TYPE_TEXT,
                'nullable' => false,
                'comment' => 'Warehouse Title',
            ]
        );
        $installer->getConnection()->addColumn(
            $installer->getTable('sales_order'),
            'city_ref',
            [
                'type' => Table::TYPE_TEXT,
                'nullable' => false,
                'comment' => 'City Reference ID',
            ]
        );
        $installer->getConnection()->addColumn(
            $installer->getTable('sales_order'),
            'city_title',
            [
                'type' => Table::TYPE_TEXT,
                'nullable' => false,
                'comment' => 'City Title',
            ]
        );
    }

    protected function addToOrderGrid(SchemaSetupInterface $installer)
    {
        // no need to add any columns to sales grid.
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