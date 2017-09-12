<?php

require_once '../../../../vendor/autoload.php';

use Solis\Expressive\Sample\Pessoa\Repository\Pessoa;
use Solis\Breaker\Abstractions\TExceptionAbstract;

try {

    require_once '../../connection/connection.php';

    $Pessoa = Pessoa::make([
       "proCodigo"           => 1,
       "proNome"             => 'Fulano - ' . uniqid(rand()),
       "proInscricaoFederal" => '' . rand(11111111111, 99999999999) . '',
       "proCidade"           => [
           "proID"     => 1,
           "proNome"   => "Pouso Redondo",
           "proIbgeId" => "1",
       ],
   ]);

    $record = $Pessoa->create();
    if (empty($record)) {
        die('erro ao criar registro');
    }

    echo json_encode($record->toArray());

} catch (TExceptionAbstract $ex) {
    echo $ex->toJson();
}
