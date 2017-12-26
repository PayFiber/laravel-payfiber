<?php

/*
 * This file is part of the Laravel Rave package.
 *
 * (c) Oluwole Adebiyi - Flamez <flamekeed@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

return [

    /**
     * Public Key: Your Rave publicKey. Sign up on https://ravepay.co to get one from your settings page
     *
     */
    'publicKey' => getenv('RAVE_PUBLIC_KEY'),

    /**
     * Secret Key: Your Rave secretKey. Sign up on https://ravepay.co to get one from your settings page
     *
     */
    'secretKey' => getenv('RAVE_SECRET_KEY'),

    /**
     * Environment: This can either be 'staging' or 'live'
     *
     */
    'env' => env('RAVE_ENVIRONMENT', 'staging'),

];