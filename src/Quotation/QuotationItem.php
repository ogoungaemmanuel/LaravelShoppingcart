<?php

namespace Xslaincart\Shoppingcart\Quotation;

use Illuminate\Contracts\Support\Arrayable;
use Xslaincart\Shoppingcart\Contracts\Buyable;
use Illuminate\Contracts\Support\Jsonable;

use Illuminate\Support\Arr;

class QuotationItem implements Arrayable, Jsonable
{
    /**
     * The rowID of the booking item.
     *
     * @var string
     */
    public $rowId;

    /**
     * The ID of the booking item.
     *
     * @var int|string
     */
    public $id;

    /**
     * The quantity for this booking item.
     *
     * @var int|float
     */
    public $qty;

    /**
     * The name of the booking item.
     *
     * @var string
     */
    public $name;

    /**
     * The price without TAX of the booking item.
     *
     * @var float
     */
    public $price;

    /**
     * The options for this booking item.
     *
     * @var array
     */
    public $options;

    /**
     * The FQN of the associated model.
     *
     * @var string|null
     */
    private $associatedModel = null;

    /**
     * The tax rate for the booking item.
     *
     * @var int|float
     */
    private $taxRate = 0;

    /**
     * Is item saved for later.
     *
     * @var boolean
     */
    private $isSaved = false;

    /**
     * BookingItem constructor.
     *
     * @param int|string $id
     * @param string     $name
     * @param float      $price
     * @param array      $options
     */
    public function __construct($id, $name, $price, array $options = [])
    {
        if(empty($id)) {
            throw new \InvalidArgumentException('Please supply a valid identifier.');
        }
        if(empty($name)) {
            throw new \InvalidArgumentException('Please supply a valid name.');
        }
        if(strlen($price) < 0 || ! is_numeric($price)) {
            throw new \InvalidArgumentException('Please supply a valid price.');
        }

        $this->id       = $id;
        $this->name     = $name;
        $this->price    = floatval($price);
        $this->options  = new QuotationItemOptions($options);
        $this->rowId = $this->generateRowId($id, $options);
    }

    /**
     * Returns the formatted price without TAX.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     * @return string
     */
    public function price($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        return $this->numberFormat($this->price, $decimals, $decimalPoint, $thousandSeperator);
    }
    
    /**
     * Returns the formatted price with TAX.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     * @return string
     */
    public function priceTax($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        return $this->numberFormat($this->priceTax, $decimals, $decimalPoint, $thousandSeperator);
    }

    /**
     * Returns the formatted subtotal.
     * Subtotal is price for whole BookingItem without TAX
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     * @return string
     */
    public function subtotal($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        return $this->numberFormat($this->subtotal, $decimals, $decimalPoint, $thousandSeperator);
    }
    
    /**
     * Returns the formatted total.
     * Total is price for whole BookingItem with TAX
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     * @return string
     */
    public function total($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        return $this->numberFormat($this->total, $decimals, $decimalPoint, $thousandSeperator);
    }

    /**
     * Returns the formatted tax.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     * @return string
     */
    public function tax($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        return $this->numberFormat($this->tax, $decimals, $decimalPoint, $thousandSeperator);
    }
    
    /**
     * Returns the formatted tax.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     * @return string
     */
    public function taxTotal($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        return $this->numberFormat($this->taxTotal, $decimals, $decimalPoint, $thousandSeperator);
    }

    /**
     * Set the quantity for this booking item.
     *
     * @param int|float $qty
     */
    public function setQuantity($qty)
    {
        if(empty($qty) || ! is_numeric($qty))
            throw new \InvalidArgumentException('Please supply a valid quantity.');

        $this->qty = $qty;
    }

    /**
     * Update the booking item from a Buyable.
     *
     * @param \Xslaincart\Shoppingcart\Contracts\Buyable $item
     * @return void
     */
    public function updateFromBuyable(Buyable $item)
    {
        $this->id       = $item->getBuyableIdentifier($this->options);
        $this->name     = $item->getBuyableDescription($this->options);
        $this->price    = $item->getBuyablePrice($this->options);
        $this->priceTax = $this->price + $this->tax;
    }

    /**
     * Update the booking item from an array.
     *
     * @param array $attributes
     * @return void
     */
    public function updateFromArray(array $attributes)
    {
        $this->id       = Arr::get($attributes, 'id', $this->id);
        $this->qty      = Arr::get($attributes, 'qty', $this->qty);
        $this->name     = Arr::get($attributes, 'name', $this->name);
        $this->price    = Arr::get($attributes, 'price', $this->price);
        $this->priceTax = $this->price + $this->tax;
        $this->options  = new QuotationItemOptions(Arr::get($attributes, 'options', $this->options));

        $this->rowId = $this->generateRowId($this->id, $this->options->all());
    }

