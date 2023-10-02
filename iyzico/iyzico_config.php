<?php

require $_SERVER['DOCUMENT_ROOT'].'/api/main/iyzipay-php-2.0.48/IyzipayBootstrap.php';

IyzipayBootstrap::init();

class Config 
{
    public static function options()
    {
        $options = new \Iyzipay\Options();
                 
        $options->setApiKey('@iyzico_api_key@');
        $options->setSecretKey('@iyzico_secret_key@');
        $options->setBaseUrl('@iyzico_base_url@');

        return $options;
    }
}  