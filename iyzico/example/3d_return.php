<?php
 
session_start();

$url = '@site@';

if(!isset($_POST['status'])){

    exit('<script>
     
    if(!window.navigator.userAgent.match(/(iPod|iPhone|iPad)/i)) {
        window.close();
    }
    location.href="'.$url.'";
    
    </script>');
      
}

$status = $_POST['status'];
$paymentId = $_POST['paymentId'];
$conversationData = $_POST['conversationData'];
$conversationId = $_POST['conversationId'];
$mdStatus = $_POST['mdStatus'];

require $_SERVER['DOCUMENT_ROOT'].'/api/database/Sql.php';
$sql = new Sql();

$mysqli = $sql->connect();
 

if($status=='success' && $mdStatus==1){
    include('../iyzico_config.php');
    
    
    $request = new \Iyzipay\Request\CreateThreedsPaymentRequest();
    $request->setLocale(\Iyzipay\Model\Locale::TR);
    $request->setConversationId($conversationId);
    $request->setPaymentId($paymentId);
    $request->setConversationData($conversationData);

    $a = \Iyzipay\Model\ThreedsPayment::create($request, Config::options());
    $a = json_decode($a->getRawResult());
    
    if($a->status=='success'){
        $id = $conversationId;
        $sql->query($mysqli,'UPDATE orders SET paid=1, paid_at = NOW() WHERE id = ?',[$id]);

        $m = $sql->get($mysqli,'SELECT * FROM orders WHERE id=?',[$id]);

        $user_infos = $m->data[0];

        if($user_infos['discount']===0){
            $m = $user_infos['discount_code'];
            $sql->query($mysqli,'UPDATE discounts SET used = used+1 WHERE code=?',[$m]);
        }

        $m = $sql->get($mysqli,'SELECT c.page,b.header,c.name FROM orders a inner join education b on a.education_id = b.id inner join education_categories c on b.education_id = c.id WHERE a.id=?',[$id]);
        $m = $m->data[0];

        $header = $m['header'];
        $category = $m['category'];
        $page = $m['page'];

        $url .= 'egitim/'.$page.'?tebrikler';
        
        $data = (Object) [];
        $data->id = $id;
        $data->price = $user_infos['price'];

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

    }else{
        $url.='?1';
    }
}

echo '<script>

if(!window.navigator.userAgent.match(/(iPod|iPhone|iPad)/i)) {
    window.close();
}
location.href="'.$url.'";

</script>';
  