    /**
     * Associate the booking item with the given model.
     *
     * @param mixed $model
     * @return \Xslaincart\Shoppingcart\BookingItem
     */
    public function associate($model)
    {
        $this->associatedModel = is_string($model) ? $model : get_class($model);
        
        return $this;
    }

    /**
     * Set the tax rate.
     *
     * @param int|float $taxRate
     * @return \Xslaincart\Shoppingcart\BookingItem
     */
    public function setTaxRate($taxRate)
    {
        $this->taxRate = $taxRate;
        
        return $this;
    }

    /**
     * Set saved state.
     *
     * @param bool $bool
     * @return \Xslaincart\Shoppingcart\BookingItem
     */
    public function setSaved($bool)
    {
        $this->isSaved = $bool;

        return $this;
    }

    /**
     * Get an attribute from the booking item or get the associated model.
     *
     * @param string $attribute
     * @return mixed
     */
    public function __get($attribute)
    {
        if(property_exists($this, $attribute)) {
            return $this->{$attribute};
        }

        if ($attribute === 'priceTax') {
            return number_format(($this->price + $this->tax), 2, '.', '');
        }

        if ($attribute === 'subtotal') {
            return number_format(($this->qty * $this->price), 2, '.', '');
        }

        if ($attribute === 'total') {
            return number_format(($this->qty * $this->priceTax), 2, '.', '');
        }

        if ($attribute === 'tax') {
            return number_format(($this->price * ($this->taxRate / 100)), 2, '.', '');
        }

        if ($attribute === 'taxTotal') {
            return number_format(($this->tax * $this->qty), 2, '.', '');
        }

        if($attribute === 'model' && isset($this->associatedModel)) {
            return with(new $this->associatedModel)->find($this->id);
        }

        return null;
    }

    /**
     * Create a new instance from a Buyable.
     *
     * @param \Xslaincart\Shoppingcart\Contracts\Buyable $item
     * @param array                                      $options
     * @return \Xslaincart\Shoppingcart\BookingItem
     */
    public static function fromBuyable(Buyable $item, array $options = [])
    {
        return new self($item->getBuyableIdentifier($options), $item->getBuyableDescription($options), $item->getBuyablePrice($options), $options);
    }

    /**
     * Create a new instance from the given array.
     *
     * @param array $attributes
     * @return \Xslaincart\Shoppingcart\BookingItem
     */
    public static function fromArray(array $attributes)
    {
        $options = Arr::get($attributes, 'options', []);

        return new self($attributes['id'], $attributes['name'], $attributes['price'], $options);
    }

    /**
     * Create a new instance from the given attributes.
     *
     * @param int|string $id
     * @param string     $name
     * @param float      $price
     * @param array      $options
     * @return \Xslaincart\Shoppingcart\BookingItem
     */
    public static function fromAttributes($id, $name, $price, array $options = [])
    {
        return new self($id, $name, $price, $options);
    }

    /**
     * Generate a unique id for the booking item.
     *
     * @param string $id
     * @param array  $options
     * @return string
     */
    protected function generateRowId($id, array $options)
    {
        ksort($options);

        return md5($id . serialize($options));
    }

    /**
     * Get the instance as an array.
     *
     * @return array
     */
    public function toArray()
    {
        return [
            'rowId'    => $this->rowId,
            'id'       => $this->id,
            'name'     => $this->name,
            'qty'      => $this->qty,
            'price'    => $this->price,
            'options'  => $this->options->toArray(),
            'tax'      => $this->tax,
            'isSaved'      => $this->isSaved,
            'subtotal' => $this->subtotal
        ];
    }

    /**
     * Convert the object to its JSON representation.
     *
     * @param int $options
     * @return string
     */
    public function toJson($options = 0)
    {
        if (isset($this->associatedModel)){
            
           return json_encode(array_merge($this->toArray(), ['model' => $this->model]), $options);
        }
        
        return json_encode($this->toArray(), $options);
    }

    /**
     * Get the formatted number.
     *
     * @param float  $value
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     * @return string
     */
    private function numberFormat($value, $decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        if (is_null($decimals)){
            $decimals = is_null(config('booking.format.decimals')) ? 2 : config('booking.format.decimals');
        }

        if (is_null($decimalPoint)){
            $decimalPoint = is_null(config('booking.format.decimal_point')) ? '.' : config('booking.format.decimal_point');
        }

        if (is_null($thousandSeperator)){
            $thousandSeperator = is_null(config('booking.format.thousand_seperator')) ? ',' : config('booking.format.thousand_seperator');
        }

        return number_format($value, $decimals, $decimalPoint, $thousandSeperator);
    }
}
