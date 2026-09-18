<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'perry_weather' => [
        'url' => env('PERRY_WEATHER_URL', 'https://widget.api.perryweather.com/v1'),
        // Other McKinney field IDs are listed in .env.example
        'location_id' => env('PERRY_WEATHER_LOCATION_ID', 'eaed2528-92ff-4fca-bb27-028e2c70a058'),
        'location_name' => env('PERRY_WEATHER_LOCATION_NAME', 'Al Ruschhaupt Park'),
    ],

    'nws' => [
        'user_agent' => env('NWS_USER_AGENT', 'wbgt (jake.bathman@gmail.com)'),
        'location_name' => env('WEATHER_LOCATION_NAME', 'McKinney, TX'),
        'latitude' => env('WEATHER_LATITUDE', 33.1972),
        'longitude' => env('WEATHER_LONGITUDE', -96.6398),
        'timezone' => env('WEATHER_TIMEZONE', 'America/Chicago'),
    ],

];
