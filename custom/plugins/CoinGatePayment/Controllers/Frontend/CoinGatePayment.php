<?php

use CoinGatePayment\Components\CoinGatePayment\PaymentResponse;
use CoinGatePayment\Components\CoinGatePayment\CoinGatePaymentService;
use Shopware\Components\Plugin\ConfigReader;
use Shopware\Components\CSRFWhitelistAware;

require_once __DIR__ . '/../../Components/coingate-php/init.php';

class Shopware_Controllers_Frontend_CoinGatePayment extends Shopware_Controllers_Frontend_Payment implements CSRFWhitelistAware
{
    private $pluginDirectory;
    private $config;

    const PAYMENTSTATUSPAID = 12;
    const PAYMENTSTATUSCANCELED = 17;
    const PAYMENTSTATUSPENDING = 18;
    const PAYMENTSTATUSREFUNDED = 20;

    public function preDispatch()
    {
        /** @var \Shopware\Components\Plugin $plugin */
        $plugin = $this->get('kernel')->getPlugins()['CoinGatePayment'];

        $this->get('template')->addTemplateDir($plugin->getPath() . '/Resources/views/');
    }

    public function indexAction()
    {
        switch ($this->getPaymentShortName()) {
            case 'cryptocurrency_payments_via_coingate':
                return $this->redirect(['action' => 'direct', 'forceSecure' => true]);
            default:
                return $this->redirect(['controller' => 'checkout']);
        }
    }

    /**
     * Direct action method.
     *
     * Collects the payment information and transmits it to the payment provider.
     */
    public function directAction()
    {
        $config = $this->container->get('shopware.plugin.cached_config_reader')->getByPluginName('CoinGatePayment');
        $router = $this->Front()->Router();

        $data = $this->getOrderData();
        $order_id = $data[0]["orderID"];
        $shop = $this->getShopData();

        $post_params = array(
            'order_id'          => $order_id,
            'price_amount'      => $this->getAmount(),
            'price_currency'    => $this->getCurrencyShortName(),
            'receive_currency'  => $config['CoinGatePayout'],
            'title'             => $shop[0]["name"],
            'description'       => "Order #" . $order_id,
            'success_url'       => $router->assemble(['action' => 'return']),
            'cancel_url'        => $router->assemble(['action' => 'cancel']),
            'callback_url'      => $router->assemble(['action' => 'callback']),
        );


        $coingate_environment = $this->coingateEnvironment();

        $order = \CoinGate\Merchant\Order::create($post_params, array(), array(
            'environment' => $coingate_environment,
            'auth_token'  => $config['CoinGateCredentials'],
            'user_agent'  => $this->userAgent(),
        ));

        if ($order && $order->payment_url) {
          $this->insertOrderID($order->id);
          $this->redirect($order->payment_url);
        } else {
            error_log(print_r(array($order), true)."\n", 3, Shopware()->DocPath() . '/error.log');
        }

    }

    public function returnAction()
    {
        $service = $this->container->get('cryptocurrency_payments_via_coingate.coingate_payment_service');
        $token = $this->createPaymentToken($this->getAmount(), $billing['customernumber']);
        $config = $this->container->get('shopware.plugin.cached_config_reader')->getByPluginName('CoinGatePayment');
        $coingate_environment = $this->coingateEnvironment();
        $agent = $this->userAgent();
        $order_id = $this->getOrderData()[0]['coingate_callback_order_id'];

        $response = $service->createPaymentResponse($order_id, $coingate_environment, $config['CoinGateCredentials'], $billing, $agent);

        if (empty($response->token) || strcmp($response->token, $token) !== 0) {
            $this->forward('cancel');
        }

        $cgOrder = $service->coingateCallback($response->id, $coingate_environment, $config['CoinGateCredentials'], $agent);

        switch ($cgOrder->status) {
            case 'paid':
                $this->saveOrder(
                    $cgOrder->payment_url,
                    $response->token,
                    self::PAYMENTSTATUSPAID
                );
                $this->redirect(['controller' => 'checkout', 'action' => 'finish']);
                break;
            case 'pending':
            case 'confirming':
                $this->saveOrder(
                    self::PAYMENTSTATUSPENDING
                );
                $this->forward('cancel');
                break;
            case 'invalid':
            case 'expired':
            case 'canceled':
                $this->saveOrder(
                    self::PAYMENTSTATUSCANCELED
                );
                $this->forward('cancel');
                break;
            case 'refunded':
                $this->saveOrder(
                    self::PAYMENTSTATUSREFUNDED
                );
                $this->forward('cancel');
                break;
            default:
                $this->forward('cancel');
                break;
        }
    }

    public function cancelAction()
    {
    }

    public function createPaymentToken($amount, $customerId)
    {
        return md5(implode('|', [$amount, $customerId]));
    }

    public function getWhitelistedCSRFActions()
    {
        return array(
            'callback',
        );
    }

    private function coingateEnvironment()
    {
        $config = $this->container->get('shopware.plugin.cached_config_reader')->getByPluginName('CoinGatePayment');
        if ($config['CoinGateEnvironment'] == 'sandbox') {
            $environment = 'sandbox';
        } else {
            $environment = 'live';
        }

        return $environment;
    }

    private function getOrderData()
    {
        $queryBuilder = $this->container->get('dbal_connection')->createQueryBuilder();
        $queryBuilder->select('*')
            ->from('s_order_attributes');
        $data = $queryBuilder->execute()->fetchAll();
        $last_order = array_values(array_slice($data, -1));

        return $last_order;
    }

    private function getShopData()
    {
        $queryBuilder = $this->container->get('dbal_connection')->createQueryBuilder();
        $queryBuilder->select('*')
            ->from('s_core_shops');
        $data = $queryBuilder->execute()->fetchAll();

        return $data;
    }

    private function getPluginVersion()
    {
        $plugin = $this->get('kernel')->getPlugins()['CoinGatePayment'];
        $xml = simplexml_load_file( $plugin->getPath() ."/plugin.xml") or die("Error parsing plugin.xml");

        return $xml->version;
    }

    private function insertOrderID($id)
    {
        /** @var \Shopware\Bundle\AttributeBundle\Service\CrudService $service */
        $service = $this->get('shopware_attribute.crud_service');
        $service->update('s_order_attributes', 'coingate_callback_order_id', 'text');
        $queryBuilder = $this->container->get('dbal_connection')->createQueryBuilder();
        $queryBuilder
            ->insert('s_order_attributes')
            ->values(
                array(
                    'coingate_callback_order_id' => $id,
                )
            );
        $data = $queryBuilder->execute();
    }

    private function userAgent()
    {
        $coingate_version = $this->getPluginVersion();
        return $agent = 'Shopware v' . Shopware()->Container()->get('config')->get('version') . ' CoinGate Extension v' . $coingate_version[0]["version"];
    }

}
