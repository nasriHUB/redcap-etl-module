<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInita8b4eba1f286c7b8e6f4ee6fe1ff7ba2
{
    public static $files = array (
        'decc78cc4436b1292c6c0d151b19445c' => __DIR__ . '/..' . '/phpseclib/phpseclib/phpseclib/bootstrap.php',
    );

    public static $prefixLengthsPsr4 = array (
        'p' => 
        array (
            'phpseclib3\\' => 11,
        ),
        'P' => 
        array (
            'ParagonIE\\ConstantTime\\' => 23,
        ),
        'I' => 
        array (
            'IU\\RedCapEtlModule\\' => 19,
            'IU\\REDCapETL\\' => 13,
            'IU\\PHPCap\\' => 10,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'phpseclib3\\' => 
        array (
            0 => __DIR__ . '/..' . '/phpseclib/phpseclib/phpseclib',
        ),
        'ParagonIE\\ConstantTime\\' => 
        array (
            0 => __DIR__ . '/..' . '/paragonie/constant_time_encoding/src',
        ),
        'IU\\RedCapEtlModule\\' => 
        array (
            0 => __DIR__ . '/../..' . '/',
            1 => __DIR__ . '/../..' . '/classes',
        ),
        'IU\\REDCapETL\\' => 
        array (
            0 => __DIR__ . '/..' . '/iu-redcap/redcap-etl/src',
        ),
        'IU\\PHPCap\\' => 
        array (
            0 => __DIR__ . '/..' . '/iu-redcap/phpcap/src',
        ),
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInita8b4eba1f286c7b8e6f4ee6fe1ff7ba2::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInita8b4eba1f286c7b8e6f4ee6fe1ff7ba2::$prefixDirsPsr4;

        }, null, ClassLoader::class);
    }
}
