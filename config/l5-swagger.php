<?php

return [

    'default' => 'default',

    'documentations' => [

        'default' => [
            'api' => [
                'title' => 'API de Gestión Académica',
                'version' => '1.0.0',
            ],

            'routes' => [
                /*
                 * Route for accessing api documentation interface, e.g. `/api/documentation`.
                 */
                'api' => 'api/documentation',
            ],

            'paths' => [
                /*
                 * Absolute path to location where parsed swagger yaml file will be stored.
                 */
                'docs_json' => 'api-docs.json',

                /*
                 * Absolute path to location where parsed swagger YAML file will be stored.
                 */
                'docs_yaml' => 'api-docs.yaml',

                /*
                 * File name of the generated JSON documentation file.
                 */
                'format_json' => 'json',

                /*
                 * File name of the generated YAML documentation file.
                 */
                'format_yaml' => 'yaml',

                /**
                 * Set this to `json` or `yaml` to determine which documentation file to load in Swagger UI
                 */
                'docs_format' => 'json',

                /*
                 * Absolute path to directory where to dump generated json files.
                 */
                'base_path' => base_path('storage/api-docs'),

                /*
                 * Relative application path.
                 */
                'app_path' => app_path(),

                /*
                 * Absolute path to directories that you would like the scraper to ignore
                 */
                'ignore' => [
                    //
                ],

                /*
                 * Hide routes from the generated swagger.json
                 */
                'exclude' => [
                    //
                ],
            ],

            'security' => [
                /*
                 * Examples of Security "Schemes"
                 */
                'api_key_security_example' => [
                    'type' => 'apiKey',
                    'description' => 'API key authentication',
                    'name' => 'api_key',
                    'in' => 'header',
                ],

                'oauth2_security_example' => [
                    'type' => 'oauth2',
                    'description' => 'OAuth 2.0 authentication',
                    'flow' => 'implicit',
                    'authorizationUrl' => env('L5_SWAGGER_OAUTH_AUTHORIZATION_URL', 'http://localhost/oauth/authorize'),
                    'tokenUrl' => env('L5_SWAGGER_OAUTH_TOKEN_URL', 'http://localhost/oauth/token'),

                    'scopes' => [
                        'project:read' => 'Read projects',
                        'project:write' => 'Modify projects',
                    ],
                ],

                'bearer_security_example' => [
                    'type' => 'http',
                    'scheme' => 'bearer',
                    'bearerFormat' => 'JWT',
                    'description' => 'JWT Token based security',
                ],
            ],
        ],
    ],

    'defaults' => [
        'controllers_namespace' => 'App\\Http\\Controllers',
        'routes_prefix' => 'api/v1',
        'info_version' => env('L5_SWAGGER_VERSION', '1.0.0'),
        'app_url' => env('APP_URL', 'http://localhost'),

        'route_middleware' => ['api'], // Accept an array of route middleware to apply to every route

        'route_group_middleware' => [], // Accept an array of route group middleware to apply to every route

        'api_key' => env('L5_SWAGGER_API_KEY', ''),

        'constants' => [
            'L5_SWAGGER_CONST_HOST' => env('L5_SWAGGER_CONST_HOST', ''),
        ],
    ],

    /*
     * Uncomment to add custom ui assets path (css or js files).
     * Enter the url from your js or css files.
     * Example: 'custom_assets_url' => 'https://cdn.jsdelivr.net/npm/swagger-ui-dist@3/swagger-ui.css',
     */

    'operations' => [
        /*
         * The algorithm you want to use for hashing route parameters
         * See https://www.php.net/manual/en/function.hash-algos.php for
         * the list of available hash algorithms.
         */
        'hash_algorithm' => 'md5',

        /*
         * The path to be used as base path in swagger.json.
         * This allows you to separate swagger documentation per API version.
         */
        'base_path' => 'api/v1',

        /*
         * Edit the operations sort, default: Routes order
         * Available values: 'alpha', 'method'
         */
        'sort' => env('L5_SWAGGER_OPERATIONS_SORT'),

        /*
         * Policies used for operation parameters and responses types.
         */
        'parameters' => [
            'in_file' => true,
        ],

        /*
         * Set this to true if you want the operation to not be visible,
         */
        'hidenut' => env('L5_SWAGGER_HIDENUT', false),
    ],

    /*
     * Set this to true if you want the swagger UI and swagger json file to be generated
     * every time you access the `/api/documentation` path.
     * This could be used to regenerate the swagger documentation on documents changes.
     */
    'generate_always' => env('L5_SWAGGER_GENERATE_ALWAYS', false),

    /*
     * Set this to true if you want to generate a copy of swagger json in the given
     * `json_path` every time you manually run `php artisan l5-swagger:generate` command.
     */
    'generate_yaml_copy' => env('L5_SWAGGER_GENERATE_YAML_COPY', false),

    /*
     * Configs plugin allows you to change the way the list of servers is resolved in Swagger.
     * Every time you change the configurations, you need to regenerate the swagger docs.
     *
     * Note: If you are serving your files dynamically using routes and requests,
     * you might want to add those routes/requests to generate the Swagger.
     */
    'servers' => [
        [
            'url' => env('L5_SWAGGER_SERVER_URL', 'http://localhost:8000'),
            'description' => env('L5_SWAGGER_SERVER_DESCRIPTION', 'Servidor de desarrollo'),
        ],
        [
            'url' => env('L5_SWAGGER_PRODUCTION_URL', 'https://api.production.com'),
            'description' => env('L5_SWAGGER_PRODUCTION_DESCRIPTION', 'Servidor de producción'),
        ],
    ],

    /*
     * Custom Swagger UI assets folder path and URL, to override default swagger files
     * Files to override:
     *      - swagger-ui.css
     *      - swagger-ui.js
     *      - swagger-ui-bundle.js
     *      - swagger-ui-standalone-preset.js
     *      - favicon-16x16.png
     *      - favicon-32x32.png
     */
    'ui' => [
        'use_cdn' => true,
        'cdn_url' => 'https://cdn.jsdelivr.net/npm/swagger-ui-dist@3',
    ],

    /*
     * Laravel-Swagger Middleware
     */
    'middleware' => [
        'api' => [],
        'asset' => [],
        'docs' => [],
    ],

    /*
     * Laravel-Swagger authentication token key in request header.
     */
    'api_token_headers' => [
        'api_key' => 'api_token',
    ],

];
