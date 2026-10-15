<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Block\Adminhtml\System\Config\Form;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Renders a multiline (`type="textarea"`) config field with the same masking behavior as
 * Magento's own `type="obscure"` (`Obscure` renders `<input type="password">`, which collapses
 * newlines and is wrong for a pasted PEM key).
 *
 * The placeholder matches the pattern `Magento\Config\Model\Config\Backend\Encrypted::
 * beforeSave()` recognizes (`/^\*+$/`) as "unchanged, keep the existing stored value" — saving
 * without touching this field does not wipe the stored private key.
 *
 * @api
 */
class ObscureTextarea extends Field
{
    private const OBSCURED_VALUE = '******';

    /**
     * Replace a stored (encrypted) value with the fixed placeholder before rendering, mirroring
     * {@see \Magento\Framework\Data\Form\Element\Obscure::getEscapedValue()}.
     *
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        if ((string)$element->getValue() !== '') {
            $element->setValue(self::OBSCURED_VALUE);
        }

        return parent::_getElementHtml($element);
    }
}
