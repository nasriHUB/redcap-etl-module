<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInitbebade0fb8db2b527089d5a5cc560a7c
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
            $loader->prefixLengthsPsr4 = ComposerStaticInitbebade0fb8db2b527089d5a5cc560a7c::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInitbebade0fb8db2b527089d5a5cc560a7c::$prefixDirsPsr4;

        }, null, ClassLoader::class);
    }
}
