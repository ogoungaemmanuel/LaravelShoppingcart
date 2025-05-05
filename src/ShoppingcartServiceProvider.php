<?php

namespace Xslaincart\Shoppingcart;

use Illuminate\Auth\Events\Logout;
use Illuminate\Session\SessionManager;
use Illuminate\Support\ServiceProvider;

class ShoppingcartServiceProvider extends ServiceProvider
{

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->app->bind('cart', \Xslaincart\Shoppingcart\Cart\Cart::class);
        $this->app->bind('expense', \Xslaincart\Shoppingcart\Expense\Expense::class);
        $this->app->bind('invoice', \Xslaincart\Shoppingcart\Invoice\Invoice::class);
        $this->app->bind('booking', \Xslaincart\Shoppingcart\Booking\Booking::class);
        $this->app->bind('quotation', \Xslaincart\Shoppingcart\Quotation\Quotation::class);
        $this->app->bind('fee', \Xslaincart\Shoppingcart\Fee\Fee::class);

        $config = __DIR__ . '/../config/cart.php';
        $this->mergeConfigFrom($config, 'cart');

        $this->publishes([__DIR__ . '/../config/cart.php' => config_path('cart.php')], 'config');

        $this->app['events']->listen(Logout::class, function (): void {
            if ($this->app['config']->get('cart.destroy_on_logout') || $this->app['config']->get('expense.destroy_on_logout') || $this->app['config']->get('invoice.destroy_on_logout') || $this->app['config']->get('booking.destroy_on_logout') || $this->app['config']->get('quotation.destroy_on_logout') || $this->app['config']->get('fee.destroy_on_logout')) {
                $this->app->make(SessionManager::class)->forget('cart');
                $this->app->make(SessionManager::class)->forget('expense');
                $this->app->make(SessionManager::class)->forget('invoice');
                $this->app->make(SessionManager::class)->forget('booking');
                $this->app->make(SessionManager::class)->forget('quotation');
                $this->app->make(SessionManager::class)->forget('fee');
            }
        });

        if ( ! class_exists('CreateShoppingcartTable')) {
            // Publish the migration
            $timestamp = date('Y_m_d_His', time());

            $this->publishes([
                __DIR__.'/../database/migrations/0000_00_00_000000_create_shoppingcart_table.php' => database_path('migrations/'.$timestamp.'_create_shoppingcart_table.php'),
            ], 'migrations');
        }
    }
}
