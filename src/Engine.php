<?php

namespace League\Plates;

/** API for the Plates system */
final class Engine
{
    private $container;

    public function __construct($config = []) {
        $this->container = new Util\Container();

        $this->container->add('engine_methods', []);
        $this->container->add('config', [
            'render_context_var_name' => 'v',
            'ext' => 'phtml',
            'base_dir' => null,
            'escape_encoding' => null,
            'escape_flags' => null,
            'validate_paths' => true,
            'php_extensions' => ['php', 'phtml'],
            'image_extensions' => ['png', 'jpg'],
        ]);
        $this->container->addComposed('compose', function() { return []; });
        $this->container->add('fileExists', function($c) {
            return 'file_exists';
        });
        $this->container->add('escape', function($c) {
            $config = $c->get('config');
            return isset($config['escape_flags'], $config['escape_encoding'])
                ? Util\escape($config['escape_flags'], $config['escape_encoding'])
                : Util\escape();
        });
        $this->container->add('renderTemplate', function($c) {
            $rt = new RenderTemplate\FileSystemRenderTemplate([
                [
                    Template\matchExtensions($c->get('config')['php_extensions']),
                    new RenderTemplate\PhpRenderTemplate($c->get('renderTemplate.bind'))
                ],
                [
                    Template\matchExtensions($c->get('config')['image_extensions']),
                    RenderTemplate\MapContentRenderTemplate::base64Encode(new RenderTemplate\StaticFileRenderTemplate())
                ],
                [
                    Template\matchStub(true),
                    new RenderTemplate\StaticFileRenderTemplate(),
                ]
            ]);
            if ($c->get('config')['validate_paths']) {
                $rt = new RenderTemplate\ValidatePathRenderTemplate($rt, $c->get('fileExists'));
            }
            $rt = array_reduce($c->get('renderTemplate.factories'), function($rt, $create) {
                return $create($rt);
            }, $rt);
            $rt = new RenderTemplate\ComposeRenderTemplate($rt, $c->get('compose'));
            return $rt;
        });
        $this->container->add('renderTemplate.bind', function() {
            return Util\id();
        });
        $this->container->add('renderTemplate.factories', function() {
            return [];
        });

        $this->addMethods([
            'pushComposers' => function(Engine $e, $def_composer) {
                $e->getContainer()->wrapComposed('compose', function($composed, $c) use ($def_composer) {
                    return array_merge($composed, $def_composer($c));
                });
            },
            'unshiftComposers' => function(Engine $e, $def_composer) {
                $e->getContainer()->wrapComposed('compose', function($composed, $c) use ($def_composer) {
                    return array_merge($def_composer($c), $composed);
                });
            },
        ]);

        $this->register(new Extension\Data\DataExtension());
        $this->register(new Extension\Path\PathExtension());
        $this->register(new Extension\RenderContext\RenderContextExtension());
        $this->register(new Extension\LayoutSections\LayoutSectionsExtension());
        $this->register(new Extension\Folders\FoldersExtension());
        $this->register(new Extension\AutoEscape\AutoEscapeExtension());

        $this->addConfig($config);
    }

    /** @return string */
    public function render($template_name, array $data = [], array $attributes = []) {
        return $this->container->get('renderTemplate')->renderTemplate(new Template(
            $template_name,
            $data,
            $attributes
        ));
    }

    public function __call($method, array $args) {
        $methods = $this->container->get('engine_methods');
        if (isset($methods[$method])) {
            return $methods[$method]($this, ...$args);
        }

        throw new \BadMethodCallException("No method {$method} found for engine.");
    }

    public function register(Extension $extension) {
        $extension->register($this);
    }
    public function addMethods(array $methods) {
        $this->container->merge('engine_methods', $methods);
    }
    public function addConfig(array $config) {
        $this->container->merge('config', $config);
    }

    public function getContainer() {
        return $this->container;
    }
}
