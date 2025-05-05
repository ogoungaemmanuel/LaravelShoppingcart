<?php

namespace Xslaincart\Shoppingcart\Fee;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\Session\SessionManager;
use Illuminate\Database\DatabaseManager;
use Illuminate\Contracts\Events\Dispatcher;
use Xslaincart\Shoppingcart\Contracts\Buyable;
use Xslaincart\Shoppingcart\Exceptions\UnknownModelException;
use Xslaincart\Shoppingcart\Exceptions\InvalidRowIDException;
use Xslaincart\Shoppingcart\Exceptions\FeeAlreadyStoredException;

class Fee
{
    const DEFAULT_INSTANCE = 'default';

    /**
     * Instance of the session manager.
     *
     * @var \Illuminate\Session\SessionManager
     */
    protected $session;

    /**
     * Holds the current fee instance.
     *
     * @var string
     */
    private $instance;

    /**
     * Fee constructor.
     *
     * @param \Illuminate\Session\SessionManager      $session
     * @param \Illuminate\Contracts\Events\Dispatcher $events
     */
    public function __construct(SessionManager $session, /**
     * Instance of the event dispatcher.
     */
    private readonly Dispatcher $events)
    {
        $this->session = $session;

        $this->instance(self::DEFAULT_INSTANCE);
    }

    /**
     * Set the current fee instance.
     *
     * @param string|null $instance
     * @return \Xslaincart\Shoppingcart\Fee
     */
    public function instance($instance = null)
    {
        $instance = $instance ?: self::DEFAULT_INSTANCE;

        $this->instance = sprintf('%s.%s', 'fee', $instance);

        return $this;
    }

    /**
     * Get the current fee instance.
     *
     * @return string
     */
    public function currentInstance()
    {
        return str_replace('fee.', '', $this->instance);
    }

    /**
     * Add an item to the fee.
     *
     * @param mixed     $id
     * @param mixed     $name
     * @param int|float $qty
     * @param float     $price
     * @param array     $options
     * @param float     $taxrate
     * @return \Xslaincart\Shoppingcart\FeeItem
     */
    public function add($id, $name = null, $qty = null, $price = null, array $options = [], $taxrate = null)
    {
        if ($this->isMulti($id)) {
            return array_map(fn($item) => $this->add($item), $id);
        }

        if ($id instanceof FeeItem) {
            $feeItem = $id;
        } else {
            $feeItem = $this->createFeeItem($id, $name, $qty, $price, $options, $taxrate);
        }

        $content = $this->getContent();

        if ($content->has($feeItem->rowId)) {
            $feeItem->qty += $content->get($feeItem->rowId)->qty;
        }

        $content->put($feeItem->rowId, $feeItem);

        $this->events->dispatch('fee.added', $feeItem);

        $this->session->put($this->instance, $content);

        return $feeItem;
    }

    /**
     * Update the fee item with the given rowId.
     *
     * @param string $rowId
     * @param mixed  $qty
     * @return \Xslaincart\Shoppingcart\FeeItem
     */
    public function update($rowId, $qty)
    {
        $feeItem = $this->get($rowId);

        if ($qty instanceof Buyable) {
            $feeItem->updateFromBuyable($qty);
        } elseif (is_array($qty)) {
            $feeItem->updateFromArray($qty);
        } else {
            $feeItem->qty = $qty;
        }

        $content = $this->getContent();

        if ($rowId !== $feeItem->rowId) {
            $content->pull($rowId);

            if ($content->has($feeItem->rowId)) {
                $existingFeeItem = $this->get($feeItem->rowId);
                $feeItem->setQuantity($existingFeeItem->qty + $feeItem->qty);
            }
        }

        if ($feeItem->qty <= 0) {
            $this->remove($feeItem->rowId);
            return;
        } else {
            $content->put($feeItem->rowId, $feeItem);
        }

        $this->events->dispatch('fee.updated', $feeItem);

        $this->session->put($this->instance, $content);

        return $feeItem;
    }

    /**
     * Remove the fee item with the given rowId from the fee.
     *
     * @param string $rowId
     * @return void
     */
    public function remove($rowId)
    {
        $feeItem = $this->get($rowId);

        $content = $this->getContent();

        $content->pull($feeItem->rowId);

        $this->events->dispatch('fee.removed', $feeItem);

        $this->session->put($this->instance, $content);
    }

    /**
     * Get a fee item from the fee by its rowId.
     *
     * @param string $rowId
     * @return \Xslaincart\Shoppingcart\FeeItem
     */
    public function get($rowId)
    {
        $content = $this->getContent();

        if ( ! $content->has($rowId))
            throw new InvalidRowIDException("The fee does not contain rowId {$rowId}.");

        return $content->get($rowId);
    }

    /**
     * Destroy the current fee instance.
     *
     * @return void
     */
    public function destroy()
    {
        $this->session->remove($this->instance);
    }

    /**
     * Get the content of the fee.
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
     * Get the number of items in the fee.
     *
     * @return int|float
     */
    public function count()
    {
        $content = $this->getContent();

        return $content->sum('qty');
    }

