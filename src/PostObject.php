<?php

namespace WPGraphQL\Extensions\Polylang;

use GraphQLRelay\Relay;

class PostObject
{
    function __construct()
    {
        add_action('graphql_register_types', [$this, 'register'], 10, 0);

        add_filter(
            'graphql_post_object_connection_query_args',
            function ($query_args) {
                // Polylang handles 'lang' query arg so convert our 'language'
                // query arg if it is set
                if (isset($query_args['language'])) {
                    $lang = $query_args['language'];

                    if ('default' === $lang) {
                        $lang = pll_default_language('slug');
                    }

                    $query_args['lang'] = $lang;

                    unset($query_args['language']);
                }

                return $query_args;
            },
            10,
            1
        );
    }

    function register()
    {
        $this->show_posts_by_all_languages();

        foreach (\WPGraphQL::get_allowed_post_types() as $post_type) {
            $this->add_post_type_fields(get_post_type_object($post_type));
        }

        /**
         * Handle create and update input fields
         */
        add_action(
            'graphql_post_object_mutation_update_additional_data',
            function ($post_id, array $input, \WP_Post_Type $post_type_object) {
                if (isset($input['language'])) {
                    pll_set_post_language($post_id, $input['language']);
                }
            },
            10,
            3
        );
    }

    function show_posts_by_all_languages()
    {
        add_filter(
            'graphql_post_object_connection_query_args',
            function ($query_args) {
                $query_args['show_all_languages_in_graphql'] = true;
                return $query_args;
            },
            10,
            1
        );

        /**
         * Handle query var added by the above filter in Polylang which
         * causes all languages to be shown in the queries.
         * See https://github.com/polylang/polylang/blob/2ed446f92955cc2c952b944280fce3c18319bd85/include/query.php#L125-L134
         */
        add_filter(
            'pll_filter_query_excluded_query_vars',
            function () {
                $excludes[] = 'show_all_languages_in_graphql';
                return $excludes;
            },
            3,
            10
        );
    }

    function add_post_type_fields(\WP_Post_Type $post_type_object)
    {
        if (!pll_is_translated_post_type($post_type_object->name)) {
            return;
        }

        $type = ucfirst($post_type_object->graphql_single_name);

        register_graphql_fields("RootQueryTo${type}ConnectionWhereArgs", [
            'language' => [
                'type' => 'LanguageCodeFilterEnum',
                'description' => "Filter by ${type}s by language code (Polylang)",
            ],
        ]);

        register_graphql_fields("Create${type}Input", [
            'language' => [
                'type' => 'LanguageCodeEnum',
            ],
        ]);

        register_graphql_fields("Update${type}Input", [
            'language' => [
                'type' => 'LanguageCodeEnum',
            ],
        ]);

        error_log('REG');
        register_graphql_field(
            $post_type_object->graphql_single_name,
            'language',
            [
                'type' => 'Language',
                'description' => __('Polylang language', 'wpnext'),
                'resolve' => function (\WP_Post $post, $args, $context, $info) {
                    $fields = $info->getFieldSelection();
                    $language = [];

                    if (usesSlugBasedField($fields)) {
                        $language['code'] = pll_get_post_language(
                            $post->ID,
                            'slug'
                        );
                        $language['slug'] = $language['code'];
                        $language['id'] = Relay::toGlobalId(
                            'Language',
                            $language['code']
                        );
                    }

                    if (isset($fields['name'])) {
                        $language['name'] = pll_get_post_language(
                            $post->ID,
                            'name'
                        );
                    }

                    if (isset($fields['locale'])) {
                        $language['locale'] = pll_get_post_language(
                            $post->ID,
                            'locale'
                        );
                    }

                    return $language;
                },
            ]
        );

        register_graphql_field(
            $post_type_object->graphql_single_name,
            'translation',
            [
                'type' => $type,
                'description' => __(
                    'Get specific translation version of this object',
                    'wp-graphql-polylang'
                ),
                'args' => [
                    'language' => [
                        'type' => [
                            'non_null' => 'LanguageCodeEnum',
                        ],
                    ],
                ],
                'resolve' => function (\WP_Post $post, array $args) {
                    $translations = pll_get_post_translations($post->ID);
                    $post_id = $translations[$args['language']] ?? null;

                    if (!$post_id) {
                        return null;
                    }

                    return \WP_Post::get_instance($post_id);
                },
            ]
        );

        register_graphql_field(
            $post_type_object->graphql_single_name,
            'translations',
            [
                'type' => [
                    'list_of' => $type,
                ],
                'description' => __(
                    'List all translated versions of this post',
                    'wp-graphql-polylang'
                ),
                'resolve' => function (\WP_Post $post) {
                    $posts = [];

                    foreach (
                        pll_get_post_translations($post->ID)
                        as $lang => $post_id
                    ) {
                        $translation = \WP_Post::get_instance($post_id);

                        if (!$translation) {
                            continue;
                        }

                        if (is_wp_error($translation)) {
                            continue;
                        }

                        if ($post->ID === $translation->ID) {
                            continue;
                        }

                        $posts[] = $translation;
                    }

                    return $posts;
                },
            ]
        );
    }
}
