<?php

namespace MageDad\LowStockNotification\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\Area;
use Magento\Framework\Filesystem;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\View\Layout;
use Magento\Framework\View\Element\Template;
use Magento\InventoryApi\Api\StockRepositoryInterface;
use Magento\InventorySalesApi\Model\GetStockItemDataInterface;
use Magento\InventoryConfigurationApi\Api\GetStockItemConfigurationInterface;
use Magento\InventoryReservationsApi\Model\GetReservationsQuantityInterface;
use Magento\InventoryConfigurationApi\Model\IsSourceItemManagementAllowedForSkuInterface;
use Magento\InventoryApi\Model\IsProductAssignedToStockInterface;
use Magento\Catalog\Model\Product\Type;
use Magento\GroupedProduct\Model\Product\Type\Grouped as GroupedProductType;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableProductType;
use Magento\Downloadable\Model\Product\Type as DownloadableProductType;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\File\Csv;
use Magento\Reports\Model\ResourceModel\Product\Lowstock\CollectionFactory as LowstocksFactory;
use Magento\InventoryLowQuantityNotification\Model\ResourceModel\LowQuantityCollectionFactory;

/**
 * Class Data
 * @package MageDad\LowStockNotification\Helper
 */
class Data extends AbstractHelper
{
    const XML_PATH_GENERAL_QTY = 'lowstocknotification/low_stock_notification/qty';

    const FILE_DIR = 'lowstocknotification/low_stock_notification/email/email_template';

    const CSV_PATH = 'lowstocknotification';

    const LOW_STOCK_SINGLE_ALERT_TEMPLATE_FILE =
                    'MageDad_LowStockNotification::notifications/low_stock_single_alert.phtml';

    const LOW_STOCK_ALERT_TEMPLATE_FILE = 'MageDad_LowStockNotification::notifications/low_stock_alert.phtml';

    const SINGLE_ALERT_EMAIL_TEMPLATE = 'lowstocknotification_low_stock_single_notification_email_email_template';

    const SUPPORTED_PRODUCT_TYPE = [
                                        Type::TYPE_SIMPLE,
                                        Type::TYPE_VIRTUAL,
                                        GroupedProductType::TYPE_CODE,
                                        ConfigurableProductType::TYPE_CODE,
                                        DownloadableProductType::TYPE_DOWNLOADABLE
                                    ];

    const REPORT_ALERT_EMAIL_TEMPLATE = 'lowstocknotification_low_stock_report_notification_email_email_template';
    /**
     * @var TransportBuilder
     */
    private $transportBuilder;

    /**
     * @var StateInterface
     */
    private $inlineTranslation;

    /**
     * @var mixed
     */
    private $storeManager;

    /**
     * @var StockRepositoryInterface
     */
    private $stockRepository;

    /**
     * @var GetStockItemDataInterface
     */
    private $getStockItemData;

    /**
     * @var GetStockItemConfigurationInterface
     */
    private $getStockItemConfiguration;

    /**
     * @var GetReservationsQuantityInterface
     */
    private $getReservationsQuantity;

    /**
     * @var IsSourceItemManagementAllowedForSkuInterface
     */
    private $isSourceItemManagementAllowedForSku;

    /**
     * @var IsProductAssignedToStockInterface
     */
    private $isProductAssignedToStock;

    /**
     * @var \Magento\Reports\Model\ResourceModel\Product\Lowstock\CollectionFactory
     */
    protected $_lowstocksFactory;

    /**
     * @var LowQuantityCollectionFactory
     */
    private $lowQuantityCollectionFactory;

    /**
     * Data constructor.
     * @param Context $context
     * @param TransportBuilder $transportBuilder
     * @param StateInterface $inlineTranslation
     * @param StoreManagerInterface|null $storeManager
     * @param StockRepositoryInterface $stockRepository
     * @param GetStockItemDataInterface $getStockItemData
     * @param GetStockItemConfigurationInterface $getStockItemConfiguration
     * @param GetReservationsQuantityInterface $getReservationsQuantity
     * @param IsSourceItemManagementAllowedForSkuInterface $isSourceItemManagementAllowedForSku
     * @param IsProductAssignedToStockInterface $isProductAssignedToStock
     * @param \Magento\Framework\File\Csv $csvProcessor
     * @param DirectoryList $directoryList
     * @param \Magento\Framework\Filesystem $filesystem
     * @param CollectionFactory $productCollectionFactory
     * @param Layout $layout
     */
    public function __construct(
        Context $context,
        TransportBuilder $transportBuilder,
        StateInterface $inlineTranslation,
        StoreManagerInterface $storeManager = null,
        StockRepositoryInterface $stockRepository,
        GetStockItemDataInterface $getStockItemData,
        GetStockItemConfigurationInterface $getStockItemConfiguration,
        GetReservationsQuantityInterface $getReservationsQuantity,
        IsSourceItemManagementAllowedForSkuInterface $isSourceItemManagementAllowedForSku,
        IsProductAssignedToStockInterface $isProductAssignedToStock,
        Csv $csvProcessor,
        DirectoryList $directoryList,
        Filesystem $filesystem,
        CollectionFactory $productCollectionFactory,
        Layout $layout,
        LowstocksFactory $lowstocksFactory,
        LowQuantityCollectionFactory $lowQuantityCollectionFactory
    ) {
        $this->stockRepository = $stockRepository;
        $this->getStockItemData = $getStockItemData;
        $this->getStockItemConfiguration = $getStockItemConfiguration;
        $this->getReservationsQuantity = $getReservationsQuantity;
        $this->isSourceItemManagementAllowedForSku = $isSourceItemManagementAllowedForSku;
        $this->isProductAssignedToStock = $isProductAssignedToStock;
        $this->transportBuilder = $transportBuilder;
        $this->inlineTranslation = $inlineTranslation;
        $this->storeManager = $storeManager ?: ObjectManager::getInstance()->get(StoreManagerInterface::class);
        $this->filesystem = $filesystem;
        $this->directoryList = $directoryList;
        $this->csvProcessor = $csvProcessor;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->layout = $layout;
        $this->_lowstocksFactory = $lowstocksFactory;
        $this->lowQuantityCollectionFactory = $lowQuantityCollectionFactory;
        parent::__construct($context);
    }

