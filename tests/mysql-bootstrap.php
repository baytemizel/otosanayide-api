<?php

/*
 * phpunit.mysql.xml: baglanti bilgisini .env'den alir. Laravel test modunda .env.testing okudugu icin
 * .env tek basina etkili olmaz. Hedef DB_TEST_DATABASE, yoksa DB_DATABASE. Icinde kayit varsa TestCase migrate:fresh'i reddeder.
 */
require __DIR__.'/../vendor/autoload.php';

$path = __DIR__.'/../.env';
$values = is_file($path) ? Dotenv\Dotenv::parse((string) file_get_contents($path)) : [];
foreach (['HOST', 'PORT', 'DATABASE', 'USERNAME', 'PASSWORD'] as $field) {
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
putenv('ALLOWED_TEST_DATABASE='.$_ENV['DB_DATABASE']);
$_ENV['ALLOWED_TEST_DATABASE'] = $_SERVER['ALLOWED_TEST_DATABASE'] = $_ENV['DB_DATABASE'];
if ($_ENV['DB_HOST'] === '' || $_ENV['DB_USERNAME'] === '') {
    fwrite(STDERR, ".env icinde DB_TEST_HOST ve DB_TEST_USERNAME (veya DB_HOST/DB_USERNAME) doldurulmali. Baglanti kurulmadi.\n");
    exit(2);
}
