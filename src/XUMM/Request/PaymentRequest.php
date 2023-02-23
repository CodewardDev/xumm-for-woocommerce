<?php

namespace XummForWoocomerce\XUMM\Request;

use Xrpl\XummSdkPhp\Payload\CustomMeta;
use Xrpl\XummSdkPhp\Payload\Options;
use Xrpl\XummSdkPhp\Payload\Payload;
use Xrpl\XummSdkPhp\Payload\ReturnUrl;
use Xrpl\XummSdkPhp\XummSdk;
use XummForWoocomerce\XUMM\Traits\XummPaymentGatewayTrait;

class PaymentRequest
{
    use XummPaymentGatewayTrait;

    public function processPayment($orderId)
    {
        $context = $this->getXummPaymentGateway();

        $order = wc_get_order( $orderId );
        $storeCurrency = get_woocommerce_currency();

        $exchangeRateRequest = new ExchangeRateRequest();
        $exchangeRateRequest->setXummPaymentGateway($context);
        $exchangeRates = $exchangeRateRequest->getExchangeRates($storeCurrency, $order->get_total());

        $totalSum = round($exchangeRates['totalSum'], 6);
        $xr = $exchangeRates['xr'];

        $identifier = $orderId . '_' . strtoupper(substr(md5(microtime()), 0, 10));

        $totalSum = round($totalSum, 6);
        $query = [
            'wc-api' => 'XUMM',
            'order_id' => $identifier
        ];

        $return_url = add_query_arg($query, get_home_url());

        $memo = bin2hex('Order id: '.$orderId.', '.__('paid with XUMM', 'xumm-for-woocommerce'));

        $payload = new Payload([
            'TransactionType' => 'Payment',
            'Destination' => $context->destination,
            'Amount' => $context->currencies === 'XRP' ?
                number_format($totalSum * 1000000, 0, '', '')
                :
                [
                    'currency' => $context->currencies,
                    'value' => $totalSum,
                    'issuer' => $context->issuers
                ],
            'Memos' => array(
                0 => array(
                    'Memo' => array(
                        'MemoType' => strtoupper(bin2hex(__('https://xumm.app/', 'xumm-for-woocommerce'))),
                        'MemoData' => strtoupper($memo)
                    )
                )
            ),
            'Flags' => 2147483648
            ], null,
                new Options(
                true, null, 15, null, null,
                    new ReturnUrl(\wp_is_mobile() ? $return_url : null, $return_url)
                ),
            new CustomMeta($identifier, null, [
                'xr' => $xr,
                'base' => $storeCurrency
            ]),
        );

        error_log($totalSum);

        $sdk = new XummSdk($context->api, $context->api_secret);

        $response = $sdk->createPayload($payload);

        if (!empty($response->next->always)) {
            return $response->next->always;
        } else {
            throw new \Exception(__('Got an error from XUMM', 'xumm-for-woocommerce'));
        }
    }
}
