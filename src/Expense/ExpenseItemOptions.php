<?php

namespace Xslaincart\Shoppingcart\Expense;

use Illuminate\Support\Collection;

class ExpenseItemOptions extends Collection
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