    /**
     * Get the total price of the items in the fee.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     * @return string
     */
    public function total($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        $content = $this->getContent();

        $total = $content->reduce(fn($total, FeeItem $feeItem) => $total + ($feeItem->qty * $feeItem->priceTax), 0);

        return $this->numberFormat($total, $decimals, $decimalPoint, $thousandSeperator);
    }

    /**
     * Get the total tax of the items in the fee.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     * @return float
     */
    public function tax($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        $content = $this->getContent();

        $tax = $content->reduce(fn($tax, FeeItem $feeItem) => $tax + ($feeItem->qty * $feeItem->tax), 0);

        return $this->numberFormat($tax, $decimals, $decimalPoint, $thousandSeperator);
    }

    /**
     * Get the subtotal (total - tax) of the items in the fee.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     * @return float
     */
    public function subtotal($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        $content = $this->getContent();

        $subTotal = $content->reduce(fn($subTotal, FeeItem $feeItem) => $subTotal + ($feeItem->qty * $feeItem->price), 0);

        return $this->numberFormat($subTotal, $decimals, $decimalPoint, $thousandSeperator);
    }

    /**
     * Search the fee content for a fee item matching the given search closure.
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
     * Associate the fee item with the given rowId with the given model.
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

        $feeItem = $this->get($rowId);

        $feeItem->associate($model);

        $content = $this->getContent();

        $content->put($feeItem->rowId, $feeItem);

        $this->session->put($this->instance, $content);
    }

    /**
     * Set the tax rate for the fee item with the given rowId.
     *
     * @param string    $rowId
     * @param int|float $taxRate
     * @return void
     */
    public function setTax($rowId, $taxRate)
    {
        $feeItem = $this->get($rowId);

        $feeItem->setTaxRate($taxRate);

        $content = $this->getContent();

        $content->put($feeItem->rowId, $feeItem);

        $this->session->put($this->instance, $content);
    }

    /**
     * Store an the current instance of the fee.
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

        $this->events->dispatch('fee.stored');
    }

    /**
     * Restore the fee with the given identifier.
     *
     * @param mixed $identifier
     * @return void
     */
    public function restore($identifier)
    {
        if( ! $this->storedFeeWithIdentifierExists($identifier)) {
            return;
        }

        $stored = $this->getConnection()->table($this->getTableName())
            ->where('instance', $this->currentInstance())
            ->where('identifier', $identifier)->first();

        $storedContent = unserialize(data_get($stored, 'content'));

        $currentInstance = $this->currentInstance();

        $this->instance(data_get($stored, 'instance'));

        $content = $this->getContent();

        foreach ($storedContent as $feeItem) {
            $content->put($feeItem->rowId, $feeItem);
        }

        $this->events->dispatch('fee.restored');

        $this->session->put($this->instance, $content);

        $this->instance($currentInstance);

    }



    /**
     * Deletes the stored fee with given identifier
     *
     * @param mixed $identifier
     */
    public function deleteStoredFee($identifier) {
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
     * Get the carts content, if there is no fee content set yet, return a new empty Collection
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
     * Create a new FeeItem from the supplied attributes.
     *
     * @param mixed     $id
     * @param mixed     $name
     * @param int|float $qty
     * @param float     $price
     * @param array     $options
     * @param float     $taxrate
     * @return \Xslaincart\Shoppingcart\FeeItem
     */
    private function createFeeItem($id, $name, $qty, $price, array $options, $taxrate)
    {
        if ($id instanceof Buyable) {
            $feeItem = FeeItem::fromBuyable($id, $qty ?: []);
            $feeItem->setQuantity($name ?: 1);
            $feeItem->associate($id);
        } elseif (is_array($id)) {
            $feeItem = FeeItem::fromArray($id);
            $feeItem->setQuantity($id['qty']);
        } else {
            $feeItem = FeeItem::fromAttributes($id, $name, $price, $options);
            $feeItem->setQuantity($qty);
        }

        if(isset($taxrate) && is_numeric($taxrate)) {
            $feeItem->setTaxRate($taxrate);
        } else {
            $feeItem->setTaxRate(config('fee.tax'));
        }

        return $feeItem;
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
    protected function storedFeeWithIdentifierExists($identifier)
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
        return config('fee.database.table', 'shoppingfee');
    }

    /**
     * Get the database connection name.
     *
     * @return string
     */
    private function getConnectionName()
    {
        $connection = config('fee.database.connection');

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
            $decimals = is_null(config('fee.format.decimals')) ? 2 : config('fee.format.decimals');
        }
        if(is_null($decimalPoint)){
            $decimalPoint = is_null(config('fee.format.decimal_point')) ? '.' : config('fee.format.decimal_point');
        }
        if(is_null($thousandSeperator)){
            $thousandSeperator = is_null(config('fee.format.thousand_seperator')) ? ',' : config('fee.format.thousand_seperator');
        }

        return number_format($value, $decimals, $decimalPoint, $thousandSeperator);
    }
}
