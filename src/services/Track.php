<?php
namespace fostercommerce\klaviyoconnect\services;

use Craft;
use craft\commerce\events\RefundTransactionEvent;
use craft\helpers\ArrayHelper;
use fostercommerce\klaviyoconnect\Plugin;
use fostercommerce\klaviyoconnect\models\Profile;
use fostercommerce\klaviyoconnect\models\KlaviyoList;
use fostercommerce\klaviyoconnect\models\EventProperties;
use fostercommerce\klaviyoconnect\events\AddCustomPropertiesEvent;
use fostercommerce\klaviyoconnect\events\AddOrderCustomPropertiesEvent;
use fostercommerce\klaviyoconnect\events\AddLineItemCustomPropertiesEvent;
use fostercommerce\klaviyoconnect\events\AddProfilePropertiesEvent;
use Stripe\Order;
use yii\base\Event;
use Klaviyo;
use GuzzleHttp\Exception\RequestException;
use DateTime;
use yii\db\Exception;

class Track extends Base
{
    const ADD_CUSTOM_PROPERTIES = 'addCustomProperties';

    const ADD_ORDER_CUSTOM_PROPERTIES = 'addOrderCustomProperties';

    const ADD_LINE_ITEM_CUSTOM_PROPERTIES = 'addLineItemCustomProperties';

    const ADD_PROFILE_PROPERTIES = 'addProfileProperties';

    /**
     * onSaveUser.
     *
     * @author	Unknown
     * @since	v0.0.1
     * @version	v1.0.0	Monday, May 23rd, 2022.
     * @access	public
     * @param	event	$event	
     * @return	void
     */
    public function onSaveUser(Event $event): void
    {
        $user = $event->sender;
        $groups = $this->getSetting('klaviyoAvailableGroups');
        $userGroups = Craft::$app->getUserGroups()->getGroupsByUserId($user->id);

        if ($this->isInGroup($groups, $userGroups)) {
            $this->identifyUser(Plugin::getInstance()->map->mapUser($user));
        }
    }

    /**
     * isInGroup.
     *
     * @author	Unknown
     * @since	v0.0.1
     * @version	v1.0.0	Monday, May 23rd, 2022.
     * @access	private
     * @param	mixed	$selectedGroups	
     * @param	mixed	$userGroups    	
     * @return	boolean
     */
    private function isInGroup($selectedGroups, $userGroups): bool
    {
        foreach ($selectedGroups as $group) {
            $hasGroup = in_array($group, ArrayHelper::getColumn($userGroups, 'id'), false);
            if ($hasGroup) {
                return true;
            }
        }

        return false;
    }

    /**
     * createProfile.
     *
     * @author	Unknown
     * @since	v0.0.1
     * @version	v1.0.0	Monday, May 23rd, 2022.
     * @access	protected
     * @param	mixed	$params   	
     * @param	mixed	$eventName	Default: null
     * @param	mixed	$context  	Default: null
     * @return	mixed
     */
    protected function createProfile($params, $eventName = null, $context = null): mixed
    {
        $profile = new Profile($params);

        $event = new AddProfilePropertiesEvent([
            'profile' => $profile,
            'event' => $eventName,
            'context' => $context,
        ]);
        Event::trigger(static::class, self::ADD_PROFILE_PROPERTIES, $event);

        $profile->setCustomProperties($event->properties);
        return $profile;
    }

    /**
     * identifyUser.
     *
     * @author	Unknown
     * @since	v0.0.1
     * @version	v1.0.0	Monday, May 23rd, 2022.
     * @access	public
     * @param	mixed	$params	
     * @return	void
     */
    public function identifyUser($params): void
    {
        Plugin::getInstance()->api->identify($this->createProfile($params));
    }

    /**
     * onCartUpdated.
     *
     * @author	Unknown
     * @since	v0.0.1
     * @version	v1.0.0	Monday, May 23rd, 2022.
     * @access	public
     * @param	event	$event	
     * @return	void
     */
    public function onCartUpdated(Event $event): void
    {
        $this->trackOrder('Updated Cart', $event->sender);
    }

    /**
     * onOrderCompleted.
     *
     * @author	Unknown
     * @since	v0.0.1
     * @version	v1.0.0	Monday, May 23rd, 2022.
     * @access	public
     * @param	event	$event	
     * @return	void
     */
    public function onOrderCompleted(Event $event): void
    {
        $this->trackOrder('Placed Order', $event->sender);
    }

