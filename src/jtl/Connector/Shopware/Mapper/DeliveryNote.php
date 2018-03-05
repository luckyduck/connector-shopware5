<?php
/**
 * @copyright 2010-2013 JTL-Software GmbH
 * @package jtl\Connector\Shopware\Controller
 */

namespace jtl\Connector\Shopware\Mapper;

use jtl\Connector\Core\Logger\Logger;
use jtl\Connector\Formatter\ExceptionFormatter;
use \Shopware\Components\Api\Exception as ApiException;
use \jtl\Connector\Model\DeliveryNote as DeliveryNoteModel;
use \Shopware\Models\Order\Document\Document as DocumentSW;
use \jtl\Connector\Model\Identity;
use \jtl\Connector\Shopware\Utilities\Mmc;

class DeliveryNote extends DataMapper
{
    public function find($id)
    {
        return (intval($id) == 0) ? null : $this->Manager()->getRepository('Shopware\Models\Order\Document\Document')->find($id);
    }

    public function findType($name)
    {
        return $this->Manager()->getRepository('Shopware\Models\Order\Document\Type')->findOneBy(array('name' => $name));
    }
    
    public function findNewType($name)
    {
        return $this->Manager()->getRepository('Shopware\Models\Document\Document')->findOneBy(array('name' => $name));
    }
    
    public function findAll($limit = 100, $count = false)
    {
        $query = $this->Manager()->createQueryBuilder()->select(array(
                'documents'
            ))
            //->from('Shopware\Models\Order\Document\Document', 'documents')
            //->leftJoin('jtl\Connector\Shopware\Model\ConnectorLink', 'link', \Doctrine\ORM\Query\Expr\Join::WITH, 'documents.id = link.endpointId AND link.type = 29')
            ->from('jtl\Connector\Shopware\Model\Linker\DeliveryNote', 'documents')
            ->leftJoin('documents.linker', 'linker')
            ->where('documents.type = 2')
            ->andWhere('documents.documentId = 20001')
            ->andWhere('linker.hostId IS NULL')
            ->setFirstResult(0)
            ->setMaxResults($limit)
            //->getQuery();
            ->getQuery()->setHydrationMode(\Doctrine\ORM\AbstractQuery::HYDRATE_ARRAY);

        $paginator = new \Doctrine\ORM\Tools\Pagination\Paginator($query, $fetchJoinCollection = true);

        //$res = $query->getResult(\Doctrine\ORM\Query::HYDRATE_ARRAY);

        //return $count ? count($res) : $res;

        return $count ? ($paginator->count()) : iterator_to_array($paginator);
    }

    public function fetchCount($limit = 100)
    {
        return $this->findAll($limit, true);
    }

    public function delete(DeliveryNoteModel $deliveryNote)
    {
        $result = $deliveryNote;

        $this->deleteDeliveryNoteData($deliveryNote);

        // Result
        $result->setId(new Identity('', $deliveryNote->getId()->getHost()));

        return $result;
    }

    public function save(DeliveryNoteModel $deliveryNote)
    {
        //$deliveryNoteSW = null;
        $result = $deliveryNote;
    
        $endpointId = 0;
        $hostId = $deliveryNote->getId()->getHost();
        $this->prepareDeliveryNoteAssociatedData($deliveryNote, $endpointId);
        
        /*
        $this->prepareDeliveryNoteAssociatedData($deliveryNote, $deliveryNoteSW);

        $endpointId = 0;
        $hostId = 0;
        if ($deliveryNoteSW !== null) {
            $this->Manager()->persist($deliveryNoteSW);
            $this->flush();

            $endpointId = $deliveryNoteSW->getId();
            $hostId = $deliveryNote->getId()->getHost();
        }
        */

        // Result
        $result->setId(new Identity($endpointId, $hostId));

        return $result;
    }

