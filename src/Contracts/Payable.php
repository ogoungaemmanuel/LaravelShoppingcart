<?php

namespace Xslaincart\Shoppingcart\Contracts;

interface Payable
{
    /**
     * Get the identifier of the Payable item.
     *
     * @return int|string
     */
    public function getPayableIdentifier($options = null);

    /**
     * Get the description or title of the Payable item.
     *
     * @return string
     */
    public function getPayableDescription($options = null);

    /**
     * Get the price of the Payable item.
     *
     * @return float
     */
    public function getPayablePrice($options = null);
}