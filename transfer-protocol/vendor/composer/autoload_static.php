<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInit82edd3a9af4afbf739dd8113cfd69f11
{
    public static $files = array (
        'e69f7f6ee287b969198c3c9d6777bd38' => __DIR__ . '/..' . '/symfony/polyfill-intl-normalizer/bootstrap.php',
    );

    public static $prefixLengthsPsr4 = array (
        'W' => 
        array (
            'Wordpress\\TransferProtocol\\' => 27,
        ),
        'S' => 
        array (
            'Symfony\\Polyfill\\Intl\\Normalizer\\' => 33,
        ),
        'R' => 
        array (
            'Rowbot\\URL\\' => 11,
            'Rowbot\\Punycode\\' => 16,
            'Rowbot\\Idna\\Resource\\' => 21,
            'Rowbot\\Idna\\' => 12,
        ),
        'P' => 
        array (
            'Psr\\Log\\' => 8,
        ),
        'B' => 
        array (
            'Brick\\Math\\' => 11,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'Wordpress\\TransferProtocol\\' => 
        array (
            0 => __DIR__ . '/../..' . '/src',
        ),
        'Symfony\\Polyfill\\Intl\\Normalizer\\' => 
        array (
            0 => __DIR__ . '/..' . '/symfony/polyfill-intl-normalizer',
        ),
        'Rowbot\\URL\\' => 
        array (
            0 => __DIR__ . '/..' . '/rowbot/url/src',
        ),
        'Rowbot\\Punycode\\' => 
        array (
            0 => __DIR__ . '/..' . '/rowbot/punycode/src',
        ),
        'Rowbot\\Idna\\Resource\\' => 
        array (
            0 => __DIR__ . '/..' . '/rowbot/idna/resources',
        ),
        'Rowbot\\Idna\\' => 
        array (
            0 => __DIR__ . '/..' . '/rowbot/idna/src',
        ),
        'Psr\\Log\\' => 
        array (
            0 => __DIR__ . '/..' . '/psr/log/src',
        ),
        'Brick\\Math\\' => 
        array (
            0 => __DIR__ . '/..' . '/brick/math/src',
        ),
    );

    public static $classMap = array (
        'Composer\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
        'Normalizer' => __DIR__ . '/..' . '/symfony/polyfill-intl-normalizer/Resources/stubs/Normalizer.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInit82edd3a9af4afbf739dd8113cfd69f11::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInit82edd3a9af4afbf739dd8113cfd69f11::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInit82edd3a9af4afbf739dd8113cfd69f11::$classMap;

        }, null, ClassLoader::class);
    }
}