    /**
     * @param $path
     * @param int $storeId
     * @return mixed
     */
    public function getModuleConfig($path)
    {
        return $this->scopeConfig->getValue(
            'lowstocknotification/' . $path,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * @return bool
     */
    public function isEnabled() : bool
    {
        return (bool) $this->getModuleConfig('low_stock_notification/active');
    }

    /**
     * @return mixed
     */
    public function getQty()
    {
        return $this->getModuleConfig('low_stock_notification/qty');
    }

    /**
     * @return string
     */
    public function emailTemplate() : string
    {
        return (string) $this->getModuleConfig('low_stock_notification/email/email_template');
    }

    /**
     * @return string
     */
    public function emailSender() : string
    {
        return (string) $this->getModuleConfig('low_stock_notification/email/sender_email_identity');
    }

    /**
     * @return string
     */
    public function emailRecipient() : string
    {
        return (string) $this->getModuleConfig('low_stock_notification/email/recipient_email');
    }

    /**
     * @param $proudctData
     * @return string
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    private function writeToCsv($proudctData)
    {
        $fileDirectoryPath = $this->directoryList->getPath(DirectoryList::MEDIA);
        $fileDirectoryPath.= '/'.self::CSV_PATH;
        if (!is_dir($fileDirectoryPath)) {
            mkdir($fileDirectoryPath, 0777, true);
        }

        $fileName = time().'-products.csv';
        $filePath =  $fileDirectoryPath . '/' . $fileName;

        $header = array_keys($proudctData[0]);
        $data = $proudctData;
        array_unshift($data, $header);
        $this->csvProcessor
            ->setEnclosure('"')
            ->setDelimiter(',')
            ->saveData($filePath, $data);

        return $filePath;
    }

    /**
     * @param array $lowStockItems
     * @return bool
     * @throws \Exception
     */
    public function notify($lowStockItems = [])
    {
        $this->inlineTranslation->suspend();
        try {
            if ($this->isEnabled()) {

                if ($this->getQty() === null || !$lowStockItems) {
                    return true;
                }
                $items = [];
                foreach ($lowStockItems as $key => $item) {
                    $data = [
                                'name' => $item->getName(),
                                'sku' => $item->getSku(),
                                'stockName' => $item->getData('stockName'),
                                'saleable' => $item->getData('saleable'),
                                'quantity' => $item->getData('quantity')
                            ];
                    $items[] = $data;
                }

                $lowStockItemBlock = $this->layout->createBlock(Template::class)
                    ->setTemplate(self::LOW_STOCK_SINGLE_ALERT_TEMPLATE_FILE)
                    ->setData('lowStockItems', $items);

                $lowStockHtml = $lowStockItemBlock->toHtml();

                $transport = $this->transportBuilder
                    ->setTemplateIdentifier(self::SINGLE_ALERT_EMAIL_TEMPLATE)
                    ->setTemplateOptions(
                        [
                            'area' => Area::AREA_FRONTEND,
                            'store' => $this->storeManager->getStore()->getId()
                        ]
                    )
                    ->setTemplateVars(['qty' => $this->getQty(),'lowStockHtml' => $lowStockHtml])
                    ->setFrom($this->emailSender())
                    ->addTo($this->emailRecipient());

                $transport->getTransport()->sendMessage();
                return true;
            }
        } catch (\Exception $e) {
            throw $e;
        } finally {
            $this->inlineTranslation->resume();
        }

        return false;
    }

    /**
     * @param array $proudctData
     * @return mixed
     */
    public function lowStockItems($proudctData = [])
    {
        $lowStockItemBlock = $this->layout->createBlock(Template::class)
            ->setTemplate(self::LOW_STOCK_ALERT_TEMPLATE_FILE)
            ->setData('lowStockItems', $proudctData);
        return $lowStockItemBlock->toHtml();
    }

}
