<?php

if (!class_exists('msPaymentInterface')) {
    require_once dirname(dirname(dirname(__FILE__))) . '/model/minishop2/mspaymenthandler.class.php';
}

class Robokassa extends msPaymentHandler implements msPaymentInterface
{
    /** @var array $config */
    public $config = array();


    /**
     * Robokassa constructor.
     *
     * @param xPDOObject $object
     * @param array $config
     */
    function __construct(xPDOObject $object, $config = array())
    {
        parent::__construct($object, $config);

        $siteUrl = $this->modx->getOption('site_url');
        $assetsUrl = $this->modx->getOption('minishop2.assets_url', $config,
            $this->modx->getOption('assets_url') . 'components/minishop2/');
        $paymentUrl = $siteUrl . substr($assetsUrl, 1) . 'payment/robokassa.php';

        $this->config = array_merge(array(
            'paymentUrl' => $paymentUrl,
            'checkoutUrl' => $this->modx->getOption('ms2_payment_rbks_url', null,
                'https://merchant.roboxchange.com/Index.aspx', true
            ),
            'login' => $this->modx->getOption('ms2_payment_rbks_login'),
            'pass1' => $this->modx->getOption('ms2_payment_rbks_pass1'),
            'pass2' => $this->modx->getOption('ms2_payment_rbks_pass2'),
            'currency' => $this->modx->getOption('ms2_payment_rbks_currency', '', true),
            'culture' => $this->modx->getOption('ms2_payment_rbks_culture', 'ru', true),
            'test_mode' => $this->modx->getOption('ms2_payment_rbks_test_mode', true, true),
            'test_pass1' => $this->modx->getOption('ms2_payment_rbks_test_pass1'),
            'test_pass2' => $this->modx->getOption('ms2_payment_rbks_test_pass2'),
            'receipt' => $this->modx->getOption('ms2_payment_rbks_receipt', false, true),
            'json_response' => false,
        ), $config);
    }


    /**
     * @param msOrder $order
     *
     * @return array|string
     */
    public function send(msOrder $order)
    {
        $link = $this->getPaymentLink($order);

        if (!empty($link)) {
            return $this->success('', array('redirect' => $link));
        } else {
            return $this->success('', array('msorder' => $order->get('id')));
        }
    }


    /**
     * @param msOrder $order
     *
     * @return string
     */
    public function getPaymentLink(msOrder $order)
    {
        $id = $order->get('id');
        $sum = number_format($order->get('cost'), 2, '.', '');

        $request = array(
            'MrchLogin' => $this->config['login'],
            'OutSum' => $sum,
            'InvId' => $id,
            'Desc' => $this->modx->lexicon('ms2_order') . ' #' . $id,
            'IncCurrLabel' => $this->config['currency'],
            'Culture' => $this->config['culture'],
        );

        if (!empty($_REQUEST['email'])) {
            $request['Email'] = $_REQUEST['email'];
        }

        if ($this->config['test_mode']) {
            $pass1 = $this->config['test_pass1'];
            $request['IsTest'] = 1;
        } else {
            $pass1 = $this->config['pass1'];
        }

        if ($this->config['receipt']) {
            /** @var msOrderProduct $item */
            $products = $order->getMany('Products');

            foreach ($products as $item) {
                /** @var msProduct $product */
                $name = $item->get('name');
                if (empty($name) && $product = $item->getOne('Product')) {
                    $name = $product->get('pagetitle');
                }

                $items[] = array(
                    'name' => mb_substr(trim($name), 0, 63, 'UTF-8'),
                    'quantity' => $item->get('count'),
                    'sum' => str_replace(',', '.', $item->get('price')),
                    // 'payment_method' => 'full_prepayment',
                    // 'payment_object' => 'commodity',
                    'tax' => 'none'
                );
            }

            /** @var msDelivery $delivery */
            $delivery = $order->getOne('Delivery');

            if ($delivery->get('price') > 0) {
                $items[] = array(
                    'name' => mb_substr(trim($delivery->get('name')), 0, 63, 'UTF-8'),
                    'quantity' => 1,
                    'sum' => $delivery->get('price'),
                    // 'payment_method' => 'full_prepayment',
                    // 'payment_object' => 'commodity',
                    'tax' => 'none'
                );
            }

            $receipt = json_encode(array(
                //'sno' => 'osn',
                'items' => $items
            ));

            $request['Receipt'] = $receipt;
            $request['SignatureValue'] = md5($this->config['login'] . ':' . $sum . ':' . $id . ':' . $receipt . ':' . $pass1);
        } else {
            $request['SignatureValue'] = md5($this->config['login'] . ':' . $sum . ':' . $id . ':' . $pass1);
        }

        //$link = $this->config['checkoutUrl'] . '?' . http_build_query($request);

        $response = $this->request($request);

        if (is_array($response) && !empty($response['redirect_url'])) {
            $link = $response['redirect_url'];
        } else {
            $this->modx->log(modX::LOG_LEVEL_ERROR,
                '[miniShop2] Payment error while request. Response: ' . print_r($response['error'], 1));

            $link = '';
        }

        return $link;
    }


