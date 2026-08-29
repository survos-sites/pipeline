<?php

/**
 * Returns the importmap for this application.
 *
 * - "path" is a path inside the asset mapper system. Use the
 *     "debug:asset-map" command to see the full list of paths.
 *
 * - "entrypoint" (JavaScript only) set to true for any module that will
 *     be used as an "entrypoint" (and passed to the importmap() Twig function).
 *
 * The "importmap:require" command can be used to add new entries to this file.
 *
 * @return array<string, array{    // Import name as key, description of the imported file as value
 *     path: string,               // Logical, relative or absolute path to the file
 *     type?: 'js'|'css'|'json',   // Type of the file, defaults to 'js'
 *     entrypoint?: bool,          // Whether the file is an entrypoint, for 'js' only
 * }|array{
 *     version: string,            // Version of the remote package
 *     package_specifier?: string, // Remote "package-name/path" specifier, defaults to the import name
 *     type?: 'js'|'css'|'json',
 *     entrypoint?: bool,
 * }>
 */
return [
    '@survos/js-twig/generated/fos_routes.js' => ['path' => '@survos/js-twig/generated/fos_routes.js'],
    '@survos/js-twig-bundle/twig_api' => ['path' => './vendor/survos/js-twig-bundle/assets/src/lib/twig_api.js'],
    '@survos/js-twig-bundle/twig_blocks' => ['path' => './vendor/survos/js-twig-bundle/assets/src/lib/twig_blocks.js'],
    'fos-routing' => ['version' => '0.0.6'],
    'app' => ['path' => './assets/app.js', 'entrypoint' => true],
    '@hotwired/stimulus' => ['version' => '3.2.2'],
    '@symfony/stimulus-bundle' => ['path' => './vendor/symfony/stimulus-bundle/assets/dist/loader.js'],
    '@hotwired/turbo' => ['version' => '7.3.0'],
    'bootstrap' => ['version' => '5.3.8'],
    '@popperjs/core' => ['version' => '2.11.8'],
    '@tabler/core' => ['version' => '1.4.0'],
    '@tabler/core/dist/css/tabler.min.css' => ['version' => '1.4.0', 'type' => 'css'],
    'simple-datatables' => ['version' => '10.3.0'],
    'simple-datatables/dist/style.min.css' => ['version' => '10.3.0', 'type' => 'css'],
    'dexie' => ['version' => '4.4.2'],
    '@tacman1123/twig-browser' => ['version' => '1.0.0'],
    '@tacman1123/twig-browser/src/compat/compileTwigBlocks.js' => ['version' => '1.0.0'],
    '@tacman1123/twig-browser/adapters/symfony' => ['version' => '1.0.0'],
    'debug' => ['version' => '4.4.3'],
    'ms' => ['version' => '2.1.3'],
    'instantsearch.js' => ['version' => '4.96.2'],
    '@swc/helpers/esm/_sliced_to_array.js' => ['version' => '0.5.18'],
    '@swc/helpers/esm/_to_consumable_array.js' => ['version' => '0.5.18'],
    '@swc/helpers/esm/_define_property.js' => ['version' => '0.5.18'],
    '@swc/helpers/esm/_extends.js' => ['version' => '0.5.18'],
    '@swc/helpers/esm/_object_destructuring_empty.js' => ['version' => '0.5.18'],
    '@swc/helpers/esm/_object_spread.js' => ['version' => '0.5.18'],
    '@swc/helpers/esm/_object_spread_props.js' => ['version' => '0.5.18'],
    '@swc/helpers/esm/_type_of.js' => ['version' => '0.5.18'],
    'algoliasearch-helper/types/algoliasearch.js' => ['version' => '3.29.1'],
    '@swc/helpers/esm/_instanceof.js' => ['version' => '0.5.18'],
    '@swc/helpers/esm/_object_without_properties.js' => ['version' => '0.5.18'],
    '@swc/helpers/esm/_call_super.js' => ['version' => '0.5.18'],
    '@swc/helpers/esm/_class_call_check.js' => ['version' => '0.5.18'],
    '@swc/helpers/esm/_create_class.js' => ['version' => '0.5.18'],
    '@swc/helpers/esm/_inherits.js' => ['version' => '0.5.18'],
    '@algolia/events' => ['version' => '4.0.1'],
    'algoliasearch-helper' => ['version' => '3.29.1'],
    'qs' => ['version' => '6.15.1'],
    'side-channel' => ['version' => '1.1.0'],
    'es-errors/type' => ['version' => '1.3.0'],
    'object-inspect' => ['version' => '1.13.3'],
    'side-channel-list' => ['version' => '1.0.0'],
    'side-channel-map' => ['version' => '1.0.1'],
    'side-channel-weakmap' => ['version' => '1.0.2'],
    'get-intrinsic' => ['version' => '1.2.5'],
    'call-bound' => ['version' => '1.0.2'],
    'es-errors' => ['version' => '1.3.0'],
    'es-errors/eval' => ['version' => '1.3.0'],
    'es-errors/range' => ['version' => '1.3.0'],
    'es-errors/ref' => ['version' => '1.3.0'],
    'es-errors/syntax' => ['version' => '1.3.0'],
    'es-errors/uri' => ['version' => '1.3.0'],
    'gopd' => ['version' => '1.2.0'],
    'es-define-property' => ['version' => '1.0.1'],
    'has-symbols' => ['version' => '1.1.0'],
    'dunder-proto/get' => ['version' => '1.0.0'],
    'call-bind-apply-helpers/functionApply' => ['version' => '1.0.0'],
    'call-bind-apply-helpers/functionCall' => ['version' => '1.0.0'],
    'function-bind' => ['version' => '1.1.2'],
    'hasown' => ['version' => '2.0.2'],
    'call-bind' => ['version' => '1.0.8'],
    'call-bind-apply-helpers' => ['version' => '1.0.0'],
    'set-function-length' => ['version' => '1.2.2'],
    'call-bind-apply-helpers/applyBind' => ['version' => '1.0.0'],
    'define-data-property' => ['version' => '1.1.4'],
    'has-property-descriptors' => ['version' => '1.0.2'],
    'instantsearch.js/es/widgets' => ['version' => '4.96.2'],
    'instantsearch-ui-components' => ['version' => '0.26.1'],
    'preact' => ['version' => '10.29.1'],
    'hogan.js' => ['version' => '3.0.2'],
    'htm/preact' => ['version' => '3.1.1'],
    'preact/hooks' => ['version' => '10.29.1'],
    'markdown-to-jsx' => ['version' => '7.7.17'],
    'htm' => ['version' => '3.1.1'],
    'react' => ['version' => '19.2.0'],
    'instantsearch.css/themes/algolia.min.css' => ['version' => '8.16.1', 'type' => 'css'],
    '@meilisearch/instant-meilisearch' => ['version' => '0.30.0'],
    'meilisearch' => ['version' => '0.54.0'],
    '@stimulus-components/dialog' => ['version' => '1.0.1'],
    '@andypf/json-viewer' => ['version' => '2.4.0'],
    'pretty-print-json' => ['version' => '3.0.7'],
    'pretty-print-json/dist/css/pretty-print-json.min.css' => ['version' => '3.0.7', 'type' => 'css'],
    'openseadragon' => ['version' => '6.1.0'],
    'diva.js' => ['version' => '7.4.1'],
    'pdfjs-dist' => ['version' => '6.2.108'],
    'marked' => ['version' => '18.0.9'],
    'flag-icons/css/flag-icons.min.css' => ['version' => '7.5.0', 'type' => 'css'],
    '@floating-ui/dom' => ['version' => '1.8.0'],
    '@floating-ui/core' => ['version' => '1.8.0'],
    '@floating-ui/utils' => ['version' => '0.2.12'],
    '@floating-ui/utils/dom' => ['version' => '0.2.12'],
];
