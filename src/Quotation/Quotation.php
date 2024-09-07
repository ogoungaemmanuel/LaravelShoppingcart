<?php

namespace Xslaincart\Shoppingcart\Quotation;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\Session\SessionManager;
use Illuminate\Database\DatabaseManager;
use Illuminate\Contracts\Events\Dispatcher;
use Xslaincart\Shoppingcart\Contracts\Buyable;
use Xslaincart\Shoppingcart\Exceptions\UnknownModelException;
use Xslaincart\Shoppingcart\Exceptions\InvalidRowIDException;
use Xslaincart\Shoppingcart\Exceptions\QuotationAlreadyStoredException;

class Quotation
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
     * Holds the current quotation instance.
     *
     * @var string
     */
    private $instance;

    /**
     * Quotation constructor.
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
     * Set the current quotation instance.
     *
     * @param string|null $instance
     * @return \Xslaincart\Shoppingcart\Quotation
     */
    public function instance($instance = null)
    {
        $instance = $instance ?: self::DEFAULT_INSTANCE;

        $this->instance = sprintf('%s.%s', 'quotation', $instance);

        return $this;
    }

    /**
     * Get the current quotation instance.
     *
     * @return string
     */
    public function currentInstance()
    {
        return str_replace('quotation.', '', $this->instance);
    }

    /**
     * Add an item to the quotation.
     *
     * @param mixed     $id
     * @param mixed     $name
     * @param int|float $qty
     * @param float     $price
     * @param array     $options
     * @param float     $taxrate
     * @return \Xslaincart\Shoppingcart\QuotationItem
     */
    public function add($id, $name = null, $qty = null, $price = null, array $options = [], $taxrate = null)
    {
        if ($this->isMulti($id)) {
            return array_map(function ($item) {
                return $this->add($item);
            }, $id);
        }

        if ($id instanceof QuotationItem) {
            $quotationItem = $id;
        } else {
            $quotationItem = $this->createQuotationItem($id, $name, $qty, $price, $options, $taxrate);
        }

        $content = $this->getContent();

        if ($content->has($quotationItem->rowId)) {
            $quotationItem->qty += $content->get($quotationItem->rowId)->qty;
        }

        $content->put($quotationItem->rowId, $quotationItem);

        $this->events->dispatch('quotation.added', $quotationItem);

        $this->session->put($this->instance, $content);

        return $quotationItem;
    }

    /**
     * Update the quotation item with the given rowId.
     *
     * @param string $rowId
     * @param mixed  $qty
     * @return \Xslaincart\Shoppingcart\QuotationItem
     */
    public function update($rowId, $qty)
    {
        $quotationItem = $this->get($rowId);

        if ($qty instanceof Buyable) {
            $quotationItem->updateFromBuyable($qty);
        } elseif (is_array($qty)) {
            $quotationItem->updateFromArray($qty);
        } else {
            $quotationItem->qty = $qty;
        }

        $content = $this->getContent();

        if ($rowId !== $quotationItem->rowId) {
            $content->pull($rowId);

            if ($content->has($quotationItem->rowId)) {
                $existingQuotationItem = $this->get($quotationItem->rowId);
                $quotationItem->setQuantity($existingQuotationItem->qty + $quotationItem->qty);
            }
        }

        if ($quotationItem->qty <= 0) {
            $this->remove($quotationItem->rowId);
            return;
        } else {
            $content->put($quotationItem->rowId, $quotationItem);
        }

        $this->events->dispatch('quotation.updated', $quotationItem);

        $this->session->put($this->instance, $content);

        return $quotationItem;
    }

    /**
     * Remove the quotation item with the given rowId from the quotation.
     *
     * @param string $rowId
     * @return void
     */
    public function remove($rowId)
    {
        $quotationItem = $this->get($rowId);

        $content = $this->getContent();

        $content->pull($quotationItem->rowId);

        $this->events->dispatch('quotation.removed', $quotationItem);

        $this->session->put($this->instance, $content);
    }

    /**
     * Get a quotation item from the quotation by its rowId.
     *
     * @param string $rowId
     * @return \Xslaincart\Shoppingcart\QuotationItem
     */
    public function get($rowId)
    {
        $content = $this->getContent();

        if ( ! $content->has($rowId))
            throw new InvalidRowIDException("The quotation does not contain rowId {$rowId}.");

        return $content->get($rowId);
    }

    /**
     * Destroy the current quotation instance.
     *
     * @return void
     */
    public function destroy()
    {
        $this->session->remove($this->instance);
    }

    /**
     * Get the content of the quotation.
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
     * Get the number of items in the quotation.
     *
     * @return int|float
     */
    public function count()
    {
        $content = $this->getContent();

        return $content->sum('qty');
    }

    /**
     * Get the total price of the items in the quotation.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     * @return string
     */
    public function total($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        $content = $this->getContent();

        $total = $content->reduce(function ($total, QuotationItem $quotationItem) {
            return $total + ($quotationItem->qty * $quotationItem->priceTax);
        }, 0);

        return $this->numberFormat($total, $decimals, $decimalPoint, $thousandSeperator);
    }

    /**
     * Get the total tax of the items in the quotation.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     * @return float
     */
    public function tax($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        $content = $this->getContent();

        $tax = $content->reduce(function ($tax, QuotationItem $quotationItem) {
            return $tax + ($quotationItem->qty * $quotationItem->tax);
        }, 0);

        return $this->numberFormat($tax, $decimals, $decimalPoint, $thousandSeperator);
    }

    /**
     * Get the subtotal (total - tax) of the items in the quotation.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     * @return float
     */
    public function subtotal($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        $content = $this->getContent();

        $subTotal = $content->reduce(function ($subTotal, QuotationItem $quotationItem) {
            return $subTotal + ($quotationItem->qty * $quotationItem->price);
        }, 0);

        return $this->numberFormat($subTotal, $decimals, $decimalPoint, $thousandSeperator);
    }

    /**
     * Search the quotation content for a quotation item matching the given search closure.
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
     * Associate the quotation item with the given rowId with the given model.
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

        $quotationItem = $this->get($rowId);

        $quotationItem->associate($model);

        $content = $this->getContent();

        $content->put($quotationItem->rowId, $quotationItem);

        $this->session->put($this->instance, $content);
    }

    /**
     * Set the tax rate for the quotation item with the given rowId.
     *
     * @param string    $rowId
     * @param int|float $taxRate
     * @return void
     */
    public function setTax($rowId, $taxRate)
    {
        $quotationItem = $this->get($rowId);

        $quotationItem->setTaxRate($taxRate);

        $content = $this->getContent();

        $content->put($quotationItem->rowId, $quotationItem);

        $this->session->put($this->instance, $content);
    }

    /**
     * Store an the current instance of the quotation.
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

        $this->events->dispatch('quotation.stored');
    }

    /**
     * Restore the quotation with the given identifier.
     *
     * @param mixed $identifier
     * @return void
     */
    public function restore($identifier)
    {
        if( ! $this->storedQuotationWithIdentifierExists($identifier)) {
            return;
        }

        $stored = $this->getConnection()->table($this->getTableName())
            ->where('instance', $this->currentInstance())
            ->where('identifier', $identifier)->first();

        $storedContent = unserialize(data_get($stored, 'content'));

        $currentInstance = $this->currentInstance();

        $this->instance(data_get($stored, 'instance'));

        $content = $this->getContent();

        foreach ($storedContent as $quotationItem) {
            $content->put($quotationItem->rowId, $quotationItem);
        }

        $this->events->dispatch('quotation.restored');

        $this->session->put($this->instance, $content);

        $this->instance($currentInstance);

    }



    /**
     * Deletes the stored quotation with given identifier
     *
     * @param mixed $identifier
     */
    public function deleteStoredQuotation($identifier) {
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
     * Get the carts content, if there is no quotation content set yet, return a new empty Collection
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
     * Create a new QuotationItem from the supplied attributes.
     *
     * @param mixed     $id
     * @param mixed     $name
     * @param int|float $qty
     * @param float     $price
     * @param array     $options
     * @param float     $taxrate
     * @return \Xslaincart\Shoppingcart\QuotationItem
     */
    private function createQuotationItem($id, $name, $qty, $price, array $options, $taxrate)
    {
        if ($id instanceof Buyable) {
            $quotationItem = QuotationItem::fromBuyable($id, $qty ?: []);
            $quotationItem->setQuantity($name ?: 1);
            $quotationItem->associate($id);
        } elseif (is_array($id)) {
            $quotationItem = QuotationItem::fromArray($id);
            $quotationItem->setQuantity($id['qty']);
        } else {
            $quotationItem = QuotationItem::fromAttributes($id, $name, $price, $options);
            $quotationItem->setQuantity($qty);
        }

        if(isset($taxrate) && is_numeric($taxrate)) {
            $quotationItem->setTaxRate($taxrate);
        } else {
            $quotationItem->setTaxRate(config('quotation.tax'));
        }

        return $quotationItem;
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
    protected function storedQuotationWithIdentifierExists($identifier)
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
        return config('quotation.database.table', 'shoppingcart');
    }

    /**
     * Get the database connection name.
     *
     * @return string
     */
    private function getConnectionName()
    {
        $connection = config('quotation.database.connection');

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
            $decimals = is_null(config('quotation.format.decimals')) ? 2 : config('quotation.format.decimals');
        }
        if(is_null($decimalPoint)){
            $decimalPoint = is_null(config('quotation.format.decimal_point')) ? '.' : config('quotation.format.decimal_point');
        }
        if(is_null($thousandSeperator)){
            $thousandSeperator = is_null(config('quotation.format.thousand_seperator')) ? ',' : config('quotation.format.thousand_seperator');
        }

        return number_format($value, $decimals, $decimalPoint, $thousandSeperator);
    }
}
