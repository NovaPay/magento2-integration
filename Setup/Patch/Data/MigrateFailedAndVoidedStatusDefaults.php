<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Setup\Patch\Data;

use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory as ConfigCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use NovaPay\Payment\Model\Order\StatusCode;
use Psr\Log\LoggerInterface;

/**
 * Moves the Failed / Voided rows of "NovaPay Order Status Mapping" from the OLD shipped defaults
 * (core `canceled` / `closed`) to the new module statuses `novapay_failed` / `novapay_refunded`.
 *
 * Why: saving the admin payment section once writes every status-mapping field to
 * `core_config_data`, including rows the merchant never touched. On installs that did so before
 * the new defaults shipped, `status_failed = canceled` and `status_voided = closed` are stored
 * explicitly, which would keep the new `etc/config.xml` defaults from ever applying. Those values
 * were the shipped defaults, never a deliberate merchant choice, so they are migrated.
 *
 * Only the default-scope row is touched, and only when it still holds exactly the old default.
 * Any other value (a custom status, or an explicit website/store-scope override) is a deliberate
 * choice and left alone. A missing row needs nothing: the new config.xml default applies.
 */
class MigrateFailedAndVoidedStatusDefaults implements DataPatchInterface
{
    /**
     * Config path => [old shipped default, new default].
     */
    private const MIGRATIONS = [
        'payment/novapay/status_failed' => ['canceled', StatusCode::FAILED],
        'payment/novapay/status_voided' => ['closed', StatusCode::REFUNDED],
    ];

    /**
     * @param ConfigCollectionFactory $configCollectionFactory
     * @param WriterInterface $configWriter
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ConfigCollectionFactory $configCollectionFactory,
        private readonly WriterInterface $configWriter,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritdoc
     */
    public function apply(): self
    {
        foreach (self::MIGRATIONS as $path => [$oldDefault, $newDefault]) {
            $savedValue = $this->getDefaultScopeValue($path);
            if ($savedValue !== $oldDefault) {
                continue;
            }

            $this->configWriter->save($path, $newDefault, ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);
            $this->logger->info(
                'NovaPay: migrated the order status mapping from the old shipped default to the new one.',
                ['path' => $path, 'old_value' => $oldDefault, 'new_value' => $newDefault]
            );
        }

        return $this;
    }

    /**
     * The value explicitly stored for `$path` at default scope, or null when there is no row.
     *
     * @param string $path
     * @return string|null
     */
    private function getDefaultScopeValue(string $path): ?string
    {
        $collection = $this->configCollectionFactory->create();
        $collection->addFieldToFilter('scope', ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
        $collection->addFieldToFilter('scope_id', ['eq' => 0]);
        $collection->addFieldToFilter('path', $path);

        /** @var Value $row */
        $row = $collection->getFirstItem();
        if (!$row->getId()) {
            return null;
        }

        return (string)$row->getValue();
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies(): array
    {
        return [AddFailedAndRefundedOrderStatuses::class];
    }

    /**
     * @inheritdoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
