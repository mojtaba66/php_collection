<?php 
require_once 'Collection.php';

$array=[
    ['account_id' => 'account-x15', 'product' => 'Desk', 'price' => '50'],
    ['account_id' => 'account-x11', 'product' => 'Chair', 'price' => '50'],
    ['account_id' => 'account-x5411', 'product' => 'Bookcase', 'price' => '40'],
];

$collection = collect($array);
echo '<pre>';
print_r($collection->skip(1));
echo '</pre>';


function collect($array){
    return new Collection($array);
}