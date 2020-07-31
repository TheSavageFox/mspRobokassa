<?php
define('MODX_API_MODE', true);
require dirname(dirname(dirname(dirname(dirname(__FILE__))))) . '/index.php';

$modx->getService('error', 'error.modError');
$modx->setLogLevel(modX::LOG_LEVEL_ERROR);
$modx->setLogTarget('FILE');

/** @var miniShop2 $miniShop2 */
$miniShop2 = $modx->getService('minishop2');
$miniShop2->loadCustomClasses('payment');

if (!class_exists('Robokassa')) {
    exit('Error: could not load payment class "Robokassa".');
}
$context = '';
$params = array();

/** @var msOrder $order */
$order = $modx->newObject('msOrder');
/** @var msPaymentInterface|Robokassa $handler */
$handler = new Robokassa($order);

if (!empty($_REQUEST['SignatureValue']) && !empty($_REQUEST['InvId'])) {
    if ($order = $modx->getObject('msOrder', $_REQUEST['InvId'])) {
        if (empty($_REQUEST['action'])) {
            $handler->answer($order, $_REQUEST);
        } elseif (!empty($_REQUEST['action']) && $_REQUEST['action'] == 'success') {
            $handler->receive($order, $_REQUEST);
        }
    } else {
        $modx->log(modX::LOG_LEVEL_ERROR,
            '[miniShop2:Robokassa] Could not retrieve order with id ' . $_REQUEST['LMI_PAYMENT_NO']);
    }
}

if (!empty($_REQUEST['InvId'])) {
    $params['msorder'] = $_REQUEST['InvId'];
}

$success = $failure = $modx->getOption('site_url');
if ($id = $modx->getOption('ms2_payment_rbks_success_id', null, 0)) {
	$params['status'] = 1;
    $success = $modx->makeUrl($id, $context, $params, 'full');
}
if ($id = $modx->getOption('ms2_payment_rbks_failure_id', null, 0)) {
	$params['status'] = 2;
    $failure = $modx->makeUrl($id, $context, $params, 'full');
}

$redirect = !empty($_REQUEST['action']) && $_REQUEST['action'] == 'success'
    ? $success
    : $failure;
header('Location: ' . $redirect);
