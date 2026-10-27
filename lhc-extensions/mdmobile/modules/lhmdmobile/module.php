<?php

$Module = array(
    'name' => 'Mobile desk',
);

$ViewList = array();

// No functions entry. LHC would require lhmdmobile/use, which the operator
// does not have. chat.php and messages.php check the operator session and
// hasAccessToRead themselves.
$ViewList['chat'] = array(
    'params' => array('chat_id'),
    'uparams' => array(),
    'pagelayout' => 'mdmobile',
);

$ViewList['messages'] = array(
    'params' => array('chat_id'),
    'uparams' => array('after_id'),
    'ajax' => true,
);

$FunctionList = array();
