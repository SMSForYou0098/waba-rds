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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'meta' => [
        'api_version' => env('META_API_VERSION', 'v25.0'),
        'dry_run' => filter_var(env('META_DRY_RUN', false), FILTER_VALIDATE_BOOL),
        'fair_feeder_enabled' => filter_var(env('META_FAIR_FEEDER_ENABLED', true), FILTER_VALIDATE_BOOL),
        'feeder_tick_seconds' => (int) env('META_FEEDER_TICK_SECONDS', 1),
        'feeder_per_campaign_slice' => (int) env('META_FEEDER_PER_CAMPAIGN_SLICE', 15),
        'feeder_max_dispatch_per_tick' => (int) env('META_FEEDER_MAX_DISPATCH_PER_TICK', 120),
        'feeder_max_queue_depth' => (int) env('META_FEEDER_MAX_QUEUE_DEPTH', 300),
    ],

    'wa' => [
        'api_templates' => env('WA_API_TEMPLATES'),
    ],

];
