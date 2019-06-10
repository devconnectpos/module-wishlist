<?php
/**
 * Created by mr.vjcspy@gmail.com - khoild@smartosc.com.
 * Date: 05/01/2017
 * Time: 17:54
 */

namespace SM\Wishlist\Repositories;

use Exception;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Helper\Product;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Wishlist\Model\ResourceModel\Item\Option\CollectionFactory;
use Magento\Wishlist\Model\WishlistFactory;
use SM\XRetail\Helper\Data;
use SM\XRetail\Helper\DataConfig;
use SM\XRetail\Repositories\Contract\ServiceAbstract;

/**
 * Class WishlistManagement
 *
 * @package SM\Wishlist\Repositories
 */
class WishlistManagement extends ServiceAbstract
{
    /**
     * @var
     */
    protected $wishlist;
    /**
     * @var \Magento\Wishlist\Model\WishlistFactory
     */
    protected $wishlistFactory;
    /**
     * @var \Magento\Catalog\Api\ProductRepositoryInterface
     */
    protected $productRepository;
    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    protected $objectManager;
    /**
     * @var \Magento\Framework\Event\ManagerInterface
     */
    protected $eventManager;
    /**
     * @var \Magento\Wishlist\Model\ResourceModel\Item\Option\CollectionFactory
     */
    private $wishlistItemOptionCollectionFactory;
    /**
     * @var \Magento\Catalog\Helper\Product
     */
    private $catalogProduct;

    /**
     * @var   \SM\XRetail\Helper\Data
     */
    private $retailConfig;

    /**
     * WishlistManagement constructor.
     *
     * @param \Magento\Framework\App\RequestInterface                             $requestInterface
     * @param \SM\XRetail\Helper\DataConfig                                       $dataConfig
     * @param \SM\XRetail\Helper\Data                                             $retailConfig
     * @param \Magento\Store\Model\StoreManagerInterface                          $storeManager
     * @param \Magento\Wishlist\Model\WishlistFactory                             $wishlistFactory
     * @param \Magento\Catalog\Api\ProductRepositoryInterface                     $productRepository
     * @param \Magento\Framework\ObjectManagerInterface                           $objectManager
     * @param \Magento\Framework\Event\ManagerInterface                           $eventManager
     * @param \Magento\Wishlist\Model\ResourceModel\Item\Option\CollectionFactory $wishlistItemOptionCollectionFactory
     * @param \Magento\Catalog\Helper\Product                                     $catalogProduct
     */
    public function __construct(
        RequestInterface $requestInterface,
        DataConfig $dataConfig,
        Data $retailConfig,
        StoreManagerInterface $storeManager,
        WishlistFactory $wishlistFactory,
        ProductRepositoryInterface $productRepository,
        ObjectManagerInterface $objectManager,
        ManagerInterface $eventManager,
        CollectionFactory $wishlistItemOptionCollectionFactory,
        Product $catalogProduct
    ) {
        $this->objectManager                       = $objectManager;
        $this->productRepository                   = $productRepository;
        $this->wishlistFactory                     = $wishlistFactory;
        $this->eventManager                       = $eventManager;
        $this->wishlistItemOptionCollectionFactory = $wishlistItemOptionCollectionFactory;
        $this->retailConfig = $retailConfig;
        $this->catalogProduct                      = $catalogProduct;
        parent::__construct($requestInterface, $dataConfig, $storeManager);
    }

    /**
     * @return array
     * @throws \Exception
     */
    public function add()
    {
        $this->catalogProduct->setSkipSaleableCheck(true);
        
        $wishlist = $this->getWishlist();
        if (!$wishlist) {
            throw new Exception(__("Can't get wishlist."));
        }

        $requestParams = $this->getRequestData();

        if ($requestParams->getData('store_id')) {
            $this->storeManager->setCurrentStore($requestParams->getData('store_id'));
        } else {
            throw new Exception("require store_id data");
        }
        try {
            foreach ($requestParams->getData('items') as $item) {
                $productId = isset($item['product_id']) ? (int)$item['product_id'] : null;
                if (!$productId) {
                    throw new Exception("Can't find product id");
                }

                $product = $this->productRepository->getById($productId);

                $buyRequest = new DataObject($item);

                $result = $wishlist->addNewItem($product, $buyRequest);
                if (is_string($result)) {
                    throw new LocalizedException(__($result));
                }

            }
            $wishlist->save();

            $this->eventManager->dispatch(
                'wishlist_add_product',
                ['wishlist' => $wishlist, 'product' => $product, 'item' => $result]
            );

            $this->objectManager->get('Magento\Wishlist\Helper\Data')->calculate();
        } catch (LocalizedException $e) {
            throw new Exception(
                __('We can\'t add the item to Wish List right now: %1.', $e->getMessage())
            );
        } catch (Exception $e) {
            throw new Exception(
                __('We can\'t add the item to Wish List right now.')
            );
        }

        return ["success" => true, "message" => __('You pushed items to wishlist.')];
    }

