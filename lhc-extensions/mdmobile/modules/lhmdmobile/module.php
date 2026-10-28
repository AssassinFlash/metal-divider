<?php

$Module = array(
    'name' => 'Mobile desk',
);

$ViewList = array();

// No functions entry. LHC would require lhmdmobile/use, which the operator
// does not have. The views check the operator session and lhchat/use
// themselves. The list then uses the native department limitation and
// hasAccessToRead. A new module permission would hide the same operator.
$ViewList['chats'] = array(
    'params' => array(),
    'uparams' => array('q', 'status', 'offset'),
    'pagelayout' => 'mdmobile',
);

$ViewList['list'] = array(
    'params' => array(),
    'uparams' => array('q', 'status', 'offset'),
    'ajax' => true,
);

$ViewList['chat'] = array(
    'params' => array('chat_id'),
    'uparams' => array('q', 'status', 'offset'),
    'pagelayout' => 'mdmobile',
);

$ViewList['messages'] = array(
    'params' => array('chat_id'),
    'uparams' => array('after_id'),
    'ajax' => true,
);

$ViewList['read'] = array(
    'params' => array('chat_id'),
    'uparams' => array(),
    'ajax' => true,
);

$FunctionList = array();
