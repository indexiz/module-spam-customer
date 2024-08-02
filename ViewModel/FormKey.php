<?php
declare(strict_types=1);

namespace Indexiz\SpamCustomer\ViewModel;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Element\Block\ArgumentInterface;

class FormKey implements ArgumentInterface
{
    public const DUMMY_KEY_START = 'i2M2scg46IMfeKg';
    public const DUMMY_KEY_END = 'Jd9W8s06gz3Oo9k';

    /**
     * @var \Magento\Framework\Data\Form\FormKey
     */
    private $formKey;

    /**
     * @param \Magento\Framework\Data\Form\FormKey $formKey
     */
    public function __construct(
        \Magento\Framework\Data\Form\FormKey $formKey
    ) {
        $this->formKey = $formKey;
    }

    /**
     * Retrieve Session Form Key
     *
     * @return string
     * @throws LocalizedException
     */
    public function getFormKey()
    {
        return self::DUMMY_KEY_START . $this->formKey->getFormKey() . self::DUMMY_KEY_END;
    }
}