    /**
     * onStatusChanged.
     *
     * @author	Unknown
     * @since	v0.0.1
     * @version	v1.0.0	Monday, May 23rd, 2022.
     * @access	public
     * @param	event	$event	
     * @return	void
     */
    public function onStatusChanged(Event $event): void
    {
        $order = $event->orderHistory->getOrder();
        $this->trackOrder("Status Changed", $order, null, null, $event);
    }

    /**
     * onOrderRefunded.
     *
     * @author	Unknown
     * @since	v0.0.1
     * @version	v1.0.0	Monday, May 23rd, 2022.
     * @access	public
     * @param	refundtransactionevent	$event	
     * @return	void
     */
    public function onOrderRefunded(RefundTransactionEvent $event): void
    {
        $order = $event->transaction->getOrder();
        $this->trackOrder("Refunded Order", $order, null, null, $event);
    }

    /**
     * addToLists.
     *
     * @author	Unknown
     * @since	v0.0.1
     * @version	v1.0.0	Monday, May 23rd, 2022.
     * @access	public
     * @param	mixed  	$listIds             	
     * @param	mixed  	$profileParams       	
     * @param	boolean	$useSubscribeEndpoint	Default: false
     * @return	void
     */
    public function addToLists($listIds, $profileParams, $useSubscribeEndpoint = false): void
    {
        $profile = $this->createProfile($profileParams);

        foreach ($listIds as $listId) {
            $list = new KlaviyoList(['id' => $listId]);

            try {
                Plugin::getInstance()->api->addProfileToList($list, $profile, $useSubscribeEndpoint);
            } catch (RequestException $e) {
                // Swallow. Klaviyo responds with a 200.
            }
        }
    }

    /**
     * trackEvent.
     *
     * @author	Unknown
     * @since	v0.0.1
     * @version	v1.0.0	Monday, May 23rd, 2022.
     * @access	public
     * @param	mixed	$eventName      	
     * @param	mixed	$profileParams  	
     * @param	mixed	$eventProperties	
     * @param	mixed	$trackOnce      	
     * @param	mixed	$timestamp      	Default: null
     * @return	void
     */
    public function trackEvent($eventName, $profileParams, $eventProperties, $trackOnce, $timestamp = null): void
    {
        $profile = $this->createProfile($profileParams);

        $addCustomPropertiesEvent = new AddCustomPropertiesEvent(['name' => $eventName]);
        Event::trigger(static::class, self::ADD_CUSTOM_PROPERTIES, $addCustomPropertiesEvent);

        if (sizeof($addCustomPropertiesEvent->properties) > 0) {
            $eventProperties->setCustomProperties($addCustomPropertiesEvent->properties);
        }

        try {
            Plugin::getInstance()->api->track($eventName, $profile, $eventProperties, $trackOnce, $timestamp);
        } catch (RequestException $e) {
            // Swallow. Klaviyo responds with a 200.
        }
    }

    /**
     * trackOrder.
     *
     * @author	Unknown
     * @since	v0.0.1
     * @version	v1.0.0	Monday, May 23rd, 2022.
     * @access	public
     * @param	mixed	$eventName	
     * @param	mixed	$order    	
     * @param	mixed	$profile  	Default: null
     * @param	mixed	$timestamp	Default: null
     * @param	mixed	$fullEvent	Default: null
     * @return	void
     */
    public function trackOrder($eventName, $order, $profile = null, $timestamp = null, $fullEvent = null): void
    {
        if ($order->email) {
            $profile = [
                'email'      => $order->email,
                'first_name' => $order->billingAddress->firstName ?? null,
                'last_name'  => $order->billingAddress->lastName ?? null,
            ];
        }

        if (!$profile && $currentUser = Craft::$app->user->getIdentity()) {
            $profile = Plugin::getInstance()->map->mapUser($currentUser);
        }

        if ($profile) {
            $orderDetails = $this->getOrderDetails($order, $eventName);
            $dateTime = new DateTime();

            $event = [
                'event_id' => $order->id.'_'.$dateTime->getTimestamp(),
                'value' => $order->totalPaid
            ];
            $eventProperties = new EventProperties($event);
            $eventProperties->setCustomProperties($orderDetails);
            $success = true;

            if($eventName === 'Refunded Order') {
                $children = $fullEvent->transaction->childTransactions;
                $child    = $children[count($children) - 1];

                if($child->status === 'success') {
                    $message = $child->note;
                    $eventProperties->setCustomProperties(['Reason' => $message]);

                    $eventName === 'Refund Issued';
                } else {
                    $success = false;
                }
            }

            if($eventName === 'Status Changed') {
                $orderHistory = $fullEvent->orderHistory;
                $status = $orderHistory->getNewStatus()->name;
                $eventProperties->setCustomProperties(['Reason' => $orderHistory->message]);

                $eventName = "$status Order";
            }

            if($success) {
                try {
                    $profile = $this->createProfile(
                        $profile,
                        $eventName,
                        [
                            'order' => $order,
                            'eventProperties' => $eventProperties,
                        ]
                    );

                    Plugin::getInstance()->api->track($eventName, $profile, $eventProperties, $timestamp);

                    if ($eventName === 'Placed Order') {
                        foreach ($orderDetails['Items'] as $item) {
                            $event = [
                                'event_id' => $order->id.'_'.$item['Slug'].'_'.$dateTime->getTimestamp(),
                                'value' => $order->totalPaid,
                            ];

                            $eventProperties = new EventProperties($event);
                            $eventProperties->setCustomProperties($item);

                            Plugin::getInstance()->api->track('Ordered Product', $profile, $eventProperties, $timestamp);
                        }
                    }
                } catch (RequestException $e) {
                    // Swallow. Klaviyo responds with a 200.
                }
            }
        } else {
            // Swallow.
            // This is likely a logged out user adding an item to their cart.
        }
    }

