<?php

namespace Xslaincart\Shoppingcart\Invoice;

use Illuminate\Support\Collection;

class InvoiceItemOptions extends Collection
{
    /**
     * Get the option by the given key.
     *
     * @param string $key
     * @return mixed
     */
    public function __get($key)
    {
        return $this->get($key);
    }
}