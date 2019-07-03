<?php
return [
    'USERNAME' => env('GAZETTE_USERNAME', ''),
    'PASSWORD' => env('GAZETTE_PASSWORD', ''),
    'TOKEN_URL' => env('GAZETTE_TOKEN_URL', 'https://www.thegazette.co.uk/oauth/token'),
    'GAZETTE_API_ENDPOINT' => env('GAZETTE_API_ENDPOINT', 'https://www.thegazette.co.uk/'),
];
