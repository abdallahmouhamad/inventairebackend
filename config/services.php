<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
     * API PHP "RererentielX3" -- interface de lecture seule deja en place
     * entre Laravel et Sage X3 (voir Docs/FRONTEND_CONTEXT.md §1/§2). Jamais
     * de tables X3 dupliquees en Postgres, on interroge en direct via HTTP.
     */
    'referentielx3' => [
        'base_url' => env('REFERENTIELX3_BASE_URL', 'http://196.207.230.171:9191/referentielx3'),
        'timeout' => env('REFERENTIELX3_TIMEOUT', 15),
    ],

];
