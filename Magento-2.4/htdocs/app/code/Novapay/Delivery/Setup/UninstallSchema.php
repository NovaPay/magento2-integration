<?php
namespace Novapay\Delivery\Setup;

use Magento\Framework\Setup\UninstallInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\DB\Ddl\Table;
/**
 * @codeCoverageIgnore
 */
class UninstallSchema implements UninstallInterface
{
    /**
     * Invoked when remove-data flag is set during module uninstall.
     *
     * @param SchemaSetupInterface $setup
     * @param ModuleContextInterface $context
     * @return void
     */
    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $installer = $setup;
        $installer->startSetup();

        // $this->removeFromQuote($installer);
        // $this->removeFromOrder($installer);
        $this->removeFromOrderGrid($installer);

        $installer->endSetup();
    }

    /**
     * Remove columns from quote table.
     *
     * @param SchemaSetupInterface $installer
     * 
     * @return void
     */
    protected function removeFromQuote(SchemaSetupInterface $installer)
    {
        $table = 'quote';
        $columns = [
            'warehouse_ref',
            'warehouse_title',
            'city_ref',
            'city_title'
        ];
        $this->dropColumns($installer, $table, $columns);
    }

    /**
     * Remove columns from quote table.
     *
     * @param SchemaSetupInterface $installer
     * 
     * @return void
     */
    protected function removeFromOrder(SchemaSetupInterface $installer)
    {
        $table = 'sales_order';
        $columns = [
            'warehouse_ref',
            'warehouse_title',
            'city_ref',
            'city_title'
        ];
        $this->dropColumns($installer, $table, $columns);
    }

    /**
     * Remove columns from quote table.
     *
     * @param SchemaSetupInterface $installer
     * 
     * @return void
     */
    protected function removeFromOrderGrid(SchemaSetupInterface $installer)
    {
        $table = 'sales_order_grid';
        $columns = [
            'warehouse_ref',
            'warehouse_title',
            'city_ref',
            'city_title'
        ];
        $this->dropColumns($installer, $table, $columns);
    }
    
    /**
     * @param Magento\Framework\DB\Adapter\AdapterInterface $connection
     * @param string                                        $table
     * @param array                                         $columns
     * 
     * @return [type]
     */
    protected function dropColumns(SchemaSetupInterface $installer, $table, $columns)
    {
        $connection = $installer->getConnection();
        foreach ($columns as $column) {
            if ($connection->tableColumnExists($table, $column) === false) {
                continue;
            }
            $installer->getConnection()->dropColumn(
                $installer->getTable($table),
                $column
            );
        }
    }
}