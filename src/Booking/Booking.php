<?php

namespace Xslaincart\Shoppingcart\Booking;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\Session\SessionManager;
use Illuminate\Database\DatabaseManager;
use Illuminate\Contracts\Events\Dispatcher;
use Xslaincart\Shoppingcart\Contracts\Buyable;
use Xslaincart\Shoppingcart\Exceptions\UnknownModelException;
use Xslaincart\Shoppingcart\Exceptions\InvalidRowIDException;
use Xslaincart\Shoppingcart\Exceptions\BookingAlreadyStoredException;

class Booking
{
    const DEFAULT_INSTANCE = 'default';

    /**
     * Instance of the session manager.
     *
     * @var \Illuminate\Session\SessionManager
     */
    protected $session;

    /**
     * Instance of the event dispatcher.
     *
     * @var \Illuminate\Contracts\Events\Dispatcher
     */
    private $events;

    /**
     * Holds the current booking instance.
     *
     * @var string
     */
    private $instance;

    /**
     * Booking constructor.
     *
     * @param \Illuminate\Session\SessionManager      $session
     * @param \Illuminate\Contracts\Events\Dispatcher $events
     */
    public function __construct(SessionManager $session, Dispatcher $events)
    {
        $this->session = $session;
        $this->events = $events;

        $this->instance(self::DEFAULT_INSTANCE);
    }

    /**
     * Set the current booking instance.
     *
     * @param string|null $instance
     * @return \Xslaincart\Shoppingcart\Booking
     */
    public function instance($instance = null)
    {
        $instance = $instance ?: self::DEFAULT_INSTANCE;

        $this->instance = sprintf('%s.%s', 'booking', $instance);

        return $this;
    }

    /**
     * Get the current booking instance.
     *
     * @return string
     */
    public function currentInstance()
    {
        return str_replace('booking.', '', $this->instance);
    }

    /**
     * Add an item to the booking.
     *
     * @param mixed     $id
     * @param mixed     $name
     * @param int|float $qty
     * @param float     $price
     * @param array     $options
     * @param float     $taxrate
     * @return \Xslaincart\Shoppingcart\BookingItem
     */
    public function add($id, $name = null, $qty = null, $price = null, array $options = [], $taxrate = null)
    {
        if ($this->isMulti($id)) {
            return array_map(function ($item) {
                return $this->add($item);
            }, $id);
        }

        if ($id instanceof BookingItem) {
            $bookingItem = $id;
        } else {
            $bookingItem = $this->createBookingItem($id, $name, $qty, $price, $options, $taxrate);
        }

        $content = $this->getContent();

        if ($content->has($bookingItem->rowId)) {
            $bookingItem->qty += $content->get($bookingItem->rowId)->qty;
        }

        $content->put($bookingItem->rowId, $bookingItem);

        $this->events->dispatch('booking.added', $bookingItem);

        $this->session->put($this->instance, $content);

        return $bookingItem;
    }

    /**
     * Update the booking item with the given rowId.
     *
     * @param string $rowId
     * @param mixed  $qty
     * @return \Xslaincart\Shoppingcart\BookingItem
     */
    public function update($rowId, $qty)
    {
        $bookingItem = $this->get($rowId);

        if ($qty instanceof Buyable) {
            $bookingItem->updateFromBuyable($qty);
        } elseif (is_array($qty)) {
            $bookingItem->updateFromArray($qty);
        } else {
            $bookingItem->qty = $qty;
        }

        $content = $this->getContent();

        if ($rowId !== $bookingItem->rowId) {
            $content->pull($rowId);

            if ($content->has($bookingItem->rowId)) {
                $existingBookingItem = $this->get($bookingItem->rowId);
                $bookingItem->setQuantity($existingBookingItem->qty + $bookingItem->qty);
            }
        }

        if ($bookingItem->qty <= 0) {
            $this->remove($bookingItem->rowId);
            return;
        } else {
            $content->put($bookingItem->rowId, $bookingItem);
        }

        $this->events->dispatch('booking.updated', $bookingItem);

        $this->session->put($this->instance, $content);

        return $bookingItem;
    }

    /**
     * Remove the booking item with the given rowId from the booking.
     *
     * @param string $rowId
     * @return void
     */
    public function remove($rowId)
    {
        $bookingItem = $this->get($rowId);

        $content = $this->getContent();

        $content->pull($bookingItem->rowId);

        $this->events->dispatch('booking.removed', $bookingItem);

        $this->session->put($this->instance, $content);
    }

