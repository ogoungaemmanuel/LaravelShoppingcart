<?php

namespace Xslaincart\Shoppingcart\Expense;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\Session\SessionManager;
use Illuminate\Database\DatabaseManager;
use Illuminate\Contracts\Events\Dispatcher;
use Xslaincart\Shoppingcart\Contracts\Buyable;
use Xslaincart\Shoppingcart\Exceptions\UnknownModelException;
use Xslaincart\Shoppingcart\Exceptions\InvalidRowIDException;
use Xslaincart\Shoppingcart\Exceptions\ExpenseAlreadyStoredException;

class Expense
{
    const DEFAULT_INSTANCE = 'default';

    /**
     * Instance of the session manager.
     *
     * @var \Illuminate\Session\SessionManager
     */
    protected $session;

    /**
     * Holds the current expense instance.
     *
     * @var string
     */
    private $instance;

    /**
     * Expense constructor.
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
     * Set the current expense instance.
     *
     * @param string|null $instance
     * @return \Xslaincart\Shoppingcart\Expense
     */
    public function instance($instance = null)
    {
        $instance = $instance ?: self::DEFAULT_INSTANCE;

        $this->instance = sprintf('%s.%s', 'expense', $instance);

        return $this;
    }

    /**
     * Get the current expense instance.
     *
     * @return string
     */
    public function currentInstance()
    {
        return str_replace('expense.', '', $this->instance);
    }

    /**
     * Add an item to the expense.
     *
     * @param mixed     $id
     * @param mixed     $name
     * @param int|float $qty
     * @param float     $price
     * @param array     $options
     * @param float     $taxrate
     * @return \Xslaincart\Shoppingcart\ExpenseItem
     */
    public function add($id, $name = null, $qty = null, $price = null, array $options = [], $taxrate = null)
    {
        if ($this->isMulti($id)) {
            return array_map(fn($item) => $this->add($item), $id);
        }

        if ($id instanceof ExpenseItem) {
            $expenseItem = $id;
        } else {
            $expenseItem = $this->createExpenseItem($id, $name, $qty, $price, $options, $taxrate);
        }

        $content = $this->getContent();

        if ($content->has($expenseItem->rowId)) {
            $expenseItem->qty += $content->get($expenseItem->rowId)->qty;
        }

        $content->put($expenseItem->rowId, $expenseItem);

        $this->events->dispatch('expense.added', $expenseItem);

        $this->session->put($this->instance, $content);

        return $expenseItem;
    }

    /**
     * Update the expense item with the given rowId.
     *
     * @param string $rowId
     * @param mixed  $qty
     * @return \Xslaincart\Shoppingcart\ExpenseItem
     */
    public function update($rowId, $qty)
    {
        $expenseItem = $this->get($rowId);

        if ($qty instanceof Buyable) {
            $expenseItem->updateFromBuyable($qty);
        } elseif (is_array($qty)) {
            $expenseItem->updateFromArray($qty);
        } else {
            $expenseItem->qty = $qty;
        }

        $content = $this->getContent();

        if ($rowId !== $expenseItem->rowId) {
            $content->pull($rowId);

            if ($content->has($expenseItem->rowId)) {
                $existingExpenseItem = $this->get($expenseItem->rowId);
                $expenseItem->setQuantity($existingExpenseItem->qty + $expenseItem->qty);
            }
        }

        if ($expenseItem->qty <= 0) {
            $this->remove($expenseItem->rowId);
            return;
        } else {
            $content->put($expenseItem->rowId, $expenseItem);
        }

        $this->events->dispatch('expense.updated', $expenseItem);

        $this->session->put($this->instance, $content);

        return $expenseItem;
    }

    /**
     * Remove the expense item with the given rowId from the expense.
     *
     * @param string $rowId
     * @return void
     */
    public function remove($rowId)
    {
        $expenseItem = $this->get($rowId);

        $content = $this->getContent();

        $content->pull($expenseItem->rowId);

        $this->events->dispatch('expense.removed', $expenseItem);

        $this->session->put($this->instance, $content);
    }

    /**
     * Get a expense item from the expense by its rowId.
     *
     * @param string $rowId
     * @return \Xslaincart\Shoppingcart\ExpenseItem
     */
    public function get($rowId)
    {
        $content = $this->getContent();

        if ( ! $content->has($rowId))
            throw new InvalidRowIDException("The expense does not contain rowId {$rowId}.");

        return $content->get($rowId);
    }

    /**
     * Destroy the current expense instance.
     *
     * @return void
     */
    public function destroy()
    {
        $this->session->remove($this->instance);
    }

    /**
     * Get the content of the expense.
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
     * Get the number of items in the expense.
     *
     * @return int|float
     */
    public function count()
    {
        $content = $this->getContent();

        return $content->sum('qty');
    }