    /**
     * @return \Magento\Wishlist\Model\Wishlist
     * @throws \Exception
     */
    protected function getWishlist()
    {
        if ($this->wishlist) {
            return $this->wishlist;
        }
        try {
            if ($customerId = $this->getRequestData()->getData('customer_id')) {
                /** @var  \Magento\Wishlist\Model\Wishlist $wishlist */
                $wishlist = $this->wishlistFactory->create();
                $wishlist->loadByCustomerId($customerId, true);

                if (!$wishlist->getId() || $wishlist->getCustomerId() != $customerId) {
                    throw new \Magento\Framework\Exception\NoSuchEntityException(
                        __('The requested Wish List doesn\'t exist.')
                    );
                }
            } else {
                throw new Exception("Can't find customer id");
            }
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            throw new Exception($e->getMessage());

        } catch (Exception $e) {
            throw new Exception(__('We can\'t create the Wish List right now.'));

        }
        $this->wishlist = $wishlist;

        return $wishlist;
    }

    /**
     * @param $customerId
     * @param $storeId
     * @param $usingProductOnline
     *
     * @return array
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getWishlistData($customerId, $storeId, $usingProductOnline)
    {
        $wishlistFactory = $this->wishlistFactory->create();
        $wishlistItems   = $wishlistFactory->loadByCustomerId($customerId, true)
                                           ->setStore($this->getStoreManager()->getStore($storeId))
                                           ->getItemCollection()
                                           ->getData();

        $items = [];

        if (sizeof($wishlistItems) == 0) {
        } else {
            foreach ($wishlistItems as $item) {
                $productId = $item['product_id'];
                $store     = $item['store_id'];
                if ($storeId == $store && !is_null($store) && !is_null($productId)) {
                    $productType = $this->productRepository->getById($productId)->getTypeId();
                    if ($productType === "grouped") {
                        $option = $this->wishlistItemOptionCollectionFactory->create()
                                                                            ->addItemFilter([$item['wishlist_item_id']])
                                                                            ->getLastItem();
                    } else {
                        $option = $this->wishlistItemOptionCollectionFactory->create()
                                                                            ->addItemFilter([$item['wishlist_item_id']])
                                                                            ->getFirstItem();
                    }

                    if ($option->getData('option_id')) {
                        $item['buyRequest'] = $this->retailConfig->unserialize($option->getData('value'));
                    }

                    if ($usingProductOnline) {
                        $item['product'] = $this->productRepository->getById($productId)->getData();
                    }
                    $items[] = $item;
                }
            }
        }

        return $items;
    }


    /**
     * @return array
     * @throws \Exception
     */
    public function remove()
    {
        $customerId   = $this->getRequest()->getParam('customer_id');
        $wishlistItem = $this->getRequest()->getParam('items');
        $removeAll    = $this->getRequest()->getParam('removeAll');

        if (is_null($customerId) || ((!is_array($wishlistItem) || count($wishlistItem) < 1) && !$removeAll)) {
            throw new Exception(__('Something wrong! Missing require value'));
        }
        if (!$removeAll) {
            foreach ($wishlistItem as $wishlist) {
                $productId = $wishlist['wishlist_item_id'];

                $this->removeItemWishlist($productId);
            }
        } else {
            $this->removeItemWishlist(null);
        }

        return ["success" => true, "message" => __('Wishlist item has been removed')];
    }

    /**
     * @param null $productId
     *
     * @throws \Exception
     */
    protected function removeItemWishlist($productId = null)
    {
        $wish  = $this->getWishlist();
        $items = $wish->getItemCollection();

        /** @var \Magento\Wishlist\Model\Item $item */
        foreach ($items as $item) {
            if (is_null($productId) || $item->getProductId() == $productId) {
                $item->delete();
                $wish->save();
            }
        }
    }
}
