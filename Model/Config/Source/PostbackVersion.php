<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * "Postback Version" admin config source. Must match the postback version
 * configured on the merchant's NovaPay admin account. Mismatch detection/surfacing between the
 * two is not implemented.
 */
class PostbackVersion implements OptionSourceInterface
{
    public const VERSION_V1 = 'v1';
    public const VERSION_V2 = 'v2';
    public const VERSION_V3 = 'v3';

    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::VERSION_V1, 'label' => __('v1')],
            ['value' => self::VERSION_V2, 'label' => __('v2')],
            ['value' => self::VERSION_V3, 'label' => __('v3')],
        ];
    }
}
