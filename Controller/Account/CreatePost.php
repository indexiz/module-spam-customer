<?php
declare(strict_types=1);

namespace Indexiz\SpamCustomer\Controller\Account;

use Indexiz\SpamCustomer\ViewModel\FormKey;
use Magento\Customer\Api\CustomerRepositoryInterface as CustomerRepository;
use Magento\Customer\Controller\Account\CreatePost as CustomerCreatePost;
use Magento\Customer\Model\Account\Redirect as AccountRedirect;
use Magento\Framework\Api\DataObjectHelper;
use Magento\Framework\App\Action\Context;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Encryption\Helper\Security;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\MessageInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\Cookie\PhpCookieManager;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Helper\Address;
use Magento\Framework\UrlFactory;
use Magento\Customer\Model\Metadata\FormFactory;
use Magento\Newsletter\Model\SubscriberFactory;
use Magento\Customer\Api\Data\RegionInterfaceFactory;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Customer\Model\Url as CustomerUrl;
use Magento\Customer\Model\Registration;
use Magento\Framework\Escaper;
use Magento\Customer\Model\CustomerExtractor;
use Magento\Framework\Exception\StateException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Data\Form\FormKey\Validator;

/**
 * Post create customer action
 *
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class CreatePost extends CustomerCreatePost
{
    /**
     * @var AccountRedirect
     */
    private $accountRedirect;

    /**
     * @var CookieMetadataFactory
     */
    private $cookieMetadataFactory;

    /**
     * @var PhpCookieManager
     */
    private $cookieMetadataManager;

    /**
     * @var Validator
     */
    private $formKeyValidator;

    /**
     * @var CustomerRepository
     */
    private $customerRepository;

    /**
     * @param Context $context
     * @param Session $customerSession
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     * @param AccountManagementInterface $accountManagement
     * @param Address $addressHelper
     * @param UrlFactory $urlFactory
     * @param FormFactory $formFactory
     * @param SubscriberFactory $subscriberFactory
     * @param RegionInterfaceFactory $regionDataFactory
     * @param AddressInterfaceFactory $addressDataFactory
     * @param CustomerInterfaceFactory $customerDataFactory
     * @param CustomerUrl $customerUrl
     * @param Registration $registration
     * @param Escaper $escaper
     * @param CustomerExtractor $customerExtractor
     * @param DataObjectHelper $dataObjectHelper
     * @param AccountRedirect $accountRedirect
     * @param CustomerRepository $customerRepository
     * @param Validator|null $formKeyValidator
     */
    public function __construct(
        Context $context,
        Session $customerSession,
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        AccountManagementInterface $accountManagement,
        Address $addressHelper,
        UrlFactory $urlFactory,
        FormFactory $formFactory,
        SubscriberFactory $subscriberFactory,
        RegionInterfaceFactory $regionDataFactory,
        AddressInterfaceFactory $addressDataFactory,
        CustomerInterfaceFactory $customerDataFactory,
        CustomerUrl $customerUrl,
        Registration $registration,
        Escaper $escaper,
        CustomerExtractor $customerExtractor,
        DataObjectHelper $dataObjectHelper,
        AccountRedirect $accountRedirect,
        CustomerRepository $customerRepository,
        Validator $formKeyValidator = null
    ) {
        parent::__construct(
            $context,
            $customerSession,
            $scopeConfig,
            $storeManager,
            $accountManagement,
            $addressHelper,
            $urlFactory,
            $formFactory,
            $subscriberFactory,
            $regionDataFactory,
            $addressDataFactory,
            $customerDataFactory,
            $customerUrl,
            $registration,
            $escaper,
            $customerExtractor,
            $dataObjectHelper,
            $accountRedirect,
            $customerRepository,
            $formKeyValidator
        );
        $this->accountRedirect = $accountRedirect;
        $this->formKeyValidator = $formKeyValidator ?: ObjectManager::getInstance()->get(Validator::class);
        $this->customerRepository = $customerRepository;
    }

    /**
     * Retrieve cookie manager
     *
     * @deprecated 100.1.0
     * @return PhpCookieManager
     */
    private function getCookieManager()
    {
        if (!$this->cookieMetadataManager) {
            $this->cookieMetadataManager = ObjectManager::getInstance()->get(
                PhpCookieManager::class
            );
        }
        return $this->cookieMetadataManager;
    }

    /**
     * Retrieve cookie metadata factory
     *
     * @deprecated 100.1.0
     * @return CookieMetadataFactory
     */
    private function getCookieMetadataFactory()
    {
        if (!$this->cookieMetadataFactory) {
            $this->cookieMetadataFactory = ObjectManager::getInstance()->get(
                CookieMetadataFactory::class
            );
        }
        return $this->cookieMetadataFactory;
    }

    /**
     * Create customer account action
     *
     * @return \Magento\Framework\Controller\Result\Redirect
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function execute()
    {
        /** @var Redirect $resultRedirect */
        $resultRedirect = $this->resultRedirectFactory->create();

        // Indexiz - Spam Customer Generation Detection Code Start
        $dummyKey = FormKey::DUMMY_KEY_START . $this->getRequest()->getParam('form_key') . FormKey::DUMMY_KEY_END;
        if (!Security::compareStrings($dummyKey, $this->getRequest()->getParam('indexiz_key_hidden'))) {
            $url = $this->urlModel->getUrl('*/*/create', ['_secure' => true]);
            return $resultRedirect->setUrl($this->_redirect->error($url));
        }
        // Indexiz - Spam Customer Generation Detection Code End

        if ($this->session->isLoggedIn() || !$this->registration->isAllowed()) {
            $resultRedirect->setPath('*/*/');
            return $resultRedirect;
        }

        if (!$this->getRequest()->isPost()
            || !$this->formKeyValidator->validate($this->getRequest())
        ) {
            $url = $this->urlModel->getUrl('*/*/create', ['_secure' => true]);
            return $this->resultRedirectFactory->create()
                ->setUrl($this->_redirect->error($url));
        }
        $this->session->regenerateId();
        try {
            $address = $this->extractAddress();
            $addresses = $address === null ? [] : [$address];
            $customer = $this->customerExtractor->extract('customer_account_create', $this->_request);
            $customer->setAddresses($addresses);
            $password = $this->getRequest()->getParam('password');
            $confirmation = $this->getRequest()->getParam('password_confirmation');
            $redirectUrl = $this->session->getBeforeAuthUrl();
            $this->checkPasswordConfirmation($password, $confirmation);

            $extensionAttributes = $customer->getExtensionAttributes();
            $extensionAttributes->setIsSubscribed($this->getRequest()->getParam('is_subscribed', false));
            $customer->setExtensionAttributes($extensionAttributes);

            $customer = $this->accountManagement
                ->createAccount($customer, $password, $redirectUrl);

            $this->_eventManager->dispatch(
                'customer_register_success',
                ['account_controller' => $this, 'customer' => $customer]
            );
            $confirmationStatus = $this->accountManagement->getConfirmationStatus($customer->getId());
            if ($confirmationStatus === AccountManagementInterface::ACCOUNT_CONFIRMATION_REQUIRED) {
                $this->messageManager->addComplexSuccessMessage(
                    'confirmAccountSuccessMessage',
                    [
                        'url' => $this->customerUrl->getEmailConfirmationUrl($customer->getEmail()),
                    ]
                );
                $url = $this->urlModel->getUrl('*/*/index', ['_secure' => true]);
                $resultRedirect->setUrl($this->_redirect->success($url));
            } else {
                $this->session->setCustomerDataAsLoggedIn($customer);
                $this->messageManager->addMessage($this->getMessageManagerSuccessMessage());
                $requestedRedirect = $this->accountRedirect->getRedirectCookie();
                if (!$this->scopeConfig->getValue('customer/startup/redirect_dashboard') && $requestedRedirect) {
                    $resultRedirect->setUrl($this->_redirect->success($requestedRedirect));
                    $this->accountRedirect->clearRedirectCookie();
                    return $resultRedirect;
                }
                $resultRedirect = $this->accountRedirect->getRedirect();
            }
            if ($this->getCookieManager()->getCookie('mage-cache-sessid')) {
                $metadata = $this->getCookieMetadataFactory()->createCookieMetadata();
                $metadata->setPath('/');
                $this->getCookieManager()->deleteCookie('mage-cache-sessid', $metadata);
            }

            return $resultRedirect;
        } catch (StateException $e) {
            $this->messageManager->addComplexErrorMessage(
                'customerAlreadyExistsErrorMessage',
                [
                    'url' => $this->urlModel->getUrl('customer/account/forgotpassword'),
                ]
            );
        } catch (InputException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            foreach ($e->getErrors() as $error) {
                $this->messageManager->addErrorMessage($error->getMessage());
            }
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Exception $e) {
            $this->messageManager->addExceptionMessage($e, __('We can\'t save the customer.'));
        }

        $this->session->setCustomerFormData($this->getRequest()->getPostValue());
        $defaultUrl = $this->urlModel->getUrl('*/*/create', ['_secure' => true]);
        return $resultRedirect->setUrl($this->_redirect->error($defaultUrl));
    }

    /**
     * Retrieve success message manager message
     *
     * @return MessageInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    private function getMessageManagerSuccessMessage(): MessageInterface
    {
        if ($this->addressHelper->isVatValidationEnabled()) {
            if ($this->addressHelper->getTaxCalculationAddressType() == Address::TYPE_SHIPPING) {
                $identifier = 'customerVatShippingAddressSuccessMessage';
            } else {
                $identifier = 'customerVatBillingAddressSuccessMessage';
            }

            $message = $this->messageManager
                ->createMessage(MessageInterface::TYPE_SUCCESS, $identifier)
                ->setData(
                    [
                        'url' => $this->urlModel->getUrl('customer/address/edit'),
                    ]
                );
        } else {
            $message = $this->messageManager
                ->createMessage(MessageInterface::TYPE_SUCCESS)
                ->setText(
                    __('Thank you for registering with %1.', $this->storeManager->getStore()->getFrontendName())
                );
        }

        return $message;
    }
}
