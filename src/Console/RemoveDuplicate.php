<?php

declare(strict_types=1);

namespace Elgentos\RemoveDuplicateImage\Console;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

class RemoveDuplicate extends Command
{
    public function __construct(
        private readonly State $state,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly DirectoryList $directoryList,
        private readonly \Magento\Framework\App\ResourceConnection $resource,
    ) {
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('duplicate:remove')
            ->setDescription('Remove duplicate product images')
            ->addOption(
                'unlink',
                'u',
                InputOption::VALUE_OPTIONAL,
                'Unlink the duplicate files from system',
                false
            )
            ->addOption(
                'dryrun',
                'd',
                InputOption::VALUE_OPTIONAL,
                'Dry-run does not delete any values or files',
                true
            )
            ->addArgument(
                'products',
                InputArgument::IS_ARRAY,
                'Product entity SKUs to filter on',
                null
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->state->setAreaCode(Area::AREA_GLOBAL);

        $this->storeManager->setCurrentStore(0);

        $unlink = $input->getOption('unlink') === 'false' ? false : $input->getOption('unlink');
        $dryrun = $input->getOption('dryrun') === 'false' ? false : $input->getOption('dryrun');

        $mediaUrl = $this->storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA);
        $path = $this->directoryList->getPath('media');
        $productCollection = $this->productCollectionFactory->create();
        if ($input->getArgument('products')) {
            $productCollection->addFieldToFilter('sku' , ['in' => $input->getArgument('products')]);
        } else {
            $productCollection->addIdFilter($this->getEntityIds());
        }

        $i = 0;
        $total = $productCollection->getSize();
        $count = 0;

        if ($dryrun) {
            $output->writeln('THIS IS A DRY-RUN, NO CHANGES WILL BE MADE!');
        }
        $output->writeln($total . ' products found with 2 images or more.');

        foreach ($productCollection as $product) {
            $product = $this->productRepository->getById($product->getId());
            $product->setStoreId(0);
            $md5Values = [];
            $baseImage = $product->getImage();

            if ($baseImage !== 'no_selection') {
                $filepath = $path . '/catalog/product' . $baseImage;
                if (file_exists($filepath) && is_file($filepath)) {
                    $md5Values[] = md5_file($filepath);
                }

                $i++;
                $output->writeln("Processing product $i of $total");

                $gallery = $product->getMediaGalleryEntries();

                $shouldSave = false;

                $filepaths = [];
                if ($gallery && count($gallery)) {
                    foreach ($gallery as $key => $galleryImage) {
                        if ($galleryImage->getFile() == $baseImage) {
                            continue;
                        }
                        $filepath = $path . '/catalog/product' . $galleryImage->getFile();

                        if (file_exists($filepath)) {
                            $md5 = md5_file($filepath);
                        } else {
                            continue;
                        }

                        if (in_array($md5, $md5Values)) {
                            if (count($galleryImage->getTypes()) > 0) {
                                continue;
                            }
                            unset($gallery[$key]);
                            $filepaths[] = $filepath;
                            $output->writeln('Removed duplicate image from ' . $product->getSku());
                            $count++;
                            $shouldSave = true;
                        } else {
                            $md5Values[] = $md5;
                        }
                    }

                    if (!$dryrun && $shouldSave) {
                        $product->setMediaGalleryEntries($gallery);
                        try {
                            $this->productRepository->save($product);
                        } catch (\Exception $e) {
                            $output->writeln('Could not save product: ' . $e->getMessage());
                        }
                    }

                    foreach ($filepaths as $filepath) {
                        if (is_file($filepath)) {
                            if (
                                !$dryrun
                                && $unlink
                                && $shouldSave
                            ) {
                                unlink($filepath);
                            }
                            if (
                                $unlink
                                && $shouldSave
                            ) {
                                $output->writeln('Deleted file: ' . $filepath);
                            }
                        }
                    }
                }
            }
        }

        if ($dryrun) {
            $output->writeln('THIS WAS A DRY-RUN, NO CHANGES WERE MADE!');
        } else {
            $output->writeln('Duplicate images are removed');
        }

        return Command::SUCCESS;
    }

    public function getEntityIds()
    {
        $connection = $this->resource->getConnection();
        $tableName = $this->resource->getTableName('catalog_product_entity_media_gallery_value_to_entity');

        $select = $connection->select()
            ->from($tableName, ['entity_id'])
            ->group('entity_id')
            ->having('COUNT(entity_id) >= 2');

        $result = $connection->fetchCol($select);

        return $result;
    }
}
