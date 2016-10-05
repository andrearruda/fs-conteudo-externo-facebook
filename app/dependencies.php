<?php
// DIC configuration

$container = $app->getContainer();

// -----------------------------------------------------------------------------
// Service providers
// -----------------------------------------------------------------------------

// -----------------------------------------------------------------------------
// Service factories
// -----------------------------------------------------------------------------
// Facebook Config

$container['facebook'] = function ($c) {
    $config = require('config.local.php');
    return $config['facebook'];
};

$container['paths'] = function ($c) {
    $config = require('config.local.php');
    return $config['paths'];
};


// -----------------------------------------------------------------------------
// Action factories
// -----------------------------------------------------------------------------

$container[App\Action\FacebookAction::class] = function ($c) {
    return new App\Action\FacebookAction($c->get('facebook'), $c->get('paths'));
};

$container[App\Action\HomeAction::class] = function ($c) {
    return new App\Action\HomeAction($c->get('facebook'));
};