    /**
     * Get a booking item from the booking by its rowId.
     *
     * @param string $rowId
     * @return \Xslaincart\Shoppingcart\BookingItem
     */
    public function get($rowId)
    {
        $content = $this->getContent();

        if ( ! $content->has($rowId))
            throw new InvalidRowIDException("The booking does not contain rowId {$rowId}.");

        return $content->get($rowId);
    }

    /**
     * Destroy the current booking instance.
     *
     * @return void
     */
    public function destroy()
    {
        $this->session->remove($this->instance);
    }

    /**
     * Get the content of the booking.
     *
     * @return \Illuminate\Support\Collection
     */
    public function content()
    {
        if (is_null($this->session->get($this->instance))) {
            return new Collection([]);
        }

        return $this->session->get($this->instance);
    }

    /**
     * Get the number of items in the booking.
     *
     * @return int|float
     */
    public function count()
    {
        $content = $this->getContent();

        return $content->sum('qty');
    }

    /**
     * Get the total price of the items in the booking.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     * @return string
     */
    public function total($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        $content = $this->getContent();

        $total = $content->reduce(function ($total, BookingItem $bookingItem) {
            return $total + ($bookingItem->qty * $bookingItem->priceTax);
        }, 0);

        return $this->numberFormat($total, $decimals, $decimalPoint, $thousandSeperator);
    }

    /**
     * Get the total tax of the items in the booking.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     * @return float
     */
    public function tax($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        $content = $this->getContent();

        $tax = $content->reduce(function ($tax, BookingItem $bookingItem) {
            return $tax + ($bookingItem->qty * $bookingItem->tax);
        }, 0);

        return $this->numberFormat($tax, $decimals, $decimalPoint, $thousandSeperator);
    }

    /**
     * Get the subtotal (total - tax) of the items in the booking.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     * @return float
     */
    public function subtotal($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        $content = $this->getContent();

        $subTotal = $content->reduce(function ($subTotal, BookingItem $bookingItem) {
            return $subTotal + ($bookingItem->qty * $bookingItem->price);
        }, 0);

        return $this->numberFormat($subTotal, $decimals, $decimalPoint, $thousandSeperator);
    }

    /**
     * Search the booking content for a booking item matching the given search closure.
     *
     * @param \Closure $search
     * @return \Illuminate\Support\Collection
     */
    public function search(Closure $search)
    {
        $content = $this->getContent();

        return $content->filter($search);
    }

    /**
     * Associate the booking item with the given rowId with the given model.
     *
     * @param string $rowId
     * @param mixed  $model
     * @return void
     */
    public function associate($rowId, $model)
    {
        if(is_string($model) && ! class_exists($model)) {
            throw new UnknownModelException("The supplied model {$model} does not exist.");
        }

        $bookingItem = $this->get($rowId);

        $bookingItem->associate($model);

        $content = $this->getContent();

        $content->put($bookingItem->rowId, $bookingItem);

        $this->session->put($this->instance, $content);
    }

    /**
     * Set the tax rate for the booking item with the given rowId.
     *
     * @param string    $rowId
     * @param int|float $taxRate
     * @return void
     */
    public function setTax($rowId, $taxRate)
    {
        $bookingItem = $this->get($rowId);

        $bookingItem->setTaxRate($taxRate);

        $content = $this->getContent();

        $content->put($bookingItem->rowId, $bookingItem);

        $this->session->put($this->instance, $content);
    }

    /**
     * Store an the current instance of the booking.
     *
     * @param mixed $identifier
     * @return void
     */
    public function store($identifier)
    {
        $content = $this->getContent();


        $this->getConnection()
             ->table($this->getTableName())
             ->where('identifier', $identifier)
             ->where('instance', $this->currentInstance())
             ->delete();


        $this->getConnection()->table($this->getTableName())->insert([
            'identifier' => $identifier,
            'instance' => $this->currentInstance(),
            'content' => serialize($content),
            'created_at'=> new \DateTime()
        ]);

        $this->events->dispatch('booking.stored');
    }

    /**
     * Restore the booking with the given identifier.
     *
     * @param mixed $identifier
     * @return void
     */
    public function restore($identifier)
    {
        if( ! $this->storedBookingWithIdentifierExists($identifier)) {
            return;
        }

        $stored = $this->getConnection()->table($this->getTableName())
            ->where('instance', $this->currentInstance())
            ->where('identifier', $identifier)->first();

        $storedContent = unserialize(data_get($stored, 'content'));

        $currentInstance = $this->currentInstance();

        $this->instance(data_get($stored, 'instance'));

        $content = $this->getContent();

        foreach ($storedContent as $bookingItem) {
            $content->put($bookingItem->rowId, $bookingItem);
        }

        $this->events->dispatch('booking.restored');

        $this->session->put($this->instance, $content);

        $this->instance($currentInstance);

    }