    /**
     * Building query
     *
     * @param array $params Query params
     *
     * @return array/boolean
     */
    public function request($params = array())
    {
        $request = http_build_query($params);
        $curlOptions = array(
            CURLOPT_URL => $this->config['checkoutUrl'],
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => $request,
        );

        $ch = curl_init();
        curl_setopt_array($ch, $curlOptions);

        curl_exec($ch);

        if (curl_errno($ch)) {
            $result['error'] = curl_error($ch);
        } else {
            $result['redirect_url'] = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        }

        curl_close($ch);

        return $result;
    }


    /**
     * @param msOrder $order
     * @param array $params
     *
     * @return void
     */
    public function answer(msOrder $order, $params = array())
    {
        $id = $order->get('id');
        $crc = strtoupper($params['SignatureValue']);

        if ($this->config['test_mode']) {
            $pass2 = $this->config['test_pass2'];
            $this->paymentDebag('Debag: Stage `result`', $params);
        } else {
            $pass2 = $this->config['pass2'];
        }

        // Production
        $sum1 = number_format($order->get('cost'), 6, '.', '');
        $crc1 = strtoupper(md5($sum1 . ':' . $id . ':' . $pass2));
        // Test
        $sum2 = number_format($order->get('cost'), 2, '.', '');
        $crc2 = strtoupper(md5($sum2 . ':' . $id . ':' . $pass2));

        if ($crc == $crc1 || $crc == $crc2) {
            /** @var miniShop2 $miniShop2 */
            $miniShop2 = $this->modx->getService('miniShop2');
            @$this->modx->context->key = 'mgr';
            $miniShop2->changeOrderStatus($order->get('id'), 2);
            exit('OK' . $id);
        } else {
            $this->paymentError('Err: wrong signature.', $params);
        }
    }


    /**
     * @param msOrder $order
     * @param array $params
     *
     * @return void
     */
    public function receive(msOrder $order, $params = array())
    {
		$id = $order->get('id');
        $crc = strtoupper($params['SignatureValue']);

		if ($this->config['test_mode']) {
            $pass1 = $this->config['test_pass1'];
            $this->paymentDebag('Debag: Stage `success`', $params);
        } else {
            $pass1 = $this->config['pass1'];
        }

        // Test
        $sum1 = number_format($order->get('cost'), 2, '.', '');
        $crc1 = strtoupper(md5($sum1 . ':' . $id . ':' . $pass1));

		if ($crc != $crc1) {
			$this->paymentError('Err: wrong signature.', $params);
		}
	}


    /**
     * @param $text
     * @param array $request
     */
    public function paymentError($text, $request = array())
    {
        $this->modx->log(modX::LOG_LEVEL_ERROR,
            '[miniShop2:Robokassa] ' . $text . ', request: ' . print_r($request, 1));
        header("HTTP/1.0 400 Bad Request");

        die('ERR: ' . $text);
    }


    /**
     * @param $text
     * @param array $request
     */
    public function paymentDebag($text, $request = array())
    {
        $this->modx->log(modX::LOG_LEVEL_DEBUG,
            '[miniShop2:Robokassa] ' . $text . ', request: ' . print_r($request, 1));
    }
}