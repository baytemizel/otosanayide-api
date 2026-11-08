<?php

/*
 * phpunit.mysql.xml: baglanti bilgisini .env'den alir. Laravel test modunda .env.testing okudugu icin
 * .env tek basina etkili olmaz. Veritabani adi XML'de otosanayide_test'e sabitlenmistir, buradan degistirilemez.
 */
require __DIR__.'/../vendor/autoload.php';

$path = __DIR__.'/../.env';
$values = is_file($path) ? Dotenv\Dotenv::parse((string) file_get_contents($path)) : [];
foreach (['HOST', 'PORT', 'USERNAME', 'PASSWORD'] as $field) {
    $value = $values['DB_TEST_'.$field] ?? '';
    $value = $value !== '' ? $value : ($values['DB_'.$field] ?? '');
    putenv('DB_'.$field.'='.$value);
    $_ENV['DB_'.$field] = $value;
    $_SERVER['DB_'.$field] = $value;
}
if (($values['MYSQL_ATTR_SSL_CA'] ?? '') !== '') {
    putenv('MYSQL_ATTR_SSL_CA='.$values['MYSQL_ATTR_SSL_CA']);
    $_ENV['MYSQL_ATTR_SSL_CA'] = $_SERVER['MYSQL_ATTR_SSL_CA'] = $values['MYSQL_ATTR_SSL_CA'];
}
if ($_ENV['DB_HOST'] === '' || $_ENV['DB_USERNAME'] === '') {
    fwrite(STDERR, ".env icinde DB_TEST_HOST ve DB_TEST_USERNAME (veya DB_HOST/DB_USERNAME) doldurulmali. Baglanti kurulmadi.\n");
    exit(2);
}
