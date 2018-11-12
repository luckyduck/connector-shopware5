<?php
/**
 * @copyright 2010-2013 JTL-Software GmbH
 * @package jtl\Connector\Shopware\Controller
 */

namespace jtl\Connector\Shopware\Mapper;

use jtl\Connector\Core\IO\Path;
use jtl\Connector\Core\IO\Temp;
use jtl\Connector\Core\Logger\Logger;
use jtl\Connector\Core\Utilities\Seo;
use \jtl\Connector\Drawing\ImageRelationType;
use jtl\Connector\Formatter\ExceptionFormatter;
use jtl\Connector\Linker\IdentityLinker;
use \jtl\Connector\Model\Image as JtlImage;
use \jtl\Connector\Shopware\Model\Image as ImageConModel;
use \jtl\Connector\Model\Identity;
use jtl\Connector\Shopware\Utilities\Sort;
use Shopware\Components\Api\Manager;
use Shopware\Models\Article\Article;
use Shopware\Models\Article\Configurator\Option;
use Shopware\Models\Article\Detail;
use Shopware\Models\Media\Album;
use \Shopware\Models\Media\Media as MediaSW;
use \Shopware\Models\Article\Image as ArticleImage;
use \jtl\Connector\Shopware\Utilities\Mmc;
use \jtl\Connector\Shopware\Utilities\IdConcatenator;
use \Shopware\Models\Article\Detail as DetailSW;
use \Shopware\Models\Article\Article as ArticleSW;
use Shopware\Models\Media\Media;
use \Symfony\Component\HttpFoundation\File\File;
use jtl\Connector\Core\Utilities\Language as LanguageUtil;
use jtl\Connector\Shopware\Utilities\Translation as TranslationUtil;
use jtl\Connector\Shopware\Utilities\Locale as LocaleUtil;
use jtl\Connector\Shopware\Utilities\CategoryMapping as CategoryMappingUtil;
use \jtl\Connector\Shopware\Utilities\Shop;

class Image extends DataMapper
{
    public function find($id)
    {
        return (intval($id) == 0) ? null : $this->Manager()->getRepository('Shopware\Models\Media\Media')->find((int)$id);
    }

    public function findBy(array $kv)
    {
        return $this->Manager()->getRepository('Shopware\Models\Media\Media')->findOneBy($kv);
    }

    public function findArticleImage($id)
    {
        try {
            return $this->Manager()->createQueryBuilder()
                ->select(
                    'image',
                    'media'
                )
                ->from('Shopware\Models\Article\Image', 'image')
                ->leftJoin('image.media', 'media')
                ->where('image.id = :id')
                ->setParameter('id', $id)
                ->getQuery()->getOneOrNullResult();
        } catch (\Exception $e) {
            return null;
        }
    }

