<?php

declare(strict_types=1);

namespace Elgentos\RemoveDuplicateImage\Console;

use Magento\Catalog\Api\Data\ProductSearchResultsInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Area;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RemoveDuplicate extends Command
{
    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    /**
     * @var State
     */
    private $state;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var DirectoryList
     */
    private $directoryList;

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var File
     */
    private $fileDriver;

    /**
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param State $state
     * @param ProductRepositoryInterface $productRepository
     * @param StoreManagerInterface $storeManager
     * @param DirectoryList $directoryList
     * @param ResourceConnection $resource
     * @param File $fileDriver
     */
    public function __construct(
        SearchCriteriaBuilder $searchCriteriaBuilder,
        State $state,
        ProductRepositoryInterface $productRepository,
        StoreManagerInterface $storeManager,
        DirectoryList $directoryList,
        ResourceConnection $resource,
        File $fileDriver
    ) {
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->state = $state;
        $this->productRepository = $productRepository;
        $this->storeManager = $storeManager;
        $this->directoryList = $directoryList;
        $this->resource = $resource;
        $this->fileDriver = $fileDriver;
        parent::__construct();
    }

    /**
     * @inheritdoc
     */
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

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $this->state->setAreaCode(Area::AREA_GLOBAL);
        } catch (\Exception $e) {
            return Cli::RETURN_FAILURE;
        }

        $this->storeManager->setCurrentStore(0);

        $isUnlink = !($input->getOption('unlink') === 'false') && $input->getOption('unlink');
        $isDryRun = !($input->getOption('dryrun') === 'false') && $input->getOption('dryrun');

        $path = $this->directoryList->getPath('media');

        $targetProductSku = $input->getArgument('products') ?: $this->getEntityIds();
        $searchCriteriaBuilder = $this->searchCriteriaBuilder
            ->addFilter('image', 'no_selection', 'neq');

        if ($input->getArgument('products')) {
            $searchCriteriaBuilder->addFilter('sku', $targetProductSku, 'in');
        } else {
            $searchCriteriaBuilder->addFilter('entity_id', $this->getEntityIds(), 'in');
        }

        $searchCriteriaBuilder = $searchCriteriaBuilder->create();

        /** @var ProductSearchResultsInterface $products */
        $products = $this->productRepository->getList($searchCriteriaBuilder);

        if (!$products->getTotalCount()) {
            return Cli::RETURN_SUCCESS;
        }

        if ($isDryRun) {
            $output->writeln('THIS IS A DRY-RUN, NO CHANGES WILL BE MADE!');
        }
        $output->writeln(sprintf('%s products found with 2 images or more.', $products->getTotalCount()));

        foreach ($products->getItems() as $product) {
            $product->setStoreId(0);
            $md5Values = [];
            $baseImage = $product->getImage();

            $filePath = $path . '/catalog/product' . $baseImage;
            if ($this->isFileExists($filePath)) {
                $md5Values[] = md5_file($filePath);
            }

            $gallery = $product->getMediaGalleryEntries();

            $shouldSave = false;
            $filePaths = [];

            if (empty($gallery)) {
                continue;
            }

            foreach ($gallery as $key => $galleryImage) {
                if ($galleryImage->getFile() == $baseImage) {
                    continue;
                }

                $filePath = $path . '/catalog/product' . $galleryImage->getFile();

                if ($this->isFileExists($filePath)) {
                    $md5 = md5_file($filePath);
                } else {
                    continue;
                }

                if (in_array($md5, $md5Values)) {
                    if (count($galleryImage->getTypes()) > 0) {
                        continue;
                    }
                    unset($gallery[$key]);
                    $filePaths[] = $filePath;
                    $output->writeln(sprintf('Removed duplicate image from %s', $product->getSku()));
                    $shouldSave = true;
                } else {
                    $md5Values[] = $md5;
                }
            }

            if (!$isDryRun && $shouldSave) {
                $product->setMediaGalleryEntries($gallery);
                try {
                    $this->productRepository->save($product);
                } catch (\Exception $e) {
                    $output->writeln('Could not save product: ' . $e->getMessage());
                }
            }

            foreach ($filePaths as $filePath) {
                if (!$this->isFile($filePath)) {
                    continue;
                }

                if (!$isDryRun
                    && $isUnlink
                    && $shouldSave
                ) {
                    try {
                        $this->fileDriver->deleteFile($filePath);
                    } catch (FileSystemException $e) {
                        continue;
                    }
                }

                if ($isUnlink
                    && $shouldSave
                ) {
                    $output->writeln('Deleted file: ' . $filePath);
                }
            }
        }

        if ($isDryRun) {
            $output->writeln('THIS WAS A DRY-RUN, NO CHANGES WERE MADE!');
        } else {
            $output->writeln('Duplicate images are removed');
        }

        return Cli::RETURN_SUCCESS;
    }

    /**
     * Get Entity IDs related
     *
     * @return array
     */
    public function getEntityIds(): array
    {
        $connection = $this->resource->getConnection();
        $tableName = $this->resource->getTableName('catalog_product_entity_media_gallery_value_to_entity');

        $select = $connection->select()
            ->from($tableName, ['entity_id'])
            ->group('entity_id')
            ->having('COUNT(entity_id) >= 2');

        return $connection->fetchCol($select);
    }

    /**
     * Is file exists
     *
     * @param string $path
     * @return bool
     */
    protected function isFileExists(string $path): bool
    {
        try {
            $fileExists = $this->fileDriver->isExists($path);
        } catch (\Exception $exception) {
            $fileExists = false;
        }

        return $fileExists;
    }

    /***
     * Tells whether the filename is a regular file
     *
     * @param string $path
     * @return bool
     */
    protected function isFile(string $path): bool
    {
        try {
            $isFile = $this->fileDriver->isFile($path);
        } catch (\Exception $exception) {
            $isFile = false;
        }

        return $isFile;
    }
}