    /**
     * Get the total price of the items in the expense.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     * @return string
     */
    public function total($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        $content = $this->getContent();

        $total = $content->reduce(fn($total, ExpenseItem $expenseItem) => $total + ($expenseItem->qty * $expenseItem->priceTax), 0);

        return $this->numberFormat($total, $decimals, $decimalPoint, $thousandSeperator);
    }

    /**
     * Get the total tax of the items in the expense.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     * @return float
     */
    public function tax($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        $content = $this->getContent();

        $tax = $content->reduce(fn($tax, ExpenseItem $expenseItem) => $tax + ($expenseItem->qty * $expenseItem->tax), 0);

        return $this->numberFormat($tax, $decimals, $decimalPoint, $thousandSeperator);
    }

    /**
     * Get the subtotal (total - tax) of the items in the expense.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     * @return float
     */
    public function subtotal($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        $content = $this->getContent();

        $subTotal = $content->reduce(fn($subTotal, ExpenseItem $expenseItem) => $subTotal + ($expenseItem->qty * $expenseItem->price), 0);

        return $this->numberFormat($subTotal, $decimals, $decimalPoint, $thousandSeperator);
    }

    /**
     * Search the expense content for a expense item matching the given search closure.
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
     * Associate the expense item with the given rowId with the given model.
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

        $expenseItem = $this->get($rowId);

        $expenseItem->associate($model);

        $content = $this->getContent();

        $content->put($expenseItem->rowId, $expenseItem);

        $this->session->put($this->instance, $content);
    }

    /**
     * Set the tax rate for the expense item with the given rowId.
     *
     * @param string    $rowId
     * @param int|float $taxRate
     * @return void
     */
    public function setTax($rowId, $taxRate)
    {
        $expenseItem = $this->get($rowId);

        $expenseItem->setTaxRate($taxRate);

        $content = $this->getContent();

        $content->put($expenseItem->rowId, $expenseItem);

        $this->session->put($this->instance, $content);
    }

    /**
     * Store an the current instance of the expense.
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

        $this->events->dispatch('expense.stored');
    }

    /**
     * Restore the expense with the given identifier.
     *
     * @param mixed $identifier
     * @return void
     */
    public function restore($identifier)
    {
        if( ! $this->storedExpenseWithIdentifierExists($identifier)) {
            return;
        }

        $stored = $this->getConnection()->table($this->getTableName())
            ->where('instance', $this->currentInstance())
            ->where('identifier', $identifier)->first();

        $storedContent = unserialize(data_get($stored, 'content'));

        $currentInstance = $this->currentInstance();

        $this->instance(data_get($stored, 'instance'));

        $content = $this->getContent();

        foreach ($storedContent as $expenseItem) {
            $content->put($expenseItem->rowId, $expenseItem);
        }

        $this->events->dispatch('expense.restored');

        $this->session->put($this->instance, $content);

        $this->instance($currentInstance);

    }



    /**
     * Deletes the stored expense with given identifier
     *
     * @param mixed $identifier
     */
    public function deleteStoredExpense($identifier) {
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
     * Get the carts content, if there is no expense content set yet, return a new empty Collection
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
     * Create a new ExpenseItem from the supplied attributes.
     *
     * @param mixed     $id
     * @param mixed     $name
     * @param int|float $qty
     * @param float     $price
     * @param array     $options
     * @param float     $taxrate
     * @return \Xslaincart\Shoppingcart\ExpenseItem
     */
    private function createExpenseItem($id, $name, $qty, $price, array $options, $taxrate)
    {
        if ($id instanceof Buyable) {
            $expenseItem = ExpenseItem::fromBuyable($id, $qty ?: []);
            $expenseItem->setQuantity($name ?: 1);
            $expenseItem->associate($id);
        } elseif (is_array($id)) {
            $expenseItem = ExpenseItem::fromArray($id);
            $expenseItem->setQuantity($id['qty']);
        } else {
            $expenseItem = ExpenseItem::fromAttributes($id, $name, $price, $options);
            $expenseItem->setQuantity($qty);
        }

        if(isset($taxrate) && is_numeric($taxrate)) {
            $expenseItem->setTaxRate($taxrate);
        } else {
            $expenseItem->setTaxRate(config('expense.tax'));
        }

        return $expenseItem;
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
    protected function storedExpenseWithIdentifierExists($identifier)
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
        return config('expense.database.table', 'shoppingbooking');
    }

    /**
     * Get the database connection name.
     *
     * @return string
     */
    private function getConnectionName()
    {
        $connection = config('expense.database.connection');

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
            $decimals = is_null(config('expense.format.decimals')) ? 2 : config('expense.format.decimals');
        }
        if(is_null($decimalPoint)){
            $decimalPoint = is_null(config('expense.format.decimal_point')) ? '.' : config('expense.format.decimal_point');
        }
        if(is_null($thousandSeperator)){
            $thousandSeperator = is_null(config('expense.format.thousand_seperator')) ? ',' : config('expense.format.thousand_seperator');
        }

        return number_format($value, $decimals, $decimalPoint, $thousandSeperator);
    }
}
