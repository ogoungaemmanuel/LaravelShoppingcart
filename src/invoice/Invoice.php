<?php

namespace Xslaincart\Shoppingcart\Invoice;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\Session\SessionManager;
use Illuminate\Database\DatabaseManager;
use Illuminate\Contracts\Events\Dispatcher;
use Xslaincart\Shoppingcart\Contracts\Buyable;
use Xslaincart\Shoppingcart\Exceptions\UnknownModelException;
use Xslaincart\Shoppingcart\Exceptions\InvalidRowIDException;
use Xslaincart\Shoppingcart\Exceptions\InvoiceAlreadyStoredException;

class Invoice
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
     * Holds the current invoice instance.
     *
     * @var string
     */
    private $instance;

    /**
     * Invoice constructor.
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
     * Set the current invoice instance.
     *
     * @param string|null $instance
     * @return \Xslaincart\Shoppingcart\Invoice
     */
    public function instance($instance = null)
    {
        $instance = $instance ?: self::DEFAULT_INSTANCE;

        $this->instance = sprintf('%s.%s', 'invoice', $instance);

        return $this;
    }

    /**
     * Get the current invoice instance.
     *
     * @return string
     */
    public function currentInstance()
    {
        return str_replace('invoice.', '', $this->instance);
    }

    /**
     * Add an item to the invoice.
     *
     * @param mixed     $id
     * @param mixed     $name
     * @param int|float $qty
     * @param float     $price
     * @param array     $options
     * @param float     $taxrate
     * @return \Xslaincart\Shoppingcart\InvoiceItem
     */
    public function add($id, $name = null, $qty = null, $price = null, array $options = [], $taxrate = null)
    {
        if ($this->isMulti($id)) {
            return array_map(function ($item) {
                return $this->add($item);
            }, $id);
        }

        if ($id instanceof InvoiceItem) {
            $invoiceItem = $id;
        } else {
            $invoiceItem = $this->createInvoiceItem($id, $name, $qty, $price, $options, $taxrate);
        }

        $content = $this->getContent();

        if ($content->has($invoiceItem->rowId)) {
            $invoiceItem->qty += $content->get($invoiceItem->rowId)->qty;
        }

        $content->put($invoiceItem->rowId, $invoiceItem);

        $this->events->dispatch('invoice.added', $invoiceItem);

        $this->session->put($this->instance, $content);

        return $invoiceItem;
    }

    /**
     * Update the invoice item with the given rowId.
     *
     * @param string $rowId
     * @param mixed  $qty
     * @return \Xslaincart\Shoppingcart\InvoiceItem
     */
    public function update($rowId, $qty)
    {
        $invoiceItem = $this->get($rowId);

        if ($qty instanceof Buyable) {
            $invoiceItem->updateFromBuyable($qty);
        } elseif (is_array($qty)) {
            $invoiceItem->updateFromArray($qty);
        } else {
            $invoiceItem->qty = $qty;
        }

        $content = $this->getContent();

        if ($rowId !== $invoiceItem->rowId) {
            $content->pull($rowId);

            if ($content->has($invoiceItem->rowId)) {
                $existingInvoiceItem = $this->get($invoiceItem->rowId);
                $invoiceItem->setQuantity($existingInvoiceItem->qty + $invoiceItem->qty);
            }
        }

        if ($invoiceItem->qty <= 0) {
            $this->remove($invoiceItem->rowId);
            return;
        } else {
            $content->put($invoiceItem->rowId, $invoiceItem);
        }

        $this->events->dispatch('invoice.updated', $invoiceItem);

        $this->session->put($this->instance, $content);

        return $invoiceItem;
    }

    /**
     * Remove the invoice item with the given rowId from the invoice.
     *
     * @param string $rowId
     * @return void
     */
    public function remove($rowId)
    {
        $invoiceItem = $this->get($rowId);

        $content = $this->getContent();

        $content->pull($invoiceItem->rowId);

        $this->events->dispatch('invoice.removed', $invoiceItem);

        $this->session->put($this->instance, $content);
    }

    /**
     * Get a invoice item from the invoice by its rowId.
     *
     * @param string $rowId
     * @return \Xslaincart\Shoppingcart\InvoiceItem
     */
    public function get($rowId)
    {
        $content = $this->getContent();

        if ( ! $content->has($rowId))
            throw new InvalidRowIDException("The invoice does not contain rowId {$rowId}.");

        return $content->get($rowId);
    }

    /**
     * Destroy the current invoice instance.
     *
     * @return void
     */
    public function destroy()
    {
        $this->session->remove($this->instance);
    }

    /**
     * Get the content of the invoice.
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
     * Get the number of items in the invoice.
     *
     * @return int|float
     */
    public function count()
    {
        $content = $this->getContent();

        return $content->sum('qty');
    }

    /**
     * Get the total price of the items in the invoice.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     * @return string
     */
    public function total($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        $content = $this->getContent();

        $total = $content->reduce(function ($total, InvoiceItem $invoiceItem) {
            return $total + ($invoiceItem->qty * $invoiceItem->priceTax);
        }, 0);

        return $this->numberFormat($total, $decimals, $decimalPoint, $thousandSeperator);
    }

    /**
     * Get the total tax of the items in the invoice.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     * @return float
     */
    public function tax($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        $content = $this->getContent();

        $tax = $content->reduce(function ($tax, InvoiceItem $invoiceItem) {
            return $tax + ($invoiceItem->qty * $invoiceItem->tax);
        }, 0);

        return $this->numberFormat($tax, $decimals, $decimalPoint, $thousandSeperator);
    }

    /**
     * Get the subtotal (total - tax) of the items in the invoice.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     * @return float
     */
    public function subtotal($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        $content = $this->getContent();

        $subTotal = $content->reduce(function ($subTotal, InvoiceItem $invoiceItem) {
            return $subTotal + ($invoiceItem->qty * $invoiceItem->price);
        }, 0);

        return $this->numberFormat($subTotal, $decimals, $decimalPoint, $thousandSeperator);
    }

    /**
     * Search the invoice content for a invoice item matching the given search closure.
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
     * Associate the invoice item with the given rowId with the given model.
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

        $invoiceItem = $this->get($rowId);

        $invoiceItem->associate($model);

        $content = $this->getContent();

        $content->put($invoiceItem->rowId, $invoiceItem);

        $this->session->put($this->instance, $content);
    }

    /**
     * Set the tax rate for the invoice item with the given rowId.
     *
     * @param string    $rowId
     * @param int|float $taxRate
     * @return void
     */
    public function setTax($rowId, $taxRate)
    {
        $invoiceItem = $this->get($rowId);

        $invoiceItem->setTaxRate($taxRate);

        $content = $this->getContent();

        $content->put($invoiceItem->rowId, $invoiceItem);

        $this->session->put($this->instance, $content);
    }

    /**
     * Store an the current instance of the invoice.
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

        $this->events->dispatch('invoice.stored');
    }

    /**
     * Restore the invoice with the given identifier.
     *
     * @param mixed $identifier
     * @return void
     */
    public function restore($identifier)
    {
        if( ! $this->storedInvoiceWithIdentifierExists($identifier)) {
            return;
        }

        $stored = $this->getConnection()->table($this->getTableName())
            ->where('instance', $this->currentInstance())
            ->where('identifier', $identifier)->first();

        $storedContent = unserialize(data_get($stored, 'content'));

        $currentInstance = $this->currentInstance();

        $this->instance(data_get($stored, 'instance'));

        $content = $this->getContent();

        foreach ($storedContent as $invoiceItem) {
            $content->put($invoiceItem->rowId, $invoiceItem);
        }

        $this->events->dispatch('invoice.restored');

        $this->session->put($this->instance, $content);

        $this->instance($currentInstance);

    }



    /**
     * Deletes the stored invoice with given identifier
     *
     * @param mixed $identifier
     */
    public function deleteStoredInvoice($identifier) {
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
     * Get the invoices content, if there is no invoice content set yet, return a new empty Collection
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
     * Create a new InvoiceItem from the supplied attributes.
     *
     * @param mixed     $id
     * @param mixed     $name
     * @param int|float $qty
     * @param float     $price
     * @param array     $options
     * @param float     $taxrate
     * @return \Xslaincart\Shoppingcart\InvoiceItem
     */
    private function createInvoiceItem($id, $name, $qty, $price, array $options, $taxrate)
    {
        if ($id instanceof Buyable) {
            $invoiceItem = InvoiceItem::fromBuyable($id, $qty ?: []);
            $invoiceItem->setQuantity($name ?: 1);
            $invoiceItem->associate($id);
        } elseif (is_array($id)) {
            $invoiceItem = InvoiceItem::fromArray($id);
            $invoiceItem->setQuantity($id['qty']);
        } else {
            $invoiceItem = InvoiceItem::fromAttributes($id, $name, $price, $options);
            $invoiceItem->setQuantity($qty);
        }

        if(isset($taxrate) && is_numeric($taxrate)) {
            $invoiceItem->setTaxRate($taxrate);
        } else {
            $invoiceItem->setTaxRate(config('invoice.tax'));
        }

        return $invoiceItem;
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
    protected function storedInvoiceWithIdentifierExists($identifier)
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
        return config('invoice.database.table', 'shoppinginvoice');
    }

    /**
     * Get the database connection name.
     *
     * @return string
     */
    private function getConnectionName()
    {
        $connection = config('invoice.database.connection');

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
            $decimals = is_null(config('invoice.format.decimals')) ? 2 : config('invoice.format.decimals');
        }
        if(is_null($decimalPoint)){
            $decimalPoint = is_null(config('invoice.format.decimal_point')) ? '.' : config('invoice.format.decimal_point');
        }
        if(is_null($thousandSeperator)){
            $thousandSeperator = is_null(config('invoice.format.thousand_seperator')) ? ',' : config('invoice.format.thousand_seperator');
        }

        return number_format($value, $decimals, $decimalPoint, $thousandSeperator);
    }
}