    protected function deleteDeliveryNoteData(DeliveryNoteModel $deliveryNote)
    {
        $deliveryNoteId = (strlen($deliveryNote->getId()->getEndpoint()) > 0) ? (int) $deliveryNote->getId()->getEndpoint() : null;

        if ($deliveryNoteId !== null && $deliveryNoteId > 0) {
            /*
            $deliveryNoteSW = $this->find((int) $deliveryNoteId);
            if ($deliveryNoteSW !== null) {
            */
    
                $documentPath = rtrim(Shopware()->Container()->getParameter('shopware.app.documentsdir'), '/') . DIRECTORY_SEPARATOR;
                /** @var \Doctrine\DBAL\Connection $connection */
                $connection = Shopware()->Container()->get('dbal_connection');
                $queryBuilder = $connection->createQueryBuilder();
    
                $documentHash = $queryBuilder->select('hash')
                    ->from('s_order_documents')
                    ->where('id = :documentId')
                    ->setParameter('documentId', $deliveryNoteId)
                    ->execute()
                    ->fetchColumn();
    
                $queryBuilder = $connection->createQueryBuilder();
                $queryBuilder->delete('s_order_documents')
                    ->where('id = :documentId')
                    ->setParameter('documentId', $deliveryNoteId)
                    ->execute();
    
                $file = $documentPath . $documentHash . '.pdf';
                if (!is_file($file)) {
                    return;
                }
    
                unlink($file);
                
                /*
                $this->Manager()->remove($deliveryNoteSW);
                $this->Manager()->flush($deliveryNoteSW);
                */
            //}
        }
    }

    //protected function prepareDeliveryNoteAssociatedData(DeliveryNoteModel $deliveryNote, DocumentSW &$deliveryNoteSW = null)
    protected function prepareDeliveryNoteAssociatedData(DeliveryNoteModel &$deliveryNote, &$endpointId)
    {
        /*
        $deliveryNoteId = (strlen($deliveryNote->getId()->getEndpoint()) > 0) ? (int) $deliveryNote->getId()->getEndpoint() : null;

        if ($deliveryNoteId !== null && $deliveryNoteId > 0) {
            $deliveryNoteSW = $this->find($deliveryNoteId);
        }
        */

        $orderMapper = Mmc::getMapper('CustomerOrder');
        
        /** @var \Shopware\Models\Order\Order $orderSW */
        $orderSW = $orderMapper->find($deliveryNote->getCustomerOrderId()->getEndpoint());

        if (is_null($orderSW)) {
            return;
        }
    
        $deliveryNote->getCustomerOrderId()->setEndpoint($orderSW->getId());
        
        // Tracking
        if (count($deliveryNote->getTrackingLists()) > 0) {
            $trackingLists = $deliveryNote->getTrackingLists();
            $codes = $trackingLists[0]->getCodes();
    
            if (count($codes) > 0) {
                $orderSW->setTrackingCode($codes[0]);
                $this->Manager()->persist($orderSW);
            }
        }
    
        /** @var \Shopware\Models\Document\Document $document */
        $document = Shopware()->Models()->getRepository(\Shopware\Models\Document\Document::class)->find(2);
        if (!is_null($document)) {
        
            try {
                // Create order document
                $document = \Shopware_Components_Document::initDocument(
                    $orderSW->getId(),
                    $document->getId(),
                    [
                        'netto' => false,
                        'bid' => '',
                        'voucher' => null,
                        'date' => $deliveryNote->getCreationDate()->format('d.m.Y'),
                        'delivery_date' => $deliveryNote->getCreationDate()->format('d.m.Y'),
                        'shippingCostsAsPosition' => 0,
                        '_renderer' => 'pdf',
                        '_preview' => false,
                        '_previewForcePagebreak' => '',
                        '_previewSample' => '',
                        'docComment' => $deliveryNote->getNote(),
                        'forceTaxCheck' => false,
                    ]
                );
            
                $document->render();
            } catch (\Exception $e) {
                Logger::write(ExceptionFormatter::format($e), Logger::ERROR, 'database');
            }
        }
    
        $endpointId = $document->_documentRowID;
        
        /*
        $sw = Shopware();
        $type = version_compare($sw::VERSION, '5.4', '>=') ? $this->findNewType('Lieferschein')
            : $this->findType('Lieferschein');
        
        if (!is_null($type)) {
            if (is_null($deliveryNoteSW)) {
                $deliveryNoteSW = new DocumentSW;
            }
            
            $amount = $orderSW->getNet() == 0 ? $orderSW->getInvoiceAmount() : $orderSW->getInvoiceAmountNet();

            $deliveryNoteSW->setDate($deliveryNote->getCreationDate())
                ->setCustomerId($orderSW->getCustomer()->getId())
                ->setOrderId($orderSW->getId())
                ->setAmount($amount)
                ->setHash(md5(uniqid(rand())))
                ->setDocumentId($orderSW->getNumber());

            $deliveryNoteSW->setType($type);
            $deliveryNoteSW->setOrder($orderSW);
        } else {
            Logger::write('Could not find type \'Lieferschein\'. Please check your shopware backend settings', Logger::WARNING, 'database');
        }
        */
    }
}
