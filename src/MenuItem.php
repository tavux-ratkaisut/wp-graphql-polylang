<?php

namespace WPGraphQL\Extensions\Polylang;

class MenuItem
{
    static function translate_menu_location(
        string $location,
        string $language
    ): string {
        $language = strtoupper($language);
        return "${location}___${language}";
    }

    function __construct()
    {
        add_action('graphql_register_types', [$this, 'register_fields'], 10, 0);
        add_action('graphql_init', [$this, 'create_nav_menu_locations'], 10, 0);
        add_filter(
            'graphql_menu_item_connection_args',
            [$this, 'map_input_language_to_location'],
            10,
            1
        );
    }

    function map_input_language_to_location(array $args)
    {
        if (!isset($args['where']['language'])) {
            return $args;
        }

        if (!isset($args['where']['location'])) {
            return $args;
        }

        $args['where']['location'] = self::translate_menu_location(
            $args['where']['location'],
            $args['where']['language']
        );

        unset($args['where']['language']);

        return $args;
    }

    /**
     * Nav menu locations are created on admin_init with PLL_Admin but GraphQL
     * requests do not call se we must manually call it
     */
    function create_nav_menu_locations()
    {
        // graphql_init is bit early. Delay to wp_loaded so the nav_menu object is avalable
        add_action(
            'wp_loaded',
            function () {
                global $polylang;

                if (
                    property_exists($polylang, 'nav_menu') &&
                    $polylang->nav_menu
                ) {
                    $polylang->nav_menu->create_nav_menu_locations();
                }
            },
            50
        );
    }

    function register_fields()
    {
        register_graphql_fields('RootQueryToMenuItemConnectionWhereArgs', [
            'language' => [
                'type' => 'LanguageCodeFilterEnum',
                'description' => '',
            ],
        ]);
    }
}
