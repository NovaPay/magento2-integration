<?php
/**
 * FraudHandler class.
 * Handles fraud responses.
 * 
 * PHP version 7.X
 * 
 * @category Module
 * @package  Magento2
 * @author   NovaPay <acquiring@novapay.ua>
 * @license  https://github.com/NovaPay/prestashop-integration/blob/master/LICENSE MIT
 * @link     https://novapay.ua/
 */

namespace Novapay\Payment\Gateway\Response;

use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Gateway\Response\HandlerInterface;
use Magento\Sales\Model\Order\Payment;

use InvalidArgumentException;

/**
 * FraudHandler class.
 * Handles fraud responses.
 */
class FraudHandler implements HandlerInterface
{
    const FRAUD_MSG_LIST = 'FRAUD_MSG_LIST';

    /**
     * Handles fraud messages
     * 
     * @todo remove fraud handler
     *
     * @param array $handlingSubject
     * @param array $response
     * @return void
     */
    public function handle(array $handlingSubject, array $response)
    {
        if (!isset($response[self::FRAUD_MSG_LIST]) || !is_array($response[self::FRAUD_MSG_LIST])) {
            return;
        }

        if (!isset($handlingSubject['payment'])
            || !$handlingSubject['payment'] instanceof PaymentDataObjectInterface
        ) {
            throw new InvalidArgumentException('Payment data object should be provided');
        }

        /** @var PaymentDataObjectInterface $paymentDO */
        $paymentDO = $handlingSubject['payment'];
        $payment = $paymentDO->getPayment();

        $payment->setAdditionalInformation(
            self::FRAUD_MSG_LIST,
            (array)$response[self::FRAUD_MSG_LIST]
        );

        /** @var $payment Payment */
        $payment->setIsTransactionPending(true);
        $payment->setIsFraudDetected(true);
    }
}
