<?php
/**
 * @copyright 2010-2013 JTL-Software GmbH
 * @package jtl\Connector\Shopware\Controller
 */

namespace jtl\Connector\Shopware\Controller;

use jtl\Connector\Core\Utilities\Language as LanguageUtil;
use jtl\Connector\Model\CustomerAttr;
use \jtl\Connector\Result\Action;
use \jtl\Connector\Core\Rpc\Error;
use \jtl\Connector\Core\Model\QueryFilter;
use \jtl\Connector\Core\Utilities\DataConverter;
use jtl\Connector\Shopware\Utilities\KeyValueAttributes;
use \jtl\Connector\Shopware\Utilities\Mmc;
use \jtl\Connector\Shopware\Utilities\Salutation;
use \jtl\Connector\Core\Logger\Logger;
use \jtl\Connector\Formatter\ExceptionFormatter;
use jtl\Connector\Shopware\Utilities\Str;
use Shopware\Bundle\AttributeBundle\Service\ConfigurationStruct;

/**
 * Customer Controller
 * @access public
 */
class Customer extends DataController
{
    /**
     * Pull
     *
     * @param \jtl\Connector\Core\Model\QueryFilter $queryFilter
     * @return \jtl\Connector\Result\Action
     */
    public function pull(QueryFilter $queryFilter)
    {
        $action = new Action();
        $action->setHandled(true);

        try {
            $result = array();
            $limit = $queryFilter->isLimit() ? $queryFilter->getLimit() : 100;

            $mapper = Mmc::getMapper('Customer');
            $customers = $mapper->findAll($limit);

            foreach ($customers as $customerSW) {
                try {
                    $customerSW['newsletter'] = (bool)$customerSW['newsletter'];

                    /** @var \jtl\Connector\Shopware\Model\Customer $customer */
                    $customer = Mmc::getModel('Customer');
                    $customer->map(true, DataConverter::toObject($customerSW, true));

                    $vatNumber = $customer->getVatNumber();
                    if(strlen($vatNumber) > 20) {
                        $customer->setVatNumber(substr($vatNumber, 0, 20));
                    }

                    /**
                     * 0 => normal account ("don't create customer account" wasn't checked)<br>
                     * 1 => hidden account ("don't create customer account" was checked)
                     */
                    $customer->setHasCustomerAccount((int) $customerSW['accountMode'] == 0);

                    $country = Shopware()->Models()->getRepository('Shopware\Models\Country\Country')
                        ->findOneById($customerSW['defaultBillingAddress']['countryId']);

                    $iso = ($country !== null) ? $country->getIso() : 'DE';
                    
                    // Birthday
                    try {
                        if (isset($customerSW['birthday']) && !($customerSW['birthday'] instanceof \DateTime)
                            && strlen($customerSW['birthday']) > 0
                            && $customerSW['birthday'] !== '0000-00-00') {
                            $customer->setBirthday(new \DateTime($customerSW['birthday']));
                        } elseif (isset($customerSW['birthday']) && $customerSW['birthday'] instanceof \DateTime
                            && $customerSW['birthday'] != new \DateTime('0000-00-00')) {
                            $customer->setBirthday($customerSW['birthday']);
                        }
                    } catch (\Exception $e) { }

                    /* Set actual date as creation date if creation date is 0000-00-00 or null */
                    if(\is_null($customer->getCreationDate()) || (int)$customer->getCreationDate()->format('Y') < 0) {
                        $customer->setCreationDate(new \DateTime());
                    }

                    // Salutation
                    $customer->setSalutation(Salutation::toConnector($customer->getSalutation()))
                        ->setCountryIso($iso)
                        ->setLanguageISO(LanguageUtil::map($customerSW['languageSubShop']['locale']['locale']));

                    // Attributes
                    $keyValueAttributes = new KeyValueAttributes(CustomerAttr::class);
                    if (isset($customerSW['defaultBillingAddress']['attribute']) && is_array($customerSW['defaultBillingAddress']['attribute'])) {
                        for ($i = 1; $i <= 6; $i++) {
                            if (isset($customerSW['defaultBillingAddress']['attribute']["text{$i}"]) && strlen(trim($customerSW['defaultBillingAddress']['attribute']["text{$i}"]))) {
                                $keyValueAttributes->addAttribute("text{$i}", $customerSW['defaultBillingAddress']['attribute']["text{$i}"]);
                            }
                        }
                    }

                    if($customerSW['attribute'] !== null){
                        /** @var \Shopware\Models\Attribute\Customer $swCustomerAttribute */
                        $swCustomerAttribute = Shopware()->Models()
                            ->getRepository('Shopware\Models\Attribute\Customer')
                            ->findOneById($customerSW['attribute']['id']);

                        $swCustomerAttributes = Shopware()->Container()->get('shopware_attribute.crud_service')->getList('s_user_attributes');
                        /** @var ConfigurationStruct $swCustomerAttribute */
                        foreach($swCustomerAttributes as $attribute){
                            if(!$attribute->isIdentifier()){
                                $getter = sprintf('get%s', ucfirst(Str::camel($attribute->getColumnName())));
                                if(method_exists($swCustomerAttribute,$getter)){
                                    $value = $swCustomerAttribute->$getter();
                                    $key = $attribute->getColumnName();

                                    if ($value instanceof \DateTimeInterface) {
                                        $value = $value->format(\DateTime::ISO8601);
                                    }

                                    if (is_null($value) || empty($value)) {
                                        continue;
                                    }

                                    $keyValueAttributes->addAttribute(
                                        $key,
                                        $value
                                    );
                                }
                            }
                        }
                    }

                    foreach($keyValueAttributes->getAttributes() as $attribute) {
                        $customer->addAttribute($attribute);
                    }

                    //$result[] = $customer->getPublic();
                    $result[] = $customer;
                } catch (\Exception $exc) {
                    Logger::write(ExceptionFormatter::format($exc), Logger::WARNING, 'controller');
                }
            }

            $action->setResult($result);
        } catch (\Exception $exc) {
            $err = new Error();
            $err->setCode($exc->getCode());
            $err->setMessage($exc->getMessage());
            $action->setError($err);
        }

        return $action;
    }
}
