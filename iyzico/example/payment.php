<?php
 
session_start();

$results = (object) [];

if (isset($_SESSION['payment']) && !empty($_SESSION['payment'])) {
    $payment=$_SESSION['payment'];
    $b = strtotime('-5 minute');
    $c = $payment['time'];
    if ($c-$b>0 && $payment['count']>2) {
        $results->result = -3;
        exit(json_encode($results));
    }
    if ($payment['count']>10) {
        $b = strtotime('-60 minute');
        if ($c-$b>0) {
            $results->result = -4;
            exit(json_encode($results));
        } else {
            $payment = ['time'=>time(),'count'=>0];
            $_SESSION['payment']=$payment;
        }
    }
} else {
    $payment = ['time'=>time(),'count'=>0];
    $_SESSION['payment']=$payment;
}

$data = json_decode(file_get_contents("php://input"));

if (!is_object($data) ||
!isset($data->id) || 
!isset($data->card) || 
!isset($data->name) || 
!isset($data->month) || 
!isset($data->year) || 
!isset($data->ccv)|| 
!isset($data->installment)) {
    $payment->time = time();
    $payment->count = $payment->count+1;
    $_SESSION['payment']=$payment;
    $results->result = 0;
    exit(json_encode($results));
}

require $_SERVER['DOCUMENT_ROOT'].'/api/database/Sql.php';
$sql = new Sql();

$id = $sql->saveText($data->id);
$card = $sql->saveText($data->card);
$card_name = $sql->saveText($data->name);
$month = $sql->saveText($data->month); 
$year = $sql->saveText($data->year);
$ccv = $sql->saveText($data->ccv);
$installment = $sql->saveText($data->installment);

$mysqli = $sql->connect();

$m = $sql->get($mysqli, 'SELECT * FROM orders WHERE id=?', [$id]);

$user_infos = $m->data[0];
$education_id = $user_infos['education_id'];
$price = $user_infos['price'];

$m = $sql->get($mysqli, 'SELECT a.header,b.name FROM educations a inner join education_categories b on a.education_id = b.id WHERE a.id=?', [$education_id]);
$m = $m->data[0];

$header = $m['header'];
$category = $m['name'];

include('../iyzico_config.php');

if($installment>1){
    # create request class
    $request = new \Iyzipay\Request\RetrieveInstallmentInfoRequest();
    $request->setLocale(\Iyzipay\Model\Locale::TR);
    $request->setConversationId($id);
    $request->setBinNumber($card);
    $request->setPrice($price);

    # make request
    $a = \Iyzipay\Model\InstallmentInfo::retrieve($request, Config::options());
    
    $a = json_decode($a->getRawResult());
    $a = $a->installmentDetails[0]->installmentPrices;
    foreach ($a as $b){
        if($b->installmentNumber==$installment){
            $price = $b->totalPrice+($price*159/10000)+0.25;
        }
    }
}

$request = new \Iyzipay\Request\CreatePaymentRequest();
$request->setLocale(\Iyzipay\Model\Locale::TR);
$request->setConversationId($id);
$request->setPrice($price);
$request->setPaidPrice($price);
$request->setCurrency(\Iyzipay\Model\Currency::TL);
$request->setInstallment($installment);
$request->setBasketId($id);
$request->setPaymentChannel(\Iyzipay\Model\PaymentChannel::WEB);
$request->setPaymentGroup(\Iyzipay\Model\PaymentGroup::PRODUCT);

$paymentCard = new \Iyzipay\Model\PaymentCard();
$paymentCard->setCardHolderName($card_name);
$paymentCard->setCardNumber(preg_replace('/ /','',$card));
$paymentCard->setExpireMonth($month);
$paymentCard->setExpireYear($year);
$paymentCard->setCvc($ccv);
$paymentCard->setRegisterCard(0);   
$request->setPaymentCard($paymentCard);

 
if (!empty($_SERVER["HTTP_CLIENT_IP"]))
{
    $ip = $_SERVER["HTTP_CLIENT_IP"];
}
elseif (!empty($_SERVER["HTTP_X_FORWARDED_FOR"]))
{
    $ip = $_SERVER["HTTP_X_FORWARDED_FOR"];
}
else
{
    $ip = $_SERVER["REMOTE_ADDR"];
} 

$buyer = new \Iyzipay\Model\Buyer();
$buyer->setId($id);
$buyer->setName($user_infos['name']);
$buyer->setSurname($user_infos['surname']);
$buyer->setIdentityNumber($user_infos['tc']);
$buyer->setGsmNumber($user_infos['phone']);
$buyer->setEmail($user_infos['email']);
$buyer->setRegistrationAddress($user_infos['address']);
$buyer->setIp($ip);
$buyer->setCountry($user_infos['country']);
$buyer->setCity($user_infos['city']);
$buyer->setZipCode('123');
$request->setBuyer($buyer);

$billingAddress = new \Iyzipay\Model\Address();
$billingAddress->setContactName($$user_infos['name'].' '.$user_infos['surname']);
$billingAddress->setCity($user_infos['city']);
$billingAddress->setCountry($user_infos['country']);
$billingAddress->setAddress($user_infos['address']); 
$billingAddress->setZipCode('123');
$request->setBillingAddress($billingAddress);

$basketItems = array();
$firstBasketItem = new \Iyzipay\Model\BasketItem();
$firstBasketItem->setId($education_id);
$firstBasketItem->setName($header);
$firstBasketItem->setCategory1($category); 
$firstBasketItem->setItemType(\Iyzipay\Model\BasketItemType::VIRTUAL);
$firstBasketItem->setPrice($price);
$basketItems[0] = $firstBasketItem;
$request->setBasketItems($basketItems);

$payment = \Iyzipay\Model\Payment::create($request, Config::options());
$c = $payment->getRawResult();
$b = json_decode($c); 

if($b->status=='success'){
    $sql->query($mysqli,'UPDATE orders SET paid=1, paid_at = NOW() WHERE id = ?',[$id]);
    $m = $sql->get($mysqli,'SELECT discount_code FROM orders WHERE id=?',[$id]);
    $m = $m->data[0]['discount_code'];
    $sql->query($mysqli,'UPDATE discounts SET used = used+1 WHERE code=?',[$m]);
    
    $data = (Object) [];
    $data->id = $id;
    $data->price = $price;

    $email = $user_infos['email'];

    require_once $_SERVER['DOCUMENT_ROOT'].'/api/kalenux/Kalenux.php';
    $kalenux = new Kalenux();
    $m = $kalenux->get_template('static_templates/bill.kalenux', $data);
    if ($m->result===1) {
        $mail = $m->text;

        require_once $_SERVER['DOCUMENT_ROOT'].'/api/mailer/Mailer.php';
        $mailer = new Mailer();
        $m = $mailer->php_mailer($email, 'Sipariş Detayları', $mail);
    }

}

$results->result = 1;
$results->data = $c;
exit(json_encode($results));
