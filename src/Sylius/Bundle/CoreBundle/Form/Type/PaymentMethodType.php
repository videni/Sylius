<?php

namespace Sylius\Bundle\CoreBundle\Form\Type;

use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormBuilderInterface;
use Sylius\Bundle\PaymentBundle\Form\Type\PaymentMethodType as BasePaymentMethodType;
use Payum\Core\Storage\StorageInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Payum\Core\Registry\GatewayFactoryRegistryInterface;
use Symfony\Component\Translation\TranslatorInterface;

/**
 * @author   Vidy Videni   <videni@foxmail.com>
 */
class PaymentMethodType extends BasePaymentMethodType
{

    /**
     * @var GatewayFactoryRegistryInterface
     */
    protected $registry;

    /**
     * @var array
     */
    protected $gatewayConfigs = [];

    /**
     * @var StorageInterface
     */
    protected $gatewayConfigStore;

    /**
     * @var array
     */
    protected $defaultGateways = [];

    /**
     * @var TranslatorInterface
     */
    protected $translator;

    /**
     * PaymentMethodType constructor.
     * @param string $paymentMethodClass
     * @param array $validationGroups
     * @param GatewayFactoryRegistryInterface $registry
     * @param StorageInterface $gatewayConfigStore
     * @param TranslatorInterface $translator
     * @param array $defaultGateways
     */
    public function __construct($paymentMethodClass, array $validationGroups = [], GatewayFactoryRegistryInterface $registry, StorageInterface $gatewayConfigStore,TranslatorInterface $translator, array $defaultGateways = [])
    {
        parent::__construct($paymentMethodClass, $validationGroups);
        $this->registry = $registry;
        $this->gatewayConfigStore = $gatewayConfigStore;
        $this->defaultGateways = $defaultGateways;
        $this->translator=$translator;

        $this->initializeGatewayConfigs();
    }


    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        parent::buildForm($builder, $options);

        $this->buildGatewayConfigForm($builder, $options);

        $builder->addEventListener(FormEvents::POST_SUBMIT, array($this,'processGatewayConfig'));
    }

    /**
     * @param FormBuilderInterface $builder
     * @param array $options
     */
    protected function buildGatewayConfigForm(FormBuilderInterface $builder, array $options)
    {
        foreach ($this->gatewayConfigs as $factoryName => $config) {

            /**
             * @var  $gatewayConfig \Sylius\Bundle\PayumBundle\Model\GatewayConfig
             */
            $gatewayConfig = null;

            if ($gatewayConfigs = $this->gatewayConfigStore->findBy(array('gatewayName' => $factoryName))) {
                $gatewayConfig = array_shift($gatewayConfigs);
            }


            $configForm = $builder->create($factoryName, FormType::class, array(
                'mapped' => false,
                'csrf_protection' => false,
                'data' => $gatewayConfig ? $gatewayConfig->getConfig() : null
            ));

            foreach ($config['payum.default_options'] as $name => $value) {
                $type = is_bool($value) ? CheckboxType::class : TextType::class;

                $options = array(
                    'required' => in_array($name, $config['payum.required_options'])
                );

                $configForm->add($name, $type, $options);
            }

            $builder->add($configForm);
        }
    }


    /**
     * @param FormEvent $event
     */
    public  function processGatewayConfig(FormEvent $event)
    {
        /**
         * @var  $paymentMethod \Sylius\Component\Payment\Model\PaymentMethod
         */
        $paymentMethod = $event->getData();
        $form = $event->getForm();

        $factoryName = $paymentMethod->getGateway();
        $configForm = $form->has($factoryName) ? $form->get($factoryName) : null;

        if (!$configForm) {
            return;
        }

        $data = $configForm->getData();

        $config = $this->gatewayConfigs[$factoryName];

        if (!isset($config['payum.required_options']))
            return;

        foreach ($config['payum.required_options'] as $option) {
            if (!isset($data[$option]) || empty($data[$option])) {
                $formError = new FormError($this->translator->trans('This value should not be blank.'));
                $configForm->get($option)->addError($formError);
            }
        }

        if ($form->isValid())  //now we are sure the payment method data will be saved  , so we also save gateway config
        {
            $gatewayConfig = $this->gatewayConfigStore->create();
            $gatewayConfig->setGatewayName($paymentMethod->getGateway());
            $gatewayConfig->setFactoryName($paymentMethod->getGateway());
            $gatewayConfig->setConfig($data);
            $this->gatewayConfigStore->update($gatewayConfig);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function buildView(FormView $view, FormInterface $form, array $options)
    {
        $view->vars['gateways'] = array_keys($this->gatewayConfigs);
    }

    protected function initializeGatewayConfigs()
    {
        foreach ($this->defaultGateways as $name => $factory) {
            $gatewayFactory = $this->registry->getGatewayFactory($name);
            $config = $gatewayFactory->createConfig();

            if (empty($config['payum.default_options']))
                continue;
            $this->gatewayConfigs[$name] = $config;
        }
    }
}
