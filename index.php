<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');
error_reporting(0);

function push($data, $name, $die=false, $clear=false, $msg=''){
    if ($clear) unlink($name.'.log');
    $fp = fopen($name.'.log', 'a');
    fwrite($fp, date("d.m.y").' '.date("H:i:s").' | '.$data . PHP_EOL);
    fclose($fp);
    if ($die) die($msg);
}

function _isCurl(){
    return function_exists('curl_version');
}

function connect($db, $p) {
    $connect = mysqli_connect($p[$db]['host'], $p[$db]['user'], $p[$db]['password']) or push('no connection to the database', 'error', true);
    mysqli_query($connect, "set names utf8");
    mysqli_query($connect, "SET sql_mode = ''");
    return $connect;
}

function disconnect($db){
    mysqli_close($db);
}

function getQuantitiesFrom1C(){
    if (!_iscurl()) push('curl is disabled', 'error', true);
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => "http://cloud.itone.ru/LADYSSHOWROOM_UNF/hs/atnApi/ProductInfo",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "UTF-8",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "POST",
        /*CURLOPT_POSTFIELDS => "[\r\n  {\r\n    \"id\": \"adr 1\",\r\n    \"original-address\": \"".$_POST['original_address']."\"\r\n  }\r\n]",*/
        CURLOPT_POSTFIELDS => "{\"product\": \"\"}",
        CURLOPT_HTTPHEADER => array(
            "Authorization: Basic " . base64_encode("itone" . ":" . "itone"),
            "cache-control: no-cache",
            "content-type: application/json"
        ),
    ));
    $data = curl_exec($curl); $error = curl_error($curl); curl_close($curl);
    if ($error) push('request failed: '.var_dump($error), 'error', true);
    return json_decode($data, true);
}

$config = parse_ini_file('config.ini', true);
$db =  connect('development', $config);
mysqli_select_db($db, $config['development']['dbname']);

$rows = getQuantitiesFrom1C();
if(empty($rows)) push('response empty', 'error', true);
mysqli_query($db, "SET FOREIGN_KEY_CHECKS = 0;");
mysqli_query($db, "TRUNCATE table catalog;");
mysqli_query($db, "SET FOREIGN_KEY_CHECKS = 1;");
//file_put_contents('data.json', json_encode($rows['products'], JSON_UNESCAPED_UNICODE));
foreach ($rows['products'] as $key => $product) {
    $product['stock'] = "5";
    foreach ($product['onhand'] as $key => $quantity) {
        $query = "
                INSERT IGNORE INTO  `catalog` 
                (
                    `id` ,
                    `product_id` ,
                    `size_id` ,
                    `showroom_id` ,
                    `place_id` ,
                    `quantity`
                )
                VALUES 
                (NULL ,  '" . $product['id'] . "',  '" . $quantity['sizeid'] . "',  '" . $product['stock'] . "',  NULL,  '" . $quantity['qty'] . "')
                ON DUPLICATE KEY UPDATE  id=LAST_INSERT_ID(id), quantity=" . $quantity['qty']
        ;
        //echo $query."\t"."\t";
    }
    $result = mysqli_query($db, $query);
    $id = mysqli_insert_id($db); ($id>0?$created++:$updated++);
}
disconnect($db);

$response = [];
/*iconv(mb_detect_encoding($data, mb_detect_order(), true), "UTF-8", $data);*/
$response['result']['created'] = ($created>0?$created:0);
$response['result']['updated'] = ($updated>0?$updated:0);
echo json_encode($response, JSON_UNESCAPED_UNICODE );
?>