<?php
namespace Xslaincart\Shoppingcart\Facades;

use Illuminate\Support\Facades\Facade;

class Fee extends Facade {
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'fee';
    }
}