    /**
     * Deletes the stored booking with given identifier
     *
     * @param mixed $identifier
     */
    public function deleteStoredBooking($identifier) {
        $this->getConnection()
             ->table($this->getTableName())
             ->where('identifier', $identifier)
             ->delete();
    }



    /**
     * Magic method to make accessing the total, tax and subtotal properties possible.
     *
     * @param string $attribute
     * @return float|null
     */
    public function __get($attribute)
    {
        if($attribute === 'total') {
            return $this->total();
        }

        if($attribute === 'tax') {
            return $this->tax();
        }

        if($attribute === 'subtotal') {
            return $this->subtotal();
        }

        return null;
    }

    /**
     * Get the carts content, if there is no booking content set yet, return a new empty Collection
     *
     * @return \Illuminate\Support\Collection
     */
    protected function getContent()
    {
        $content = $this->session->has($this->instance)
            ? $this->session->get($this->instance)
            : new Collection;

        return $content;
    }

    /**
     * Create a new BookingItem from the supplied attributes.
     *
     * @param mixed     $id
     * @param mixed     $name
     * @param int|float $qty
     * @param float     $price
     * @param array     $options
     * @param float     $taxrate
     * @return \Xslaincart\Shoppingcart\BookingItem
     */
    private function createBookingItem($id, $name, $qty, $price, array $options, $taxrate)
    {
        if ($id instanceof Buyable) {
            $bookingItem = BookingItem::fromBuyable($id, $qty ?: []);
            $bookingItem->setQuantity($name ?: 1);
            $bookingItem->associate($id);
        } elseif (is_array($id)) {
            $bookingItem = BookingItem::fromArray($id);
            $bookingItem->setQuantity($id['qty']);
        } else {
            $bookingItem = BookingItem::fromAttributes($id, $name, $price, $options);
            $bookingItem->setQuantity($qty);
        }

        if(isset($taxrate) && is_numeric($taxrate)) {
            $bookingItem->setTaxRate($taxrate);
        } else {
            $bookingItem->setTaxRate(config('booking.tax'));
        }

        return $bookingItem;
    }

    /**
     * Check if the item is a multidimensional array or an array of Buyables.
     *
     * @param mixed $item
     * @return bool
     */
    private function isMulti($item)
    {
        if ( ! is_array($item)) return false;

        return is_array(head($item)) || head($item) instanceof Buyable;
    }

    /**
     * @param $identifier
     * @return bool
     */
    protected function storedBookingWithIdentifierExists($identifier)
    {
        return $this->getConnection()->table($this->getTableName())->where('identifier', $identifier)->where('instance', $this->currentInstance())->exists();
    }

    /**
     * Get the database connection.
     *
     * @return \Illuminate\Database\Connection
     */
    protected function getConnection()
    {
        $connectionName = $this->getConnectionName();

        return app(DatabaseManager::class)->connection($connectionName);
    }

    /**
     * Get the database table name.
     *
     * @return string
     */
    protected function getTableName()
    {
        return config('booking.database.table', 'shoppingcart');
    }

    /**
     * Get the database connection name.
     *
     * @return string
     */
    private function getConnectionName()
    {
        $connection = config('booking.database.connection');

        return is_null($connection) ? config('database.default') : $connection;
    }

    /**
     * Get the Formated number
     *
     * @param $value
     * @param $decimals
     * @param $decimalPoint
     * @param $thousandSeperator
     * @return string
     */
    private function numberFormat($value, $decimals, $decimalPoint, $thousandSeperator)
    {
        if(is_null($decimals)){
            $decimals = is_null(config('booking.format.decimals')) ? 2 : config('booking.format.decimals');
        }
        if(is_null($decimalPoint)){
            $decimalPoint = is_null(config('booking.format.decimal_point')) ? '.' : config('booking.format.decimal_point');
        }
        if(is_null($thousandSeperator)){
            $thousandSeperator = is_null(config('booking.format.thousand_seperator')) ? ',' : config('booking.format.thousand_seperator');
        }

        return number_format($value, $decimals, $decimalPoint, $thousandSeperator);
    }
}
