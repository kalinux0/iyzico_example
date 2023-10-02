<?php
 
session_start();

$results = (object) [];
 
$data = json_decode(file_get_contents("php://input"));

if (!is_object($data) ||
!isset($data->card) ||
!isset($data->price) ) { 
    $results->result = 0;
    exit(json_encode($results));
}

require $_SERVER['DOCUMENT_ROOT'].'/api/database/Sql.php';
$sql = new Sql();

$mysqli = $sql->connect();

include('../iyzico_config.php');
 
$request = new \Iyzipay\Request\RetrieveInstallmentInfoRequest();

$request->setLocale(\Iyzipay\Model\Locale::TR);
$request->setConversationId("123456789");
$request->setBinNumber($data->card);
$request->setPrice($data->price);

$installmentInfo = \Iyzipay\Model\InstallmentInfo::retrieve($request, Config::options());

$results->data = $installmentInfo->getRawResult();
$results->result = 1;

exit(json_encode($results));