    public function findAll($limit = null, $count = false, $relationType = null)
    {
        $rsm = new \Doctrine\ORM\Query\ResultSetMapping();
        $rsm->addScalarResult('id', 'id');
        $rsm->addScalarResult('mediaId', 'mediaId');
        $rsm->addScalarResult('path', 'path');

        switch ($relationType) {
            case ImageRelationType::TYPE_PRODUCT:
                return Shopware()->Db()->fetchAssoc(
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

                /*
                return $this->Manager()->createQueryBuilder()
                    ->select(
                        'image',
                        'article',
                        'media',
                        'parent',
                        'pmedia'
                    )
                    ->from('jtl\Connector\Shopware\Model\Linker\ProductImage', 'image')
                    ->leftJoin('image.article', 'article')
                    ->leftJoin('image.media', 'media')
                    ->leftJoin('image.parent', 'parent')
                    ->leftJoin('parent.media', 'pmedia')
                    ->leftJoin('image.linker', 'linker')
                    ->setFirstResult(0)
                    ->setMaxResults($limit)
                    ->where('linker.hostId IS NULL')
                    ->getQuery()->getResult(\Doctrine\ORM\AbstractQuery::HYDRATE_ARRAY);
                */
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

    public function delete(JtlImage $image)
    {
        $result = $image;

        try {
            $this->deleteImageData($image);
            Shop::entityManager()->flush();
        } catch (\Exception $e) {
            Logger::write(ExceptionFormatter::format($e), Logger::ERROR, 'database');
        }

        return $result;
    }

    public function save(JtlImage $jtlImage)
    {
        $mediaSW = null;
        $imageSW = null;
        $parentImageSW = null;
        $result = new JtlImage;

        $foreignId = (strlen($jtlImage->getForeignKey()->getEndpoint()) > 0) ? $jtlImage->getForeignKey()->getEndpoint() : null;
        if ($foreignId === null) {
            throw new \RuntimeException('ForeignKey cannot be null');
        }

        try {

            //Extra try catch blog for transaction debug message!
            try {
                Logger::write('Transaction start', Logger::DEBUG, 'database');
                $this->prepareImageAssociatedData($jtlImage, $mediaSW, $imageSW, $parentImageSW);
                Shop::entityManager()->persist($mediaSW);
                Shop::entityManager()->persist($imageSW);
                Shop::entityManager()->flush();
            } catch (\Exception $ex) {
                Logger::write('Transaction rollback', Logger::DEBUG, 'database');
                throw $ex;
            }

            // Save image title translations
            if (!is_null($parentImageSW)) {
                $this->saveAltText($jtlImage, $parentImageSW);
            }

            $manager = Shopware()->Container()->get('thumbnail_manager');
            $manager->createMediaThumbnail($mediaSW, array(), true);

            $endpoint = ImageConModel::generateId($jtlImage->getRelationType(), $imageSW->getId(), $mediaSW->getId());
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
            $result->setId(new Identity($endpoint, $jtlImage->getId()->getHost()))
                ->setForeignKey(new Identity($jtlImage->getForeignKey()->getEndpoint(), $jtlImage->getForeignKey()->getHost()))
                ->setRelationType($jtlImage->getRelationType())
                ->setFilename(Shop::mediaService()->getUrl($mediaSW->getPath()));
            ;

        } catch (\Exception $e) {
            Logger::write(ExceptionFormatter::format($e), Logger::ERROR, 'database');

        } finally {
            @unlink($jtlImage->getFilename());
        }

        return $result;
    }

    /**
     * @param JtlImage $image
     * @return JtlImage
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     * @throws \Doctrine\ORM\TransactionRequiredException
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
                $deleteMedia = false;
                list($detailId, $articleId) = IdConcatenator::unlink($foreignId);
                $this->deleteProductImage($articleId, $detailId, $imageId);
                Shop::entityManager()->flush();
                break;
            case ImageRelationType::TYPE_CATEGORY:
                $categorySW = $this->Manager()->getRepository('Shopware\Models\Category\Category')->find((int)$imageId);
                if ($categorySW !== null) {
                    $categorySW->setMedia(null);

                    try {
                        $this->Manager()->persist($categorySW);
                        $this->Manager()->flush();
                    } catch (\Exception $e) {
                        Logger::write(ExceptionFormatter::format($e), Logger::ERROR, 'database');
                    }
                }
                break;
            case ImageRelationType::TYPE_MANUFACTURER:
                $supplierSW = $this->Manager()->getRepository('Shopware\Models\Article\Supplier')->find((int)$imageId);
                if ($supplierSW !== null) {
                    $supplierSW->setImage('');

                    try {
                        $this->Manager()->persist($supplierSW);
                        $this->Manager()->flush();
                    } catch (\Exception $e) {
                        Logger::write(ExceptionFormatter::format($e), Logger::ERROR, 'database');
                    }
                }
                break;
        }

        if ($deleteMedia) {
            $media = Shop::entityManager()->find(Media::class, $mediaId);
            if(!is_null($media)) {
                $this->deleteMedia($media);
            }
        }
    }

    /**
     * @param integer $articleId
     * @param integer $detailId
     * @param integer $imageId
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     * @throws \Doctrine\ORM\TransactionRequiredException
     */
    protected function deleteProductImage($articleId, $detailId, $imageId)
    {
        /** @var Article $article */
        $article = Shop::entityManager()->find(Article::class, $articleId);
        if (is_null($article)) {
            throw new \RuntimeException('Article (' . $articleId . ') not found!');
        }

        /** @var Detail $articleDetail */
        foreach($article->getDetails() as $articleDetail) {
            if($articleDetail->getId() == $detailId) {
                $detail = $articleDetail;
                break;
            }
        }

        if (is_null($detail)) {
            throw new \RuntimeException('Detail (' . $detailId . ') not found!');
        }

        $swImage = null;
        /** @var ArticleImage $image */
        foreach ($detail->getImages() as $image) {
            if ($image->getId() == $imageId) {
                $swImage = $image;
                break;
            }
        }

        if (is_null($swImage)) {
            throw new \RuntimeException('Image (' . $imageId . ') not found!');
        }

        if (!is_null($swImage->getParent())) {
            $mapping = $this->findImageMapping($detail, $swImage->getParent());
            if (!is_null($mapping)) {
                foreach ($mapping->getRules() as $rule) {
                    Shop::entityManager()->remove($rule);
                }
                shop::entityManager()->remove($mapping);
            }
            Shop::entityManager()->remove($swImage);
            $swImage = $swImage->getParent();

            Logger::write(
                sprintf(
                    'Article (%s) detail (%s) pseudo image (%s) and depending mappings deleted',
                    $articleId,
                    $detailId,
                    $imageId
                ),
                Logger::DEBUG,
                'image'
            );
        }

        if ($swImage->getChildren()->count() < 2) {
            $this->deleteMedia($swImage->getMedia());
            Shop::entityManager()->remove($swImage);

            Logger::write(
                sprintf(
                    'Image (%s) from article (%s) deleted',
                    $imageId,
                    $articleId
                ),
                Logger::DEBUG,
                'image'
            );
        }
    }

    /**
     * @param DetailSW $detail
     * @param ArticleImage $swImage
     * @return null|Mapping
     */
    protected function findImageMapping(Detail $detail, ArticleImage $swImage)
    {
        $detailOptions = array_map(function (Option $option) {
            return $option->getId();
        }, $detail->getConfiguratorOptions()->toArray());

        /** @var ArticleImage\Mapping $mapping */
        foreach ($swImage->getMappings() as $mapping) {
            $mappingOptions = [];
            /** @var ArticleImage\Rule $rule */
            foreach ($mapping->getRules() as $rule) {
                $mappingOptions[] = $rule->getOption()->getId();
            }

            if ($detailOptions == $mappingOptions) {
                return $mapping;
            }
        }
        return null;
    }


    /**
     * @param Media $media
     */
    protected function deleteMedia(Media $media)
    {
        $mediaService = Shop::mediaService();
        $thumbnailManager = Shop::thumbnailManager();
        $thumbnailManager->removeMediaThumbnails($media);
        $mediaService->delete($media->getPath());
        $this->Manager()->remove($media);
    }

    /**
     * @param JtlImage $jtlImage
     * @param Media|null $media
     * @param \Shopware\Components\Model\ModelEntity|null $swImage
     * @param \Shopware\Components\Model\ModelEntity|null $swParentImage
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     * @throws \Doctrine\ORM\TransactionRequiredException
     * @throws \RuntimeException
     */
    protected function prepareImageAssociatedData(JtlImage &$jtlImage,
                                                  MediaSW &$media = null,
                                                  \Shopware\Components\Model\ModelEntity &$swImage = null,
                                                  \Shopware\Components\Model\ModelEntity &$swParentImage = null)
    {
        if (!file_exists($jtlImage->getFilename())) {
            throw new \RuntimeException(sprintf('File (%s) does not exist!', $jtlImage->getFilename()));
        }

        if ($jtlImage->getRelationType() === ImageRelationType::TYPE_PRODUCT) {
            $swImage = $this->prepareProductImageAssociateData($jtlImage);
            $media = $swImage->getMedia();
            if(!is_null($swImage->getParent())) {
                $swParentImage = $swImage->getParent();
                $media = $swParentImage->getMedia();
            }
        } else {
            $media = $this->getMedia($jtlImage);
            $this->prepareTypeSwitchAssociateData($jtlImage, $media, $swImage);
        }
    }

    /**
     * @param JtlImage $jtlImage
     * @param Media $media
     * @param \Shopware\Components\Model\ModelEntity|null $swImage
     * @throws \RuntimeException
     */
    protected function prepareTypeSwitchAssociateData(JtlImage &$jtlImage, MediaSW &$media, \Shopware\Components\Model\ModelEntity &$swImage = null)
    {
        switch ($jtlImage->getRelationType()) {
            case ImageRelationType::TYPE_CATEGORY:
                $this->prepareCategoryImageAssociateData($jtlImage, $media, $swImage);
                break;
            case ImageRelationType::TYPE_MANUFACTURER:
                $this->prepareManufacturerImageAssociateData($jtlImage, $media, $swImage);
                break;
            case ImageRelationType::TYPE_SPECIFIC_VALUE:
                $this->prepareSpecificValueImageAssociateDate($jtlImage, $media, $swImage);
                break;
        }
    }

    /**
     * @param JtlImage $jtlImage
     * @return ArticleImage
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     * @throws \Doctrine\ORM\TransactionRequiredException
     * @throws \RuntimeException
     */
    protected function prepareProductImageAssociateData(JtlImage &$jtlImage)
    {
        list($detailId, $articleId) = IdConcatenator::unlink($jtlImage->getForeignKey()->getEndpoint());

        /** @var Article $article */
        $article = Shop::entityManager()->find(Article::class, $articleId);
        if (is_null($article)) {
            throw new \RuntimeException('Article (' . $articleId . ') not found!');
        }

        $detail = null;
        /** @var Detail $articleDetail */
        foreach ($article->getDetails() as $articleDetail) {
            if ($articleDetail->getId() == $detailId) {
                $detail = $articleDetail;
                break;
            }
        }

        if (is_null($article)) {
            throw new \RuntimeException('Detail (' . $detailId . ') not found!');
        }

        /** @var Product $productMapper */
        $productMapper = Mmc::getMapper('Product');

        /** @var \Shopware\Components\Api\Resource\Article $articleResource */
        $articleResource = Manager::getResource('article');

        $existingImage = $this->findExistingImage($article, $jtlImage);
        $imageExists = !is_null($existingImage);

        $swImage = new ArticleImage();
        $media = new Media();

        $isNewImage = empty($jtlImage->getId()->getEndpoint());
        if (!$isNewImage) {
            list($type, $imageId, $mediaId) = IdConcatenator::unlink($jtlImage->getId()->getEndpoint());

            /** @var ArticleImage $swImage */
            $swImage = Shop::entityManager()->find(ArticleImage::class, $imageId);
            $media = $swImage->getMedia();
        }

        if (!$imageExists) {
            list ($uuid, $ext) = explode('.', $jtlImage->getFilename());

            // Seo
            $productSeo = $this->getProductSeoName($article, $detail, $jtlImage);
            $filename = sprintf('%s.%s', $productSeo, $ext);
            if (strlen($filename) > 100) {
                $filename = substr($filename, strlen($filename) - 100, 100);
            }

            $path = Path::combine(Temp::getDirectory(), $filename);
            if (!copy($jtlImage->getFilename(), $path)) {
                throw new \RuntimeException('File with host id ' . $jtlImage->getId()->getHost() . 'could not get copied!');
            }
            $jtlImage->setFilename($path);

            $media = $this->initMedia($jtlImage, $media);

            if ($isNewImage) {
                $swImage = $articleResource->createNewArticleImage($article, $media);
                if ($productMapper->isChildSW($article, $detail)) {
                    $swImage->setArticleDetail($detail);
                }
            } else {
                $swImage = $articleResource->updateArticleImageWithMedia($article, $swImage, $media);
            }

            Logger::write(
                sprintf(
                    'Real image for Product (%s/%s) - Image (%s/%s) saved',
                    $jtlImage->getForeignKey()->getEndpoint(),
                    $jtlImage->getForeignKey()->getHost(),
                    $jtlImage->getId()->getEndpoint(),
                    $jtlImage->getId()->getHost()
                ),
                Logger::DEBUG,
                'image'
            );

        } else {
            $swImage = $existingImage;
        }

        if ($article->getImages()->count() === 1) {
            $swImage->setMain(1);
            $swImage->setPosition(1);
        } elseif (!$productMapper->isChildSW($article, $detail)) {
            $swMain = $jtlImage->getSort() === 1 ? 1 : 2;
            if ($swMain === 1) {
                /** @var ArticleImage $aImage */
                foreach ($article->getImages() as $aImage) {
                    $aImage->setMain(2);
                }
            }
            $swImage->setMain($swMain);
            $swImage->setPosition($jtlImage->getSort());
            Sort::reSort($article->getImages()->toArray(), 'position');
        } elseif (!$imageExists) {
            $swImage->setPosition((count($article->getImages()) + 1));
            Sort::reSort($article->getImages()->toArray(), 'position');
        }

        $variantImage = null;
        if ($productMapper->isChildSW($article, $detail)) {
            if ($isNewImage) {
                $variantImage = new ArticleImage();
                $variantImage->setArticleDetail($detail);
                $variantImage->setExtension($swImage->getExtension());
                $variantImage->setParent($swImage);
                Shop::entityManager()->persist($variantImage);

                $mapping = new ArticleImage\Mapping();
                $mapping->setImage($swImage);
                foreach ($detail->getConfiguratorOptions() as $option) {
                    $rule = new ArticleImage\Rule();
                    $rule->setMapping($mapping);
                    $rule->setOption($option);
                    Shop::entityManager()->persist($rule);
                    $mapping->getRules()->add($rule);
                }
                Shop::entityManager()->persist($mapping);
            }

            /** @var ArticleImage $child */
            foreach ($swImage->getChildren() as $child) {
                if ($child->getArticleDetail()->getId() === $detail->getId()) {
                    $variantImage = $child;
                    break;
                }
            }

            if (is_null($variantImage)) {
                throw new \RuntimeException('Pseudo variant image for article detail (' . $detail->getId() . ') not found!');
            }

            $variantMain = $jtlImage->getSort() === 1 ? 1 : 2;
            if ($variantMain === 1) {
                /** @var ArticleImage $image */
                foreach ($detail->getImages() as $image) {
                    $image->setMain(2);
                }
            }
            $variantImage->setMain($variantMain);
            $variantImage->setPosition($jtlImage->getSort());
            Shop::entityManager()->persist($variantImage);

            Logger::write(
                sprintf(
                    'Pseudo variant image for Product (%s/%s) - Main (%s) - Sort (%s)saved',
                    $jtlImage->getForeignKey()->getEndpoint(),
                    $jtlImage->getForeignKey()->getHost(),
                    $variantMain,
                    $jtlImage->getSort()
                ),
                Logger::DEBUG,
                'image'
            );
        }

        Shop::entityManager()->persist($swImage);
        Shop::entityManager()->persist($article);
        Shop::entityManager()->persist($detail);

        return !is_null($variantImage) ? $variantImage : $swImage;
    }

    /**
     * @param JtlImage $image
     * @param Media $mediaSW
     * @param ArticleImage|null $imageSW
     * @throws \RuntimeException
     */
    protected function prepareManufacturerImageAssociateData(JtlImage &$image, MediaSW &$mediaSW, ArticleImage &$imageSW = null)
    {
        $foreignId = (int)$image->getForeignKey()->getEndpoint();
        $imageSW = $this->Manager()->getRepository('Shopware\Models\Article\Supplier')->find((int)$foreignId);
        if ($imageSW === null) {
            throw new \RuntimeException(sprintf('Cannot find manufacturer with id (%s)', $foreignId));
        }

        $imageSW->setImage($mediaSW->getPath());
    }

    /**
     * @param JtlImage $jtlImage
     * @param Media $media
     * @param ArticleImage|null $swImage
     * @throws \RuntimeException
     */
    protected function prepareCategoryImageAssociateData(JtlImage &$jtlImage, Media &$media, ArticleImage &$swImage = null)
    {
        $foreignId = (int)$jtlImage->getForeignKey()->getEndpoint();
        $swImage = $this->Manager()->getRepository('Shopware\Models\Category\Category')->find($foreignId);
        if (is_null($swImage)) {
            throw new \RuntimeException(sprintf('Cannot find category with id (%s)', $foreignId));
        }

        // Special category mapping
        /** @deprecated Will be removed in a future connector release  $mappingOld */
        $mappingOld = Application()->getConfig()->get('category_mapping', false);
        if (Application()->getConfig()->get('category.mapping', $mappingOld)) {
            $categorySWs = CategoryMappingUtil::findAllCategoriesByMappingParent($foreignId);
            foreach ($categorySWs as $categorySW) {
                $categorySW->setMedia($media);
                $this->Manager()->persist($categorySW);
            }
        }

        $swImage->setMedia($media);
    }

    /**
     * @param JtlImage $jtlImage
     * @param Media $media
     * @param ArticleImage|null $swImage
     * @throws \RuntimeException
     */
    protected function prepareSpecificValueImageAssociateDate(JtlImage &$jtlImage, Media &$media, ArticleImage &$swImage = null)
    {
        $foreignId = (int)$jtlImage->getForeignKey()->getEndpoint();
        $swImage = $this->Manager()->getRepository('Shopware\Models\Property\Value')->find($foreignId);
        if ($swImage === null) {
            throw new \RuntimeException(sprintf('Cannot find specific value with id (%s)', $foreignId));
        }

        $swImage->setMedia($media);
    }

    /**
     * @param JtlImage $jtlImage
     * @return Media
     * @throws \RuntimeException
     */
    protected function getMedia(JtlImage $jtlImage)
    {
        $mediaSW = null;
        $imageId = (strlen($jtlImage->getId()->getEndpoint()) > 0) ? $jtlImage->getId()->getEndpoint() : null;
        if ($imageId !== null) {
            list($type, $imageId, $mediaId) = IdConcatenator::unlink($jtlImage->getId()->getEndpoint());
            $mediaSW = $this->find((int)$mediaId);
        }

        return $this->initMedia($jtlImage, $mediaSW);
    }

    /**
     * @param JtlImage $jtlImage
     * @param Media $media
     * @return Media
     * @throws \RuntimeException
     */
    protected function initMedia(JtlImage $jtlImage, Media $media)
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

        $album = Shop::entityManager()->getRepository(Album::class)->find($albumId);
        if (is_null($album)) {
            throw new \RuntimeException(sprintf('Album with id (%s) not found', $albumId));
        }

        if (is_null($media)) {
            $media = new Media();
        }

        $media
            ->setFile(new File($jtlImage->getFilename()))
            ->setDescription('')
            ->setCreated(new \DateTime())
            ->setUserId(0)
            ->setAlbum($album);

        Shop::entityManager()->persist($media);

        return $media;
    }

    /**
     * @param ArticleSW $article
     * @param DetailSW $detail
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
     * @param ArticleSW $article
     * @param JtlImage $jtlImage
     * @return null|ArticleImage
     */
    protected function findExistingImage(Article $article, JtlImage $jtlImage)
    {
        if (count($article->getImages()) > 0) {
            clearstatcache();
            /** @var ArticleImage $image */
            foreach ($article->getImages() as $image) {
                try {
                    $media = $image->getMedia();
                    $swImageContent = Shop::mediaService()->read($media->getPath());
                    if (md5_file($jtlImage->getFilename()) == md5($swImageContent)) {
                        return $image;
                    }
                } catch (\Exception $ex) {
                    Logger::write($ex->getMessage(), Logger::ERROR);
                }
            }
        }

        return null;
    }

    /**
     * @param JtlImage $jtlImage
     * @param ArticleImage $swImage
     * @throws \Zend_Db_Adapter_Exception
     * @throws \jtl\Connector\Core\Exception\LanguageException
     */
    private function saveAltText(JtlImage $jtlImage, ArticleImage &$swImage)
    {
        $translationUtil = new TranslationUtil();
        $translationUtil->delete('articleimage', $swImage->getId());

        $shopMapper = Mmc::getMapper('Shop');
        foreach ($jtlImage->getI18ns() as $i18n) {
            if (empty($i18n->getAltText())) {
                continue;
            }

            if ($i18n->getLanguageISO() !== LanguageUtil::map(Shopware()->Shop()->getLocale()->getLocale())) {
                $locale = LocaleUtil::getByKey(LanguageUtil::map(null, null, $i18n->getLanguageISO()));
                $shops = $shopMapper->findByLocale($locale->getLocale());

                if ($shops !== null && is_array($shops) && count($shops) > 0) {
                    foreach ($shops as $shop) {
                        $translationUtil->write(
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
}