    /**
     * getOrderDetails.
     *
     * @author	Unknown
     * @since	v0.0.1
     * @version	v1.0.0	Monday, May 23rd, 2022.
     * @access	protected
     * @param	mixed 	$order	
     * @param	string	$event	Default: ''
     * @return	mixed
     */
    protected function getOrderDetails($order, $event = ''): mixed
    {
        $settings = Plugin::getInstance()->settings;

        $lineItemsProperties = array();

        foreach ($order->lineItems as $lineItem) {
            $lineItemProperties = [];

            // Add regular Product purchasable properties
            $product = $lineItem->purchasable->product ?? [];
            if ($product) {
                $lineItemProperties = [
                    'value' => $lineItem->price * $lineItem->qty,
                    'ProductName' => $product->title,
                    'Slug' => $lineItem->purchasable->product->slug,
                    'ProductURL' => $product->getUrl(),
                    'ProductType' => $product->type->name,
                    'ItemPrice' => $lineItem->price,
                    'RowTotal' => $lineItem->subtotal,
                    'Quantity' => $lineItem->qty,
                    'SKU' => $lineItem->purchasable->sku,
                ];

                $variant = $lineItem->purchasable;
                
                $productImageField = $settings->productImageField;

                if ( isset($variant->$productImageField) && $variant->$productImageField->count() ) {
                    if ($image = $variant->$productImageField->one()) {
                        $lineItemProperties['ImageURL'] = $image->getUrl($settings->productImageFieldTransformation,true);
                    }
                } else if ( isset($product->$productImageField) && $product->$productImageField->count() ) {
                    if ($image = $product->$productImageField->one()) {
                        $lineItemProperties['ImageURL'] = $image->getUrl($settings->productImageFieldTransformation,true);
                    }
                }

            }

            // Add any additional user-defined properties
            $addLineItemCustomPropertiesEvent = new AddLineItemCustomPropertiesEvent([
                'properties' => $lineItemProperties,
                'order' => $order,
                'lineItem' => $lineItem,
                'event' => $event,
            ]);

            Event::trigger(static::class, self::ADD_LINE_ITEM_CUSTOM_PROPERTIES, $addLineItemCustomPropertiesEvent);

            $lineItemsProperties[] = $addLineItemCustomPropertiesEvent->properties;
        }

        $customProperties = [
            'OrderID' => $order->id,
            'OrderNumber' => $order->number,
            'TotalPrice' => $order->totalPrice,
            'TotalQuantity' => $order->totalQty,
            'Items' => $lineItemsProperties,
        ];

        $addOrderCustomPropertiesEvent = new AddOrderCustomPropertiesEvent([
            'properties' => $customProperties,
            'order' => $order,
            'event' => $event,
        ]);
        Event::trigger(static::class, self::ADD_ORDER_CUSTOM_PROPERTIES, $addOrderCustomPropertiesEvent);

        return $addOrderCustomPropertiesEvent->properties;
    }
}
