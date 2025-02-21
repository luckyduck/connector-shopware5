<?php
/**
 * @copyright 2010-2013 JTL-Software GmbH
 * @package jtl\Connector\Shopware\Controller
 */

namespace jtl\Connector\Shopware\Mapper;

use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use Doctrine\ORM\TransactionRequiredException;
use jtl\Connector\Core\Exception\LanguageException;
use jtl\Connector\Core\Logger\Logger;
use jtl\Connector\Core\Utilities\Seo;
use jtl\Connector\Core\Utilities;
use jtl\Connector\Drawing\ImageRelationType;
use jtl\Connector\Formatter\ExceptionFormatter;
use jtl\Connector\Linker\IdentityLinker;
use jtl\Connector\Model\Image as JtlImage;
use jtl\Connector\Model\Identity;
use jtl\Connector\Model\ImageI18n;
use jtl\Connector\Shopware\Model\ProductAttr;
use jtl\Connector\Shopware\Utilities\I18n;
use jtl\Connector\Shopware\Utilities\Sort;
use Shopware\Bundle\MediaBundle\MediaReplaceService;
use Shopware\Components\Random;
use Shopware\Models\Category\Category;
use Shopware\Models\Article\Article;
use Shopware\Models\Article\Detail;
use Shopware\Models\Article\Supplier;
use Shopware\Models\Media\Media;
use Shopware\Models\Media\Album;
use Shopware\Models\Article\Image as ArticleImage;
use Shopware\Models\Article\Configurator\Option;
use jtl\Connector\Shopware\Utilities\Mmc;
use jtl\Connector\Shopware\Utilities\IdConcatenator;
use jtl\Connector\Shopware\Utilities\Locale as LocaleUtil;
use jtl\Connector\Shopware\Utilities\CategoryMapping as CategoryMappingUtil;
use jtl\Connector\Shopware\Utilities\Shop as ShopUtil;
use Shopware\Models\Property\Value;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class Image extends DataMapper
{
    /**
     * @param integer $id
     * @return Media|null
     */
    public function find($id)
    {
        return (intval($id) == 0) ? null : $this->Manager()->getRepository('Shopware\Models\Media\Media')->find((int)$id);
    }

    /**
     * @param mixed[] $kv
     * @return Media|null
     */
    public function findBy(array $kv)
    {
        return $this->Manager()->getRepository('Shopware\Models\Media\Media')->findOneBy($kv);
    }

    /**
     * @param integer|null $limit
     * @param boolean $count
     * @param string|null $relationType
     * @return mixed[]
     */
    public function findAll($limit = null, $count = false, $relationType = null)
    {
        $rsm = new \Doctrine\ORM\Query\ResultSetMapping();
        $rsm->addScalarResult('id', 'id');
        $rsm->addScalarResult('mediaId', 'mediaId');
        $rsm->addScalarResult('path', 'path');

        switch ($relationType) {
            case ImageRelationType::TYPE_PRODUCT:
                $productImages = Shopware()->Db()->fetchAssoc(
                    'SELECT i.id as cId, if (d.id > 0, d.id, a.main_detail_id) as detailId, i.*, m.path
                      FROM s_articles_img i
                      LEFT JOIN s_articles_img c ON c.parent_id = i.id
                      LEFT JOIN s_articles a ON a.id = i.articleID
                      LEFT JOIN s_articles_details d ON d.articleID = a.id
                          AND d.kind = ?
                      LEFT JOIN jtl_connector_link_product_image l ON l.id = i.id
                      JOIN s_media m ON m.id = i.media_id
                      WHERE i.articleID IS NOT NULL
                          AND c.id IS NULL
                          AND l.host_id IS NULL
                      UNION
                      SELECT i.id as cId, i.article_detail_id as detailId, p.*, m.path
                      FROM s_articles_img i
                      JOIN s_articles_img p ON i.parent_id = p.id
                      LEFT JOIN jtl_connector_link_product_image l ON l.id = i.id
                      JOIN s_media m ON m.id = p.media_id
                      WHERE i.articleID IS NULL
                          AND l.host_id IS NULL
                      LIMIT ' . intval($limit)
                    , [Product::KIND_VALUE_PARENT]);

                $translationService = ShopUtil::translationService();
                $shops = Mmc::getMapper('Shop')->findAll(null, null);
                foreach ($productImages as $i => $productImage) {
                    foreach ($shops as $shop) {
                        $translation = $translationService->read($shop['id'], 'articleimage', $productImage['id']);
                        if (!empty($translation)) {
                            $translation['shopId'] = $shop['id'];
                            $productImages[$i]['translations'][$shop['locale']['locale']] = $translation;
                        }
                    }
                }

                return $productImages;

                break;
            case ImageRelationType::TYPE_CATEGORY:
                $query = Shopware()->Models()->createNativeQuery(
                    'SELECT c.id, m.id AS mediaId, m.path
                    FROM s_categories c
                    JOIN s_media m ON m.id = c.mediaID
                    LEFT JOIN jtl_connector_link_image i ON i.media_id = m.id
                    WHERE i.host_id IS NULL
                    LIMIT ' . $limit, $rsm);
                break;
            case ImageRelationType::TYPE_MANUFACTURER:
                $query = Shopware()->Models()->createNativeQuery(
                    'SELECT s.id, m.id AS mediaId, m.path
                    FROM s_articles_supplier s
                    JOIN s_media m ON m.path = s.img
                    LEFT JOIN jtl_connector_link_image i ON i.media_id = m.id
                    WHERE i.host_id IS NULL
                    LIMIT ' . $limit, $rsm);
                break;
            case ImageRelationType::TYPE_SPECIFIC_VALUE:
                $query = Shopware()->Models()->createNativeQuery(
                    'SELECT v.id, m.id AS mediaId, m.path
                    FROM s_filter_values v
                    JOIN s_media m ON m.id = v.media_id
                    LEFT JOIN jtl_connector_link_image i ON i.media_id = m.id
                    WHERE i.host_id IS NULL
                    LIMIT ' . $limit, $rsm);
                break;
        }

        if ($query !== null) {
            return $query->getResult();
        }

        return array();
    }

    /**
     * @param integer $limit
     * @param string|null $relationType
     * @return integer
     */
    public function fetchCount($limit = 100, $relationType = null)
    {
        $rsm = new \Doctrine\ORM\Query\ResultSetMapping();
        $rsm->addScalarResult('count', 'count');

        $query = null;
        $count = 0;
        switch ($relationType) {
            case ImageRelationType::TYPE_PRODUCT:
                $counts = Shopware()->Db()->fetchAssoc(
                    'SELECT count(*) as count
                      FROM s_articles_img i
                      LEFT JOIN s_articles_img c ON c.parent_id = i.id
                      LEFT JOIN s_articles a ON a.id = i.articleID
                      LEFT JOIN jtl_connector_link_product_image l ON l.id = i.id
                      JOIN s_media m ON m.id = i.media_id
                      WHERE i.articleID IS NOT NULL
                          AND c.id IS NULL
                          AND l.host_id IS NULL
                      UNION
                      SELECT count(*) as count
                      FROM s_articles_img i
                      JOIN s_articles_img p ON i.parent_id = p.id
                      LEFT JOIN jtl_connector_link_product_image l ON l.id = i.id
                      JOIN s_media m ON m.id = p.media_id
                      WHERE i.articleID IS NULL
                          AND l.host_id IS NULL'
                );

                foreach ($counts as $c) {
                    $count += (int)$c['count'];
                }

                break;
            case ImageRelationType::TYPE_CATEGORY:
                $query = Shopware()->Models()->createNativeQuery(
                    'SELECT count(*) as count 
                    FROM s_categories c 
                    LEFT JOIN jtl_connector_link_image i ON i.media_id = c.mediaID
                    WHERE c.mediaID > 0
                        AND i.host_id IS NULL', $rsm);
                break;
            case ImageRelationType::TYPE_MANUFACTURER:
                $query = Shopware()->Models()->createNativeQuery(
                    'SELECT count(*) as count
                    FROM s_articles_supplier s
                    JOIN s_media m ON m.path = s.img
                    LEFT JOIN jtl_connector_link_image i ON i.media_id = m.id
                    WHERE LENGTH(s.img) > 0 AND i.host_id IS NULL', $rsm);
                break;
            case ImageRelationType::TYPE_SPECIFIC_VALUE:
                $query = Shopware()->Models()->createNativeQuery(
                    'SELECT count(*) as count
                    FROM s_filter_values v
                    JOIN s_media m ON m.id = v.media_id
                    LEFT JOIN jtl_connector_link_image i ON i.media_id = m.id
                    WHERE v.media_id IS NOT NULL AND i.host_id IS NULL', $rsm);
                break;
        }

        if ($query !== null) {
            $result = $query->getResult();
            if (isset($result[0]['count'])) {
                $count = (int)$result[0]['count'];
            }
        }

        return $count;
    }

    /**
     * @param JtlImage $image
     * @return JtlImage
     */
    public function delete(JtlImage $image)
    {
        $result = $image;

        try {
            $this->deleteImageData($image);
            ShopUtil::entityManager()->flush();
            Application()->getConnector()->getPrimaryKeyMapper()->delete(
                $image->getId()->getEndpoint(),
                $image->getId()->getHost(),
                IdentityLinker::TYPE_IMAGE
            );
        } catch (\Exception $e) {
            Logger::write(ExceptionFormatter::format($e), Logger::ERROR, 'database');
        }

        return $result;
    }

    /**
     * @param JtlImage $jtlImage
     * @return JtlImage
     */
    public function save(JtlImage $jtlImage)
    {
        try {
            $foreignId = $jtlImage->getForeignKey()->getEndpoint();
            if ($foreignId === '') {
                throw new \RuntimeException(sprintf('Foreign key can not be null (%s image: %s)!', $jtlImage->getRelationType(), $jtlImage->getId()->getHost()));
            }

            if (!file_exists($jtlImage->getFilename())) {
                throw new \RuntimeException(sprintf('File (%s) does not exist!', $jtlImage->getFilename()));
            }

            $type = null;
            $imageId = null;
            $mediaId = null;

            $endpointId = $jtlImage->getId()->getEndpoint();
            if ($endpointId !== '') {
                list($type, $imageId, $mediaId) = IdConcatenator::unlink($endpointId);
            }

            if ($jtlImage->getRelationType() !== ImageRelationType::TYPE_PRODUCT) {
                $media = $this->createOrUpdateMedia($jtlImage, $mediaId);
            }

            switch ($jtlImage->getRelationType()) {
                case ImageRelationType::TYPE_PRODUCT:
                    $referencedModel = $this->saveArticleImage($jtlImage);
                    $media = $referencedModel->getMedia();
                    if (!is_null($referencedModel->getParent())) {
                        $media = $referencedModel->getParent()->getMedia();
                    }
                    break;
                case ImageRelationType::TYPE_CATEGORY:
                    $referencedModel = $this->saveCategoryImage($foreignId, $media);
                    break;
                case ImageRelationType::TYPE_MANUFACTURER:
                    $referencedModel = $this->saveSupplierImage($foreignId, $media);
                    break;
                case ImageRelationType::TYPE_SPECIFIC_VALUE:
                    $referencedModel = $this->savePropertyValueImage($foreignId, $media);
                    break;
            }

            ShopUtil::entityManager()->persist($referencedModel);
            ShopUtil::entityManager()->flush();

            // Save image title translations
            if ($referencedModel instanceof ArticleImage && is_null($referencedModel->getParent())) {
                $this->saveAltText($jtlImage, $referencedModel);
            }

            $endpoint = \jtl\Connector\Shopware\Model\Image::generateId($jtlImage->getRelationType(), $referencedModel->getId(), $media->getId());
            if (strlen($jtlImage->getId()->getEndpoint()) > 0 && $jtlImage->getId()->getHost() > 0
                && $endpoint !== $jtlImage->getId()->getEndpoint()) {

                Application()->getConnector()->getPrimaryKeyMapper()->delete(
                    $jtlImage->getId()->getEndpoint(),
                    $jtlImage->getId()->getHost(),
                    IdentityLinker::TYPE_IMAGE
                );

                Application()->getConnector()->getPrimaryKeyMapper()->save(
                    $endpoint,
                    $jtlImage->getId()->getHost(),
                    IdentityLinker::TYPE_IMAGE
                );
            }

            // Result
            $jtlImage->setId(new Identity($endpoint, $jtlImage->getId()->getHost()))
                ->setForeignKey(new Identity($jtlImage->getForeignKey()->getEndpoint(), $jtlImage->getForeignKey()->getHost()))
                ->setRelationType($jtlImage->getRelationType())
                ->setFilename(ShopUtil::mediaService()->getUrl($media->getPath()));;

        } catch (ORMException $e) {
            Logger::write(ExceptionFormatter::format($e), Logger::ERROR, 'database');

        } catch (\Exception $e) {
            Logger::write(ExceptionFormatter::format($e), Logger::ERROR, 'image');
        }

        return $jtlImage;
    }

    /**
     * @param JtlImage $jtlImage
     * @return string|null
     */
    protected function createArticleImageName(JtlImage $jtlImage, Article $article, Detail $detail): string
    {
        $name = $this->getProductSeoName($article, $detail, $jtlImage);
        if ($jtlImage->getName() !== '') {
            $name = $jtlImage->getName();
        }

        if (strlen($name) > 100) {
            $name = substr($name, strlen($name) - 100, 100);
        }

        return $name;
    }

    /**
     * @param JtlImage $image
     * @return JtlImage
     * @throws \Doctrine\ORM\ORMException
     * @throws OptimisticLockException
     * @throws TransactionRequiredException
     */
    protected function deleteImageData(JtlImage &$image)
    {
        if (strlen($image->getId()->getEndpoint()) === 0) {
            return $image;
        }

        list($type, $imageId, $mediaId) = IdConcatenator::unlink($image->getId()->getEndpoint());
        $foreignId = (strlen($image->getForeignKey()->getEndpoint()) > 0) ? $image->getForeignKey()->getEndpoint() : null;
        if (is_null($foreignId)) {
            throw new \RuntimeException(sprintf('Foreign key from image (%s/%s) is empty!', $image->getId()->getEndpoint(), $image->getId()->getHost()));
        }

        $deleteMedia = true;
        switch ($image->getRelationType()) {
            case ImageRelationType::TYPE_PRODUCT:
                list($detailId, $articleId) = IdConcatenator::unlink($foreignId);
                $stmt = ShopUtil::entityManager()->getDBALQueryBuilder()
                    ->select('COUNT(clpi.media_id)')
                    ->from('jtl_connector_link_product_image', 'clpi')
                    ->where('clpi.media_id = :mediaId')
                    ->setParameters(['mediaId' => $mediaId])
                    ->execute();

                $mediaCount = (int)$stmt->fetchColumn();

                $deleteMedia = ($mediaCount < 2);
                $this->deleteArticleImage($articleId, $detailId, $imageId, $deleteMedia);

                break;
            case ImageRelationType::TYPE_CATEGORY:
                $categorySW = $this->Manager()->getRepository(Category::class)->find((int)$imageId);
                if ($categorySW !== null) {
                    $categorySW->setMedia(null);

                    try {
                        ShopUtil::entityManager()->persist($categorySW);
                    } catch (\Exception $e) {
                        Logger::write(ExceptionFormatter::format($e), Logger::ERROR, 'database');
                    }
                }
                break;
            case ImageRelationType::TYPE_MANUFACTURER:
                $supplierSW = $this->Manager()->getRepository(Supplier::class)->find((int)$imageId);
                if ($supplierSW !== null) {
                    $supplierSW->setImage('');

                    try {
                        ShopUtil::entityManager()->persist($supplierSW);
                    } catch (\Exception $e) {
                        Logger::write(ExceptionFormatter::format($e), Logger::ERROR, 'database');
                    }
                }
                break;
        }

        if ($deleteMedia) {
            $media = ShopUtil::entityManager()->find(Media::class, $mediaId);
            if (!is_null($media)) {
                $this->deleteMedia($media);
            }
        }

        ShopUtil::entityManager()->flush();
        if ($image->getRelationType() === ImageRelationType::TYPE_PRODUCT) {
            ShopUtil::translationService()->deleteAll('articleimage', $imageId);
        }
    }

    /**
     * @param integer $articleId
     * @param integer $detailId
     * @param integer $imageId
     * @param bool $isLastImage
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws TransactionRequiredException
     */
    protected function deleteArticleImage($articleId, $detailId, $imageId, $isLastImage = false)
    {
        /** @var Article $article */
        $article = ShopUtil::entityManager()->find(Article::class, $articleId);
        if (is_null($article)) {
            Logger::write('Article (' . $articleId . ') not found!', Logger::DEBUG, 'image');
            return;
        }

        /** @var Detail $articleDetail */
        foreach ($article->getDetails() as $articleDetail) {
            if ($articleDetail->getId() == $detailId) {
                $detail = $articleDetail;
                break;
            }
        }

        if (is_null($detail)) {
            Logger::write('Detail (' . $detailId . ') not found!', Logger::DEBUG, 'image');
            return;

        }

        /** @var Product $productMapper */
        $productMapper = Mmc::getMapper('Product');
        if ($productMapper->isChildSW($article, $detail)) {
            $imageId = $this->deletePseudoArticleImage($detail, $imageId);
        }

        if (!is_null($imageId) && $isLastImage === true) {
            $this->deleteRealArticleImage($article, $imageId);
        }
    }

    /**
     * @param Article $article
     * @param integer $imageId
     * @throws ORMException
     */
    protected function deleteRealArticleImage(Article $article, $imageId)
    {
        $swImage = null;
        /** @var ArticleImage $image */
        foreach ($article->getImages() as $image) {
            if ($image->getId() == $imageId) {
                $swImage = $image;
                break;
            }
        }

        if (is_null($swImage)) {
            Logger::write('To be deleted image (' . $imageId . ') not found!', Logger::DEBUG, 'image');
            return;
        }

        ShopUtil::entityManager()->remove($swImage);
        $article->getImages()->removeElement($swImage);
        if ($swImage->getMain() === 1 && $article->getImages()->count() > 0) {
            /** @var ArticleImage $newMainImage */
            $newMainImage = $article->getImages()->first();
            foreach ($article->getImages() as $image) {
                if ($image->getPosition() < $newMainImage->getPosition()) {
                    $newMainImage = $image;
                }
            }
            $newMainImage->setMain(1);
            ShopUtil::entityManager()->persist($newMainImage);
            Logger::write(sprintf('Article (%s) main image (%s) switched', $article->getId(), $newMainImage->getId()), Logger::DEBUG, 'image');
        }
        Logger::write(sprintf('Article (%s) image (%s) deleted', $article->getId(), $imageId), Logger::DEBUG, 'image');
    }

    /**
     * @param Detail $detail
     * @param integer $imageId
     * @return int|null
     * @throws ORMException
     */
    protected function deletePseudoArticleImage(Detail $detail, $imageId)
    {
        $swImage = null;
        /** @var ArticleImage $image */
        foreach ($detail->getImages() as $image) {
            if ($image->getId() == $imageId) {
                $swImage = $image;
                break;
            }
        }

        if (is_null($swImage)) {
            Logger::write('To be deleted pseudo image (' . $imageId . ') not found!', Logger::DEBUG, 'image');
            return null;
        }

        Logger::write(sprintf('Article (%s) detail (%s) pseudo image (%s) deleted', $detail->getArticleId(), $detail->getId(), $imageId), Logger::DEBUG, 'image');
        ShopUtil::entityManager()->remove($swImage);
        $detail->getImages()->removeElement($swImage);
        if (!empty($swImage->getParent())) {
            $this->rebuildArticleImagesMappings($swImage->getParent()->getArticle());
            return $swImage->getParent()->getId();
        }

        return null;
    }

    /**
     * @param ArticleImage $swImage
     */
    protected function removeImageMappings(ArticleImage $swImage)
    {
        /** @var ArticleImage\Mapping $mapping */
        foreach ($swImage->getMappings() as $mapping) {
            foreach ($mapping->getRules() as $rule) {
                ShopUtil::entityManager()->remove($rule);
            }
            ShopUtil::entityManager()->remove($mapping);
        }
    }

    /**
     * @param Media $media
     * @throws ORMException
     */
    protected function deleteMedia(Media $media)
    {
        ShopUtil::thumbnailManager()->removeMediaThumbnails($media);
        $this->Manager()->remove($media);
        Logger::write(sprintf('Media (%s) deleted', $media->getId()), Logger::DEBUG, 'image');
    }


    /**
     * @param JtlImage $jtlImage
     * @param int|null $mediaId
     * @return ArticleImage|null
     * @throws LanguageException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws TransactionRequiredException
     */
    protected function saveArticleImage(JtlImage $jtlImage)
    {
        list($detailId, $articleId) = IdConcatenator::unlink($jtlImage->getForeignKey()->getEndpoint());

        /** @var Article $article */
        $article = ShopUtil::entityManager()->find(Article::class, $articleId);
        if (is_null($article)) {
            throw new \RuntimeException('Article (' . $articleId . ') not found');
        }

        $detail = null;
        /** @var Detail $articleDetail */
        foreach ($article->getDetails() as $articleDetail) {
            if ($articleDetail->getId() == $detailId) {
                $detail = $articleDetail;
                break;
            }
        }

        if (is_null($detail)) {
            throw new \RuntimeException('Article (' . $articleId . ') detail (' . $detailId . ') not found');
        }

        /** @var Product $productMapper */
        $productMapper = Mmc::getMapper('Product');

        $isVariantChild = $productMapper->isChildSW($article, $detail);

        /** @var \Shopware\Components\Api\Resource\Article $articleResource */
        $articleResource = ShopUtil::get()->Container()->get('shopware.api.article');
        if (is_null($articleResource->getManager())) {
            $articleResource->setManager(ShopUtil::entityManager());
        }

        $existingImage = $this->findExistingArticleImage($article, $jtlImage);
        $imageExists = !is_null($existingImage);

        $mediaId = null;
        if ($imageExists) {
            $mediaId = $existingImage->getMedia()->getId();
        } elseif ($jtlImage->getId()->getEndpoint() !== '') {
            list($type, $imageId, $mediaId) = IdConcatenator::unlink($jtlImage->getId()->getEndpoint());
        }

        $isImageReplacement = !$imageExists && !is_null($mediaId);

        if (!$isVariantChild || !$imageExists) {
            $fileName = $this->createArticleImageName($jtlImage, $article, $detail);
            $media = $this->createOrUpdateMedia($jtlImage, $mediaId, $fileName);
            if ($isImageReplacement) {
                $existingImage = $this->findExistingArticleImage($article, $jtlImage);
                $imageExists = !is_null($existingImage);
            }
        } else {
            $media = $this->find($mediaId);
        }

        if ($imageExists) {
            $swImage = $existingImage;
        } else {
            $swImage = $articleResource->createNewArticleImage($article, $media);
            $swImage->setPosition((count($article->getImages()) + 1));

            Logger::write(
                sprintf(
                    'Real image (%s) for product/article (%s/%s) saved',
                    $jtlImage->getId()->getHost(),
                    $jtlImage->getForeignKey()->getEndpoint(),
                    $jtlImage->getForeignKey()->getHost()
                ),
                Logger::DEBUG,
                'image'
            );
        }

        $imageDescription = '';

        try {
            $i18n = I18n::findByLocale(ShopUtil::locale()->getLocale(), ...$jtlImage->getI18ns());
            $imageDescription = $i18n->getAltText();
        } catch (\Throwable $ex) {

        }

        $variantImage = null;
        if (!$isVariantChild) {
            $swImage = $articleResource->updateArticleImageWithMedia($article, $swImage, $media);
            $swPos = $jtlImage->getSort();
            $swMain = $swPos === 1 ? 1 : 2;
            if ($swMain === 1) {
                /** @var ArticleImage $aImage */
                foreach ($article->getImages() as $aImage) {
                    $aImage->setMain(2);
                }
            }

            foreach ($article->getImages() as $aImage) {
                if ($aImage->getPosition() >= $swPos) {
                    $aImage->setPosition($aImage->getPosition() + 1);
                }
            }

            $swImage->setPath($media->getName());
            $swImage->setDescription($imageDescription);
            $swImage->setMain($swMain);
            $swImage->setPosition($swPos);
        } else {
            if (!$imageExists) {
                $swImage->setPosition((count($article->getImages()) + 1));
            }

            /** @var ArticleImage $child */
            foreach ($swImage->getChildren() as $child) {
                if ($child->getArticleDetail()->getId() === $detail->getId()) {
                    $variantImage = $child;
                    break;
                }
            }

            if (is_null($variantImage)) {
                $variantImage = new ArticleImage();
                $variantImage->setArticleDetail($detail);
                $variantImage->setExtension($swImage->getExtension());
                $variantImage->setParent($swImage);
                ShopUtil::entityManager()->persist($variantImage);
                $swImage->getChildren()->add($variantImage);
                $detail->getImages()->add($variantImage);
            }

            $variantPos = $jtlImage->getSort();
            $variantMain = $variantPos === 1 ? 1 : 2;
            if ($variantMain === 1) {
                /** @var ArticleImage $image */
                foreach ($detail->getImages() as $image) {
                    $image->setMain(2);
                }
            }

            foreach ($detail->getImages() as $image) {
                if ($image->getPosition() >= $variantPos) {
                    $image->setPosition($image->getPosition() + 1);
                }
            }

            $variantImage->setPath($media->getName());
            $variantImage->setDescription($imageDescription);
            $variantImage->setMain($variantMain);
            $variantImage->setPosition($jtlImage->getSort());
            ShopUtil::entityManager()->persist($variantImage);
            Sort::reSort($detail->getImages()->toArray(), 'position');

            Logger::write(
                sprintf(
                    'Pseudo variant image for product/article (%s/%s) - main (%s) - sort (%s) saved',
                    $jtlImage->getForeignKey()->getEndpoint(),
                    $jtlImage->getForeignKey()->getHost(),
                    $variantMain,
                    $jtlImage->getSort()
                ),
                Logger::DEBUG,
                'image'
            );
        }

        Sort::reSort($article->getImages()->toArray(), 'position');
        if ($article->getImages()->count() === 1) {
            $swImage->setMain(1);
            $swImage->setPosition(1);
        }

        ShopUtil::entityManager()->persist($swImage);
        ShopUtil::entityManager()->persist($article);
        ShopUtil::entityManager()->persist($detail);

        if (count($article->getDetails()) > 1) {
            $this->rebuildArticleImagesMappings($article);
        }

        return !is_null($variantImage) ? $variantImage : $swImage;
    }

    /**
     * @param integer $supplierId
     * @param Media $media
     * @return Supplier
     */
    protected function saveSupplierImage($supplierId, Media $media)
    {
        /** @var Supplier $supplier */
        $supplier = ShopUtil::entityManager()->getRepository(Supplier::class)->find((int)$supplierId);
        if ($supplier === null) {
            throw new \RuntimeException(sprintf('Can not find manufacturer (%s)', $supplierId));
        }

        $supplier->setImage($media->getPath());
        return $supplier;
    }

    /**
     * @param integer $categoryId
     * @param Media $media
     * @return Category
     */
    protected function saveCategoryImage($categoryId, Media $media)
    {
        /** @var Category $category */
        $category = ShopUtil::entityManager()->getRepository(Category::class)->find((int)$categoryId);
        if (is_null($category)) {
            throw new \RuntimeException(sprintf('Cannot find category (%s)', $categoryId));
        }

        // Special category mapping
        /** @deprecated Will be removed in a future connector release  $mappingOld */
        $mappingOld = Application()->getConfig()->get('category_mapping', false);
        if (Application()->getConfig()->get('category.mapping', $mappingOld)) {
            $categorySWs = CategoryMappingUtil::findAllCategoriesByMappingParent($categoryId);
            foreach ($categorySWs as $categorySW) {
                $categorySW->setMedia($media);
                ShopUtil::entityManager()->persist($categorySW);
            }
        }

        $category->setMedia($media);

        return $category;
    }

    /**
     * @param integer $propertyValueId
     * @param Media $media
     * @return Value
     */
    protected function savePropertyValueImage($propertyValueId, Media $media)
    {
        /** @var Value $propertyValue */
        $propertyValue = ShopUtil::entityManager()->getRepository(Value::class)->find((int)$propertyValueId);
        if ($propertyValue === null) {
            throw new \RuntimeException(sprintf('Cannot find specific value (%s)', $propertyValueId));
        }

        $propertyValue->setMedia($media);

        return $propertyValue;
    }

    /**
     * @param JtlImage $jtlImage
     * @param int|null $mediaId
     * @param string|null $imageName
     * @return Media|null
     * @throws LanguageException
     * @throws ORMException
     */
    protected function createOrUpdateMedia(JtlImage $jtlImage, ?int $mediaId, string $imageName = null)
    {
        if (!is_null($mediaId)) {
            $media = $this->find((int)$mediaId);
            if (!$this->isImageDataIdentical($jtlImage, $media)) {
                /** @var MediaReplaceService $replaceService */
                $replaceService = ShopUtil::get()->Container()->get('shopware_media.replace_service');
                $replaceService->replace((int)$mediaId, new UploadedFile($jtlImage->getFilename(), $imageName ?? $jtlImage->getFilename()));
                ShopUtil::entityManager()->refresh($media);
            }
        } else {
            $media = $this->createMedia($jtlImage);
            ShopUtil::entityManager()->refresh($media);
        }

        $mediaChanged = false;

        $altText = '';
        try {
            /** @var ImageI18n $i18n */
            $i18n = I18n::findByLocale(ShopUtil::locale()->getLocale(), ...$jtlImage->getI18ns());
            $altText = $i18n->getAltText();
        } catch (\Throwable $ex) {

        }

        if ($media->getDescription() !== $altText) {
            $media->setDescription($altText);
            $mediaChanged = true;
        }

        if (is_null($imageName)) {
            $imageName = $jtlImage->getName();
        }

        $newImageName = $this->sanitizeImageName($imageName);
        $mediaNameCompare = substr($media->getName(), 0, -4);
        if ($imageName !== '' && $media->getName() !== $newImageName && sprintf('%s-', $newImageName) !== $mediaNameCompare) {
            $mediaService = ShopUtil::mediaService();
            do {
                $newPath = sprintf('%s/%s.%s', substr($media->getPath(), 0, strrpos($media->getPath(), '/')), $newImageName, $media->getExtension());
                if (!$mediaService->has($newPath)) {
                    break;
                }

                $newImageName = sprintf('%s-%s', $imageName, Random::getAlphanumericString(4));
            } while (true);

            $media->setName($newImageName);
            $mediaChanged = true;
        }

        if ($mediaChanged) {
            ShopUtil::entityManager()->persist($media);
            ShopUtil::entityManager()->flush();
        }

        return $media;
    }

    /**
     * @param JtlImage $jtlImage
     * @param string|null $fileName
     * @return Media
     * @throws \Exception
     */
    protected function createMedia(JtlImage $jtlImage, string $fileName = null)
    {
        $albumId = null;
        switch ($jtlImage->getRelationType()) {
            case ImageRelationType::TYPE_PRODUCT:
                $albumId = -1;
                break;
            case ImageRelationType::TYPE_CATEGORY:
            case ImageRelationType::TYPE_SPECIFIC_VALUE:
                $albumId = -9;
                break;
            case ImageRelationType::TYPE_MANUFACTURER:
                $albumId = -12;
                break;
            default:
                $albumId = -10;
                break;
        }

        $album = ShopUtil::entityManager()->getRepository(Album::class)->find($albumId);
        if (is_null($album)) {
            throw new \RuntimeException(sprintf('Album (%s) not found!', $albumId));
        }

        $media = (new Media())
            ->setFile(new UploadedFile($jtlImage->getFilename(), $fileName ?? $jtlImage->getFilename()))
            ->setDescription('')
            ->setCreated(new \DateTime())
            ->setUserId(0)
            ->setAlbum($album);

        ShopUtil::entityManager()->persist($media);
        ShopUtil::entityManager()->flush();

        return $media;
    }

    /**
     * @param Article $article
     * @param Detail $detail
     * @param JtlImage $jtlImage
     * @return bool|string
     */
    protected function getProductSeoName(Article $article, Detail $detail, JtlImage $jtlImage)
    {
        $seo = new Seo();

        $pk = ' ' . $jtlImage->getId()->getHost();
        if ($article->getConfiguratorSet() !== null && $article->getConfiguratorSet()->getId() > 0) {  // Varkombi
            $pk = '';
        }

        $productSeo = sprintf('%s %s%s',
            $article->getName(),
            $detail->getAdditionalText(),
            $pk
        );

        if (strlen($productSeo) > 60) {
            $pos = strpos($productSeo, ' ', 60);
            if ($pos === false) {
                $pos = 60;
            }

            $productSeo = substr($productSeo, 0, $pos);
        }

        return $seo->create(
            sprintf('%s %s',
                $productSeo,
                $detail->getNumber()
            )
        );
    }

    /**
     * @param Article $article
     * @param JtlImage $jtlImage
     * @return null|ArticleImage
     */
    protected function findExistingArticleImage(Article $article, JtlImage $jtlImage)
    {
        if (count($article->getImages()) > 0) {
            clearstatcache();
            /** @var ArticleImage $image */
            foreach ($article->getImages() as $image) {
                try {
                    if ($this->isImageDataIdentical($jtlImage, $image->getMedia())) {
                        return $image;
                    }
                } catch (\Throwable $ex) {
                    Logger::write(ExceptionFormatter::format($ex, 'SW image (id = ' . $image->getId() . ')'), Logger::ERROR);
                }
            }
        }

        return null;
    }

    /**
     * @param JtlImage $jtlImage
     * @param Media $media
     * @return bool
     */
    protected function isImageDataIdentical(JtlImage $jtlImage, Media $media): bool
    {
        $swImageContent = ShopUtil::mediaService()->read($media->getPath());
        return md5_file($jtlImage->getFilename()) === md5($swImageContent);
    }

    /**
     * @param JtlImage $jtlImage
     * @param ArticleImage $swImage
     * @throws \Zend_Db_Adapter_Exception
     * @throws LanguageException
     */
    private function saveAltText(JtlImage $jtlImage, ArticleImage $swImage)
    {
        $translationService = ShopUtil::translationService();
        $translationService->deleteAll('articleimage', $swImage->getId());

        /** @var Shop $shopMapper */
        $shopMapper = Mmc::getMapper('Shop');
        foreach ($jtlImage->getI18ns() as $i18n) {
            if (empty($i18n->getAltText())) {
                continue;
            }

            $shopLanguageIso = Utilities\Language::map(ShopUtil::locale()->getLocale());
            if ($i18n->getLanguageISO() !== $shopLanguageIso) {
                $locale = LocaleUtil::getByKey(Utilities\Language::map(null, null, $i18n->getLanguageISO()));
                $shops = $shopMapper->findByLocale($locale->getLocale());

                if ($shops !== null && is_array($shops) && count($shops) > 0) {
                    foreach ($shops as $shop) {
                        $translationService->write(
                            $shop->getId(),
                            'articleimage',
                            $swImage->getId(),
                            array(
                                'description' => $i18n->getAltText()
                            )
                        );
                    }
                }
            }
        }
    }

    /**
     * @param string $name
     *
     * @return string
     */
    private function sanitizeImageName($name)
    {
        $name = iconv('utf-8', 'ascii//translit', $name);
        $name = preg_replace('#[^A-Za-z0-9\-_]#', '-', $name);
        $name = preg_replace('#-{2,}#', '-', $name);
        $name = trim($name, '-');

        return mb_substr($name, 0, 180);
    }
}
